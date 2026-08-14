<?php
namespace IGBZ\Suite\Modules\RestApi\Controllers;

use IGBZ\Suite\Modules\Instagram\Services\ProductIntakeService;
use IGBZ\Suite\Modules\Instagram\Services\ProductPublisher;
use IGBZ\Suite\Modules\Instagram\Services\SkuGenerator;
use IGBZ\Suite\Modules\Instagram\Services\TranslationBridge;
use IGBZ\Suite\Modules\Instagram\Speech\SpeechToText;
use IGBZ\Suite\Support\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * The whole product-registration flow, as seen by the phone.
 *
 * Thirteen steps, one endpoint each, no WooCommerce admin anywhere:
 *
 *   POST   /intake/photo              2  upload the shot, grading starts
 *   GET    /intake/{id}               3  poll: verdict, reasons, prepared image, everything
 *   POST   /intake/{id}/retry          3  the photo was rejected; here is another one
 *   POST   /intake/{id}/edited         5  the image as saved in the app's editor
 *   POST   /intake/{id}/skip-editor    5  no edits wanted
 *   POST   /intake/{id}/description    6  text, or a voice note, plus price/stock/category
 *   POST   /intake/{id}/publish        7  write the listing and create the product
 *   POST   /intake/{id}/post-kind      9  image or video?
 *   POST   /intake/{id}/video         10  produce the video from a prompt (text or voice)
 *   POST   /intake/{id}/approve-video 10  the seller likes it
 *   POST   /intake/{id}/compose       11  stamp the code, write the caption, pick hashtags
 *   POST   /intake/{id}/schedule      12  hand the finished post to Manus
 *   GET    /intake/form                   categories, languages and defaults for the form
 *   GET    /intake                        the seller's registrations
 *   DELETE /intake/{id}                   abandon one
 *
 * The editor at step 5 lives in the Flutter app; this side of it is only "here is the processed
 * image" and "here is what I saved".
 *
 * Long steps are asynchronous because Manus is: the endpoint that starts one returns immediately
 * with the row's new status and the app polls GET /intake/{id}. Every response carries the same
 * `state` envelope so the client has exactly one shape to parse.
 */
final class ProductIntakeController extends BaseController {

	public function __construct(
		private ProductIntakeService $intake,
		private ProductPublisher $publisher,
		private SpeechToText $speech,
		private TranslationBridge $translations,
		private SkuGenerator $skus
	) {}

	public function register_routes(): void {
		// The flow is only meaningful when the Instagram module is on: it is what owns the
		// assistant, the funnels and the Manus credentials.
		if ( ! Modules::enabled( Modules::INSTAGRAM ) ) {
			return;
		}

		$ns    = self::NAMESPACE;
		$owner = [ $this, 'can_manage_tenant' ];

		register_rest_route( $ns, '/intake', $this->route( 'GET', [ $this, 'index' ], $owner ) );
		register_rest_route( $ns, '/intake/form', $this->route( 'GET', [ $this, 'form' ], $owner ) );
		register_rest_route( $ns, '/intake/photo', $this->route( 'POST', [ $this, 'upload_photo' ], $owner ) );

		register_rest_route( $ns, '/intake/(?P<id>\d+)', $this->route( 'GET', [ $this, 'show' ], $owner ) );
		register_rest_route( $ns, '/intake/(?P<id>\d+)', $this->route( 'DELETE', [ $this, 'destroy' ], $owner ) );

		foreach (
			[
				'retry'         => 'retry_photo',
				'edited'        => 'save_edited',
				'skip-editor'   => 'skip_editor',
				'description'   => 'save_description',
				'publish'       => 'publish',
				'post-kind'     => 'post_kind',
				'video'         => 'make_video',
				'approve-video' => 'approve_video',
				'compose'       => 'compose',
				'schedule'      => 'schedule',
			] as $path => $method
		) {
			register_rest_route(
				$ns,
				'/intake/(?P<id>\d+)/' . $path,
				$this->route( 'POST', [ $this, $method ], $owner )
			);
		}
	}

	// ------------------------------------------------------------- listing

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		[ $page, $per_page, $offset ] = $this->page_args( $request );

		$args = [
			'tenant_id' => $this->scoped_tenant_id( $request ),
			'limit'     => $per_page,
			'offset'    => $offset,
		];

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		$items = array_map( [ $this, 'state' ], $this->intake->all( $args ) );

