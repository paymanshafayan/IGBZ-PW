<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Steps 7 and 8: turn an approved intake row into a real WooCommerce product, its comment-to-DM
 * funnel and its Instagram content row.
 *
 * This is the piece that makes the promise "you never open the WooCommerce admin" true. It is also
 * where the loop that the port had left open finally closes: until now nothing ever passed a
 * product_id and a funnel_id into the content pipeline, so the keyword-injection branch in
 * ManusService::generate() could never fire. Everything created here is stitched together —
 * product ⇄ funnel ⇄ content ⇄ intake — so a comment carrying the code resolves to the right
 * product page without anybody typing an id into a form.
 *
 * The three writes are ordered so a crash is survivable: the product first (the thing of value),
 * then the funnel (recoverable, and idempotent on the keyword), then the content row (cheap to
 * recreate). A half-finished registration leaves a real product in the catalogue rather than
 * nothing at all.
 */
final class ProductPublisher {

	/** Product meta linking a listing back to the registration that produced it. */
	public const META_INTAKE = '_igbz_intake_id';
	public const META_SKU    = '_igbz_product_code';

	public function __construct(
		private ProductIntakeService $intake,
		private FunnelService $funnels,
		private ManusService $manus,
		private TranslationBridge $translations,
		private ManyChatBridge $manychat,
		private Logger $logger
	) {}

