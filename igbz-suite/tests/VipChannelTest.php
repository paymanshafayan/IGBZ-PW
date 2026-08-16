<?php
/**
 * The VIP channel: who may open a post, and what a locked one is allowed to reveal.
 *
 * The whole feature rests on one decision — VipAccessService::check_row() — and on the promise
 * that a locked post never hands out a URL that resolves to the real file. Everything else (the
 * feed, the media responder, the share page, the app) reads that decision and echoes it, so a bug
 * there is a bug everywhere, and it is the silent kind: the feed keeps looking right while the
 * media endpoint serves the file to anyone.
 *
 * These tests pin the rules we actually agreed:
 *
 *   - a free post is free, signed in or not;
 *   - a members-only post tells an anonymous visitor to sign in, and a lapsed member that their
 *     membership expired — two different screens, so two different reasons;
 *   - a single-post purchase outlives the membership that did not pay for it;
 *   - a member never pays twice for a pay-per-view post;
 *   - an expired post is gone for everybody, member or not;
 *   - a locked post exposes the blurred placeholder and nothing else;
 *   - a signed media link is bound to one post, one item, one viewer and one clock.
 *
 * Plus the money and the clock: renewing early must not throw away the days already paid for, a
 * tip under the floor must be refused before it reaches a gateway, and expiry must do what the
 * post says it should — hide it, or take it away.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Vip\VipAccess;
use IGBZ\Suite\Modules\Instagram\Vip\VipAccessService;
use IGBZ\Suite\Modules\Instagram\Vip\VipBillingService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMediaService;
use IGBZ\Suite\Modules\Instagram\Vip\VipMessageService;
use IGBZ\Suite\Modules\Instagram\Vip\VipPostService;
use IGBZ\Suite\Modules\Instagram\Vip\VipSocialService;
use IGBZ\Suite\Support\Db;

/**
 * An in-memory stand-in for the nine vip_* tables plus payments.
 *
 * The generic wpdb double answers reads from a queue, which cannot work here: the same row is
 * written by one service and read back by another (buy a post, then ask whether you may open it),
 * and a queue of canned rows would agree with the code whatever the code did. This subclass keeps
 * real rows and answers the handful of predicates the services depend on, reading the values out
 * of the prepared statement rather than restating the WHERE clause in PHP.
 */
