<?php
/**
 * "Delivered" has to mean the subscriber got the DM.
 *
 * The ManyChat External Request action times out after about ten seconds, so the webhook computes
 * the reply, answers immediately and does the sending afterwards. That split is fine; what was not
 * fine is that the webhook also stamped the hit `delivered = 1` and incremented `conversions`
 * before a single byte had been sent. An account with a missing or revoked ManyChat key therefore
 * reported a 100% conversion rate while every DM silently failed, and because the row looked
 * delivered the hourly retry skipped it forever.
 *
 * These tests pin the corrected contract:
 *
 *   - the webhook records an attempt, never a delivery;
 *   - followup() is the only writer of `delivered`, and it writes what the API actually answered;
 *   - a conversion is counted exactly once, on the transition into delivered;
 *   - a reply that was rendered by ManyChat from our response but never confirmed by an API call
 *     is marked unconfirmed rather than either "delivered" or "failed";
 *   - a hit that failed, or one whose follow-up never ran, is reachable by the retry.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Admin\HitStatus;
use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\FunnelService;
use IGBZ\Suite\Modules\Instagram\Services\SubscriberService;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;

/**
 * An in-memory stand-in for the four tables the funnel touches.
 *
 * The generic wpdb double answers reads from a queue, which would make these tests a list of
 * canned rows in call order — unreadable, and blind to the very thing under test, since the same
 * row has to be written by one method and then read back by another. This subclass keeps real rows
 * instead, so a test can insert a funnel, run the webhook, run the follow-up and then look at what
 * the hit row actually says.
 *
 * Only the predicates the service depends on are modelled. Everything else falls through to the
 * parent.
 */
final class FunnelDb extends wpdb {

	/** @var array<int,array<string,mixed>> */
	public array $funnels = [];

	/** @var array<int,array<string,mixed>> */
	public array $hits = [];

	/** @var array<int,array<string,mixed>> */
	public array $accounts = [];

	/** @var array<int,array<string,mixed>> */
	public array $subscribers = [];

	private int $next_id = 1;

	// ------------------------------------------------------------- helpers

	/** @param array<string,mixed> $row */
	public function add_funnel( array $row ): int {
		$id                   = $this->next_id++;
		$this->funnels[ $id ] = array_merge(
			[
				'id'                  => $id,
				'tenant_id'           => 1,
				'account_id'          => 0,
				'name'                => 'Launch',
				'keyword'             => 'link',
				'match_mode'          => FunnelService::MATCH_CONTAINS,
				'post_id'             => '',
				'reply_text'          => 'Here is your link: {link}',
				'target_type'         => FunnelService::TARGET_URL,
				'target_url'          => 'https://shop.test/product',
				'product_id'          => 0,
				'coupon_code'         => '',
				'manychat_flow_ns'    => '',
				'manychat_tag'        => '',
				'grant_wallet_credit' => 0.0,
				'per_user_limit'      => 1,
				'total_limit'         => 0,
				'starts_at'           => null,
				'ends_at'             => null,
				'is_active'           => 1,
				'hits'                => 0,
				'conversions'         => 0,
			],
			$row
		);

		return $id;
	}

	/** @param array<string,mixed> $row */
	public function add_account( array $row ): int {
		$id                    = $this->next_id++;
		$this->accounts[ $id ] = array_merge(
			[
				'id'               => $id,
				'tenant_id'        => 1,
				'is_active'        => 1,
				'credential_mode'  => AccountCredentials::MODE_OWN,
				'manychat_api_key' => null,
				'manus_api_key'    => null,
			],
			$row
		);

		return $id;
	}

	/** @return array<string,mixed> */
	public function hit( int $id ): array {
		return $this->hits[ $id ] ?? [];
	}

	/** @return array<string,mixed> */
	public function funnel( int $id ): array {
		return $this->funnels[ $id ] ?? [];
	}

	private static function id_in( string $sql ): int {
		return preg_match( "/\bid = '?(\d+)'?/", $sql, $m ) ? (int) $m[1] : 0;
	}

	private static function value_of( string $column, string $sql ): string {
		return preg_match( "/\b" . preg_quote( $column, '/' ) . " = '([^']*)'/", $sql, $m ) ? $m[1] : '';
	}