	/**
	 * Create the product, the funnel and the queued post.
	 *
	 * @param array<string,mixed> $row An intake row that already carries copy_json.
	 * @return array{ok:bool,product_id:int,funnel_id:int,content_id:int,error:string}
	 */
	public function publish( array $row ): array {
		$intake_id = (int) $row['id'];
		$copy      = $this->intake->copy( $row );

		if ( ! $copy || '' === trim( (string) ( $copy['title'] ?? '' ) ) ) {
			return $this->error( $intake_id, __( 'There is no listing text to create the product from.', 'igbz-suite' ) );
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $this->error( $intake_id, __( 'WooCommerce is not active, so no product can be created.', 'igbz-suite' ) );
		}

		$product_id = $this->create_product( $row, $copy );
		if ( 0 === $product_id ) {
			return $this->error( $intake_id, __( 'The product could not be created.', 'igbz-suite' ) );
		}

		// Translations are best-effort on purpose: a store whose Polylang setup is half-configured
		// should still end up with a product, and the copy is kept in meta either way.
		$translated = [];
		try {
			$translated = $this->translations->apply(
				$product_id,
				$this->intake->translations( $row ),
				[ 'intake_id' => $intake_id ]
			);
		} catch ( \Throwable $e ) {
			$this->logger->warning(
				'intake',
				'Translations could not be published',
				[ 'intake_id' => $intake_id, 'product_id' => $product_id, 'error' => $e->getMessage() ]
			);
		}

		$funnel_id = $this->create_funnel( $row, $product_id, $copy );

		$this->intake->mark_product_created( $intake_id, $product_id, $funnel_id );

		$this->logger->info(
			'intake',
			'Product registered from the app',
			[
				'intake_id'    => $intake_id,
				'product_id'   => $product_id,
				'funnel_id'    => $funnel_id,
				'sku'          => (string) $row['sku'],
				'translations' => count( $translated ),
			]
		);

		return [
			'ok'         => true,
			'product_id' => $product_id,
			'funnel_id'  => $funnel_id,
			'content_id' => 0,
			'error'      => '',
		];
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $copy
	 */
	private function create_product( array $row, array $copy ): int {
		$product = new \WC_Product_Simple();

		$product->set_name( mb_substr( (string) $copy['title'], 0, 200 ) );
		$product->set_description( (string) ( $copy['description'] ?? '' ) );
		$product->set_short_description( (string) ( $copy['short_description'] ?? '' ) );
		$product->set_sku( (string) $row['sku'] );
		$product->set_status( igbz()->settings()->string( 'intake.product_status', 'publish' ) );
		$product->set_catalog_visibility( 'visible' );

		// Commerce fields come from the seller's form, never from the model.
		$price = (float) $row['price'];
		$product->set_regular_price( (string) $price );

		$sale = (float) $row['sale_price'];
		if ( $sale > 0 && $sale < $price ) {
			$product->set_sale_price( (string) $sale );
		}

		$stock = (int) $row['stock'];
		if ( $stock > 0 ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( 'instock' );
		} else {
			// Zero is genuinely ambiguous — "none left" and "I did not count" look identical in a
			// form — so stock management stays off and the item is sellable, which is what a shop
			// photographing something on the counter means.
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
		}

		$categories = $this->intake->category_ids( $row );
		if ( $categories ) {
			$product->set_category_ids( $categories );
		}

		$attachment_id = $this->intake->best_attachment_id( $row );
		if ( $attachment_id > 0 ) {
			$product->set_image_id( $attachment_id );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			return 0;
		}

		$product_id = (int) $product_id;

		if ( $attachment_id > 0 && ! empty( $copy['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $copy['alt_text'] ) );
		}

		$this->apply_attributes( $product_id, (array) ( $copy['specs'] ?? [] ) );
		$this->apply_tags( $product_id, (array) ( $copy['tags'] ?? [] ) );

		update_post_meta( $product_id, self::META_INTAKE, (int) $row['id'] );
		update_post_meta( $product_id, self::META_SKU, (string) $row['sku'] );

		// Tenancy is carried in meta the same way the rest of the suite scopes WooCommerce
		// objects, so a tenant's catalogue stays theirs.
		$tenant_id = (int) $row['tenant_id'];
		if ( $tenant_id > 0 ) {
			update_post_meta( $product_id, '_igbz_tenant_id', $tenant_id );
		}

		foreach ( [ 'seo_title' => '_igbz_seo_title', 'seo_description' => '_igbz_seo_description' ] as $key => $meta ) {
			if ( ! empty( $copy[ $key ] ) ) {
				update_post_meta( $product_id, $meta, sanitize_text_field( (string) $copy[ $key ] ) );
			}
		}

		do_action( 'igbz_intake_product_saved', $product_id, $row, $copy );

		return $product_id;
	}

	/**
	 * Turn the specs object into custom product attributes.
	 *
	 * Custom (non-taxonomy) attributes deliberately: the model returns free text like "leather" or
	 * "38 cm", and forcing those into global taxonomies would litter the store with hundreds of
	 * one-use terms that the shopkeeper then has to clean up by hand.
	 *
	 * @param array<string,mixed> $specs
	 */
	private function apply_attributes( int $product_id, array $specs ): void {
		if ( ! $specs ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$attributes = [];
		$position   = 0;

		foreach ( $specs as $name => $value ) {
			$name  = sanitize_text_field( (string) $name );
			$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$value = sanitize_text_field( $value );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( [ $value ] );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( false );

			$attributes[] = $attribute;
		}

		if ( $attributes ) {
			$product->set_attributes( $attributes );
			$product->save();
		}
	}

	/** @param array<int,mixed> $tags */
	private function apply_tags( int $product_id, array $tags ): void {
		$clean = [];
		foreach ( $tags as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( '' !== $tag ) {
				$clean[] = $tag;
			}
		}

		if ( $clean ) {
			wp_set_object_terms( $product_id, array_slice( $clean, 0, 15 ), 'product_tag' );
		}
	}

	/**
	 * The comment-to-DM funnel for this product.
	 *
	 * The keyword is the product code, which is the whole point: the caption tells people to
	 * comment it, the image shows it, and this row is what turns that comment into a direct
	 * message carrying the purchase link.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $copy
	 */
	private function create_funnel( array $row, int $product_id, array $copy ): int {
		$sku     = (string) $row['sku'];
		$keyword = mb_strtolower( $sku );

		$reply = igbz()->settings()->string( 'intake.funnel_reply', '' );
		if ( '' === trim( $reply ) ) {
			$reply = sprintf(
				/* translators: 1: product name, 2: link placeholder */
				__( "Here is %1\$s 🛍\nBuy it here: %2\$s", 'igbz-suite' ),
				(string) ( $copy['title'] ?? $sku ),
				'{link}'
			);
		}

		$funnel_id = $this->funnels->save(
			[
				'tenant_id'      => (int) $row['tenant_id'],
				'account_id'     => (int) $row['account_id'],
				'name'           => sprintf( /* translators: %s: product code */ __( 'Product %s', 'igbz-suite' ), $sku ),
				'keyword'        => $keyword,
				// Exact matching, because the code is a deliberate token: "contains" would let
				// a comment that merely quotes the caption trigger a delivery.
				'match_mode'     => FunnelService::MATCH_EXACT,
				'reply_text'     => $reply,
				'target_type'    => FunnelService::TARGET_PRODUCT,
				'product_id'     => $product_id,
				'per_user_limit' => max( 1, igbz()->settings()->int( 'intake.funnel_per_user_limit', 1 ) ),
				'is_active'      => 1,
			]
		);

		if ( 0 === $funnel_id ) {
			// Not fatal: the product exists and can be sold. The funnel is what automates the DM,
			// and the store owner can create it by hand, so this is a warning rather than a
			// failure that would strand a perfectly good listing.
			$this->logger->warning(
				'intake',
				'The product was created but its comment funnel could not be',
				[ 'intake_id' => (int) $row['id'], 'product_id' => $product_id, 'keyword' => $keyword ]
			);
		}

		return $funnel_id;
	}

	/**
	 * Queue the finished post for the Instagram pipeline. Step 12.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $composed Caption, hashtags and media produced at step 11.
	 */
	public function queue_post( array $row, array $composed ): int {
		$account = $this->intake->account_for( $row );
		if ( ! $account ) {
			return 0;
		}

		$copy = $this->intake->copy( $row );
		$kind = ProductIntakeService::KIND_VIDEO === (string) $row['post_kind']
			? ManusService::KIND_REEL
			: ManusService::KIND_POST;

		$media = [];
		if ( ProductIntakeService::KIND_VIDEO === (string) $row['post_kind'] && '' !== (string) $row['video_url'] ) {
			$media[] = [ 'url' => (string) $row['video_url'], 'name' => 'reel.mp4', 'type' => 'video' ];
		}
		foreach ( (array) ( $composed['media'] ?? [] ) as $item ) {
			$url = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : (string) $item;
			if ( '' !== $url ) {
				$media[] = [
					'url'  => $url,
					'name' => is_array( $item ) ? (string) ( $item['name'] ?? '' ) : '',
					'type' => 'image',
				];
			}
		}
		if ( ! $media ) {
			$media[] = [ 'url' => $this->intake->best_image( $row ), 'name' => 'product.jpg', 'type' => 'image' ];
		}

		// save_content() is used rather than the scheduler's queue() because the creative work is
		// already done: this row goes straight to `ready` and only needs scheduling, whereas
		// queue() would put it back at the start of the generation pipeline.
		$content_id = $this->manus->save_content(
			[
				'tenant_id'  => (int) $row['tenant_id'],
				'account_id' => (int) $account['id'],
				'kind'       => $kind,
				'title'      => (string) ( $copy['title'] ?? $row['sku'] ),
				'brief'      => [
					'subject'    => (string) ( $copy['title'] ?? '' ),
					'sku'        => (string) $row['sku'],
					'intake_id'  => (int) $row['id'],
					'keyword'    => mb_strtolower( (string) $row['sku'] ),
					'product_id' => (int) $row['product_id'],
					'funnel_id'  => (int) $row['funnel_id'],
				],
				'caption'    => (string) ( $composed['caption'] ?? '' ),
				'hashtags'   => array_map( 'strval', (array) ( $composed['hashtags'] ?? [] ) ),
				'media'      => $media,
				// The two ids the pipeline never used to receive. With them set, the caption
				// writer knows the keyword and the funnel knows the product.
				'product_id' => (int) $row['product_id'],
				'funnel_id'  => (int) $row['funnel_id'],
				'status'     => ManusService::STATUS_READY,
			]
		);

		if ( $content_id > 0 ) {
			$this->intake->mark_scheduled( (int) $row['id'], $content_id );
		}

		return $content_id;
	}

	/**
	 * Steps 12 and 13: give the post to Manus and the purchase link to ManyChat.
	 *
	 * The two are done together because they are two halves of one promise. The caption tells
	 * people to comment the code; if the link never reaches ManyChat, every one of those comments
	 * gets nothing back. Publishing the post first and wiring the link afterwards would leave a
	 * window in which the promise is live and unfulfillable, so ManyChat is primed first.
	 *
	 * @param array<string,mixed> $row
	 * @return array{ok:bool,scheduled:bool,error:string}
	 */
	public function hand_off( array $row, string $scheduled_for = '' ): array {
		$intake_id  = (int) $row['id'];
		$content_id = (int) $row['content_id'];

		if ( $content_id <= 0 ) {
			return [ 'ok' => false, 'scheduled' => false, 'error' => 'not_composed' ];
		}

		$account = $this->intake->account_for( $row );
		if ( ! $account ) {
			return [ 'ok' => false, 'scheduled' => false, 'error' => 'no_account' ];
		}

		// Step 13 first: prime ManyChat before anybody can comment.
		$funnel = $this->funnels->get( (int) $row['funnel_id'] );
		$link   = $funnel ? $this->funnels->resolve_link( $funnel ) : (string) get_permalink( (int) $row['product_id'] );

		$copy = $this->intake->copy( $row );
		$this->manychat->register_product( $account, (string) $row['sku'], $link, (string) ( $copy['title'] ?? '' ) );

		// Step 12: hand the finished post to Manus.
		$content = $this->manus->content( $content_id );
		if ( ! $content ) {
			return [ 'ok' => false, 'scheduled' => false, 'error' => 'content_missing' ];
		}

		$timestamp = '' !== $scheduled_for ? (int) strtotime( $scheduled_for . ' UTC' ) : 0;

		$result = $timestamp > time()
			? $this->manus->schedule( $content, $timestamp )
			: $this->manus->publish( $content );

		if ( ! $result->success ) {
			$this->logger->error(
				'intake',
				'The finished post was not accepted by Manus',
				[ 'intake_id' => $intake_id, 'content_id' => $content_id, 'error' => $result->error ]
			);

			return [ 'ok' => false, 'scheduled' => false, 'error' => $result->error ];
		}

		$scheduled = $timestamp > time();

		$this->manus->save_content(
			[
				'tenant_id'     => (int) $content['tenant_id'],
				'account_id'    => (int) $content['account_id'],
				'kind'          => (string) $content['kind'],
				'title'         => (string) $content['title'],
				'brief'         => json_decode( (string) $content['brief'], true ) ?: [],
				'caption'       => (string) $content['caption'],
				'hashtags'      => json_decode( (string) $content['hashtags'], true ) ?: [],
				'media'         => json_decode( (string) $content['media'], true ) ?: [],
				'product_id'    => (int) $content['product_id'],
				'funnel_id'     => (int) $content['funnel_id'],
				'status'        => $scheduled ? ManusService::STATUS_SCHEDULED : ManusService::STATUS_PUBLISHING,
				'scheduled_for' => $scheduled ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null,
			],
			$content_id
		);

		$this->intake->mark_scheduled( $intake_id, $content_id );

		$this->logger->info(
			'intake',
			$scheduled ? 'Post scheduled through Manus' : 'Post handed to Manus for publishing',
			[ 'intake_id' => $intake_id, 'content_id' => $content_id, 'sku' => (string) $row['sku'] ]
		);

		return [ 'ok' => true, 'scheduled' => $scheduled, 'error' => '' ];
	}

	/** @return array{ok:bool,product_id:int,funnel_id:int,content_id:int,error:string} */
	private function error( int $intake_id, string $message ): array {
		$this->intake->fail( $intake_id, $message );

		return [ 'ok' => false, 'product_id' => 0, 'funnel_id' => 0, 'content_id' => 0, 'error' => $message ];
	}
}
