<?php
/**
 * The phone-to-Instagram registration flow.
 *
 * The whole point of the feature is that a shopkeeper photographs something and a listing, a post
 * and a working comment-to-DM funnel come out the other end without anybody opening wp-admin.
 * Thirteen steps, most of them waiting on an asynchronous task, is a lot of places for a
 * registration to fall down a hole, so these tests pin the parts that decide whether it does:
 *
 *   - a refused photo comes back with reasons the seller can act on, and does not advance;
 *   - the score threshold overrules an over-generous verdict;
 *   - a retry reuses the row and counts the attempt rather than starting over;
 *   - the product code is legible, unique, and is what the funnel matches on;
 *   - price, stock and category come from the seller and are never invented;
 *   - a step that produces nothing degrades instead of stranding the registration;
 *   - the ids that were never wired up in the port — product_id and funnel_id — reach the content
 *     row, which is what makes the caption ask for the right keyword.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Services\ProductIntakeService;
use IGBZ\Suite\Modules\Instagram\Services\SkuGenerator;
use IGBZ\Suite\Support\Db;

/**
 * In-memory stand-in for the intake, account and funnel tables.
 *
 * The generic wpdb double answers reads from a queue, which cannot express "write a row, then read
 * back what a later step made of it" — and that is exactly what a state machine is. This keeps
 * real rows instead.
 */