	/**
	 * Every quoted literal following $prefix in $sql.
	 *
	 * The wpdb double quotes %d placeholders too, so everything arrives as a string; the funnel's
	 * state markers are strings anyway.
	 *
	 * @return string[]
	 */
	private static function all_values( string $prefix, string $sql ): array {
		preg_match_all( '/' . preg_quote( $prefix, '/' ) . "'([^']*)'/", $sql, $m );
		return $m[1];
	}

	// --------------------------------------------------------------- reads

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'igbz_ig_funnel_hits' ) ) {
			return $this->hits[ self::id_in( $sql ) ] ?? null;
		}
		if ( str_contains( $sql, 'igbz_ig_funnels' ) ) {
			return $this->funnels[ self::id_in( $sql ) ] ?? null;
		}
		if ( str_contains( $sql, 'igbz_ig_accounts' ) ) {
			$id = self::id_in( $sql );
			if ( $id > 0 && isset( $this->accounts[ $id ] ) ) {
				return $this->accounts[ $id ];
			}
			// The "first active account of the tenant" fallback.
			foreach ( $this->accounts as $account ) {
				if ( 1 === (int) $account['is_active'] ) {
					return $account;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'igbz_ig_subscribers' ) ) {
			$manychat_id = self::value_of( 'manychat_subscriber_id', $sql );
			if ( '' !== $manychat_id ) {
				foreach ( $this->subscribers as $row ) {
					if ( $manychat_id === (string) $row['manychat_subscriber_id'] ) {
						return $row;
					}
				}
				return null;
			}
			return $this->subscribers[ self::id_in( $sql ) ] ?? null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'igbz_ig_funnels' ) ) {
			// match() leans on the database for two things beyond the row set: the post_id IN
			// list, which decides who is eligible, and the ORDER BY, which decides who wins when
			// a post-specific funnel and a catch-all both qualify. Returning every row in
			// insertion order hides both, so honour them here.
			$rows = array_values( $this->funnels );

			if ( ! str_contains( $sql, 'post_id IN (' ) ) {
				return $rows;
			}

			preg_match( '/post_id IN \(([^)]*)\)/', $sql, $list );
			preg_match_all( "/'([^']*)'/", $list[1] ?? '', $found );
			$scopes = $found[1];
			$rows   = array_values(
				array_filter(
					$rows,
					static fn ( array $row ): bool => in_array( (string) $row['post_id'], $scopes, true )
				)
			);

			// Read the ordering expression out of the SQL rather than assuming the intended one:
			// hard-coding "pinned first" here would make this double agree with the query no
			// matter which way round the comparison is written, which is precisely how the
			// reversed ORDER BY survived unnoticed.
			$equals = (bool) preg_match( "/ORDER BY \(post_id = '[^']*'\) ASC/", $sql );
			$not    = (bool) preg_match( "/ORDER BY \(post_id <> '[^']*'\) ASC/", $sql );

			if ( $equals || $not ) {
				usort(
					$rows,
					static function ( array $a, array $b ) use ( $equals ): int {
						// The sort key is the truth value of the comparison the SQL actually
						// makes, so an inverted expression sorts inverted here too.
						$key      = static fn ( array $row ): int => $equals
							? ( '' === (string) $row['post_id'] ? 1 : 0 )
							: ( '' !== (string) $row['post_id'] ? 1 : 0 );
						$a_rank   = $key( $a );
						$b_rank   = $key( $b );

						return $a_rank === $b_rank
							? (int) $b['id'] <=> (int) $a['id']
							: $a_rank <=> $b_rank;
					}
				);
			}

			return $rows;
		}

		if ( str_contains( $sql, 'igbz_ig_funnel_hits' ) ) {
			if ( str_contains( $sql, 'GROUP BY' ) ) {
				$groups = [];
				foreach ( $this->hits as $hit ) {
					$key             = $hit['delivered'] . '|' . $hit['delivery_error'];
					$groups[ $key ] ??= [ 'delivered' => (int) $hit['delivered'], 'delivery_error' => (string) $hit['delivery_error'], 'total' => 0 ];
					++$groups[ $key ]['total'];
				}
				return array_values( $groups );
			}

			// The retry selection. The predicate is read out of the statement rather than
			// restated here: a double that re-implements the WHERE clause in PHP would pass no
			// matter what the service actually asks the database for.
			$excluded = self::all_values( 'delivery_error <> ', $sql );
			$deferred = preg_match( "/delivery_error NOT IN \( ([^)]*) \)/", $sql, $m )
				? self::all_values( '', $m[1] )
				: [];
			$oldest   = preg_match( "/created_at >= '([^']*)'/", $sql, $m2 ) ? $m2[1] : '';
			$cutoff   = preg_match( "/created_at <= '([^']*)'/", $sql, $m3 ) ? $m3[1] : '';

			$out = [];
			foreach ( array_reverse( $this->hits, true ) as $hit ) {
				$error = (string) $hit['delivery_error'];
				if ( str_contains( $sql, 'delivered = 0' ) && 1 === (int) $hit['delivered'] ) {
					continue;
				}
				if ( in_array( $error, $excluded, true ) ) {
					continue;
				}
				if ( '' !== $oldest && (string) $hit['created_at'] < $oldest ) {
					continue;
				}
				if ( $deferred && in_array( $error, $deferred, true ) && '' !== $cutoff && (string) $hit['created_at'] > $cutoff ) {
					continue;
				}
				$out[] = $hit;
			}
			return $out;
		}

		return parent::get_results( $sql, $output );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'igbz_ig_funnel_hits' ) && str_contains( $sql, 'COUNT(*)' ) ) {
			$funnel_id   = preg_match( "/funnel_id = '?(\d+)'?/", $sql, $m ) ? (int) $m[1] : 0;
			$manychat_id = self::value_of( 'manychat_subscriber_id', $sql );
			$exclude     = preg_match( "/id <> '?(\d+)'?/", $sql, $m2 ) ? (int) $m2[1] : 0;

			// Which states count towards the cap is read out of the statement, not assumed, so
			// narrowing the real query fails the test instead of quietly agreeing with it.
			$counted = preg_match( '/\(delivered = 1(.*?)\)/s', $sql, $m3 )
				? self::all_values( 'delivery_error = ', $m3[1] )
				: [];

			$count = 0;
			foreach ( $this->hits as $hit ) {
				if ( (int) $hit['funnel_id'] !== $funnel_id || (string) $hit['manychat_subscriber_id'] !== $manychat_id ) {
					continue;
				}
				if ( (int) $hit['id'] === $exclude ) {
					continue;
				}
				if ( 1 === (int) $hit['delivered'] || in_array( (string) $hit['delivery_error'], $counted, true ) ) {
					++$count;
				}
			}
			return $count;
		}

		return parent::get_var( $sql );
	}

	// -------------------------------------------------------------- writes

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( str_contains( $table, 'igbz_ig_funnel_hits' ) ) {
			foreach ( $this->hits as $hit ) {
				// UNIQUE KEY dedupe (funnel_id, comment_id).
				if ( (int) $hit['funnel_id'] === (int) $data['funnel_id'] && (string) $hit['comment_id'] === (string) $data['comment_id'] ) {
					return false;
				}
			}
			$id                = $this->next_id++;
			$this->insert_id   = $id;
			$this->hits[ $id ] = array_merge(
				[ 'id' => $id, 'delivered' => 0, 'delivery_error' => '', 'coupon_issued' => '', 'subscriber_id' => 0 ],
				$data
			);
			return 1;
		}

		if ( str_contains( $table, 'igbz_ig_subscribers' ) ) {
			$id                      = $this->next_id++;
			$this->insert_id         = $id;
			$this->subscribers[ $id ] = array_merge( [ 'id' => $id, 'user_id' => 0, 'email' => '', 'phone' => '' ], $data );
			return 1;
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$id = (int) ( $where['id'] ?? 0 );

		if ( str_contains( $table, 'igbz_ig_funnel_hits' ) && isset( $this->hits[ $id ] ) ) {
			$this->hits[ $id ] = array_merge( $this->hits[ $id ], $data );
			return 1;
		}
		if ( str_contains( $table, 'igbz_ig_funnels' ) && isset( $this->funnels[ $id ] ) ) {
			$this->funnels[ $id ] = array_merge( $this->funnels[ $id ], $data );
			return 1;
		}
		if ( str_contains( $table, 'igbz_ig_subscribers' ) && isset( $this->subscribers[ $id ] ) ) {
			$this->subscribers[ $id ] = array_merge( $this->subscribers[ $id ], $data );
			return 1;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		// settle()'s conditional claim. The condition is the whole point: it is what stops two
		// writers counting the same conversion twice.
		if ( preg_match( "/^UPDATE \S*igbz_ig_funnel_hits SET delivered = (\d+), delivery_error = '(.*)' WHERE id = '?(\d+)'? AND delivered = 0$/s", $sql, $m ) ) {
			$id = (int) $m[3];
			if ( ! isset( $this->hits[ $id ] ) || 1 === (int) $this->hits[ $id ]['delivered'] ) {
				return 0;
			}
			$this->hits[ $id ]['delivered']      = (int) $m[1];
			$this->hits[ $id ]['delivery_error'] = $m[2];
			return 1;
		}

		if ( preg_match( "/^UPDATE \S*igbz_ig_funnels SET (hits|conversions) = \\1 \+ 1 WHERE id = '?(\d+)'?$/", $sql, $m ) ) {
			$id = (int) $m[2];
			if ( ! isset( $this->funnels[ $id ] ) ) {
				return 0;
			}
			++$this->funnels[ $id ][ $m[1] ];
			return 1;
		}

		return parent::query( $sql );
	}
}