final class VipDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> table short name => id => row */
	public array $tables = [
		'vip_posts'         => [],
		'vip_plans'         => [],
		'vip_memberships'   => [],
		'vip_entitlements'  => [],
		'vip_post_likes'    => [],
		'vip_post_saves'    => [],
		'vip_post_comments' => [],
		'vip_post_views'    => [],
		'vip_threads'       => [],
		'vip_messages'      => [],
		'payments'          => [],
	];

	private int $next_id = 1;

	// ------------------------------------------------------------- seeding

	/** @param array<string,mixed> $row */
	public function seed( string $table, array $row ): int {
		$id                            = (int) ( $row['id'] ?? $this->next_id++ );
		$row['id']                     = $id;
		$this->tables[ $table ][ $id ] = $row;

		return $id;
	}

	/** @param array<string,mixed> $row */
	public function seed_post( array $row = [] ): int {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->seed(
			'vip_posts',
			array_merge(
				[
					'tenant_id'         => 1,
					'account_id'        => 0,
					'author_id'         => 99,
					'shortcode'         => 'code' . $this->next_id,
					'kind'              => VipPostService::KIND_IMAGE,
					'caption'           => 'Behind the scenes',
					'media'             => wp_json_encode(
						[
							[
								'type'   => 'image',
								'url'    => 'https://cdn.test/private/real.jpg',
								'path'   => 'vip/real.jpg',
								'thumb'  => 'https://cdn.test/private/thumb.jpg',
								'blur'   => 'https://cdn.test/public/blur.jpg',
								'width'  => 1080,
								'height' => 1350,
							],
						]
					),
					'teaser_content_id' => 0,
					'product_id'        => 0,
					'access'            => VipAccessService::ACCESS_MEMBERS,
					'price'             => 0.0,
					'status'            => VipPostService::STATUS_PUBLISHED,
					'comments_enabled'  => 1,
					'publish_at'        => null,
					'published_at'      => $now,
					'expires_at'        => null,
					'expiry_action'     => VipPostService::EXPIRY_HIDE,
					'expired_at'        => null,
					'likes_count'       => 0,
					'comments_count'    => 0,
					'views_count'       => 0,
					'created_at'        => $now,
					'updated_at'        => $now,
				],
				$row
			)
		);
	}

	/** @param array<string,mixed> $row */
	public function seed_plan( array $row = [] ): int {
		return $this->seed(
			'vip_plans',
			array_merge(
				[
					'tenant_id'     => 1,
					'slug'          => 'monthly',
					'name'          => 'VIP Monthly',
					'description'   => '',
					'price'         => 150000.0,
					'currency'      => 'IRT',
					'duration_days' => 30,
					'is_active'     => 1,
					'sort_order'    => 0,
				],
				$row
			)
		);
	}

	/** @param array<string,mixed> $row */
	public function seed_membership( array $row = [] ): int {
		$now = gmdate( 'Y-m-d H:i:s' );

		return $this->seed(
			'vip_memberships',
			array_merge(
				[
					'tenant_id'    => 1,
					'user_id'      => 7,
					'plan_id'      => 0,
					'status'       => VipAccessService::STATUS_ACTIVE,
					'starts_at'    => $now,
					'ends_at'      => gmdate( 'Y-m-d H:i:s', time() + ( 20 * DAY_IN_SECONDS ) ),
					'payment_id'   => 0,
					'auto_renew'   => 1,
					'price_paid'   => 150000.0,
					'cancelled_at' => null,
					'created_at'   => $now,
					'updated_at'   => $now,
				],
				$row
			)
		);
	}

	/** @return array<string,mixed> */
	public function get( string $table, int $id ): array {
		return $this->tables[ $table ][ $id ] ?? [];
	}

	/** @return array<int,array<string,mixed>> */
	public function all( string $table ): array {
		return array_values( $this->tables[ $table ] );
	}

	// ------------------------------------------------------------- parsing

	private static function which( string $sql ): string {
		// Longest first: vip_post_comments also contains vip_post.
		$names = [
			'vip_post_comments',
			'vip_post_likes',
			'vip_post_saves',
			'vip_post_views',
			'vip_memberships',
			'vip_entitlements',
			'vip_threads',
			'vip_messages',
			'vip_plans',
			'vip_posts',
			'payments',
		];

		foreach ( $names as $name ) {
			if ( str_contains( $sql, 'igbz_' . $name ) ) {
				return $name;
			}
		}

		return '';
	}

	private static function value_of( string $column, string $sql ): ?string {
		return preg_match( '/\b' . preg_quote( $column, '/' ) . " = '([^']*)'/", $sql, $m ) ? $m[1] : null;
	}

	private static function int_of( string $column, string $sql ): int {
		return (int) self::value_of( $column, $sql );
	}

	/** Rows of $table matching every `column = 'value'` pair named in $columns. */
	private function matching( string $table, string $sql, array $columns ): array {
		$out = [];

		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			foreach ( $columns as $column ) {
				$wanted = self::value_of( $column, $sql );
				if ( null !== $wanted && (string) ( $row[ $column ] ?? '' ) !== $wanted ) {
					continue 2;
				}
			}
			$out[] = $row;
		}

		return $out;
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	// --------------------------------------------------------------- reads

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( '' === $table ) {
			return parent::get_row( $sql, $output );
		}

		// Membership lookup: the newest one still running.
		if ( 'vip_memberships' === $table && str_contains( $sql, 'ends_at IS NULL OR ends_at >' ) ) {
			$now  = self::value_of( 'ends_at >', $sql ) ?? self::now();
			$rows = $this->matching( $table, $sql, [ 'user_id', 'status' ] );

			$best = null;
			foreach ( $rows as $row ) {
				$tenant = self::int_of( 'tenant_id', $sql );
				if ( $tenant > 0 && (int) $row['tenant_id'] !== $tenant ) {
					continue;
				}
				if ( null !== $row['ends_at'] && (string) $row['ends_at'] <= $now ) {
					continue;
				}
				if ( null === $best || (string) $row['ends_at'] > (string) $best['ends_at'] ) {
					$best = $row;
				}
			}

			return $best;
		}

		if ( 'vip_posts' === $table && str_contains( $sql, 'shortcode =' ) ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (string) $row['shortcode'] === self::value_of( 'shortcode', $sql ) ) {
					return $row;
				}
			}
			return null;
		}

		if ( 'vip_post_likes' === $table || 'vip_post_saves' === $table || 'vip_post_views' === $table || 'vip_entitlements' === $table ) {
			$rows = $this->matching( $table, $sql, [ 'post_id', 'user_id' ] );
			return $rows[0] ?? null;
		}

		return $this->tables[ $table ][ self::int_of( 'id', $sql ) ] ?? null;
	}

	public function get_results( string $sql, $output = null ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( '' === $table ) {
			return parent::get_results( $sql, $output );
		}

		if ( 'vip_plans' === $table ) {
			return array_values(
				array_filter(
					$this->tables[ $table ],
					static fn ( array $row ): bool => ! str_contains( $sql, 'is_active = 1' ) || 1 === (int) $row['is_active']
				)
			);
		}

		if ( 'vip_posts' === $table ) {
			$status = self::value_of( 'status =', $sql );
			$rows   = [];

			foreach ( $this->tables[ $table ] as $row ) {
				if ( null !== $status && (string) $row['status'] !== $status ) {
					continue;
				}
				// expire_due / publish_due carry a cut-off; the feed does not.
				if ( str_contains( $sql, 'expires_at <=' ) ) {
					$cutoff = self::value_of( 'expires_at <=', $sql ) ?? self::now();
					if ( null === $row['expires_at'] || (string) $row['expires_at'] > $cutoff ) {
						continue;
					}
				}
				if ( str_contains( $sql, 'publish_at <=' ) ) {
					$cutoff = self::value_of( 'publish_at <=', $sql ) ?? self::now();
					if ( null === $row['publish_at'] || (string) $row['publish_at'] > $cutoff ) {
						continue;
					}
				}
				$rows[] = $row;
			}

			return $rows;
		}

		if ( 'vip_post_comments' === $table ) {
			$post_id = self::int_of( 'post_id', $sql );
			$parent  = str_contains( $sql, 'parent_id = ' ) ? self::int_of( 'parent_id', $sql ) : null;
			$status  = self::value_of( 'status =', $sql );

			$rows = [];
			foreach ( $this->tables[ $table ] as $row ) {
				if ( $post_id > 0 && (int) $row['post_id'] !== $post_id ) {
					continue;
				}
				if ( null !== $parent && (int) $row['parent_id'] !== $parent ) {
					continue;
				}
				if ( null !== $status && (string) $row['status'] !== $status ) {
					continue;
				}
				$rows[] = $row;
			}

			return $rows;
		}

		return array_values( $this->tables[ $table ] );
	}

	public function get_col( string $sql ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( 'vip_entitlements' === $table ) {
			$user_id = self::int_of( 'user_id', $sql );
			$now     = self::now();

			$out = [];
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (int) $row['user_id'] !== $user_id || null !== ( $row['revoked_at'] ?? null ) ) {
					continue;
				}
				if ( ! str_contains( $sql, "'" . $row['post_id'] . "'" ) ) {
					continue;
				}
				if ( null !== ( $row['expires_at'] ?? null ) && (string) $row['expires_at'] <= $now ) {
					continue;
				}
				$out[] = (int) $row['post_id'];
			}

			return $out;
		}

		if ( 'vip_post_likes' === $table || 'vip_post_saves' === $table ) {
			// Two shapes reach here: the feed's "which of these ids did this user flag" IN-list,
			// and the saved-posts list, which is ordered and paged. Both are answered from the
			// statement itself — the ORDER BY direction and the LIMIT are read out of the SQL, so
			// reversing either in production makes this double disagree.
			$user_id = self::int_of( 'user_id', $sql );
			$rows    = [];

			foreach ( $this->tables[ $table ] as $row ) {
				if ( (int) $row['user_id'] !== $user_id ) {
					continue;
				}
				if ( str_contains( $sql, 'post_id IN' ) && ! str_contains( $sql, "'" . $row['post_id'] . "'" ) ) {
					continue;
				}
				$rows[] = $row;
			}

			usort(
				$rows,
				static fn ( array $a, array $b ): int => str_contains( $sql, 'ORDER BY id DESC' )
					? (int) $b['id'] <=> (int) $a['id']
					: (int) $a['id'] <=> (int) $b['id']
			);

			if ( preg_match( '/LIMIT \'?(\d+)\'? OFFSET \'?(\d+)\'?/', $sql, $m ) ) {
				$rows = array_slice( $rows, (int) $m[2], (int) $m[1] );
			}

			return array_map( static fn ( array $row ): int => (int) $row['post_id'], $rows );
		}

		if ( 'vip_memberships' === $table ) {
			$status = self::value_of( 'status =', $sql );
			$cutoff = self::value_of( 'ends_at <=', $sql ) ?? self::now();

			$out = [];
			foreach ( $this->tables[ $table ] as $row ) {
				if ( null !== $status && (string) $row['status'] !== $status ) {
					continue;
				}
				if ( null === $row['ends_at'] || (string) $row['ends_at'] > $cutoff ) {
					continue;
				}
				$out[] = (int) $row['id'];
			}

			return $out;
		}

		return parent::get_col( $sql );
	}

	public function get_var( string $sql ) {
		$this->queries[] = $sql;
		$table           = self::which( $sql );

		if ( '' === $table ) {
			return parent::get_var( $sql );
		}

		if ( str_contains( $sql, 'MAX(ends_at)' ) ) {
			$user_id = self::int_of( 'user_id', $sql );
			$now     = self::value_of( 'ends_at >', $sql ) ?? self::now();

			$max = null;
			foreach ( $this->tables['vip_memberships'] as $row ) {
				if ( (int) $row['user_id'] !== $user_id || VipAccessService::STATUS_ACTIVE !== (string) $row['status'] ) {
					continue;
				}
				if ( null === $row['ends_at'] || (string) $row['ends_at'] <= $now ) {
					continue;
				}
				if ( null === $max || (string) $row['ends_at'] > $max ) {
					$max = (string) $row['ends_at'];
				}
			}

			return $max;
		}

		if ( str_contains( $sql, 'SELECT tenant_id' ) ) {
			return (int) ( $this->tables[ $table ][ self::int_of( 'id', $sql ) ]['tenant_id'] ?? 0 );
		}

		if ( str_contains( $sql, 'SELECT views_count' ) ) {
			return (int) ( $this->tables[ $table ][ self::int_of( 'id', $sql ) ]['views_count'] ?? 0 );
		}

		if ( str_contains( $sql, 'SELECT created_at' ) ) {
			$rows = $this->matching( $table, $sql, [ 'user_id' ] );
			$last = end( $rows );
			return $last ? (string) $last['created_at'] : null;
		}

		if ( ! str_contains( $sql, 'COUNT(' ) ) {
			return parent::get_var( $sql );
		}

		// Entitlement checks carry a revocation and an expiry clause the plain matcher ignores.
		if ( 'vip_entitlements' === $table ) {
			$count = 0;
			foreach ( $this->matching( $table, $sql, [ 'user_id', 'post_id' ] ) as $row ) {
				if ( null !== ( $row['revoked_at'] ?? null ) ) {
					continue;
				}
				++$count;
			}
			return $count;
		}

		// had_membership(): anything that is not still pending counts as "has subscribed before".
		if ( 'vip_memberships' === $table && str_contains( $sql, 'status <>' ) ) {
			$excluded = self::value_of( 'status <>', $sql );
			$count    = 0;
			foreach ( $this->matching( $table, $sql, [ 'user_id' ] ) as $row ) {
				if ( (string) $row['status'] !== $excluded ) {
					++$count;
				}
			}
			return $count;
		}

		return count( $this->matching( $table, $sql, [ 'post_id', 'user_id', 'status', 'shortcode' ] ) );
	}

	// -------------------------------------------------------------- writes

	public function insert( string $table, array $data, $format = null ): int|bool {
		$short = self::which( 'igbz_' . str_replace( $this->prefix . 'igbz_', '', $table ) );
		if ( '' === $short ) {
			return parent::insert( $table, $data, $format );
		}

		// UNIQUE (post_id, user_id) on likes and views, UNIQUE (user_id, post_id) on entitlements.
		if ( in_array( $short, [ 'vip_post_likes', 'vip_post_views', 'vip_entitlements' ], true ) ) {
			foreach ( $this->tables[ $short ] as $row ) {
				if ( (int) $row['post_id'] === (int) $data['post_id'] && (int) $row['user_id'] === (int) $data['user_id'] ) {
					return false;
				}
			}
		}

		$id                            = $this->next_id++;
		$this->insert_id               = $id;
		$data['id']                    = $id;
		$this->tables[ $short ][ $id ] = $data;

		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$short = self::which( 'igbz_' . str_replace( $this->prefix . 'igbz_', '', $table ) );
		if ( '' === $short ) {
			return parent::update( $table, $data, $where, $format, $where_format );
		}

		$changed = 0;
		foreach ( $this->tables[ $short ] as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			$this->tables[ $short ][ $id ] = array_merge( $row, $data );
			++$changed;
		}

		return $changed;
	}

	public function delete( string $table, array $where, $where_format = null ): int|bool {
		$short = self::which( 'igbz_' . str_replace( $this->prefix . 'igbz_', '', $table ) );
		if ( '' === $short ) {
			return parent::delete( $table, $where, $where_format );
		}

		$removed = 0;
		foreach ( $this->tables[ $short ] as $id => $row ) {
			foreach ( $where as $column => $value ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
					continue 2;
				}
			}
			unset( $this->tables[ $short ][ $id ] );
			++$removed;
		}

		return $removed;
	}

	public function query( string $sql ): int|bool {
		$this->queries[] = $sql;

		if ( preg_match( "/UPDATE \S*igbz_vip_post_views SET view_count = view_count \+ 1 WHERE id = '?(\d+)'?/", $sql, $m ) ) {
			$id = (int) $m[1];
			if ( isset( $this->tables['vip_post_views'][ $id ] ) ) {
				++$this->tables['vip_post_views'][ $id ]['view_count'];
				return 1;
			}
			return 0;
		}

		return parent::query( $sql );
	}
}