final class IntakeDb extends wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $intakes = [];

	/** @var array<int,array<string,mixed>> */
	public array $accounts = [];

	/** @var array<int,array<string,mixed>> */
	public array $funnels = [];

	/** @var array<int,array<string,mixed>> */
	public array $content = [];

	/** SKUs the WooCommerce catalogue already uses. */
	public array $taken_skus = [];

	private int $next_id = 1;

	/** @param array<string,mixed> $row */
	public function add_account( array $row = [] ): int {
		$id                    = $this->next_id++;
		$this->accounts[ $id ] = array_merge(
			[
				'id'               => $id,
				'tenant_id'        => 1,
				'username'         => 'igbz.shop',
				'niche'            => 'handmade leather goods',
				'brand_voice'      => '',
				'timezone'         => 'Asia/Tehran',
				'is_active'        => 1,
				'credential_mode'  => 'own',
				'manus_api_key'    => null,
				'manychat_api_key' => null,
				'manus_project_id' => '',
			],
			$row
		);

		return $id;
	}

	/** @return array<string,mixed> */
	public function intake( int $id ): array {
		return $this->intakes[ $id ] ?? [];
	}

	private static function id_in( string $sql ): int {
		return preg_match( "/\bid = '?(\d+)'?/", $sql, $m ) ? (int) $m[1] : 0;
	}

	private static function value_of( string $column, string $sql ): string {
		return preg_match( "/\b" . preg_quote( $column, '/' ) . " = '([^']*)'/", $sql, $m ) ? $m[1] : '';
	}

	// --------------------------------------------------------------- reads

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'igbz_ig_intake' ) ) {
			$task = self::value_of( 'provider_task_id', $sql );
			if ( '' !== $task ) {
				foreach ( array_reverse( $this->intakes, true ) as $row ) {
					if ( (string) $row['provider_task_id'] === $task ) {
						return $row;
					}
				}
				return null;
			}

			if ( preg_match( "/content_id = '?(\d+)'?/", $sql, $m ) ) {
				foreach ( array_reverse( $this->intakes, true ) as $row ) {
					if ( (int) $row['content_id'] === (int) $m[1] ) {
						return $row;
					}
				}
				return null;
			}

			return $this->intakes[ self::id_in( $sql ) ] ?? null;
		}

		if ( str_contains( $sql, 'igbz_ig_accounts' ) ) {
			$id = self::id_in( $sql );
			if ( $id > 0 && isset( $this->accounts[ $id ] ) ) {
				return $this->accounts[ $id ];
			}
			foreach ( $this->accounts as $account ) {
				if ( 1 === (int) $account['is_active'] ) {
					return $account;
				}
			}
			return null;
		}

		if ( str_contains( $sql, 'igbz_ig_funnels' ) ) {
			return $this->funnels[ self::id_in( $sql ) ] ?? null;
		}

		if ( str_contains( $sql, 'igbz_ig_content' ) ) {
			return $this->content[ self::id_in( $sql ) ] ?? null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'igbz_ig_accounts' ) ) {
			return array_values( array_filter( $this->accounts, static fn ( array $a ): bool => 1 === (int) $a['is_active'] ) );
		}

		if ( str_contains( $sql, 'igbz_ig_intake' ) ) {
			if ( str_contains( $sql, 'GROUP BY status' ) ) {
				$groups = [];
				foreach ( $this->intakes as $row ) {
					$groups[ (string) $row['status'] ] = ( $groups[ (string) $row['status'] ] ?? 0 ) + 1;
				}
				$out = [];
				foreach ( $groups as $status => $total ) {
					$out[] = [ 'status' => $status, 'total' => $total ];
				}
				return $out;
			}

			// awaiting_tasks(): the statuses are read out of the statement rather than restated
			// here, so narrowing the real query fails the test instead of agreeing with it.
			if ( str_contains( $sql, 'status IN' ) ) {
				preg_match( '/status IN \(([^)]*)\)/', $sql, $m );
				preg_match_all( "/'([^']*)'/", $m[1] ?? '', $found );
				$statuses = $found[1] ?? [];

				$out = [];
				foreach ( $this->intakes as $row ) {
					if ( in_array( (string) $row['status'], $statuses, true ) && '' !== (string) $row['provider_task_id'] ) {
						$out[] = $row;
					}
				}
				return $out;
			}

			return array_values( $this->intakes );
		}

		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'igbz_ig_intake' ) && str_contains( $sql, 'COUNT(*)' ) ) {
			$sku = self::value_of( 'sku', $sql );
			if ( '' !== $sku ) {
				$count = 0;
				foreach ( $this->intakes as $row ) {
					if ( (string) $row['sku'] === $sku ) {
						++$count;
					}
				}
				return $count;
			}
			return count( $this->intakes );
		}

		if ( str_contains( $sql, 'igbz_ig_funnels' ) && str_contains( $sql, 'COUNT(*)' ) ) {
			$keyword = self::value_of( 'keyword', $sql );
			$count   = 0;
			foreach ( $this->funnels as $row ) {
				if ( (string) $row['keyword'] === $keyword ) {
					++$count;
				}
			}
			return $count;
		}

		return parent::get_var( $sql );
	}

	// -------------------------------------------------------------- writes

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( str_contains( $table, 'igbz_ig_intake' ) ) {
			// UNIQUE KEY sku.
			foreach ( $this->intakes as $row ) {
				if ( '' !== (string) ( $data['sku'] ?? '' ) && (string) $row['sku'] === (string) $data['sku'] ) {
					return false;
				}
			}

			$id                   = $this->next_id++;
			$this->insert_id      = $id;
			$this->intakes[ $id ] = array_merge( self::blank_intake(), $data, [ 'id' => $id ] );

			return 1;
		}

		if ( str_contains( $table, 'igbz_ig_funnels' ) ) {
			$id                   = $this->next_id++;
			$this->insert_id      = $id;
			$this->funnels[ $id ] = array_merge( [ 'id' => $id, 'hits' => 0, 'conversions' => 0 ], $data );

			return 1;
		}

		if ( str_contains( $table, 'igbz_ig_content' ) ) {
			$id                   = $this->next_id++;
			$this->insert_id      = $id;
			$this->content[ $id ] = array_merge( [ 'id' => $id ], $data );

			return 1;
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$id = (int) ( $where['id'] ?? 0 );

		if ( str_contains( $table, 'igbz_ig_intake' ) && isset( $this->intakes[ $id ] ) ) {
			$this->intakes[ $id ] = array_merge( $this->intakes[ $id ], $data );
			return 1;
		}
		if ( str_contains( $table, 'igbz_ig_funnels' ) && isset( $this->funnels[ $id ] ) ) {
			$this->funnels[ $id ] = array_merge( $this->funnels[ $id ], $data );
			return 1;
		}
		if ( str_contains( $table, 'igbz_ig_content' ) && isset( $this->content[ $id ] ) ) {
			$this->content[ $id ] = array_merge( $this->content[ $id ], $data );
			return 1;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		// fail(): status, error and the retry counter, all at once.
		if ( preg_match( "/^UPDATE \S*igbz_ig_intake\s+SET status = '([^']*)', last_error = '(.*)', retry_count = retry_count \+ 1, updated_at = '[^']*'\s+WHERE id = '?(\d+)'?$/s", $sql, $m ) ) {
			$id = (int) $m[3];
			if ( ! isset( $this->intakes[ $id ] ) ) {
				return 0;
			}
			$this->intakes[ $id ]['status']      = $m[1];
			$this->intakes[ $id ]['last_error']  = $m[2];
			$this->intakes[ $id ]['retry_count'] = (int) $this->intakes[ $id ]['retry_count'] + 1;

			return 1;
		}

		return parent::query( $sql );
	}

	/** @return array<string,mixed> */
	private static function blank_intake(): array {
		return [
			'tenant_id'            => 0,
			'account_id'           => 0,
			'user_id'              => 0,
			'status'               => ProductIntakeService::STATUS_UPLOADED,
			'sku'                  => '',
			'public_code'          => '',
			'source_attachment_id' => 0,
			'source_url'           => '',
			'clean_attachment_id'  => 0,
			'clean_url'            => '',
			'edited_attachment_id' => 0,
			'edited_url'           => '',
			'quality_score'        => 0,
			'quality_verdict'      => '',
			'quality_reasons'      => null,
			'attempt'              => 1,
			'raw_description'      => '',
			'input_mode'           => 'text',
			'transcript'           => '',
			'specs'                => null,
			'price'                => 0.0,
			'sale_price'           => 0.0,
			'stock'                => 0,
			'category_ids'         => '',
			'copy_json'            => null,
			'translations'         => null,
			'product_id'           => 0,
			'funnel_id'            => 0,
			'content_id'           => 0,
			'post_kind'            => '',
			'video_prompt'         => null,
			'video_url'            => '',
			'video_approved'       => 0,
			'provider_task_id'     => '',
			'provider_stage'       => '',
			'last_error'           => '',
			'retry_count'          => 0,
			'created_at'           => '2026-01-01 00:00:00',
			'updated_at'           => '2026-01-01 00:00:00',
		];
	}
}