final class FunnelDeliveryTest extends TestCase {

	private FunnelDb $db;

	private function boot(): FunnelService {
		igbz_test_reset_settings();

		$this->db        = new FunnelDb();
		$GLOBALS['wpdb'] = $this->db;

		$logger      = igbz()->get( 'logger' );
		$db          = new Db();
		$client      = new ManyChatClient( new Http( $logger ), $logger );
		$credentials = new AccountCredentials( $db );

		return new FunnelService(
			$db,
			$client,
			new SubscriberService( $db, $client, $logger, $credentials ),
			new WalletService( $db, $logger ),
			$logger,
			$credentials
		);
	}

	/** @return array<string,mixed> */
	private function comment( string $text = 'link please', string $comment_id = 'c-1', string $subscriber = 'sub-1' ): array {
		return [
			'comment_text'  => $text,
			'comment_id'    => $comment_id,
			'post_id'       => '',
			'subscriber_id' => $subscriber,
			'ig_username'   => 'buyer',
			'tenant_id'     => 1,
			'account_id'    => 0,
		];
	}

	/** A ManyChat getInfo response, so the follow-up's profile sync succeeds. */
	private function queue_profile( string $subscriber = 'sub-1' ): void {
		igbz_test_queue_http(
			[
				'status' => 200,
				'body'   => wp_json_encode(
					[
						'status' => 'success',
						'data'   => [ 'id' => $subscriber, 'name' => 'buyer', 'email' => '', 'phone' => '' ],
					]
				),
			]
		);
	}