final class VipChannelTest extends TestCase {

	private VipDb $db;

	private VipAccessService $access;

	private VipPostService $posts;

	private VipSocialService $social;

	private VipBillingService $billing;

	private VipMediaService $media;

	private function boot(): void {
		$settings = igbz_test_reset_settings();

		$this->db        = new VipDb();
		$GLOBALS['wpdb'] = $this->db;

		// Every VIP setting the services read, pinned so a changed default cannot move a test.
		$settings->set_many(
			[
				'vip.enabled'               => true,
				'vip.media_hmac_secret'     => 'test-vip-secret',
				'vip.media_link_ttl'        => 900,
				'vip.default_expiry_days'   => 7,
				'vip.default_expiry_action' => VipPostService::EXPIRY_DELETE,
				'vip.purge_media_on_expiry' => true,
				'vip.comments_enabled'      => true,
				'vip.comment_rate_seconds'  => 0,
				'vip.tips_enabled'          => true,
				'vip.tip_min'               => 10000,
			]
		);

		$db     = new Db();
		$logger = igbz()->get( 'logger' );

		$this->access  = new VipAccessService( $db );
		$this->media   = new VipMediaService( $db, $settings, $logger );
		$this->posts   = new VipPostService( $db, $settings, $logger, $this->access, $this->media );
		$this->social  = new VipSocialService( $db, $settings, $this->access );
		$this->billing = new VipBillingService( $db, $settings, $logger, $this->access );

		// The media responder resolves the access service out of the container at serve time.
		igbz()->bind( 'vip.access', fn () => $this->access );
	}