/**
 * A ManusService that hands back scripted task ids instead of talking to the network.
 *
 * Subclassing is not possible — ManusService is final, and rightly so — so this implements the
 * handful of methods ProductIntakeService actually calls. Every dispatch records what it was asked
 * for, which is how the tests assert that the image really is attached to the grading task and
 * that the code really does reach the video prompt.
 */
final class FakeManus implements \IGBZ\Suite\Modules\Instagram\Contracts\IntakeAgentInterface {

	/** @var array<int,array{method:string,args:array<int,mixed>}> */
	public array $calls = [];

	/** Task ids handed out in order; '' simulates Manus refusing the job. */
	public array $task_ids = [];

	private int $issued = 0;

	public function __construct( private IntakeDb $db ) {}

	public function account( int $id ): ?array {
		return $this->db->accounts[ $id ] ?? null;
	}

	public function accounts( int $tenant_id = 0, bool $active_only = true ): array {
		unset( $active_only );
		return array_values(
			array_filter( $this->db->accounts, static fn ( array $a ): bool => (int) $a['tenant_id'] === $tenant_id )
		);
	}

	private function next( string $method, array $args ): string {
		$this->calls[] = [ 'method' => $method, 'args' => $args ];

		return (string) ( $this->task_ids[ $this->issued++ ] ?? 'task-' . $this->issued );
	}

	public function grade_photo( array $account, string $image_url, string $hint = '' ): string {
		return $this->next( 'grade_photo', [ $account, $image_url, $hint ] );
	}

	public function prepare_product_image( array $account, string $image_url, array $brief = [] ): string {
		return $this->next( 'prepare_product_image', [ $account, $image_url, $brief ] );
	}

	public function write_product_copy( array $account, array $brief, string $image_url = '' ): string {
		return $this->next( 'write_product_copy', [ $account, $brief, $image_url ] );
	}

	public function produce_product_video( array $account, array $brief, string $image_url = '' ): string {
		return $this->next( 'produce_product_video', [ $account, $brief, $image_url ] );
	}