	public function run(): void {
		$this->test_the_webhook_records_an_attempt_not_a_delivery();
		$this->test_a_missing_key_is_recorded_as_a_failure();
		$this->test_a_failed_send_counts_no_conversion_and_fires_no_action();
		$this->test_a_confirmed_flow_send_marks_the_hit_delivered();
		$this->test_an_api_error_is_stored_verbatim();
		$this->test_an_inline_reply_settles_as_unconfirmed();
		$this->test_a_conversion_is_counted_once_only();
		$this->test_two_writers_cannot_settle_the_same_hit_twice();
		$this->test_a_hit_with_no_subscriber_id_records_why();
		$this->test_a_capped_subscriber_gets_no_link();
		$this->test_an_in_flight_hit_counts_against_the_cap();
		$this->test_every_attempt_moves_the_hits_counter();
		$this->test_a_repeated_comment_is_not_a_second_hit();
		$this->test_the_retry_picks_up_stale_pending_hits();
		$this->test_the_backlog_separates_the_states();
		$this->test_the_admin_cell_names_each_state();
		$this->test_post_specific_funnel_wins_over_a_catch_all();
	}

	/**
	 * The operator-facing wording. Two screens share this formatter, and the states it has to keep
	 * apart are exactly the ones the old two-branch version conflated.
	 */
	private function test_the_admin_cell_names_each_state(): void {
		igbz_test_reset_settings();

		$cell = static fn ( int $delivered, string $error ): string => HitStatus::cell(
			[ 'delivered' => $delivered, 'delivery_error' => $error ]
		);

		$this->assert_contains( 'delivered', $cell( 1, '' ), 'a confirmed send reads as delivered' );
		$this->assert_contains( 'OK', $cell( 1, '' ), 'and is the only green state' );

		$this->assert_contains( 'sent, unconfirmed', $cell( 1, FunnelService::DELIVERY_UNCONFIRMED ), 'an unproven send says so' );
		$this->assert_contains( 'WARN', $cell( 1, FunnelService::DELIVERY_UNCONFIRMED ), 'and is not shown as success' );

		$this->assert_contains( 'waiting to send', $cell( 0, FunnelService::DELIVERY_PENDING ), 'an in-flight hit is not a failure' );
		$this->assert_contains( 'WARN', $cell( 0, FunnelService::DELIVERY_PENDING_INLINE ), 'nor is one whose reply went out inline' );

		$this->assert_contains( 'per-user limit', $cell( 0, FunnelService::DELIVERY_BLOCKED ), 'the cap is explained, not printed raw' );

		$this->assert_contains( 'no ManyChat API key', $cell( 0, 'manychat_key_missing' ), 'our own error codes are translated' );
		$this->assert_contains( 'ERROR', $cell( 0, 'manychat_key_missing' ), 'and are the red state' );
		$this->assert_contains( 'HTTP 500', $cell( 0, 'HTTP 500' ), "ManyChat's own message is passed through for searching" );
	}