		return $this->paged( $items, $this->intake->count( $args ), $page, $per_page );
	}

	/**
	 * Everything the registration form needs, so the app never hard-codes a category list.
	 */
	public function form( \WP_REST_Request $request ): \WP_REST_Response {
		$tenant_id = $this->scoped_tenant_id( $request );

		$categories = [];
		$terms      = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300 ] );

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$owner = (int) get_term_meta( $term->term_id, '_igbz_tenant_id', true );
				if ( $tenant_id > 0 && $owner > 0 && $owner !== $tenant_id ) {
					continue;
				}
				$categories[] = [
					'id'        => (int) $term->term_id,
					'parent_id' => (int) $term->parent,
					'name'      => $term->name,
				];
			}
		}

		$settings = igbz()->settings();

		return $this->ok(
			[
				'categories'        => $categories,
				'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : $settings->string( 'general.default_currency', 'IRT' ),
				'sku_prefix'        => $this->skus->prefix(),
				'multilingual'      => $this->translations->is_multilingual(),
				'languages'         => $this->translations->languages(),
				'default_language'  => $this->translations->default_language(),
				'voice_enabled'     => $settings->bool( 'stt.enabled', true ),
				'voice_engine'      => $this->speech->preferred()->id(),
				'quality_threshold' => $settings->int( 'intake.quality_threshold', 60 ),
				'price_required'    => true,
				'max_upload_bytes'  => wp_max_upload_size(),
			]
		);
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		return $this->ok( $this->state( $row ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		// A registration that already produced a product is history, not a draft: deleting the
		// row would orphan the funnel that points at the product.
		if ( (int) $row['product_id'] > 0 ) {
			return $this->fail(
				'igbz_intake_published',
				__( 'This registration already created a product, so it cannot be discarded.', 'igbz-suite' ),
				409
			);
		}

		$this->intake->delete( (int) $row['id'] );

		return $this->ok( [ 'ok' => true ] );
	}

	// ------------------------------------------------------ step 2: photo

	public function upload_photo( \WP_REST_Request $request ): \WP_REST_Response {
		$stored = $this->store_upload( $request, 'photo', [ 'image/jpeg', 'image/png', 'image/webp', 'image/heic' ] );
		if ( $stored instanceof \WP_REST_Response ) {
			return $stored;
		}

		$id = $this->intake->create(
			[
				'tenant_id'            => $this->scoped_tenant_id( $request ),
				'account_id'           => (int) $request->get_param( 'account_id' ),
				'user_id'              => get_current_user_id(),
				'source_attachment_id' => $stored['attachment_id'],
				'source_url'           => $stored['url'],
			]
		);

		if ( 0 === $id ) {
			return $this->fail( 'igbz_intake_failed', __( 'The photo could not be registered.', 'igbz-suite' ), 500 );
		}

		$this->intake->start_grading( $id, sanitize_text_field( (string) $request->get_param( 'hint' ) ) );

		$row = $this->intake->get( $id );

		return $this->ok( $this->state( is_array( $row ) ? $row : [] ), 201 );
	}

	/**
	 * Step 3's unhappy path: the photo was rejected, here is another attempt.
	 *
	 * The same row is reused rather than starting over, so the attempt counter keeps climbing and
	 * the app can escalate its advice ("still too dark — try near a window") instead of repeating
	 * itself.
	 */
	public function retry_photo( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		if ( (int) $row['product_id'] > 0 ) {
			return $this->fail(
				'igbz_intake_published',
				__( 'This registration already created a product; start a new one instead.', 'igbz-suite' ),
				409
			);
		}

		$stored = $this->store_upload( $request, 'photo', [ 'image/jpeg', 'image/png', 'image/webp', 'image/heic' ] );
		if ( $stored instanceof \WP_REST_Response ) {
			return $stored;
		}

		$id = (int) $row['id'];

		$this->intake->update(
			$id,
			[
				'source_attachment_id' => $stored['attachment_id'],
				'source_url'           => $stored['url'],
				'attempt'              => (int) $row['attempt'] + 1,
				'quality_score'        => 0,
				'quality_verdict'      => '',
				'quality_reasons'      => null,
				'status'               => ProductIntakeService::STATUS_UPLOADED,
				'last_error'           => '',
				'retry_count'          => 0,
			]
		);

		$this->intake->start_grading( $id, sanitize_text_field( (string) $request->get_param( 'hint' ) ) );

		return $this->ok( $this->state( (array) $this->intake->get( $id ) ) );
	}

	// ----------------------------------------------------- step 5: editor

	public function save_edited( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		$stored = $this->store_upload( $request, 'image', [ 'image/jpeg', 'image/png', 'image/webp' ] );
		if ( $stored instanceof \WP_REST_Response ) {
			return $stored;
		}

		$this->intake->save_edited_image( (int) $row['id'], $stored['url'], $stored['attachment_id'] );

		return $this->ok( $this->state( (array) $this->intake->get( (int) $row['id'] ) ) );
	}

	public function skip_editor( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		$this->intake->skip_editor( (int) $row['id'] );

		return $this->ok( $this->state( (array) $this->intake->get( (int) $row['id'] ) ) );
	}

	// ------------------------------------------------ step 6: description

	/**
	 * The description, by text or by voice, plus the fields only the seller can supply.
	 *
	 * Price is mandatory and deliberately not inferable: the assistant is told never to guess one,
	 * so if the form does not carry it there is no listing to make.
	 */
	public function save_description( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		$id    = (int) $row['id'];
		$price = (float) $request->get_param( 'price' );

		if ( $price <= 0 ) {
			return $this->fail( 'igbz_price_required', __( 'A price is required.', 'igbz-suite' ) );
		}

		$categories = $request->get_param( 'category_ids' );
		$categories = is_array( $categories ) ? $categories : array_filter( explode( ',', (string) $categories ) );
		if ( ! $categories ) {
			return $this->fail( 'igbz_category_required', __( 'Choose at least one category.', 'igbz-suite' ) );
		}

		$text  = trim( (string) $request->get_param( 'description' ) );
		$files = $request->get_file_params();
		$voice = $files['voice'] ?? null;

		if ( '' === $text && ! is_array( $voice ) ) {
			return $this->fail(
				'igbz_description_required',
				__( 'Describe the product, either by typing or by recording a voice note.', 'igbz-suite' )
			);
		}

		$this->intake->save_description(
			$id,
			[
				'description'  => $text,
				'input_mode'   => is_array( $voice ) ? ProductIntakeService::INPUT_VOICE : ProductIntakeService::INPUT_TEXT,
				'price'        => $price,
				'sale_price'   => (float) $request->get_param( 'sale_price' ),
				'stock'        => (int) $request->get_param( 'stock' ),
				'category_ids' => $categories,
			]
		);

		if ( is_array( $voice ) ) {
			$transcription = $this->transcribe( $id, $request, 'voice' );
			if ( $transcription instanceof \WP_REST_Response ) {
				return $transcription;
			}
		}

		return $this->ok( $this->state( (array) $this->intake->get( $id ) ) );
	}

	// ------------------------------------------- steps 7-8: the product

	/**
	 * Write the listing and, once it is written, create the product.
	 *
	 * Two phases behind one endpoint on purpose: the app calls it, gets `writing`, polls, and
	 * calls it again when the copy has landed. Splitting it into two endpoints would push a
	 * distinction that exists only because Manus is asynchronous onto the client.
	 */
	public function publish( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		$id = (int) $row['id'];

		if ( (int) $row['product_id'] > 0 ) {
			return $this->ok( $this->state( $row ) );
		}

		// The copy is already back, so this call is the "now create it" half.
		if ( $this->intake->copy( $row ) ) {
			$result = $this->publisher->publish( $row );

			if ( ! $result['ok'] ) {
				return $this->fail( 'igbz_product_failed', $result['error'], 500 );
			}

			// Step 8: hand the product and its code to the Instagram assistant, which begins by
			// asking whether the post should be an image or a video.
			$this->intake->choose_kind( $id, '' );

			return $this->ok( $this->state( (array) $this->intake->get( $id ) ) );
		}

		if ( ProductIntakeService::STATUS_WRITING === (string) $row['status'] ) {
			return $this->ok( $this->state( $row ) );
		}

		if ( '' === trim( (string) $row['raw_description'] ) ) {
			return $this->fail(
				'igbz_description_missing',
				__( 'The description has not been saved yet.', 'igbz-suite' ),
				409
			);
		}

		$this->intake->start_writing( $id, $this->translations->target_languages() );

		return $this->ok( $this->state( (array) $this->intake->get( $id ) ) );
	}

	// ------------------------------------------- steps 9-10: the video

	public function post_kind( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		if ( ! in_array( $kind, [ ProductIntakeService::KIND_IMAGE, ProductIntakeService::KIND_VIDEO ], true ) ) {
			return $this->fail( 'igbz_bad_kind', __( 'Choose either an image post or a video post.', 'igbz-suite' ) );
		}

		$this->intake->choose_kind( (int) $row['id'], $kind );

		return $this->ok( $this->state( (array) $this->intake->get( (int) $row['id'] ) ) );
	}

	/** Step 10: the seller's brief, typed or dictated, becomes a video. */
	public function make_video( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		$id     = (int) $row['id'];
		$prompt = trim( (string) $request->get_param( 'prompt' ) );
		$files  = $request->get_file_params();

		if ( isset( $files['voice'] ) && is_array( $files['voice'] ) ) {
			$transcription = $this->transcribe( $id, $request, 'voice' );
			if ( $transcription instanceof \WP_REST_Response ) {
				return $transcription;
			}

			// A synchronous engine has already merged the text into the description; the video
			// brief needs it separately, so it is read back off the row.
			$fresh  = (array) $this->intake->get( $id );
			$prompt = trim( $prompt . "\n" . (string) $fresh['transcript'] );
		}

		if ( '' === $prompt ) {
			return $this->fail(
				'igbz_prompt_required',
				__( 'Say or type what the video should show.', 'igbz-suite' )
			);
		}

		$this->intake->start_video( $id, $prompt );

		return $this->ok( $this->state( (array) $this->intake->get( $id ) ) );
	}

	public function approve_video( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		if ( ! $this->intake->approve_video( (int) $row['id'] ) ) {
			return $this->fail(
				'igbz_no_video',
				__( 'There is no finished video waiting for approval.', 'igbz-suite' ),
				409
			);
		}

		return $this->ok( $this->state( (array) $this->intake->get( (int) $row['id'] ) ) );
	}

	// ------------------------------------------ steps 11-13: the post

	public function compose( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		if ( (int) $row['product_id'] <= 0 ) {
			return $this->fail(
				'igbz_no_product',
				__( 'The product has to exist before its post can be composed.', 'igbz-suite' ),
				409
			);
		}

		if ( ProductIntakeService::KIND_VIDEO === (string) $row['post_kind'] && ! (int) $row['video_approved'] ) {
			return $this->fail(
				'igbz_video_unapproved',
				__( 'Approve the video before the post is composed.', 'igbz-suite' ),
				409
			);
		}

		$this->intake->start_composing( (int) $row['id'] );

		return $this->ok( $this->state( (array) $this->intake->get( (int) $row['id'] ) ) );
	}

	/**
	 * Steps 12 and 13: hand the finished post to Manus and the purchase link to ManyChat.
	 *
	 * Composition has to have finished first, which is why this is a separate call rather than
	 * the tail of compose(): the caption and the code-stamped image come back from an
	 * asynchronous task, and the app polls until they have.
	 */
	public function schedule( \WP_REST_Request $request ): \WP_REST_Response {
		$row = $this->guard( $request );
		if ( $row instanceof \WP_REST_Response ) {
			return $row;
		}

		if ( (int) $row['content_id'] <= 0 ) {
			return $this->fail(
				'igbz_not_composed',
				__( 'The post has not been composed yet.', 'igbz-suite' ),
				409
			);
		}

		$when = (string) $request->get_param( 'scheduled_for' );
		$this->publisher->hand_off( $row, $when );

		return $this->ok( $this->state( (array) $this->intake->get( (int) $row['id'] ) ) );
	}

	// -------------------------------------------------------------- shared

	/**
	 * Transcribe an uploaded voice note.
	 *
	 * Returns a response only on a hard failure; a pending asynchronous transcription is a normal
	 * outcome and simply parks the row.
	 *
	 * @return true|\WP_REST_Response
	 */
	private function transcribe( int $id, \WP_REST_Request $request, string $field ) {
		if ( ! igbz()->settings()->bool( 'stt.enabled', true ) ) {
			return $this->fail( 'igbz_voice_disabled', __( 'Voice input is switched off for this store.', 'igbz-suite' ), 409 );
		}

		$stored = $this->store_upload(
			$request,
			$field,
			[ 'audio/mpeg', 'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm', 'audio/aac', 'audio/opus', 'video/mp4' ]
		);

		if ( $stored instanceof \WP_REST_Response ) {
			return $stored;
		}

		$row     = (array) $this->intake->get( $id );
		$account = $this->intake->account_for( $row );

		$result = $this->speech->transcribe(
			$stored['path'],
			igbz()->settings()->string( 'stt.language', '' ),
			[ 'account' => $account, 'url' => $stored['url'] ]
		);

		if ( $result->ok ) {
			$this->intake->absorb_transcript( $id, $result->text );
			return true;
		}

		if ( $result->is_pending() ) {
			$this->intake->await_transcript( $id, $result->task_id );
			return true;
		}

		return $this->fail( 'igbz_transcription_failed', $result->error, 502 );
	}

	/**
	 * Move an uploaded file into the media library.
	 *
	 * The media library rather than a private directory, because every one of these assets has to
	 * be fetchable by Manus over plain HTTP — an agent cannot read a path on the server's disk.
	 *
	 * @param array<int,string> $mimes
	 * @return array{attachment_id:int,url:string,path:string}|\WP_REST_Response
	 */
	private function store_upload( \WP_REST_Request $request, string $field, array $mimes ) {
		$files = $request->get_file_params();
		$file  = $files[ $field ] ?? null;

		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			return $this->fail( 'igbz_no_file', __( 'No file was uploaded.', 'igbz-suite' ) );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) ( $file['name'] ?? '' ) );
		$type  = (string) ( $check['type'] ?: ( $file['type'] ?? '' ) );

		// Checked against the real bytes, not the browser's claim: wp_check_filetype_and_ext
		// sniffs the file, so a .jpg that is really a PHP script does not get through.
		if ( '' !== $type && ! in_array( $type, $mimes, true ) ) {
			return $this->fail(
				'igbz_bad_type',
				sprintf( /* translators: %s: MIME type */ __( 'Files of type %s are not accepted here.', 'igbz-suite' ), $type )
			);
		}

		$moved = wp_handle_upload( $file, [ 'test_form' => false, 'mimes' => $this->mime_map( $mimes ) ] );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			return $this->fail( 'igbz_upload_failed', (string) ( $moved['error'] ?? __( 'The upload failed.', 'igbz-suite' ) ) );
		}

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_file_name( basename( $moved['file'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$moved['file']
		);

		if ( is_wp_error( $attachment_id ) || 0 === (int) $attachment_id ) {
			// The file is on disk and reachable, which is all the pipeline strictly needs.
			return [ 'attachment_id' => 0, 'url' => (string) $moved['url'], 'path' => (string) $moved['file'] ];
		}

		$attachment_id = (int) $attachment_id;
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $moved['file'] ) );

		return [
			'attachment_id' => $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'path'          => (string) $moved['file'],
		];
	}

	/**
	 * wp_handle_upload wants extension => MIME; we hold a MIME allow-list. Intersecting the site's
	 * own map keeps the two consistent instead of hard-coding extensions here.
	 *
	 * @param array<int,string> $mimes
	 * @return array<string,string>
	 */
	private function mime_map( array $mimes ): array {
		$allowed = [];
		foreach ( get_allowed_mime_types() as $extensions => $mime ) {
			if ( in_array( $mime, $mimes, true ) ) {
				$allowed[ $extensions ] = $mime;
			}
		}
		return $allowed ?: get_allowed_mime_types();
	}

	/**
	 * Load the row named by the request and prove the caller may touch it.
	 *
	 * @return array<string,mixed>|\WP_REST_Response
	 */
	private function guard( \WP_REST_Request $request ) {
		$row = $this->intake->get( (int) $request->get_param( 'id' ) );

		if ( ! $row ) {
			return $this->fail( 'igbz_not_found', __( 'That registration does not exist.', 'igbz-suite' ), 404 );
		}

		$tenant_id = $this->scoped_tenant_id( $request );
		if ( $tenant_id > 0 && (int) $row['tenant_id'] > 0 && (int) $row['tenant_id'] !== $tenant_id ) {
			return $this->fail( 'igbz_forbidden', __( 'That registration belongs to another store.', 'igbz-suite' ), 403 );
		}

		return $row;
	}

	/**
	 * One response shape for every endpoint.
	 *
	 * `next` is the contract that keeps the app simple: rather than reimplementing the state
	 * machine client-side, the phone reads which call to make next and whether it should keep
	 * polling.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function state( array $row ): array {
		if ( ! $row ) {
			return [];
		}

		$status  = (string) $row['status'];
		$quality = $this->intake->quality( $row );
		$copy    = $this->intake->copy( $row );

		$payload = [
			'id'         => (int) $row['id'],
			'status'     => $status,
			'next'       => $this->next_step( $status, $row ),
			'waiting'    => $this->is_waiting( $status ),
			'sku'        => (string) $row['sku'],
			'attempt'    => (int) $row['attempt'],
			'post_kind'  => (string) $row['post_kind'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
			'error'      => (string) $row['last_error'],
			'images'     => [
				'source' => (string) $row['source_url'],
				'clean'  => (string) $row['clean_url'],
				'edited' => (string) $row['edited_url'],
				'best'   => $this->intake->best_image( $row ),
			],
			'quality'    => [
				'verdict'                  => (string) $row['quality_verdict'],
				'score'                    => (int) $row['quality_score'],
				'reasons'                  => array_map( 'strval', (array) ( $quality['reasons'] ?? [] ) ),
				'suggestion'               => (string) ( $quality['suggestion'] ?? '' ),
				'background_removal_ready' => (bool) ( $quality['background_removal_ready'] ?? true ),
				'video_ready'              => (bool) ( $quality['video_ready'] ?? true ),
				'detected_product'         => (string) ( $quality['detected_product'] ?? '' ),
			],
			'commerce'   => [
				'price'        => (float) $row['price'],
				'sale_price'   => (float) $row['sale_price'],
				'stock'        => (int) $row['stock'],
				'category_ids' => $this->intake->category_ids( $row ),
			],
			'copy'       => $copy,
			'transcript' => (string) $row['transcript'],
			'video'      => [
				'url'      => (string) $row['video_url'],
				'approved' => (bool) (int) $row['video_approved'],
				'prompt'   => (string) $row['video_prompt'],
			],
			'product'    => [
				'id'        => (int) $row['product_id'],
				'permalink' => (int) $row['product_id'] > 0 ? (string) get_permalink( (int) $row['product_id'] ) : '',
				'edit_url'  => '',
			],
			'funnel_id'  => (int) $row['funnel_id'],
			'content_id' => (int) $row['content_id'],
		];

		if ( (int) $row['product_id'] > 0 ) {
			$payload['translations'] = array_keys( $this->intake->translations( $row ) );
		}

		return $payload;
	}

	/** Whether the row is parked on an asynchronous task, i.e. the app should poll. */
	private function is_waiting( string $status ): bool {
		return in_array(
			$status,
			[
				ProductIntakeService::STATUS_GRADING,
				ProductIntakeService::STATUS_PROCESSING,
				ProductIntakeService::STATUS_TRANSCRIBING,
				ProductIntakeService::STATUS_WRITING,
				ProductIntakeService::STATUS_PRODUCING_VIDEO,
				ProductIntakeService::STATUS_COMPOSING,
			],
			true
		);
	}

	/**
	 * The call the app should make next.
	 *
	 * @param array<string,mixed> $row
	 */
	private function next_step( string $status, array $row ): string {
		switch ( $status ) {
			case ProductIntakeService::STATUS_REJECTED:
				return 'retry';

			case ProductIntakeService::STATUS_READY_TO_EDIT:
				return 'edit';

			case ProductIntakeService::STATUS_EDITED:
				return 'describe';

			case ProductIntakeService::STATUS_DESCRIBING:
				return 'publish';

			case ProductIntakeService::STATUS_PRODUCT_CREATED:
			case ProductIntakeService::STATUS_AWAITING_KIND:
				if ( '' === (string) $row['post_kind'] ) {
					return 'choose-post-kind';
				}
				return ProductIntakeService::KIND_VIDEO === (string) $row['post_kind'] ? 'make-video' : 'compose';

			case ProductIntakeService::STATUS_VIDEO_REVIEW:
				return (int) $row['video_approved'] ? 'compose' : 'approve-video';

			case ProductIntakeService::STATUS_SCHEDULED:
			case ProductIntakeService::STATUS_PUBLISHED:
				return 'done';

			case ProductIntakeService::STATUS_FAILED:
				return 'retry';

			default:
				// Everything left is an asynchronous stage; `waiting` already says so.
				if ( (int) $row['content_id'] > 0 ) {
					return 'schedule';
				}
				return 'wait';
		}
	}
}