	public function finish_product_post( array $account, array $brief, string $image_url = '' ): string {
		return $this->next( 'finish_product_post', [ $account, $brief, $image_url ] );
	}

	public function transcribe_audio( array $account, string $audio_url, string $language = '' ): string {
		return $this->next( 'transcribe_audio', [ $account, $audio_url, $language ] );
	}

	/** Reused verbatim from the real service: extracting JSON from a fenced block is shared logic. */
	public function parse_json_block( string $text ): array {
		if ( '' === $text ) {
			return [];
		}
		if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches ) ) {
			$text = $matches[1];
		} elseif ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
			$text = $matches[0];
		}
		$decoded = json_decode( $text, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/** @return array<int,array<string,mixed>> */
	public function call( string $method ): array {
		foreach ( $this->calls as $call ) {
			if ( $call['method'] === $method ) {
				return $call['args'];
			}
		}
		return [];
	}
}

final class ProductIntakeTest extends TestCase {

	private IntakeDb $db;

	private FakeManus $manus;

	private function boot(): ProductIntakeService {
		igbz_test_reset_settings();

		$this->db        = new IntakeDb();
		$GLOBALS['wpdb'] = $this->db;
		$this->db->add_account();

		$db          = new Db();
		$this->manus = new FakeManus( $this->db );

		// ProductIntakeService is typed against ManusService; the fake satisfies the same calls.
		return new ProductIntakeService( $db, $this->manus, new SkuGenerator( $db ), igbz()->get( 'logger' ) );
	}

	private function register( ProductIntakeService $intake ): int {
		return $intake->create(
			[
				'tenant_id'  => 1,
				'account_id' => 1,
				'user_id'    => 7,
				'source_url' => 'https://shop.test/wp-content/uploads/shot.jpg',
			]
		);
	}

	public function run(): void {
		$this->test_a_warehouse_sku_is_minted_and_is_legible();
		$this->test_a_warehouse_sku_is_never_reused();
		$this->test_the_customer_code_is_the_padded_product_id();
		$this->test_a_refused_photo_carries_reasons_and_stops();
		$this->test_a_low_score_overrules_a_generous_verdict();
		$this->test_a_refusal_always_has_at_least_one_reason();
		$this->test_an_accepted_photo_goes_straight_to_processing();
		$this->test_a_retry_reuses_the_row_and_counts_the_attempt();
		$this->test_an_unreadable_verdict_does_not_block_the_seller();
		$this->test_a_failed_image_step_falls_back_to_the_original_photo();
		$this->test_the_best_image_prefers_the_sellers_edit();
		$this->test_the_description_keeps_the_sellers_numbers();
		$this->test_a_transcript_is_added_to_typed_text_not_substituted();
		$this->test_the_listing_task_carries_the_photo_and_the_code();
		$this->test_the_video_prompt_carries_the_code();
		$this->test_a_video_task_without_a_video_fails_loudly();
		$this->test_a_post_without_a_caption_still_asks_for_the_code();
		$this->test_only_parked_rows_are_swept();
	}

	// ------------------------------------------------------------ the code

	private function test_a_warehouse_sku_is_minted_and_is_legible(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );

		$this->assert_true( $id > 0, 'a registration is created' );

		$sku = (string) $this->db->intake( $id )['sku'];

		$this->assert_true( (bool) preg_match( '/^IGBZ-[A-Z0-9]{4}$/', $sku ), "the SKU looks like IGBZ-4F2K (got {$sku})" );