	// ------------------------------------------------------------- webhook

	private function test_the_webhook_records_an_attempt_not_a_delivery(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [] );

		$result = $funnels->handle_event_async( $this->comment() );
		$hit    = $this->db->hit( (int) $result['hit_id'] );

		$this->assert_true( $result['matched'], 'the comment matches the funnel' );
		$this->assert_same( 0, (int) $hit['delivered'], 'answering the webhook is not a delivery' );
		$this->assert_same( FunnelService::DELIVERY_PENDING_INLINE, (string) $hit['delivery_error'], 'the hit is marked in flight' );
		$this->assert_same( 0, (int) $this->db->funnel( $funnel_id )['conversions'], 'no conversion is counted yet' );
		$this->assert_same( 1, (int) $this->db->funnel( $funnel_id )['hits'], 'the attempt is counted' );
		$this->assert_contains( 'https://shop.test/product', $result['link'], 'the link is computed for ManyChat to render' );
	}

	// -------------------------------------------------------------- errors

	private function test_a_missing_key_is_recorded_as_a_failure(): void {
		$funnels = $this->boot();
		$this->db->add_funnel( [] );
		$this->db->add_account( [ 'manychat_api_key' => null ] );

		$result = $funnels->handle_event_async( $this->comment() );
		$funnels->followup( (int) $result['hit_id'] );

		$hit = $this->db->hit( (int) $result['hit_id'] );
		$this->assert_same( 0, (int) $hit['delivered'], 'an account with no ManyChat key delivers nothing' );
		$this->assert_same( 'manychat_key_missing', (string) $hit['delivery_error'], 'and the reason is on the row' );
	}

	private function test_a_failed_send_counts_no_conversion_and_fires_no_action(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [] );
		$this->db->add_account( [ 'manychat_api_key' => null ] );

		$fired = [];
		add_action(
			'igbz_ig_funnel_delivered',
			static function ( $id ) use ( &$fired ): void {
				$fired[] = (int) $id;
			}
		);

		$result = $funnels->handle_event_async( $this->comment() );
		$funnels->followup( (int) $result['hit_id'] );

		$this->assert_same( 0, (int) $this->db->funnel( $funnel_id )['conversions'], 'a failed DM is not a conversion' );
		$this->assert_same( [], $fired, 'and nothing downstream is told it was delivered' );
	}

	private function test_an_api_error_is_stored_verbatim(): void {
		$funnels = $this->boot();
		$this->db->add_funnel( [ 'manychat_flow_ns' => 'content123_456' ] );
		$this->db->add_account( [ 'manychat_api_key' => Crypto::encrypt( 'mc-key' ) ] );

		$result = $funnels->handle_event_async( $this->comment() );

		$this->queue_profile();
		igbz_test_queue_manychat_error( 'Subscriber not found', ManyChatClient::ERROR_SUBSCRIBER_NOT_FOUND, 'sending/sendFlow' );

		$funnels->followup( (int) $result['hit_id'] );

		$hit = $this->db->hit( (int) $result['hit_id'] );
		$this->assert_same( 0, (int) $hit['delivered'], 'a rejected sendFlow is not a delivery' );
		$this->assert_same( 'Subscriber not found', (string) $hit['delivery_error'], "ManyChat's own message is kept for the operator" );
	}

	// ----------------------------------------------------------- successes

	private function test_a_confirmed_flow_send_marks_the_hit_delivered(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [ 'manychat_flow_ns' => 'content123_456' ] );
		$this->db->add_account( [ 'manychat_api_key' => Crypto::encrypt( 'mc-key' ) ] );

		$result = $funnels->handle_event_async( $this->comment() );

		$this->queue_profile();
		$funnels->followup( (int) $result['hit_id'] );

		$hit = $this->db->hit( (int) $result['hit_id'] );
		$this->assert_same( 1, (int) $hit['delivered'], 'a successful sendFlow is a delivery' );
		$this->assert_same( '', (string) $hit['delivery_error'], 'with no error left on the row' );
		$this->assert_same( 1, (int) $this->db->funnel( $funnel_id )['conversions'], 'and it converts' );
	}

	/**
	 * No flow is configured, so the DM ManyChat shows is the one it rendered from our webhook
	 * response. Sending the same text again through the API would arrive as a second message, so
	 * the hit is settled as delivered — honestly labelled as unproven.
	 */
	private function test_an_inline_reply_settles_as_unconfirmed(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [ 'manychat_flow_ns' => '' ] );
		$this->db->add_account( [ 'manychat_api_key' => Crypto::encrypt( 'mc-key' ) ] );

		$result = $funnels->handle_event_async( $this->comment() );

		$this->queue_profile();
		$funnels->followup( (int) $result['hit_id'] );

		$hit = $this->db->hit( (int) $result['hit_id'] );
		$this->assert_same( 1, (int) $hit['delivered'], 'the subscriber has the reply' );
		$this->assert_same( FunnelService::DELIVERY_UNCONFIRMED, (string) $hit['delivery_error'], 'but nothing confirmed it' );
		$this->assert_same( 1, (int) $this->db->funnel( $funnel_id )['conversions'], 'it still counts as a conversion' );

		$sends = 0;
		foreach ( $GLOBALS['igbz_test_http_requests'] as $request ) {
			if ( str_contains( (string) $request['url'], '/sending/' ) ) {
				++$sends;
			}
		}
		$this->assert_same( 0, $sends, 'and the text is not sent a second time over the API' );
	}

	private function test_a_conversion_is_counted_once_only(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [ 'manychat_flow_ns' => 'content123_456' ] );
		$this->db->add_account( [ 'manychat_api_key' => Crypto::encrypt( 'mc-key' ) ] );

		$result = $funnels->handle_event_async( $this->comment() );

		$this->queue_profile();
		$funnels->followup( (int) $result['hit_id'] );

		// A duplicated cron event, or the hourly retry racing the scheduled follow-up.
		$this->queue_profile();
		$funnels->followup( (int) $result['hit_id'] );

		$this->assert_same( 1, (int) $this->db->funnel( $funnel_id )['conversions'], 'the second run adds nothing' );
	}

	/**
	 * followup() bails on an already-settled hit, so the guard inside settle() only earns its
	 * keep in a real race: two workers that both read the row while it was still unsettled. The
	 * conditional UPDATE is what makes the loser a no-op, and deliver() reaches it directly.
	 */
	private function test_two_writers_cannot_settle_the_same_hit_twice(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [ 'manychat_flow_ns' => 'content123_456' ] );
		$this->db->add_account( [ 'manychat_api_key' => Crypto::encrypt( 'mc-key' ) ] );

		$result = $funnels->handle_event_async( $this->comment() );
		$funnel = $this->db->funnel( $funnel_id );

		$fired = 0;
		add_action(
			'igbz_ig_funnel_delivered',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$funnels->deliver( $funnel, (int) $result['hit_id'], 'sub-1' );
		$funnels->deliver( $funnel, (int) $result['hit_id'], 'sub-1' );

		$this->assert_same( 1, (int) $this->db->funnel( $funnel_id )['conversions'], 'the loser of the race counts nothing' );
		$this->assert_same( 1, $fired, 'and announces nothing' );
	}

	private function test_a_hit_with_no_subscriber_id_records_why(): void {
		$funnels = $this->boot();
		$this->db->add_funnel( [] );
		$this->db->add_account( [ 'manychat_api_key' => Crypto::encrypt( 'mc-key' ) ] );

		$result = $funnels->handle_event_async( $this->comment( 'link please', 'c-9', '' ) );
		$funnels->followup( (int) $result['hit_id'] );

		$hit = $this->db->hit( (int) $result['hit_id'] );
		$this->assert_same( 'missing_subscriber_id', (string) $hit['delivery_error'], 'a flow that forgot to map the subscriber id says so' );
		$this->assert_same( 0, (int) $hit['delivered'], 'and it is not a delivery' );
	}

	// ----------------------------------------------------------- the cap

	private function test_a_capped_subscriber_gets_no_link(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [ 'per_user_limit' => 1, 'manychat_flow_ns' => 'content123_456' ] );
		$this->db->add_account( [ 'manychat_api_key' => Crypto::encrypt( 'mc-key' ) ] );

		$first = $funnels->handle_event_async( $this->comment( 'link please', 'c-1' ) );
		$this->queue_profile();
		$funnels->followup( (int) $first['hit_id'] );

		$second = $funnels->handle_event_async( $this->comment( 'link please', 'c-2' ) );

		$this->assert_true( (bool) $second['blocked'], 'the second request is blocked' );
		$this->assert_same( '', $second['link'], 'and carries no link, or the cap would be decorative' );
		$this->assert_same(
			FunnelService::DELIVERY_BLOCKED,
			(string) $this->db->hit( (int) $second['hit_id'] )['delivery_error'],
			'the row says why'
		);
		$this->assert_same( 1, (int) $this->db->funnel( $funnel_id )['conversions'], 'a blocked hit converts nothing' );
	}

	/**
	 * The delivery is settled a few seconds after the webhook answers. Counting only settled hits
	 * left that gap open: two comments inside it both passed the cap.
	 */
	private function test_an_in_flight_hit_counts_against_the_cap(): void {
		$funnels = $this->boot();
		$this->db->add_funnel( [ 'per_user_limit' => 1 ] );

		$funnels->handle_event_async( $this->comment( 'link please', 'c-1' ) );
		$second = $funnels->handle_event_async( $this->comment( 'link please', 'c-2' ) );

		$this->assert_true( (bool) $second['blocked'], 'a hit that has not settled yet still occupies the allowance' );
	}

	private function test_every_attempt_moves_the_hits_counter(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [ 'per_user_limit' => 1 ] );

		$funnels->handle_event_async( $this->comment( 'link please', 'c-1' ) );
		$funnels->handle_event_async( $this->comment( 'link please', 'c-2' ) );

		// The blocked attempt used to be left out of the denominator, which flattered the rate.
		$this->assert_same( 2, (int) $this->db->funnel( $funnel_id )['hits'], 'a blocked attempt is still an attempt' );
	}

	private function test_a_repeated_comment_is_not_a_second_hit(): void {
		$funnels   = $this->boot();
		$funnel_id = $this->db->add_funnel( [] );

		$funnels->handle_event_async( $this->comment( 'link please', 'c-1' ) );
		$again = $funnels->handle_event_async( $this->comment( 'link please', 'c-1' ) );

		$this->assert_true( (bool) $again['duplicate'], 'the same comment id is deduped' );
		$this->assert_same( 1, (int) $this->db->funnel( $funnel_id )['hits'], 'a ManyChat retry does not inflate the counter' );
	}

	// --------------------------------------------------------- the retry

	/**
	 * WP-Cron only runs on traffic, so the +5s follow-up can simply never fire on a quiet site.
	 * Those hits must not be stranded — but a hit that is merely three seconds old must not be
	 * grabbed either, or the retry would race the follow-up.
	 */
	private function test_the_retry_picks_up_stale_pending_hits(): void {
		$funnels = $this->boot();
		$this->db->add_funnel( [ 'manychat_flow_ns' => 'content123_456' ] );
		$this->db->add_account( [ 'manychat_api_key' => Crypto::encrypt( 'mc-key' ) ] );

		$fresh = $funnels->handle_event_async( $this->comment( 'link please', 'c-1', 'sub-1' ) );
		$stale = $funnels->handle_event_async( $this->comment( 'link please', 'c-2', 'sub-2' ) );

		// Age the second hit past the grace period.
		$this->db->hits[ (int) $stale['hit_id'] ]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - ( FunnelService::FOLLOWUP_GRACE + 60 ) );

		$this->queue_profile( 'sub-2' );
		$done = $funnels->retry_failed( 10 );

		$this->assert_same( 1, $done, 'only the stale hit is retried' );
		$this->assert_same( 1, (int) $this->db->hit( (int) $stale['hit_id'] )['delivered'], 'and it is delivered' );
		$this->assert_same(
			FunnelService::DELIVERY_PENDING_INLINE,
			(string) $this->db->hit( (int) $fresh['hit_id'] )['delivery_error'],
			'the fresh hit is left to its scheduled follow-up'
		);
	}

	private function test_the_backlog_separates_the_states(): void {
		$funnels = $this->boot();
		$this->db->add_funnel( [ 'per_user_limit' => 0 ] );

		$a = $funnels->handle_event_async( $this->comment( 'link please', 'c-1', 'sub-1' ) );
		$b = $funnels->handle_event_async( $this->comment( 'link please', 'c-2', 'sub-2' ) );
		$c = $funnels->handle_event_async( $this->comment( 'link please', 'c-3', 'sub-3' ) );
		$d = $funnels->handle_event_async( $this->comment( 'link please', 'c-4', 'sub-4' ) );

		$this->db->hits[ (int) $b['hit_id'] ]['delivery_error'] = 'HTTP 500';
		$this->db->hits[ (int) $c['hit_id'] ]['delivery_error'] = FunnelService::DELIVERY_BLOCKED;
		$this->db->hits[ (int) $d['hit_id'] ]['delivered']      = 1;
		$this->db->hits[ (int) $d['hit_id'] ]['delivery_error'] = FunnelService::DELIVERY_UNCONFIRMED;

		$backlog = $funnels->delivery_backlog();

		$this->assert_same( 1, $backlog['pending'], 'one hit is still in flight' );
		$this->assert_same( 1, $backlog['failed'], 'one really failed' );
		$this->assert_same( 1, $backlog['blocked'], 'one was capped, which is not a fault' );
		$this->assert_same( 1, $backlog['unconfirmed'], 'and one is delivered but unproven' );
		$this->assert_true( $a['hit_id'] > 0, 'the pending hit is the one nothing was done to' );
	}
	private function test_post_specific_funnel_wins_over_a_catch_all(): void {
		// The realistic shape of an account that has been running for a while: one broad funnel
		// answering the keyword everywhere, plus a funnel pinned to the post currently being
		// promoted. Both are valid for a comment on that post, and the pinned one has to win --
		// otherwise the catch-all quietly swallows every campaign on the account.
		$funnels = $this->boot();

		$this->db->add_funnel(
			[
				'name'       => 'Catch all',
				'keyword'    => 'link',
				'post_id'    => '',
				'target_url' => 'https://shop.test/generic',
			]
		);
		$this->db->add_funnel(
			[
				'name'       => 'Autumn launch',
				'keyword'    => 'link',
				'post_id'    => 'CxAutumn123',
				'target_url' => 'https://shop.test/autumn',
			]
		);

		$pinned = $funnels->match( 'send me the link', 'CxAutumn123', 1, 0 );
		$this->assert_same( 'Autumn launch', (string) $pinned['name'], 'the funnel pinned to the post answers the comment on that post' );

		// The same comment on a post nobody pinned still has to reach the catch-all.
		$other = $funnels->match( 'send me the link', 'CxSomethingElse', 1, 0 );
		$this->assert_same( 'Catch all', (string) $other['name'], 'an unpinned post falls through to the broad funnel' );

		// And a post id spelled as a URL is the same post as the shortcode it contains.
		$as_url = $funnels->match( 'send me the link', 'https://www.instagram.com/p/CxAutumn123/', 1, 0 );
		$this->assert_same( 'Autumn launch', (string) $as_url['name'], 'a pasted permalink resolves to the pinned funnel' );
	}

}
