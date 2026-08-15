<?php
namespace IGBZ\Suite\Modules\Instagram\Vip;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that decides who may see a VIP post.
 *
 * Every read path — feed, single post, media link, comments — funnels through check(). Access rules
 * that live in more than one place drift apart, and the failure mode is silent: a post stays locked
 * in the feed but its media URL is servable, or vice versa.
 */
final class VipAccessService {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_PENDING   = 'pending';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_REFUNDED  = 'refunded';

	public const ACCESS_FREE     = 'free';
	public const ACCESS_MEMBERS  = 'members';
	public const ACCESS_PURCHASE = 'purchase';

	public function __construct( private Db $db ) {}

	/**
	 * @param array<string,mixed>|null $post Pass a row you already loaded to avoid a second query.
	 */
	public function check( int $user_id, int $post_id, ?array $post = null ): VipAccess {
		$post ??= $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE id = %d',
			$post_id
		);

		if ( ! $post ) {
			return VipAccess::deny( VipAccess::DENY_MISSING );
		}

		return $this->check_row( $user_id, $post );
	}

	/**
	 * @param array<string,mixed> $post
	 */
	public function check_row( int $user_id, array $post ): VipAccess {
		$post_id = (int) $post['id'];
		$status  = (string) $post['status'];

		if ( VipPostService::STATUS_DELETED === $status ) {
			return VipAccess::deny( VipAccess::DENY_MISSING );
		}
		if ( VipPostService::STATUS_EXPIRED === $status ) {
			return VipAccess::deny( VipAccess::DENY_GONE );
		}

		// The author and anyone who can manage the channel always sees their own work, including
		// drafts and scheduled rows. This is checked before the publish gate on purpose.
		if ( $user_id > 0 && $this->is_manager( $user_id, $post ) ) {
			return VipAccess::allow( VipAccess::ALLOW_AUTHOR );
		}

		if ( VipPostService::STATUS_PUBLISHED !== $status ) {
			return VipAccess::deny( VipAccess::DENY_UNPUBLISHED );
		}

		$access = (string) $post['access'];

		if ( self::ACCESS_FREE === $access ) {
			return VipAccess::allow( VipAccess::ALLOW_FREE );
		}

		if ( $user_id <= 0 ) {
			return VipAccess::deny(
				VipAccess::DENY_ANONYMOUS,
				(float) $post['price'],
				$this->plans( (int) $post['tenant_id'] )
			);
		}

		// A single-post purchase is checked first because it outlives the membership: someone who
		// bought one post keeps it after their subscription lapses.
		if ( $this->has_entitlement( $user_id, $post_id ) ) {
			return VipAccess::allow( VipAccess::ALLOW_PURCHASE );
		}

		$membership = $this->active_membership( $user_id, (int) $post['tenant_id'] );

		if ( self::ACCESS_MEMBERS === $access ) {
			if ( $membership ) {
				return VipAccess::allow( VipAccess::ALLOW_MEMBERSHIP );
			}
			return VipAccess::deny(
				$this->had_membership( $user_id, (int) $post['tenant_id'] ) ? VipAccess::DENY_EXPIRED : VipAccess::DENY_NO_MEMBER,
				(float) $post['price'],
				$this->plans( (int) $post['tenant_id'] )
			);
		}

		// ACCESS_PURCHASE: pay-per-view. A membership still unlocks it — members should not be asked
		// to pay twice — but non-members must buy the post itself.
		if ( $membership ) {
			return VipAccess::allow( VipAccess::ALLOW_MEMBERSHIP );
		}

		return VipAccess::deny(
			VipAccess::DENY_UNPURCHASED,
			(float) $post['price'],
			$this->plans( (int) $post['tenant_id'] )
		);
	}

	/**
	 * Bulk variant for the feed. One query per relation instead of one per post.
	 *
	 * @param array<int,array<string,mixed>> $posts
	 * @return array<int,VipAccess> keyed by post id
	 */
	public function check_many( int $user_id, array $posts ): array {
		if ( [] === $posts ) {
			return [];
		}

		$owned      = $user_id > 0 ? $this->entitled_post_ids( $user_id, array_map( static fn( $p ) => (int) $p['id'], $posts ) ) : [];
		$membership = [];
		$out        = [];

		foreach ( $posts as $post ) {
			$post_id = (int) $post['id'];
			$tenant  = (int) $post['tenant_id'];

			if ( $user_id > 0 && isset( $owned[ $post_id ] ) ) {
				$out[ $post_id ] = VipAccess::allow( VipAccess::ALLOW_PURCHASE );
				continue;
			}

			// Cache the membership lookup per tenant: a feed is nearly always one tenant, so this
			// collapses to a single query however many posts come back.
			if ( $user_id > 0 && ! array_key_exists( $tenant, $membership ) ) {
				$membership[ $tenant ] = $this->active_membership( $user_id, $tenant );
			}

			$out[ $post_id ] = $this->check_row_with( $user_id, $post, $membership[ $tenant ] ?? null );
		}

		return $out;
	}

	/**
	 * @param array<string,mixed>      $post
	 * @param array<string,mixed>|null $membership
	 */
	private function check_row_with( int $user_id, array $post, ?array $membership ): VipAccess {
		$status = (string) $post['status'];

		if ( VipPostService::STATUS_DELETED === $status ) {
			return VipAccess::deny( VipAccess::DENY_MISSING );
		}
		if ( VipPostService::STATUS_EXPIRED === $status ) {
			return VipAccess::deny( VipAccess::DENY_GONE );
		}
		if ( $user_id > 0 && $this->is_manager( $user_id, $post ) ) {
			return VipAccess::allow( VipAccess::ALLOW_AUTHOR );
		}
		if ( VipPostService::STATUS_PUBLISHED !== $status ) {
			return VipAccess::deny( VipAccess::DENY_UNPUBLISHED );
		}

		$access = (string) $post['access'];
		if ( self::ACCESS_FREE === $access ) {
			return VipAccess::allow( VipAccess::ALLOW_FREE );
		}
		if ( $user_id <= 0 ) {
			return VipAccess::deny( VipAccess::DENY_ANONYMOUS, (float) $post['price'], $this->plans( (int) $post['tenant_id'] ) );
		}
		if ( $membership ) {
			return VipAccess::allow( VipAccess::ALLOW_MEMBERSHIP );
		}
		if ( self::ACCESS_MEMBERS === $access ) {
			return VipAccess::deny(
				$this->had_membership( $user_id, (int) $post['tenant_id'] ) ? VipAccess::DENY_EXPIRED : VipAccess::DENY_NO_MEMBER,
				(float) $post['price'],
				$this->plans( (int) $post['tenant_id'] )
			);
		}

		return VipAccess::deny( VipAccess::DENY_UNPURCHASED, (float) $post['price'], $this->plans( (int) $post['tenant_id'] ) );
	}

	// ------------------------------------------------------------- memberships

	/** @return array<string,mixed>|null */
	public function active_membership( int $user_id, int $tenant_id = 0 ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'vip_memberships' ) . '
			 WHERE user_id = %d
			   AND (tenant_id = %d OR %d = 0)
			   AND status = %s
			   AND (ends_at IS NULL OR ends_at > %s)
			 ORDER BY ends_at DESC, id DESC
			 LIMIT 1',
			$user_id,
			$tenant_id,
			$tenant_id,
			self::STATUS_ACTIVE,
			current_time( 'mysql', true )
		);
	}

	public function is_member( int $user_id, int $tenant_id = 0 ): bool {
		return null !== $this->active_membership( $user_id, $tenant_id );
	}

	/** Distinguishes "never subscribed" from "lapsed" so the app can say the right thing. */
	private function had_membership( int $user_id, int $tenant_id ): bool {
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_memberships' ) . '
			 WHERE user_id = %d AND (tenant_id = %d OR %d = 0) AND status <> %s',
			$user_id,
			$tenant_id,
			$tenant_id,
			self::STATUS_PENDING
		) > 0;
	}

	// ------------------------------------------------------------ entitlements

	public function has_entitlement( int $user_id, int $post_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_entitlements' ) . '
			 WHERE user_id = %d AND post_id = %d
			   AND revoked_at IS NULL
			   AND (expires_at IS NULL OR expires_at > %s)',
			$user_id,
			$post_id,
			current_time( 'mysql', true )
		) > 0;
	}

	/**
	 * @param int[] $post_ids
	 * @return array<int,true> keyed by post id
	 */
	private function entitled_post_ids( int $user_id, array $post_ids ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( [] === $post_ids ) {
			return [];
		}

		$in   = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$args = array_merge( [ $user_id ], $post_ids, [ current_time( 'mysql', true ) ] );

		$rows = $this->db->column(
			'SELECT post_id FROM ' . $this->db->table( 'vip_entitlements' ) . "
			 WHERE user_id = %d AND post_id IN ({$in})
			   AND revoked_at IS NULL
			   AND (expires_at IS NULL OR expires_at > %s)",
			...$args
		);

		$out = [];
		foreach ( $rows as $id ) {
			$out[ (int) $id ] = true;
		}
		return $out;
	}

	// ------------------------------------------------------------------ plans

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function plans( int $tenant_id ): array {
		$rows = $this->db->results(
			'SELECT id, slug, name, description, price, currency, duration_days
			 FROM ' . $this->db->table( 'vip_plans' ) . '
			 WHERE is_active = 1 AND (tenant_id = %d OR tenant_id = 0)
			 ORDER BY sort_order ASC, price ASC',
			$tenant_id
		);

		return array_map(
			static fn( array $r ): array => [
				'id'            => (int) $r['id'],
				'slug'          => (string) $r['slug'],
				'name'          => (string) $r['name'],
				'description'   => (string) ( $r['description'] ?? '' ),
				'price'         => (float) $r['price'],
				'currency'      => (string) $r['currency'],
				'duration_days' => (int) $r['duration_days'],
			],
			$rows
		);
	}

	// --------------------------------------------------------------- managers

	/**
	 * @param array<string,mixed> $post
	 */
	private function is_manager( int $user_id, array $post ): bool {
		if ( $user_id > 0 && $user_id === (int) $post['author_id'] ) {
			return true;
		}
		return user_can( $user_id, 'manage_woocommerce' );
	}
}