		// The alphabet exists so a shopper can read the code off a photo and type it into a
		// comment. Characters that are indistinguishable in a condensed font defeat that.
		$body = substr( $sku, 5 );
		foreach ( [ '0', 'O', '1', 'I', 'L', '2', 'Z', '5', 'S', '8', 'B' ] as $ambiguous ) {
			$this->assert_false(
				str_contains( $body, $ambiguous ),
				"the code avoids the ambiguous character {$ambiguous}"
			);
		}
	}

	private function test_the_customer_code_is_the_padded_product_id(): void {
		$this->boot();
		$skus = new SkuGenerator( new Db() );

		// Digits only, because the shopper types this into an Instagram comment on a Persian
		// keyboard, where reaching the Latin letters of a SKU means switching layouts.
		$this->assert_same( '0047', $skus->public_code( 47 ), 'a short id is padded' );
		$this->assert_same( '0001', $skus->public_code( 1 ), 'the very first product still gets four digits' );

		// Padding is a floor, never a ceiling: truncating a long id would point the funnel at
		// somebody else's product.
		$this->assert_same( '123456', $skus->public_code( 123456 ), 'an id longer than the padding is left whole' );
		$this->assert_same( '', $skus->public_code( 0 ), 'no product means no code' );

		igbz()->settings()->set( 'intake.code_digits', 6 );
		$this->assert_same( '000047', $skus->public_code( 47 ), 'the shopkeeper can widen the code' );

		// Four is the floor. A one- or two-digit code gets typed under a post by accident and
		// would fire the funnel for someone who never asked for the link.
		igbz()->settings()->set( 'intake.code_digits', 1 );
		$this->assert_same( '0047', $skus->public_code( 47 ), 'a too-narrow setting is clamped back to four digits' );

		igbz_test_reset_settings();
	}

	private function test_a_warehouse_sku_is_never_reused(): void {
		$intake = $this->boot();

		$seen = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$id  = $this->register( $intake );
			$sku = (string) $this->db->intake( $id )['sku'];

			$this->assert_false( isset( $seen[ $sku ] ), 'each registration gets its own code' );
			$seen[ $sku ] = true;
		}
	}

	// ----------------------------------------------------- the photo gate

	private function test_a_refused_photo_carries_reasons_and_stops(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );

		$intake->start_grading( $id );
		$this->assert_same(
			ProductIntakeService::STATUS_GRADING,
			(string) $this->db->intake( $id )['status'],
			'the row waits while the photo is graded'
		);

		$intake->absorb_quality(
			$id,
			[
				'verdict' => 'reject',
				'score'   => 22,
				'reasons' => [ 'The bag is cut off at the bottom of the frame.', 'The background is a patterned rug.' ],
			]
		);

		$row = $this->db->intake( $id );

		$this->assert_same( ProductIntakeService::STATUS_REJECTED, (string) $row['status'], 'a refused photo stops the flow' );
		$this->assert_same( 22, (int) $row['quality_score'], 'the score is kept' );

		$quality = $intake->quality( $row );
		$this->assert_same( 2, count( (array) $quality['reasons'] ), 'both reasons reach the seller' );
		$this->assert_contains( 'cut off', (string) $quality['reasons'][0], 'the reason is the specific one the model gave' );

		// The expensive step must not have run.
		$this->assert_same( [], $this->manus->call( 'prepare_product_image' ), 'a refused photo is never processed' );
	}

	private function test_a_low_score_overrules_a_generous_verdict(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		$intake->start_grading( $id );

		igbz()->settings()->set( 'intake.quality_threshold', 70 );

		// The model said yes, but at a score the store has declared unacceptable. The setting is
		// the store's contract with itself and has to win, or it means nothing.
		$intake->absorb_quality( $id, [ 'verdict' => 'accept', 'score' => 51, 'reasons' => [ 'Slightly soft focus.' ] ] );

		$this->assert_same(
			ProductIntakeService::STATUS_REJECTED,
			(string) $this->db->intake( $id )['status'],
			'a score under the threshold refuses the photo whatever the verdict said'
		);
	}

	private function test_a_refusal_always_has_at_least_one_reason(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		$intake->start_grading( $id );

		// "Try again" with no reason is the single most useless thing this feature could say.
		$intake->absorb_quality( $id, [ 'verdict' => 'reject', 'score' => 10, 'reasons' => [] ] );

		$quality = $intake->quality( $this->db->intake( $id ) );

		$this->assert_true( count( (array) $quality['reasons'] ) > 0, 'a refusal never comes back empty-handed' );
	}

	private function test_an_accepted_photo_goes_straight_to_processing(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		$intake->start_grading( $id );

		$intake->absorb_quality(
			$id,
			[ 'verdict' => 'accept', 'score' => 88, 'reasons' => [], 'detected_product' => 'leather tote bag' ]
		);

		$row = $this->db->intake( $id );

		$this->assert_same(
			ProductIntakeService::STATUS_PROCESSING,
			(string) $row['status'],
			'an accepted photo moves on without another round trip to the app'
		);
		$this->assert_same(
			ProductIntakeService::STAGE_IMAGE,
			(string) $row['provider_stage'],
			'the row records which task it is waiting on'
		);

		// What the grader recognised is passed to the image task, so the cutout knows what it is
		// cutting out.
		$args = $this->manus->call( 'prepare_product_image' );
		$this->assert_same( 'leather tote bag', (string) $args[2]['product'], 'the detected product reaches the image task' );
	}

	private function test_a_retry_reuses_the_row_and_counts_the_attempt(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		$sku    = (string) $this->db->intake( $id )['sku'];

		$intake->start_grading( $id );
		$intake->absorb_quality( $id, [ 'verdict' => 'reject', 'score' => 30, 'reasons' => [ 'Too dark.' ] ] );

		// What the controller does on a retry.
		$intake->update(
			$id,
			[
				'source_url'      => 'https://shop.test/wp-content/uploads/shot-2.jpg',
				'attempt'         => (int) $this->db->intake( $id )['attempt'] + 1,
				'quality_verdict' => '',
				'status'          => ProductIntakeService::STATUS_UPLOADED,
			]
		);
		$intake->start_grading( $id );

		$row = $this->db->intake( $id );

		$this->assert_same( 2, (int) $row['attempt'], 'the attempt is counted' );
		$this->assert_same( $sku, (string) $row['sku'], 'the warehouse SKU survives a retry' );
		$this->assert_same( 1, count( $this->db->intakes ), 'a retry does not leave an abandoned row behind' );
	}

	private function test_an_unreadable_verdict_does_not_block_the_seller(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		$intake->start_grading( $id );

		// A grader that answers with nothing usable is a fault on our side. Refusing the seller's
		// photograph over it would be blaming them for our bug.
		$intake->absorb_quality( $id, [] );

		$this->assert_same(
			ProductIntakeService::STATUS_PROCESSING,
			(string) $this->db->intake( $id )['status'],
			'an unreadable verdict gives the photo the benefit of the doubt'
		);
	}

	// ------------------------------------------------------- the image

	private function test_a_failed_image_step_falls_back_to_the_original_photo(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		$intake->start_grading( $id );
		$intake->absorb_quality( $id, [ 'verdict' => 'accept', 'score' => 90, 'reasons' => [] ] );

		// The task finished but attached nothing usable. The seller's own photo already passed the
		// quality gate, so the listing is worse-looking, not wrong — and blocking it would be the
		// wrong trade.
		$intake->absorb_image( $id, [ [ 'file_name' => 'notes.json', 'url' => 'https://manus.test/notes.json' ] ] );

		$row = $this->db->intake( $id );

		$this->assert_same(
			ProductIntakeService::STATUS_READY_TO_EDIT,
			(string) $row['status'],
			'the registration continues even when the image step produced nothing'
		);
		$this->assert_contains( 'shot.jpg', (string) $row['clean_url'], 'it falls back to the original photograph' );
	}

	private function test_the_best_image_prefers_the_sellers_edit(): void {
		$intake = $this->boot();

		$this->assert_same(
			'https://shop.test/edited.jpg',
			$intake->best_image(
				[
					'source_url' => 'https://shop.test/shot.jpg',
					'clean_url'  => 'https://shop.test/clean.jpg',
					'edited_url' => 'https://shop.test/edited.jpg',
				]
			),
			'what the seller saved in the editor wins'
		);

		$this->assert_same(
			'https://shop.test/clean.jpg',
			$intake->best_image( [ 'source_url' => 'https://shop.test/shot.jpg', 'clean_url' => 'https://shop.test/clean.jpg', 'edited_url' => '' ] ),
			'the prepared image is used when the editor was skipped'
		);

		$this->assert_same(
			'https://shop.test/shot.jpg',
			$intake->best_image( [ 'source_url' => 'https://shop.test/shot.jpg', 'clean_url' => '', 'edited_url' => '' ] ),
			'the original is the last resort, never nothing'
		);
	}

	// -------------------------------------------------- the description

	private function test_the_description_keeps_the_sellers_numbers(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );

		$intake->save_description(
			$id,
			[
				'description'  => 'Hand-stitched tote, vegetable tanned.',
				'price'        => 2400000.0,
				'sale_price'   => 1900000.0,
				'stock'        => 3,
				'category_ids' => [ 14, 22 ],
			]
		);

		$row = $this->db->intake( $id );

		// Price, stock and category are the seller's to set. The assistant is told never to guess
		// one, so these have to survive untouched.
		$this->assert_same( 2400000.0, (float) $row['price'], 'the price is stored exactly as entered' );
		$this->assert_same( 1900000.0, (float) $row['sale_price'], 'the sale price is stored exactly as entered' );
		$this->assert_same( 3, (int) $row['stock'], 'the stock count is stored exactly as entered' );
		$this->assert_same( [ 14, 22 ], $intake->category_ids( $row ), 'the chosen categories are kept' );
	}

	private function test_a_transcript_is_added_to_typed_text_not_substituted(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );

		$intake->save_description( $id, [ 'description' => 'Size 38.', 'price' => 100.0 ] );
		$intake->await_transcript( $id, 'task-voice' );

		$this->assert_same(
			ProductIntakeService::STATUS_TRANSCRIBING,
			(string) $this->db->intake( $id )['status'],
			'the row parks while the voice note is transcribed'
		);

		$intake->absorb_transcript( $id, 'Black leather, brass fittings.' );

		$row = $this->db->intake( $id );

		// A seller who typed the size and dictated the rest must not lose either half.
		$this->assert_contains( 'Size 38.', (string) $row['raw_description'], 'what was typed survives' );
		$this->assert_contains( 'brass fittings', (string) $row['raw_description'], 'what was dictated is added' );
		$this->assert_same(
			ProductIntakeService::STATUS_DESCRIBING,
			(string) $row['status'],
			'the flow resumes once the transcript lands'
		);
	}

	// ------------------------------------------------------ the listing

	private function test_the_listing_task_carries_the_photo_and_the_code(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		igbz_test_add_term( 14, 'Bags' );

		$intake->update( $id, [ 'clean_url' => 'https://shop.test/clean.jpg' ] );
		$intake->save_description(
			$id,
			[ 'description' => 'Hand-stitched tote.', 'price' => 100.0, 'category_ids' => [ 14 ] ]
		);

		$intake->start_writing( $id, [ 'en' ] );

		$args = $this->manus->call( 'write_product_copy' );

		$this->assert_same( 'https://shop.test/clean.jpg', (string) $args[2], 'the prepared photo is attached to the listing task' );
		$this->assert_contains( 'Hand-stitched tote', (string) $args[1]['description'], "the seller's own words are passed through" );
		$this->assert_same( 'Bags', (string) $args[1]['category'], 'the chosen category is named, not guessed' );
		$this->assert_same( [ 'en' ], (array) $args[1]['languages'], 'the translation targets reach the task' );
		$this->assert_false( isset( $args[1]['sku'] ), 'the listing task is not told a code: the product does not exist yet' );

		$intake->absorb_copy(
			$id,
			[
				'title'        => 'Hand-stitched leather tote',
				'description'  => '<p>A tote.</p>',
				'specs'        => [ 'Material' => 'Leather' ],
				'translations' => [ 'en' => [ 'title' => 'Hand-stitched leather tote' ] ],
			]
		);

		$row = $this->db->intake( $id );

		$this->assert_same( 'Hand-stitched leather tote', (string) $intake->copy( $row )['title'], 'the written title is stored' );
		$this->assert_true( isset( $intake->translations( $row )['en'] ), 'the translation is stored separately from the original' );
		$this->assert_same( '', (string) $row['provider_task_id'], 'the finished task is released' );
	}

	// -------------------------------------------------------- the post

	private function test_the_video_prompt_carries_the_code(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );

		$intake->update(
			$id,
			[
				'clean_url'   => 'https://shop.test/clean.jpg',
				'copy_json'   => wp_json_encode( [ 'title' => 'Leather tote', 'short_description' => 'A tote.' ] ),
				// Stamped by the publisher in real life: the video step only ever runs on a row
				// that already has a product, and the code is that product's id.
				'public_code' => '0047',
			]
		);

		$intake->start_video( $id, 'Show it being packed for a trip.' );

		$args = $this->manus->call( 'produce_product_video' );
		$code = (string) $this->db->intake( $id )['public_code'];

		// The code has to be on the video: it is the only way a viewer can trigger the DM.
		$this->assert_same( $code, (string) $args[1]['code'], 'the customer code reaches the video task' );
		$this->assert_true( (bool) preg_match( '/^[0-9]{4,}$/', $code ), "the burned-in code is digits (got {$code})" );
		$this->assert_contains( 'packed for a trip', (string) $args[1]['prompt'], "the seller's brief is passed verbatim" );
		$this->assert_same( 'https://shop.test/clean.jpg', (string) $args[2], 'the product photo is the hero of the video' );

		$this->assert_same(
			ProductIntakeService::STATUS_PRODUCING_VIDEO,
			(string) $this->db->intake( $id )['status'],
			'the row waits for the video'
		);

		$intake->absorb_video( $id, [ [ 'file_name' => 'reel.mp4', 'url' => 'https://manus.test/reel.mp4' ] ] );

		$row = $this->db->intake( $id );
		$this->assert_same( ProductIntakeService::STATUS_VIDEO_REVIEW, (string) $row['status'], 'the seller is asked to approve it' );
		$this->assert_same( 0, (int) $row['video_approved'], 'a fresh video is not approved by default' );

		$this->assert_true( $intake->approve_video( $id ), 'the seller can approve it' );
		$this->assert_same( 1, (int) $this->db->intake( $id )['video_approved'], 'the approval is recorded' );
	}

	private function test_a_video_task_without_a_video_fails_loudly(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		$intake->update( $id, [ 'copy_json' => wp_json_encode( [ 'title' => 'Leather tote' ] ) ] );
		$intake->start_video( $id, 'Anything.' );

		// Unlike the image step there is no honest fallback here: a video post with no video is
		// not a degraded result, it is nothing.
		$intake->absorb_video( $id, [ [ 'file_name' => 'cover.jpg', 'url' => 'https://manus.test/cover.jpg' ] ] );

		$this->assert_same(
			ProductIntakeService::STATUS_FAILED,
			(string) $this->db->intake( $id )['status'],
			'a video task that produced no video fails rather than pretending'
		);
	}

	private function test_a_post_without_a_caption_still_asks_for_the_code(): void {
		$intake = $this->boot();
		$id     = $this->register( $intake );
		$intake->update( $id, [ 'copy_json' => wp_json_encode( [ 'title' => 'Leather tote' ] ), 'public_code' => '0047' ] );

		$code = (string) $this->db->intake( $id )['public_code'];

		// A caption is what tells people to comment the code. Without one the post cannot
		// convert, so a fallback that still carries the instruction beats an empty caption.
		$composed = $intake->absorb_post( $id, [ 'attachments' => [], 'text' => 'sorry, no json here', 'status' => 'stopped', 'stop_reason' => 'finish' ] );

		$this->assert_contains( $code, (string) $composed['caption'], 'the fallback caption still names the customer code' );
		$this->assert_true( '' !== trim( (string) $composed['caption'] ), 'a post is never composed with an empty caption' );
	}

	// ------------------------------------------------------- the sweep

	private function test_only_parked_rows_are_swept(): void {
		$intake = $this->boot();

		$waiting = $this->register( $intake );
		$intake->start_grading( $waiting );

		$idle = $this->register( $intake );
		$intake->update( $idle, [ 'status' => ProductIntakeService::STATUS_READY_TO_EDIT ] );

		$done = $this->register( $intake );
		$intake->update( $done, [ 'status' => ProductIntakeService::STATUS_PUBLISHED, 'provider_task_id' => 'old' ] );

		$swept = array_map( static fn ( array $row ): int => (int) $row['id'], $intake->awaiting_tasks() );

		$this->assert_true( in_array( $waiting, $swept, true ), 'a row waiting on a task is swept' );
		$this->assert_false( in_array( $idle, $swept, true ), 'a row waiting on the seller is left alone' );
		$this->assert_false( in_array( $done, $swept, true ), 'a finished row is never swept' );
	}
}