	public function run(): void {
		$this->test_a_free_post_is_open_to_a_stranger();
		$this->test_a_members_post_asks_an_anonymous_visitor_to_sign_in();
		$this->test_a_member_gets_in();
		$this->test_a_lapsed_member_is_told_the_membership_expired();
		$this->test_a_purchase_outlives_the_membership();
		$this->test_a_member_never_pays_twice_for_a_single_post();
		$this->test_an_expired_post_is_gone_for_everybody();
		$this->test_the_author_sees_their_own_draft();
		$this->test_a_locked_post_leaks_nothing_but_the_blur();
		$this->test_an_unlocked_post_is_served_through_a_signed_link();
		$this->test_a_signed_link_is_bound_to_its_post_item_viewer_and_clock();
		$this->test_publishing_recomputes_the_expiry();
		$this->test_expiry_hides_or_removes_as_the_post_says();
		$this->test_renewing_early_keeps_the_days_already_paid_for();
		$this->test_settlement_grants_exactly_what_was_bought();
		$this->test_a_revoked_entitlement_locks_the_post_again();
		$this->test_a_tip_under_the_floor_never_reaches_a_gateway();
		$this->test_commenting_is_gated_on_the_same_rule_as_viewing();
		$this->test_a_second_like_from_the_same_person_does_not_count_twice();
		$this->test_the_share_page_cover_falls_back_to_the_blur();
		$this->test_the_deep_link_survives_url_escaping();
		$this->test_a_new_post_inherits_the_platform_expiry_window();
		$this->test_the_shipped_defaults_carry_the_ratified_policy();
		$this->test_the_share_page_warns_the_buyer_before_they_pay();
		$this->test_saving_needs_access_and_toggles();
		$this->test_the_offline_copy_marks_the_save_and_survives_the_purge();
	}

	// --------------------------------------------------- the share page

	/**
	 * Found by clicking through the real site: an unlocked post rendered no cover at all.
	 *
	 * Every media row carries a `thumb` key, usually holding an empty string, so `$m['thumb'] ??
	 * $m['blur']` never reached the blur — the coalesce only fires on a missing key, not an empty
	 * one. A public post therefore showed a blank frame where its picture should be.
	 */
	private function test_the_share_page_cover_falls_back_to_the_blur(): void {
		$this->boot();
		$page = $this->landing();

		$no_thumb = [ 'type' => 'image', 'url' => 'https://cdn.test/real.jpg', 'thumb' => '', 'blur' => 'https://cdn.test/blur.jpg' ];

		$this->assert_same(
			'https://cdn.test/blur.jpg',
			$page->cover_src( $no_thumb, true ),
			'An unlocked post with no thumbnail still shows something — the blur'
		);
		$this->assert_same(
			'https://cdn.test/blur.jpg',
			$page->cover_src( $no_thumb, false ),
			'and a locked one shows the blur and only the blur'
		);
		$this->assert_same(
			'https://cdn.test/thumb.jpg',
			$page->cover_src( [ 'thumb' => 'https://cdn.test/thumb.jpg', 'blur' => 'https://cdn.test/blur.jpg' ], true ),
			'A real thumbnail is preferred when there is one'
		);
		$this->assert_same(
			'https://cdn.test/blur.jpg',
			$page->cover_src( [ 'thumb' => 'https://cdn.test/thumb.jpg', 'blur' => 'https://cdn.test/blur.jpg' ], false ),
			'but never on a locked post, however tempting'
		);
	}

	/**
	 * Also found on the real site: the "Open in the app" button had an empty href.
	 *
	 * esc_url() drops any scheme not on its allow-list, and an app deep link is a custom scheme by
	 * definition. The page has to widen the list with its own scheme, or the one button whose whole
	 * job is to open the app goes nowhere.
	 */
	private function test_the_deep_link_survives_url_escaping(): void {
		$this->boot();
		igbz()->settings()->set( 'vip.deep_link_scheme', 'igbz' );
		$page = $this->landing();

		$link = $page->deep_link( 'AbC123' );

		$this->assert_same( 'igbz://vip/p/AbC123', $link, 'The deep link is built from the configured scheme' );
		$this->assert_same( '', esc_url( $link ), 'Core would throw it away' );
		$this->assert_same( $link, esc_url( $link, $page->allowed_schemes() ), 'so the page allows its own scheme through' );

		// A scheme with a space or a slash in it would break the URL; it is filtered, not trusted.
		igbz()->settings()->set( 'vip.deep_link_scheme', 'my app://evil' );
		$this->assert_same(
			'myappevil://vip/p/AbC123',
			$this->landing()->deep_link( 'AbC123' ),
			'A scheme typed with junk in it is stripped down to something legal'
		);
	}

	private function landing(): \IGBZ\Suite\Modules\Instagram\Vip\VipLandingPage {
		return new \IGBZ\Suite\Modules\Instagram\Vip\VipLandingPage(
			$this->posts,
			$this->access,
			$this->billing,
			igbz()->settings()
		);
	}

	// ------------------------------------------------- the expiry policy

