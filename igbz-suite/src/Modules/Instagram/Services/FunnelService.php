<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The "comment the word X and I'll DM you the link" engine.
 *
 * A funnel matches a keyword on an Instagram comment (optionally scoped to a single post) and
 * responds through ManyChat: trigger a Flow, tag the subscriber, deliver a link / coupon /
 * product page, and optionally credit the customer's wallet.
 *
 * Every hit is recorded in ig_funnel_hits with a UNIQUE (funnel_id, comment_id) key, so ManyChat
 * retries can never double-deliver.
 */
final class FunnelService {

	public const MATCH_EXACT    = 'exact';
	public const MATCH_CONTAINS = 'contains';
	public const MATCH_STARTS   = 'starts';
	public const MATCH_REGEX    = 'regex';

	public const TARGET_URL     = 'url';
	public const TARGET_PRODUCT = 'product';
	public const TARGET_COUPON  = 'coupon';
	public const TARGET_FLOW    = 'flow';

	public function __construct(
		private Db $db,
		private ManyChatClient $client,
		private SubscriberService $subscribers,
		private WalletService $wallet,
		private Logger $logger
	) {}

	// --------------------------------------------------------------- CRUD

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE id = %d', $id );
	}

	/**
	 * @param array{tenant_id?:int,account_id?:int,active_only?:bool} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];
		foreach ( [ 'tenant_id', 'account_id' ] as $column ) {
			if ( isset( $args[ $column ] ) ) {
				$where[]  = $column . ' = %d';
				$params[] = (int) $args[ $column ];
			}
		}
		if ( ! empty( $args['active_only'] ) ) {
			$where[] = 'is_active = 1';
		}

		$sql = 'SELECT * FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC';

		return $params ? $this->db->results( $sql, ...$params ) : $this->db->results( $sql );
	}

	/** @param array<string,mixed> $data */
	public function save( array $data, int $id = 0 ): int {
		$now     = current_time( 'mysql', true );
		$payload = [
			'tenant_id'           => (int) ( $data['tenant_id'] ?? 0 ),
			'account_id'          => (int) ( $data['account_id'] ?? 0 ),
			'name'                => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'keyword'             => $this->canonical( (string) ( $data['keyword'] ?? '' ) ),
			'match_mode'          => in_array( $data['match_mode'] ?? '', [ self::MATCH_EXACT, self::MATCH_CONTAINS, self::MATCH_STARTS, self::MATCH_REGEX ], true )
				? (string) $data['match_mode']
				: self::MATCH_CONTAINS,
			'post_id'             => sanitize_text_field( (string) ( $data['post_id'] ?? '' ) ),
			'reply_text'          => (string) ( $data['reply_text'] ?? '' ),
			'target_type'         => in_array( $data['target_type'] ?? '', [ self::TARGET_URL, self::TARGET_PRODUCT, self::TARGET_COUPON, self::TARGET_FLOW ], true )
				? (string) $data['target_type']
				: self::TARGET_URL,
			'target_url'          => esc_url_raw( (string) ( $data['target_url'] ?? '' ) ),
			'product_id'          => (int) ( $data['product_id'] ?? 0 ),
			'coupon_code'         => sanitize_text_field( (string) ( $data['coupon_code'] ?? '' ) ),
			'manychat_flow_ns'    => sanitize_text_field( (string) ( $data['manychat_flow_ns'] ?? '' ) ),
			'manychat_tag'        => sanitize_text_field( (string) ( $data['manychat_tag'] ?? '' ) ),
			'grant_wallet_credit' => (float) ( $data['grant_wallet_credit'] ?? 0 ),
			'per_user_limit'      => (int) ( $data['per_user_limit'] ?? 1 ),
			'total_limit'         => (int) ( $data['total_limit'] ?? 0 ),
			'starts_at'           => ! empty( $data['starts_at'] ) ? (string) $data['starts_at'] : null,
			'ends_at'             => ! empty( $data['ends_at'] ) ? (string) $data['ends_at'] : null,
			'is_active'           => empty( $data['is_active'] ) ? 0 : 1,
			'updated_at'          => $now,
		];

		if ( $id > 0 ) {
			$this->db->update( 'ig_funnels', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = $now;
		return $this->db->insert( 'ig_funnels', $payload );
	}

	public function delete( int $id ): bool {
		return $this->db->delete( 'ig_funnels', [ 'id' => $id ] ) > 0;
	}

	// ------------------------------------------------------------ matching

	private function canonical( string $value ): string {
		$value = preg_replace( '/[\x{200C}\x{200F}\x{200E}]/u', '', $value ) ?? $value;
		$value = str_replace(
			[ 'ي', 'ك', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ],
			[ 'ی', 'ک', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ],
			$value
		);
		return trim( mb_strtolower( $value ) );
	}

	/**
	 * Find the funnel a comment triggers. Post-scoped funnels win over global ones.
	 *
	 * @return array<string,mixed>|null
	 */
	public function match( string $comment, string $post_id = '', int $tenant_id = 0, int $account_id = 0 ): ?array {
		$needle = $this->canonical( $comment );
		if ( '' === $needle ) {
			return null;
		}

		$now  = current_time( 'mysql', true );
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_funnels' ) . '
			 WHERE is_active = 1
			   AND (starts_at IS NULL OR starts_at <= %s)
			   AND (ends_at IS NULL OR ends_at >= %s)
			   AND (post_id = %s OR post_id = %s)
			   AND (tenant_id = %d OR %d = 0)
			   AND (account_id = %d OR account_id = 0)
			 ORDER BY (post_id <> %s) ASC, id DESC',
			$now,
			$now,
			$post_id,
			'',
			$tenant_id,
			$tenant_id,
			$account_id,
			''
		);

		foreach ( $rows as $row ) {
			if ( $this->matches( $needle, $row ) && ! $this->exhausted( $row ) ) {
				return $row;
			}
		}

		return null;
	}

	/** @param array<string,mixed> $funnel */
	private function matches( string $needle, array $funnel ): bool {
		$keyword = $this->canonical( (string) $funnel['keyword'] );
		if ( '' === $keyword ) {
			return false;
		}

		return match ( (string) $funnel['match_mode'] ) {
			self::MATCH_EXACT  => $needle === $keyword,
			self::MATCH_STARTS => str_starts_with( $needle, $keyword ),
			self::MATCH_REGEX  => (bool) @preg_match( '/' . str_replace( '/', '\/', $keyword ) . '/iu', $needle ),
			default            => str_contains( $needle, $keyword ),
		};
	}

	/** @param array<string,mixed> $funnel */
	private function exhausted( array $funnel ): bool {
		$total = (int) $funnel['total_limit'];
		return $total > 0 && (int) $funnel['conversions'] >= $total;
	}

	// ------------------------------------------------------------ delivery

	/**
	 * Handle one inbound comment event. Idempotent on (funnel_id, comment_id).
	 *
	 * @param array{comment_text?:string,comment_id?:string,post_id?:string,subscriber_id?:string,ig_username?:string,ig_user_id?:string,first_name?:string,last_name?:string,timestamp?:int,event?:string,tenant_id?:int,account_id?:int} $event
	 * @return array{matched:bool,duplicate:bool,funnel_id:int,hit_id:int,message:string,payload:array<string,mixed>}
	 */
	public function handle_event( array $event ): array {
		$comment    = (string) ( $event['comment_text'] ?? '' );
		$comment_id = (string) ( $event['comment_id'] ?? '' );
		$post_id    = (string) ( $event['post_id'] ?? '' );
		$tenant_id  = (int) ( $event['tenant_id'] ?? 0 );
		$account_id = (int) ( $event['account_id'] ?? 0 );

		$funnel = $this->match( $comment, $post_id, $tenant_id, $account_id );
		if ( ! $funnel ) {
			return $this->result( false, false, 0, 0, __( 'No funnel matched this comment.', 'igbz-suite' ) );
		}

		$subscriber_id = $this->subscribers->upsert(
			[
				'manychat_subscriber_id' => (string) ( $event['subscriber_id'] ?? '' ),
				'ig_username'            => (string) ( $event['ig_username'] ?? '' ),
				'ig_user_id'             => (string) ( $event['ig_user_id'] ?? '' ),
				'first_name'             => (string) ( $event['first_name'] ?? '' ),
				'last_name'              => (string) ( $event['last_name'] ?? '' ),
			],
			(int) $funnel['tenant_id']
		);

		$hit_id = $this->record_hit( $funnel, $event, $subscriber_id );

		if ( 0 === $hit_id ) {
			return $this->result( true, true, (int) $funnel['id'], 0, __( 'This comment has already been processed.', 'igbz-suite' ) );
		}

		$this->db->query(
			'UPDATE ' . $this->db->table( 'ig_funnels' ) . ' SET hits = hits + 1 WHERE id = %d',
			(int) $funnel['id']
		);

		if ( $this->over_user_limit( $funnel, (string) ( $event['subscriber_id'] ?? '' ) ) ) {
			$this->db->update( 'ig_funnel_hits', [ 'delivery_error' => 'per_user_limit' ], [ 'id' => $hit_id ] );
			return $this->result( true, false, (int) $funnel['id'], $hit_id, __( 'This subscriber has already claimed this offer.', 'igbz-suite' ) );
		}

		$payload = $this->deliver( $funnel, $hit_id, (string) ( $event['subscriber_id'] ?? '' ), $subscriber_id );

		do_action( 'igbz_ig_funnel_hit', (int) $funnel['id'], $hit_id, $event );

		return $this->result( true, false, (int) $funnel['id'], $hit_id, __( 'Delivered.', 'igbz-suite' ), $payload );
	}

	/**
	 * Insert the dedupe row for one inbound event. Returns 0 when the comment was already handled
	 * (UNIQUE funnel_id + comment_id).
	 *
	 * @param array<string,mixed> $funnel
	 * @param array<string,mixed> $event
	 */
	public function record_hit( array $funnel, array $event, int $subscriber_row_id = 0 ): int {
		$comment    = (string) ( $event['comment_text'] ?? '' );
		$post_id    = (string) ( $event['post_id'] ?? '' );
		$comment_id = (string) ( $event['comment_id'] ?? '' );

		if ( '' === $comment_id ) {
			$comment_id = 'synthetic:' . md5( (string) ( $event['subscriber_id'] ?? '' ) . '|' . $post_id . '|' . $comment );
		}

		return $this->db->insert(
			'ig_funnel_hits',
			[
				'tenant_id'              => (int) $funnel['tenant_id'],
				'funnel_id'              => (int) $funnel['id'],
				'subscriber_id'          => $subscriber_row_id,
				'manychat_subscriber_id' => (string) ( $event['subscriber_id'] ?? '' ),
				'ig_username'            => ltrim( (string) ( $event['ig_username'] ?? '' ), '@' ),
				'comment_id'             => $comment_id,
				'comment_text'           => mb_substr( $comment, 0, 2000 ),
				'post_id'                => $post_id,
				'event'                  => (string) ( $event['event'] ?? 'comment' ),
				'occurred_at'            => ! empty( $event['timestamp'] )
					? gmdate( 'Y-m-d H:i:s', (int) $event['timestamp'] )
					: current_time( 'mysql', true ),
				'created_at'             => current_time( 'mysql', true ),
			]
		);
	}

	/**
	 * Fast path for the ManyChat External Request action, which times out after roughly ten
	 * seconds. Everything that needs an outbound HTTP call (sendFlow, tagging, profile sync,
	 * wallet credit) is pushed to a background event; the response only contains locally computed
	 * data so ManyChat can render the DM itself.
	 *
	 * @param array<string,mixed> $event
	 * @return array{matched:bool,duplicate:bool,funnel:array<string,mixed>|null,hit_id:int,link:string,coupon:string,text:string}
	 */
	public function handle_event_async( array $event ): array {
		$funnel = $this->match(
			(string) ( $event['comment_text'] ?? '' ),
			(string) ( $event['post_id'] ?? '' ),
			(int) ( $event['tenant_id'] ?? 0 ),
			(int) ( $event['account_id'] ?? 0 )
		);

		if ( ! $funnel ) {
			return [
				'matched'   => false,
				'duplicate' => false,
				'funnel'    => null,
				'hit_id'    => 0,
				'link'      => '',
				'coupon'    => '',
				'text'      => '',
			];
		}

		$hit_id = $this->record_hit( $funnel, $event );
		if ( 0 === $hit_id ) {
			return [
				'matched'   => true,
				'duplicate' => true,
				'funnel'    => $funnel,
				'hit_id'    => 0,
				'link'      => $this->resolve_link( $funnel ),
				'coupon'    => '',
				'text'      => '',
			];
		}

		$this->db->query(
			'UPDATE ' . $this->db->table( 'ig_funnels' ) . ' SET hits = hits + 1, conversions = conversions + 1 WHERE id = %d',
			(int) $funnel['id']
		);

		$link   = $this->resolve_link( $funnel );
		$coupon = self::TARGET_COUPON === (string) $funnel['target_type']
			? $this->issue_coupon( $funnel, (string) ( $event['subscriber_id'] ?? '' ) )
			: '';

		if ( '' !== $coupon ) {
			$link = add_query_arg( 'coupon', rawurlencode( $coupon ), $link );
		}

		$text = strtr(
			(string) $funnel['reply_text'],
			[ '{link}' => $link, '{coupon}' => $coupon, '{keyword}' => (string) $funnel['keyword'] ]
		);
		if ( '' === trim( $text ) ) {
			$text = sprintf( /* translators: %s: link */ __( 'Here you go: %s', 'igbz-suite' ), $link );
		}

		$this->db->update(
			'ig_funnel_hits',
			[ 'delivered' => 1, 'coupon_issued' => $coupon ],
			[ 'id' => $hit_id ]
		);

		if ( ! wp_next_scheduled( 'igbz_ig_funnel_followup', [ $hit_id ] ) ) {
			wp_schedule_single_event( time() + 5, 'igbz_ig_funnel_followup', [ $hit_id ] );
		}

		do_action( 'igbz_ig_funnel_hit', (int) $funnel['id'], $hit_id, $event );

		return [
			'matched'   => true,
			'duplicate' => false,
			'funnel'    => $funnel,
			'hit_id'    => $hit_id,
			'link'      => $link,
			'coupon'    => $coupon,
			'text'      => $text,
		];
	}

	/**
	 * Background half of handle_event_async(): profile sync, tagging and wallet credit.
	 */
	public function followup( int $hit_id ): void {
		$hit = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_funnel_hits' ) . ' WHERE id = %d', $hit_id );
		if ( ! $hit ) {
			return;
		}
		$funnel = $this->get( (int) $hit['funnel_id'] );
		if ( ! $funnel ) {
			return;
		}

		$manychat_id = (string) $hit['manychat_subscriber_id'];
		if ( '' === $manychat_id ) {
			return;
		}

		$subscriber = $this->subscribers->sync_from_api( $manychat_id, (int) $funnel['tenant_id'] );
		$row_id     = (int) ( $subscriber['id'] ?? 0 );

		if ( $row_id > 0 && (int) $hit['subscriber_id'] !== $row_id ) {
			$this->db->update( 'ig_funnel_hits', [ 'subscriber_id' => $row_id ], [ 'id' => $hit_id ] );
		}

		$this->client->set_custom_fields(
			$manychat_id,
			[
				'igbz_funnel' => (string) $funnel['name'],
				'igbz_coupon' => (string) $hit['coupon_issued'],
			]
		);

		if ( '' !== (string) $funnel['manychat_tag'] ) {
			$this->client->add_tag_by_name( $manychat_id, (string) $funnel['manychat_tag'] );
		}

		if ( '' !== (string) $funnel['manychat_flow_ns'] ) {
			$this->client->send_flow( $manychat_id, (string) $funnel['manychat_flow_ns'] );
		}

		$this->grant_wallet_credit( $funnel, $row_id, $hit_id );

		do_action( 'igbz_ig_funnel_followup_done', (int) $funnel['id'], $hit_id );
	}

	/** @param array<string,mixed> $funnel */
	private function over_user_limit( array $funnel, string $subscriber_id ): bool {
		$limit = (int) $funnel['per_user_limit'];
		if ( $limit <= 0 || '' === $subscriber_id ) {
			return false;
		}
		$count = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_funnel_hits' ) . '
			 WHERE funnel_id = %d AND manychat_subscriber_id = %s AND delivered = 1',
			(int) $funnel['id'],
			$subscriber_id
		);
		return $count >= $limit;
	}

	/**
	 * Resolve the funnel target and push it to the subscriber through ManyChat.
	 *
	 * @param array<string,mixed> $funnel
	 * @return array<string,mixed> fields that can be mapped back into ManyChat custom fields
	 */
	public function deliver( array $funnel, int $hit_id, string $manychat_subscriber_id, int $subscriber_row_id = 0 ): array {
		$link   = $this->resolve_link( $funnel );
		$coupon = '';

		if ( self::TARGET_COUPON === (string) $funnel['target_type'] ) {
			$coupon = $this->issue_coupon( $funnel, $manychat_subscriber_id );
			if ( '' !== $coupon ) {
				$link = add_query_arg( 'coupon', rawurlencode( $coupon ), $link ?: wc_get_cart_url() );
			}
		}

		$text = strtr(
			(string) $funnel['reply_text'],
			[
				'{link}'    => $link,
				'{coupon}'  => $coupon,
				'{keyword}' => (string) $funnel['keyword'],
			]
		);
		if ( '' === trim( $text ) ) {
			$text = sprintf( /* translators: %s: link */ __( 'Here you go: %s', 'igbz-suite' ), $link );
		}

		$fields = [
			'igbz_link'    => $link,
			'igbz_coupon'  => $coupon,
			'igbz_message' => $text,
			'igbz_funnel'  => (string) $funnel['name'],
		];

		$delivered = false;
		$error     = '';

		if ( '' !== $manychat_subscriber_id ) {
			$this->client->set_custom_fields( $manychat_subscriber_id, $fields );

			if ( '' !== (string) $funnel['manychat_tag'] ) {
				$this->client->add_tag_by_name( $manychat_subscriber_id, (string) $funnel['manychat_tag'] );
			}

			if ( '' !== (string) $funnel['manychat_flow_ns'] ) {
				$sent      = $this->client->send_flow( $manychat_subscriber_id, (string) $funnel['manychat_flow_ns'] );
				$delivered = $sent['ok'];
				$error     = $sent['error'];
			} else {
				$sent      = $this->client->send_text( $manychat_subscriber_id, $text, __( 'Open the link', 'igbz-suite' ), $link );
				$delivered = $sent['ok'];
				$error     = $sent['error'];
			}
		} else {
			$error = 'missing_subscriber_id';
		}

		$this->db->update(
			'ig_funnel_hits',
			[
				'delivered'      => $delivered ? 1 : 0,
				'delivery_error' => mb_substr( $error, 0, 255 ),
				'coupon_issued'  => $coupon,
			],
			[ 'id' => $hit_id ]
		);

		if ( $delivered ) {
			$this->db->query(
				'UPDATE ' . $this->db->table( 'ig_funnels' ) . ' SET conversions = conversions + 1 WHERE id = %d',
				(int) $funnel['id']
			);
			$this->grant_wallet_credit( $funnel, $subscriber_row_id, $hit_id );
			do_action( 'igbz_ig_funnel_delivered', (int) $funnel['id'], $hit_id, $fields );
		} else {
			$this->logger->warning( 'manychat', 'Funnel delivery failed', [ 'funnel_id' => (int) $funnel['id'], 'error' => $error ] );
		}

		return $fields;
	}

	/** @param array<string,mixed> $funnel */
	public function resolve_link( array $funnel ): string {
		if ( self::TARGET_PRODUCT === (string) $funnel['target_type'] && (int) $funnel['product_id'] > 0 ) {
			$permalink = get_permalink( (int) $funnel['product_id'] );
			if ( $permalink ) {
				return add_query_arg( [ 'utm_source' => 'instagram', 'utm_medium' => 'dm', 'utm_campaign' => rawurlencode( (string) $funnel['keyword'] ) ], $permalink );
			}
		}

		$url = (string) $funnel['target_url'];
		if ( '' === $url ) {
			return home_url( '/' );
		}

		return add_query_arg( [ 'utm_source' => 'instagram', 'utm_medium' => 'dm', 'utm_campaign' => rawurlencode( (string) $funnel['keyword'] ) ], $url );
	}

	/**
	 * Static code if the funnel names one, otherwise a single-use WooCommerce coupon cloned from
	 * the template coupon.
	 *
	 * @param array<string,mixed> $funnel
	 */
	public function issue_coupon( array $funnel, string $manychat_subscriber_id ): string {
		$template = (string) $funnel['coupon_code'];
		if ( '' === $template || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return $template;
		}

		if ( ! igbz()->settings()->bool( 'instagram.unique_coupons', true ) ) {
			return $template;
		}

		$template_id = wc_get_coupon_id_by_code( $template );
		if ( ! $template_id ) {
			return $template;
		}

		$source = new \WC_Coupon( $template_id );
		$code   = strtolower( $template . '-' . substr( Crypto::token( 4 ), 0, 6 ) );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $source->get_discount_type() );
		$coupon->set_amount( $source->get_amount() );
		$coupon->set_individual_use( $source->get_individual_use() );
		$coupon->set_product_ids( $source->get_product_ids() );
		$coupon->set_excluded_product_ids( $source->get_excluded_product_ids() );
		$coupon->set_product_categories( $source->get_product_categories() );
		$coupon->set_minimum_amount( $source->get_minimum_amount() );
		$coupon->set_maximum_amount( $source->get_maximum_amount() );
		$coupon->set_free_shipping( $source->get_free_shipping() );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( time() + igbz()->settings()->int( 'instagram.coupon_ttl_days', 7 ) * DAY_IN_SECONDS );
		$coupon->set_description( sprintf( 'IGBZ funnel %s / %s', (string) $funnel['name'], $manychat_subscriber_id ) );
		$coupon->save();

		return $code;
	}

	/** @param array<string,mixed> $funnel */
	private function grant_wallet_credit( array $funnel, int $subscriber_row_id, int $hit_id ): void {
		$amount = (float) $funnel['grant_wallet_credit'];
		if ( $amount <= 0 || $subscriber_row_id <= 0 ) {
			return;
		}

		$user_id = $this->subscribers->maybe_link_user( $subscriber_row_id );
		if ( $user_id <= 0 ) {
			return;
		}

		$this->wallet->credit(
			$user_id,
			$amount,
			WalletService::REASON_COMMISSION,
			'ig_funnel:' . $hit_id,
			[ 'funnel_id' => (int) $funnel['id'] ],
			(int) $funnel['tenant_id'],
			0,
			sprintf( /* translators: %s: funnel name */ __( 'Instagram funnel reward: %s', 'igbz-suite' ), (string) $funnel['name'] )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{matched:bool,duplicate:bool,funnel_id:int,hit_id:int,message:string,payload:array<string,mixed>}
	 */
	private function result( bool $matched, bool $duplicate, int $funnel_id, int $hit_id, string $message, array $payload = [] ): array {
		return [
			'matched'   => $matched,
			'duplicate' => $duplicate,
			'funnel_id' => $funnel_id,
			'hit_id'    => $hit_id,
			'message'   => $message,
			'payload'   => $payload,
		];
	}

	// --------------------------------------------------------------- stats

	/** @return array{hits:int,conversions:int,subscribers:int,rate:float} */
	public function stats( int $funnel_id ): array {
		$row = $this->db->row(
			'SELECT hits, conversions FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE id = %d',
			$funnel_id
		);
		$hits        = (int) ( $row['hits'] ?? 0 );
		$conversions = (int) ( $row['conversions'] ?? 0 );

		return [
			'hits'        => $hits,
			'conversions' => $conversions,
			'subscribers' => (int) $this->db->scalar(
				'SELECT COUNT(DISTINCT manychat_subscriber_id) FROM ' . $this->db->table( 'ig_funnel_hits' ) . ' WHERE funnel_id = %d',
				$funnel_id
			),
			'rate'        => $hits > 0 ? round( $conversions / $hits * 100, 2 ) : 0.0,
		];
	}

	/** @return array<int,array<string,mixed>> */
	public function hits( int $funnel_id, int $limit = 50, int $offset = 0 ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_funnel_hits' ) . ' WHERE funnel_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
			$funnel_id,
			$limit,
			$offset
		);
	}

	/** Re-attempt deliveries that failed, from cron. */
	public function retry_failed( int $limit = 20 ): int {
		$rows = $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_funnel_hits' ) . '
			 WHERE delivered = 0 AND delivery_error <> %s AND delivery_error <> %s AND created_at >= %s
			 ORDER BY id DESC LIMIT %d',
			'',
			'per_user_limit',
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			$limit
		);

		$done = 0;
		foreach ( $rows as $hit ) {
			$funnel = $this->get( (int) $hit['funnel_id'] );
			if ( ! $funnel ) {
				continue;
			}
			$this->deliver( $funnel, (int) $hit['id'], (string) $hit['manychat_subscriber_id'], (int) $hit['subscriber_id'] );
			$done++;
		}

		return $done;
	}
}