	/**
	 * The window belongs to the platform, not to the post.
	 *
	 * The client ratified a central retention period (a week by default) set by the IGBZ senior
	 * admin, after which the post really leaves the server. Two things have to hold for that to
	 * mean anything: a post created without an expiry has to inherit the platform's, and the date
	 * has to be counted from the moment it is actually published — a draft written on Monday and
	 * published on Friday gets its full week from Friday.
	 */
	private function test_a_new_post_inherits_the_platform_expiry_window(): void {
		$this->boot();

		$id   = $this->posts->create( [ 'caption' => 'Sunday drop', 'tenant_id' => 1, 'author_id' => 99 ] );
		$post = $this->db->get( 'vip_posts', $id );

		$this->assert_same( 7, $this->posts->retention_days(), 'The default window is a week' );
		$this->assert_same(
			VipPostService::EXPIRY_DELETE,
			(string) $post['expiry_action'],
			'and a new post is set to be removed, not hidden — that is what the buyer is promised'
		);

		$expected = gmdate( 'Y-m-d', time() + ( 7 * DAY_IN_SECONDS ) );
		$this->assert_contains(
			$expected,
			(string) $post['expires_at'],
			'A post created with no date of its own inherits the platform window'
		);

		// Publish a draft that has been sitting around: the clock restarts at publication.
		$draft = $this->posts->create( [ 'caption' => 'Written early', 'tenant_id' => 1, 'author_id' => 99 ] );
		$this->db->tables['vip_posts'][ $draft ]['expires_at']   = null;
		$this->db->tables['vip_posts'][ $draft ]['status']       = VipPostService::STATUS_DRAFT;
		$this->db->tables['vip_posts'][ $draft ]['published_at'] = null;

		$this->posts->publish( $draft );

		$this->assert_contains(
			$expected,
			(string) $this->db->get( 'vip_posts', $draft )['expires_at'],
			'and publishing a stale draft counts the week from the publish moment'
		);

		$notice = $this->posts->expiry_notice( (string) $this->db->get( 'vip_posts', $draft )['expires_at'] );
		$this->assert_contains( 'removed from the server', $notice, 'The one shared sentence says where the post goes' );
		$this->assert_contains( 'save icon', $notice, 'and how the customer keeps their own copy' );
		$this->assert_same( '', $this->posts->expiry_notice( null ), 'A post with no expiry says nothing rather than "expires never"' );
	}

	/**
	 * The policy has to be what a fresh install actually gets.
	 *
	 * Every other test here pins the VIP settings on purpose, so that a changed default cannot
	 * quietly move an assertion — which also means none of them would notice the shipped default
	 * drifting away from the ratified policy. This one reads what `seed_defaults()` really writes.
	 */
	private function test_the_shipped_defaults_carry_the_ratified_policy(): void {
		$settings = igbz_test_reset_settings();
		$GLOBALS['wpdb'] = new VipDb();

		\IGBZ\Suite\Support\Activator::seed_defaults();

		$this->assert_same( 7, $settings->int( 'vip.default_expiry_days', 0 ), 'A fresh install expires VIP posts after a week' );
		$this->assert_same(
			VipPostService::EXPIRY_DELETE,
			$settings->string( 'vip.default_expiry_action', '' ),
			'and removes them rather than hiding them — the buyer was promised the post leaves the server'
		);
		$this->assert_true( $settings->bool( 'vip.purge_media_on_expiry', false ), 'with the file itself deleted' );
		$this->assert_true( $settings->int( 'vip.offline_link_ttl', 0 ) > 0, 'and a download window long enough to keep a copy' );
	}

	/**
	 * The buyer is told before they pay, not after.
	 *
	 * The whole risk in "the post is deleted in a week" is somebody paying for a single post on
	 * day six. The warning names the date and points at the way to keep a copy, and it is rendered
	 * from the offers block, above the buttons — that placement is checked on the live site,
	 * because `render()` ends in `exit` and cannot be called from a test process.
	 */
	private function test_the_share_page_warns_the_buyer_before_they_pay(): void {
		$this->boot();

		$expires = gmdate( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) );
		$post    = $this->db->get(
			'vip_posts',
			$this->db->seed_post(
				[
					'access'        => VipAccessService::ACCESS_PURCHASE,
					'price'         => 90000.0,
					'expires_at'    => $expires,
					'expiry_action' => VipPostService::EXPIRY_DELETE,
				]
			)
		);

		$warning = new \ReflectionMethod( \IGBZ\Suite\Modules\Instagram\Vip\VipLandingPage::class, 'expiry_warning' );
		$page    = $this->landing();

		ob_start();
		$warning->invoke( $page, $post, true );
		$before_buying = (string) ob_get_clean();

		$this->assert_contains( 'igbz-vip-expiry-warning', $before_buying, 'The share page carries the expiry warning' );
		$this->assert_contains( 'is then removed from the server', $before_buying, 'stating plainly that the post goes away' );
		$this->assert_contains(
			wp_date( 'Y-m-d', (int) strtotime( $expires . ' UTC' ) ),
			$before_buying,
			'and naming the date it happens, in the shop\'s own timezone rather than UTC'
		);
		$this->assert_contains( 'After buying, tap the save icon', $before_buying, 'then telling the buyer how to keep a copy' );

		ob_start();
		$warning->invoke( $page, $post, false );
		$already_owned = (string) ob_get_clean();

		$this->assert_contains( 'save icon on the post', $already_owned, 'A member who already owns it gets the same advice' );
		$this->assert_true(
			! str_contains( $already_owned, 'After buying' ),
			'without being told to buy something they have already bought'
		);

		ob_start();
		$warning->invoke( $page, array_merge( $post, [ 'expires_at' => null ] ), true );
		$this->assert_same( '', trim( (string) ob_get_clean() ), 'A post with no expiry says nothing at all' );
	}

	/**
	 * Saving is gated on the same rule as viewing, and it toggles.
	 *
	 * A bookmark on a post the customer cannot open would promise a copy that can never be
	 * fetched, so the entitlement check is the same one the feed uses.
	 */
	private function test_saving_needs_access_and_toggles(): void {
		$this->boot();

		$locked = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_MEMBERS ] );
		$free   = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_FREE ] );

		$refused = false;
		try {
			$this->social->toggle_save( $locked, 7 );
		} catch ( \RuntimeException $e ) {
			$refused = 'igbz_vip_locked' === $e->getMessage();
		}
		$this->assert_true( $refused, 'A post the customer cannot open cannot be saved either' );
		$this->assert_false( $this->social->has_saved( $locked, 7 ), 'and nothing was written' );

		$first = $this->social->toggle_save( $free, 7 );
		$this->assert_true( $first['saved'], 'Saving an open post works' );
		$this->assert_true( $this->social->has_saved( $free, 7 ), 'and it is remembered' );
		$this->assert_same( [ $free ], $this->social->saved_post_ids( 7 ), 'and it shows up in the saved list' );
		$this->assert_same( 1, $this->social->saved_count( 7 ), 'which knows how long it is' );

		$second = $this->social->toggle_save( $free, 7 );
		$this->assert_false( $second['saved'], 'Tapping the icon again unsaves it' );
		$this->assert_same( [], $this->social->saved_post_ids( 7 ), 'and the list is empty again' );
		$this->assert_same( 0, $this->social->saved_count( 8 ), 'One member saving says nothing about another' );
	}

	/**
	 * The saved row survives the purge — which is the entire point.
	 *
	 * The post is removed from the server on schedule; what the customer was promised is that
	 * their own copy stays. `offline_at` is how the app knows which saves are backed by real bytes
	 * and which are only bookmarks, so it must be stamped when the download endpoint hands the
	 * media over and must still be there after the sweep has run.
	 */
	private function test_the_offline_copy_marks_the_save_and_survives_the_purge(): void {
		$this->boot();

		$id = $this->db->seed_post(
			[
				'access'        => VipAccessService::ACCESS_FREE,
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'expiry_action' => VipPostService::EXPIRY_DELETE,
			]
		);

		// Downloading implies saving: the customer taps one button, not two.
		$this->social->mark_offline( $id, 12 );
		$saved = $this->db->all( 'vip_post_saves' );

		$this->assert_same( 1, count( $saved ), 'Fetching the offline copy records the save' );
		$this->assert_false( null === reset( $saved )['offline_at'], 'and stamps when the bytes were handed over' );

		$this->posts->expire_due();

		$post = $this->db->get( 'vip_posts', $id );
		$this->assert_same( VipPostService::STATUS_DELETED, (string) $post['status'], 'The post is gone from the server' );
		$this->assert_same( '[]', (string) $post['media'], 'and so is its media' );
		$this->assert_same( 1, count( $this->db->all( 'vip_post_saves' ) ), 'but the record of the customer keeping a copy is untouched' );

		$this->assert_contains(
			'has expired',
			$this->posts->expiry_notice( (string) $post['expires_at'] ),
			'and the app is told, in one sentence, why the post is no longer there'
		);
	}

	// ------------------------------------------------------------- access

	private function test_a_free_post_is_open_to_a_stranger(): void {
		$this->boot();
		$id = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_FREE ] );

		$access = $this->access->check( 0, $id );

		$this->assert_true( $access->allowed, 'A free post opens for a signed-out visitor' );
		$this->assert_same( VipAccess::ALLOW_FREE, $access->reason, 'and says why it opened' );
	}

	private function test_a_members_post_asks_an_anonymous_visitor_to_sign_in(): void {
		$this->boot();
		$this->db->seed_plan();
		$id = $this->db->seed_post();

		$access = $this->access->check( 0, $id );

		$this->assert_false( $access->allowed, 'A members-only post stays shut for a stranger' );
		$this->assert_same( VipAccess::DENY_ANONYMOUS, $access->reason, 'and the app is told to show the sign-in screen' );
		$this->assert_true( $access->can_subscribe(), 'The plans travel with the refusal, so the page has something to sell' );
		$this->assert_false( $access->can_buy_single(), 'A members-only post is not for sale on its own' );
	}

	private function test_a_member_gets_in(): void {
		$this->boot();
		$id = $this->db->seed_post();
		$this->db->seed_membership( [ 'user_id' => 7 ] );

		$access = $this->access->check( 7, $id );

		$this->assert_true( $access->allowed, 'An active member opens a members-only post' );
		$this->assert_same( VipAccess::ALLOW_MEMBERSHIP, $access->reason, 'through the membership' );
	}

	private function test_a_lapsed_member_is_told_the_membership_expired(): void {
		$this->boot();
		$this->db->seed_plan();
		$id = $this->db->seed_post();
		$this->db->seed_membership(
			[
				'user_id' => 7,
				'status'  => VipAccessService::STATUS_EXPIRED,
				'ends_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			]
		);

		$access = $this->access->check( 7, $id );

		$this->assert_false( $access->allowed, 'A lapsed membership does not open the post' );
		$this->assert_same(
			VipAccess::DENY_EXPIRED,
			$access->reason,
			'and "your membership ran out" is a different screen from "you were never a member"'
		);
	}

	private function test_a_purchase_outlives_the_membership(): void {
		$this->boot();
		$id = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_PURCHASE, 'price' => 50000.0 ] );

		$this->db->seed(
			'vip_entitlements',
			[
				'tenant_id'  => 1,
				'user_id'    => 7,
				'post_id'    => $id,
				'source'     => VipBillingService::SOURCE_PURCHASE,
				'payment_id' => 0,
				'price_paid' => 50000.0,
				'expires_at' => null,
				'revoked_at' => null,
			]
		);

		$access = $this->access->check( 7, $id );

		$this->assert_true( $access->allowed, 'Somebody who bought the post keeps it with no membership at all' );
		$this->assert_same( VipAccess::ALLOW_PURCHASE, $access->reason, 'and the reason names the purchase, not a membership' );
	}

	private function test_a_member_never_pays_twice_for_a_single_post(): void {
		$this->boot();
		$id = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_PURCHASE, 'price' => 50000.0 ] );
		$this->db->seed_membership( [ 'user_id' => 7 ] );

		$access = $this->access->check( 7, $id );

		$this->assert_true( $access->allowed, 'A membership unlocks a pay-per-view post too' );
		$this->assert_same( VipAccess::ALLOW_MEMBERSHIP, $access->reason, 'without asking the member to buy it again' );

		$result = $this->billing->purchase_post( 7, $id );
		$this->assert_true( $result['ok'], 'Buying it anyway is not an error' );
	}

	private function test_an_expired_post_is_gone_for_everybody(): void {
		$this->boot();
		$id = $this->db->seed_post( [ 'status' => VipPostService::STATUS_EXPIRED ] );
		$this->db->seed_membership( [ 'user_id' => 7 ] );

		$access = $this->access->check( 7, $id );

		$this->assert_false( $access->allowed, 'An expired post does not open, membership or not' );
		$this->assert_same( VipAccess::DENY_GONE, $access->reason, 'and the reason is the post, not the viewer' );
		$this->assert_false( $access->can_subscribe(), 'Selling a membership on a post that no longer exists would be a lie' );
		$this->assert_false( $access->can_buy_single(), 'and so would selling the post itself' );
	}

	private function test_the_author_sees_their_own_draft(): void {
		$this->boot();
		$id = $this->db->seed_post( [ 'status' => VipPostService::STATUS_DRAFT, 'author_id' => 42 ] );

		$this->assert_true( $this->access->check( 42, $id )->allowed, 'The author can open their own unpublished post' );
		$this->assert_same(
			VipAccess::DENY_UNPUBLISHED,
			$this->access->check( 7, $id )->reason,
			'while everybody else is told it is not published, not that they need to pay'
		);
	}

	// -------------------------------------------------------------- media

	private function test_a_locked_post_leaks_nothing_but_the_blur(): void {
		$this->boot();
		$this->db->seed_plan();
		$id = $this->db->seed_post();

		$row      = (array) $this->posts->post( $id );
		$rendered = $this->posts->present( $row, $this->access->check_row( 0, $row ) );
		$json     = (string) wp_json_encode( $rendered );

		$this->assert_true( $rendered['locked'], 'The payload says the post is locked' );
		$this->assert_same( 'Behind the scenes', $rendered['caption'], 'The caption still travels — it is what sells the post' );
		$this->assert_false( str_contains( $json, 'real.jpg' ), 'The real file never appears in a locked payload' );
		$this->assert_false( str_contains( $json, 'thumb.jpg' ), 'and neither does its thumbnail' );
		$this->assert_true( str_contains( $json, 'blur.jpg' ), 'only the stored placeholder does' );
		$this->assert_same( 1, $rendered['media_count'], 'The number of items is not a secret' );
	}

	private function test_an_unlocked_post_is_served_through_a_signed_link(): void {
		$this->boot();
		$id = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_FREE ] );

		$GLOBALS['igbz_test_user_id'] = 7;
		$row                          = (array) $this->posts->post( $id );
		$rendered                     = $this->posts->present( $row, $this->access->check_row( 7, $row ) );
		$url                          = (string) $rendered['media'][0]['url'];
		$GLOBALS['igbz_test_user_id'] = 0;

		$this->assert_false( $rendered['locked'], 'A free post is not locked' );
		$this->assert_false( str_contains( $url, 'cdn.test' ), 'Even unlocked, the storage URL is not handed to the client' );
		$this->assert_contains( 'igbz_vip_media=' . $id, $url, 'The app is given a link through our own responder' );
		$this->assert_contains( 's=', $url, 'carrying a signature' );
	}

	private function test_a_signed_link_is_bound_to_its_post_item_viewer_and_clock(): void {
		$this->boot();
		$post_id = $this->db->seed_post();
		$expires = time() + 900;

		$url = $this->media->signed_url( $post_id, 0, 7 );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$signature = (string) ( $query['s'] ?? '' );
		$issued    = (int) ( $query['e'] ?? $expires );

		$this->assert_true( $this->media->verify( $post_id, 0, 7, $issued, $signature ), 'The link it minted verifies' );
		$this->assert_false( $this->media->verify( $post_id, 1, 7, $issued, $signature ), 'but not for the next item in the carousel' );
		$this->assert_false( $this->media->verify( $post_id + 1, 0, 7, $issued, $signature ), 'nor for another post' );
		$this->assert_false( $this->media->verify( $post_id, 0, 8, $issued, $signature ), 'nor for another viewer' );
		$this->assert_false(
			$this->media->verify( $post_id, 0, 7, $issued + 60, $signature ),
			'and the expiry is signed too, so it cannot be pushed forward'
		);
		$this->assert_false(
			$this->media->verify( $post_id, 0, 7, time() - 10, $this->media_signature( $post_id, 0, 7, time() - 10 ) ),
			'A correctly signed link that has already expired is still refused'
		);
	}

	private function media_signature( int $post_id, int $index, int $user_id, int $expires ): string {
		return \IGBZ\Suite\Support\Crypto::hmac( "vip|{$post_id}|{$index}|{$user_id}|{$expires}", 'test-vip-secret' );
	}

	// --------------------------------------------------------------- clock

	private function test_publishing_recomputes_the_expiry(): void {
		$this->boot();
		igbz()->settings()->set( 'vip.default_expiry_days', 7 );

		// Drafted a fortnight ago. Publishing it today must give it seven days from today, not
		// seven days from the day somebody happened to write it.
		$id = $this->db->seed_post(
			[
				'status'       => VipPostService::STATUS_DRAFT,
				'published_at' => null,
				'expires_at'   => null,
				'created_at'   => gmdate( 'Y-m-d H:i:s', time() - ( 14 * DAY_IN_SECONDS ) ),
			]
		);

		$this->posts->publish( $id );
		$row = $this->db->get( 'vip_posts', $id );

		$expected = gmdate( 'Y-m-d', time() + ( 7 * DAY_IN_SECONDS ) );
		$this->assert_same(
			$expected,
			substr( (string) $row['expires_at'], 0, 10 ),
			'The expiry runs from the publish moment'
		);
		$this->assert_same( VipPostService::STATUS_PUBLISHED, (string) $row['status'], 'and the post is live' );
	}

	private function test_expiry_hides_or_removes_as_the_post_says(): void {
		$this->boot();
		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$hidden  = $this->db->seed_post( [ 'expires_at' => $past, 'expiry_action' => VipPostService::EXPIRY_HIDE ] );
		$deleted = $this->db->seed_post( [ 'expires_at' => $past, 'expiry_action' => VipPostService::EXPIRY_DELETE ] );
		$living  = $this->db->seed_post( [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ] );

		$count = $this->posts->expire_due();

		$this->assert_same( 2, $count, 'Only the two posts past their date expired' );
		$this->assert_same( VipPostService::STATUS_EXPIRED, (string) $this->db->get( 'vip_posts', $hidden )['status'], 'The "hide" post is marked expired' );
		$this->assert_same( VipPostService::STATUS_DELETED, (string) $this->db->get( 'vip_posts', $deleted )['status'], 'and the "delete" post is marked deleted' );
		$this->assert_same( VipPostService::STATUS_PUBLISHED, (string) $this->db->get( 'vip_posts', $living )['status'], 'while a post still in date is untouched' );
		$this->assert_same( '[]', (string) $this->db->get( 'vip_posts', $hidden )['media'], 'The media reference is dropped either way — the file is what costs disk' );
		$this->assert_false(
			null === $this->db->get( 'vip_posts', $hidden )['expired_at'],
			'and when it happened is recorded'
		);
	}

	// --------------------------------------------------------------- money

	private function test_renewing_early_keeps_the_days_already_paid_for(): void {
		$this->boot();
		$plan_id = $this->db->seed_plan( [ 'duration_days' => 30 ] );

		$ends_at = gmdate( 'Y-m-d H:i:s', time() + ( 10 * DAY_IN_SECONDS ) );
		$this->db->seed_membership( [ 'user_id' => 7, 'plan_id' => $plan_id, 'ends_at' => $ends_at ] );

		$renewal = $this->db->seed_membership(
			[
				'user_id' => 7,
				'plan_id' => $plan_id,
				'status'  => VipAccessService::STATUS_PENDING,
				'ends_at' => null,
			]
		);

		$this->billing->activate_membership( $renewal, 0 );
		$row = $this->db->get( 'vip_memberships', $renewal );

		$expected = gmdate( 'Y-m-d', strtotime( $ends_at ) + ( 30 * DAY_IN_SECONDS ) );
		$this->assert_same(
			$expected,
			substr( (string) $row['ends_at'], 0, 10 ),
			'The renewal extends from the end of the current term, not from today'
		);
		$this->assert_same( VipAccessService::STATUS_ACTIVE, (string) $row['status'], 'and the renewal is active' );
	}

	private function test_settlement_grants_exactly_what_was_bought(): void {
		$this->boot();
		$post_id = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_PURCHASE, 'price' => 50000.0 ] );

		$payment_id = $this->db->seed(
			'payments',
			[
				'tenant_id' => 1,
				'user_id'   => 7,
				'purpose'   => VipBillingService::PURPOSE_POST,
				'amount'    => 50000.0,
				'status'    => 'paid',
				'meta'      => wp_json_encode( [ 'post_id' => $post_id, 'user_id' => 7 ] ),
			]
		);

		$this->assert_false( $this->access->check( 7, $post_id )->allowed, 'Before the money lands, the post is locked' );

		$this->billing->on_payment_verified( $payment_id );

		$this->assert_true( $this->access->check( 7, $post_id )->allowed, 'Once the gateway confirms, the post opens' );
		$this->assert_false( $this->access->check( 8, $post_id )->allowed, 'for the buyer only' );
	}

	private function test_a_revoked_entitlement_locks_the_post_again(): void {
		$this->boot();
		$post_id = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_PURCHASE, 'price' => 50000.0 ] );

		$this->billing->grant_entitlement( 7, $post_id, VipBillingService::SOURCE_PURCHASE, 0, 50000.0 );
		$this->assert_true( $this->access->check( 7, $post_id )->allowed, 'A granted entitlement opens the post' );

		$this->billing->revoke_entitlement( 7, $post_id );
		$this->assert_false( $this->access->check( 7, $post_id )->allowed, 'and a refund closes it again' );

		// A re-purchase must reuse the row: UNIQUE (user_id, post_id) would reject a second insert
		// and the member would pay for nothing.
		$this->billing->grant_entitlement( 7, $post_id, VipBillingService::SOURCE_PURCHASE, 0, 50000.0 );
		$this->assert_true( $this->access->check( 7, $post_id )->allowed, 'Buying it again works after a refund' );
		$this->assert_same( 1, count( $this->db->all( 'vip_entitlements' ) ), 'without leaving a duplicate row behind' );
	}

	private function test_a_tip_under_the_floor_never_reaches_a_gateway(): void {
		$this->boot();
		$post_id = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_FREE ] );

		$result = $this->billing->tip( 0, 500.0, $post_id );

		$this->assert_false( $result['ok'], 'A tip below the minimum is refused' );
		$this->assert_same( 0, (int) $result['payment_id'], 'before any payment row is created' );

		igbz()->settings()->set( 'vip.tips_enabled', false );
		$off = $this->billing->tip( 0, 50000.0, $post_id );
		$this->assert_false( $off['ok'], 'and turning tips off turns them off' );
	}

	// -------------------------------------------------------------- social

	private function test_commenting_is_gated_on_the_same_rule_as_viewing(): void {
		$this->boot();
		$locked = $this->db->seed_post();

		$refused = false;
		try {
			$this->social->add_comment( $locked, 7, 'Looks great' );
		} catch ( \RuntimeException $e ) {
			$refused = true;
		}

		$this->assert_true(
			$refused,
			'A non-member cannot comment on a locked post — the thread would be a free preview of what members are saying'
		);

		$this->db->seed_membership( [ 'user_id' => 7 ] );
		$id = $this->social->add_comment( $locked, 7, 'Looks great' );
		$this->assert_true( $id > 0, 'A member can' );

		// The admin answers from the dashboard, where the member gate does not apply.
		$reply = $this->social->add_comment( $locked, 99, 'Thank you!', $id, true );
		$this->assert_true( $reply > 0, 'and the page can always reply' );
		$this->assert_same( $id, (int) $this->db->get( 'vip_post_comments', $reply )['parent_id'], 'as a reply to that comment' );
	}

	private function test_a_second_like_from_the_same_person_does_not_count_twice(): void {
		$this->boot();
		$post_id = $this->db->seed_post( [ 'access' => VipAccessService::ACCESS_FREE ] );

		$first = $this->social->toggle_like( $post_id, 7 );
		$this->assert_true( $first['liked'], 'The first tap likes the post' );
		$this->assert_same( 1, $first['likes_count'], 'and counts once' );

		$second = $this->social->toggle_like( $post_id, 7 );
		$this->assert_false( $second['liked'], 'The second tap unlikes it' );
		$this->assert_same( 0, $second['likes_count'], 'and the count follows the rows, not an increment' );

		$this->social->toggle_like( $post_id, 7 );
		$this->social->toggle_like( $post_id, 8 );
		$this->assert_same(
			2,
			(int) $this->db->get( 'vip_posts', $post_id )['likes_count'],
			'Two people, two likes, written back to the post'
		);
	}
}
