<?php
namespace IGBZ\Suite\Modules\Instagram\Vip;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The Instagram social layer: likes, comments, replies and view counts.
 *
 * Counts are denormalised onto vip_posts. A feed page renders three counts per post, and computing
 * them with COUNT(*) subqueries would make the cheapest screen in the app the most expensive query
 * in the plugin.
 */
final class VipSocialService {

	public const STATUS_VISIBLE = 'visible';
	public const STATUS_HIDDEN  = 'hidden';
	public const STATUS_DELETED = 'deleted';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private VipAccessService $access
	) {}

	// ------------------------------------------------------------------ likes

	/**
	 * Toggle a like.
	 *
	 * @return array{liked:bool,likes_count:int}
	 */
	public function toggle_like( int $post_id, int $user_id ): array {
		$existing = $this->db->row(
			'SELECT id FROM ' . $this->db->table( 'vip_post_likes' ) . ' WHERE post_id = %d AND user_id = %d',
			$post_id,
			$user_id
		);

		if ( $existing ) {
			$this->db->delete( 'vip_post_likes', [ 'id' => (int) $existing['id'] ] );
			$liked = false;
		} else {
			// A double-tap that arrives twice hits the UNIQUE (post_id,user_id) index and fails
			// harmlessly rather than counting twice; the recount below then settles the number.
			$this->db->insert(
				'vip_post_likes',
				[
					'post_id'    => $post_id,
					'user_id'    => $user_id,
					'created_at' => current_time( 'mysql', true ),
				]
			);
			$liked = true;
		}

		$count = $this->recount_likes( $post_id );

		do_action( 'igbz_vip_post_liked', $post_id, $user_id, $liked );

		return [
			'liked'       => $liked,
			'likes_count' => $count,
		];
	}

	public function has_liked( int $post_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_post_likes' ) . ' WHERE post_id = %d AND user_id = %d',
			$post_id,
			$user_id
		) > 0;
	}

	/**
	 * Recount from the source table rather than incrementing.
	 *
	 * An increment drifts the first time two taps race or a row is removed by moderation, and a
	 * wrong like count is visible forever. The table is small and indexed by post.
	 */
	private function recount_likes( int $post_id ): int {
		$count = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_post_likes' ) . ' WHERE post_id = %d',
			$post_id
		);

		$this->db->update( 'vip_posts', [ 'likes_count' => $count ], [ 'id' => $post_id ] );

		return $count;
	}

	// --------------------------------------------------------------- comments

	/**
	 * Post a comment or a reply.
	 *
	 * @throws \RuntimeException When the post is closed, the body is unusable or the user is too fast.
	 */
	public function add_comment( int $post_id, int $user_id, string $body, int $parent_id = 0, bool $is_admin = false ): int {
		$post = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE id = %d', $post_id );
		if ( ! $post ) {
			throw new \RuntimeException( __( 'Post not found.', 'igbz-suite' ) );
		}

		if ( ! $is_admin ) {
			if ( ! (int) $post['comments_enabled'] ) {
				throw new \RuntimeException( __( 'Comments are turned off for this post.', 'igbz-suite' ) );
			}
			// Commenting is gated on the same rule as viewing. Otherwise a locked post's comment
			// thread becomes a free preview of what the members are discussing.
			if ( ! $this->access->check_row( $user_id, $post )->allowed ) {
				throw new \RuntimeException( __( 'You do not have access to this post.', 'igbz-suite' ) );
			}
		}

		$body = trim( wp_strip_all_tags( $body ) );
		if ( '' === $body ) {
			throw new \RuntimeException( __( 'Write something first.', 'igbz-suite' ) );
		}

		$max = $this->settings->int( 'vip.comment_max_length', 1000 );
		if ( $max > 0 && mb_strlen( $body ) > $max ) {
			$body = mb_substr( $body, 0, $max );
		}

		if ( ! $is_admin ) {
			$this->assert_not_flooding( $user_id );
		}

		// A reply to a reply is flattened onto the top-level comment. Instagram threads are two
		// levels deep; allowing arbitrary nesting produces threads no phone screen can render.
		if ( $parent_id > 0 ) {
			$parent = $this->db->row(
				'SELECT id, post_id, parent_id FROM ' . $this->db->table( 'vip_post_comments' ) . ' WHERE id = %d',
				$parent_id
			);
			if ( ! $parent || (int) $parent['post_id'] !== $post_id ) {
				$parent_id = 0;
			} elseif ( (int) $parent['parent_id'] > 0 ) {
				$parent_id = (int) $parent['parent_id'];
			}
		}

		$now = current_time( 'mysql', true );

		$id = $this->db->insert(
			'vip_post_comments',
			[
				'tenant_id'  => (int) $post['tenant_id'],
				'post_id'    => $post_id,
				'user_id'    => $user_id,
				'parent_id'  => $parent_id,
				'is_admin'   => (int) $is_admin,
				'body'       => $body,
				'status'     => self::STATUS_VISIBLE,
				'is_pinned'  => 0,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);

		if ( $id > 0 ) {
			$this->recount_comments( $post_id );
			do_action( 'igbz_vip_comment_added', $id, $post_id, $user_id, $is_admin );
		}

		return $id;
	}

	/**
	 * @throws \RuntimeException
	 */
	private function assert_not_flooding( int $user_id ): void {
		$seconds = $this->settings->int( 'vip.comment_rate_seconds', 15 );
		if ( $seconds <= 0 ) {
			return;
		}

		$last = $this->db->scalar(
			'SELECT created_at FROM ' . $this->db->table( 'vip_post_comments' ) . '
			 WHERE user_id = %d ORDER BY id DESC LIMIT 1',
			$user_id
		);

		if ( $last && ( time() - strtotime( (string) $last ) ) < $seconds ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: number of seconds. */
					__( 'Please wait %d seconds before commenting again.', 'igbz-suite' ),
					$seconds
				)
			);
		}
	}

	/**
	 * Comments for one post, threaded one level deep.
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public function comments( int $post_id, int $page = 1, int $per_page = 20 ): array {
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->db->table( 'vip_post_comments' );

		$total = (int) $this->db->scalar(
			"SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND parent_id = 0 AND status = %s",
			$post_id,
			self::STATUS_VISIBLE
		);

		// Pinned first, then newest — the same ordering Instagram uses.
		$roots = $this->db->results(
			"SELECT * FROM {$table}
			 WHERE post_id = %d AND parent_id = 0 AND status = %s
			 ORDER BY is_pinned DESC, id DESC
			 LIMIT %d OFFSET %d",
			$post_id,
			self::STATUS_VISIBLE,
			$per_page,
			$offset
		);

		$items = [];
		foreach ( $roots as $root ) {
			$row = $this->present_comment( $root );

			$replies = $this->db->results(
				"SELECT * FROM {$table}
				 WHERE parent_id = %d AND status = %s
				 ORDER BY id ASC
				 LIMIT 50",
				(int) $root['id'],
				self::STATUS_VISIBLE
			);

			$row['replies'] = array_map( [ $this, 'present_comment' ], $replies );
			$items[]        = $row;
		}

		return [ 'items' => $items, 'total' => $total ];
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function present_comment( array $row ): array {
		$user_id = (int) $row['user_id'];
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		return [
			'id'         => (int) $row['id'],
			'post_id'    => (int) $row['post_id'],
			'parent_id'  => (int) $row['parent_id'],
			'user_id'    => $user_id,
			'author'     => $user ? $user->display_name : __( 'Guest', 'igbz-suite' ),
			'avatar'     => $user_id > 0 ? get_avatar_url( $user_id, [ 'size' => 96 ] ) : '',
			'is_admin'   => (bool) (int) $row['is_admin'],
			'is_pinned'  => (bool) (int) $row['is_pinned'],
			'body'       => (string) $row['body'],
			'created_at' => $row['created_at'],
			'replies'    => [],
		];
	}

	public function set_comment_status( int $comment_id, string $status ): bool {
		if ( ! in_array( $status, [ self::STATUS_VISIBLE, self::STATUS_HIDDEN, self::STATUS_DELETED ], true ) ) {
			return false;
		}

		$comment = $this->db->row(
			'SELECT post_id FROM ' . $this->db->table( 'vip_post_comments' ) . ' WHERE id = %d',
			$comment_id
		);
		if ( ! $comment ) {
			return false;
		}

		$done = $this->db->update(
			'vip_post_comments',
			[
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $comment_id ]
		) > 0;

		// Replies follow their parent out of view. Leaving them behind produces orphaned answers to
		// a question nobody can read.
		if ( $done && self::STATUS_VISIBLE !== $status ) {
			$this->db->update(
				'vip_post_comments',
				[
					'status'     => $status,
					'updated_at' => current_time( 'mysql', true ),
				],
				[ 'parent_id' => $comment_id ]
			);
		}

		$this->recount_comments( (int) $comment['post_id'] );

		return $done;
	}

	public function pin_comment( int $comment_id, bool $pinned = true ): bool {
		$comment = $this->db->row(
			'SELECT post_id FROM ' . $this->db->table( 'vip_post_comments' ) . ' WHERE id = %d',
			$comment_id
		);
		if ( ! $comment ) {
			return false;
		}

		// One pin per post, like Instagram's own limit of a pinned comment set.
		if ( $pinned ) {
			$this->db->update( 'vip_post_comments', [ 'is_pinned' => 0 ], [ 'post_id' => (int) $comment['post_id'] ] );
		}

		return $this->db->update(
			'vip_post_comments',
			[
				'is_pinned'  => (int) $pinned,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $comment_id ]
		) >= 0;
	}

	private function recount_comments( int $post_id ): int {
		$count = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_post_comments' ) . ' WHERE post_id = %d AND status = %s',
			$post_id,
			self::STATUS_VISIBLE
		);

		$this->db->update( 'vip_posts', [ 'comments_count' => $count ], [ 'id' => $post_id ] );

		return $count;
	}

	// ------------------------------------------------------------------ views

	/**
	 * Record that a member watched a post.
	 *
	 * One row per (post, user): views_count on the post is the number of distinct people who have
	 * seen it, which is the number a shop owner actually wants. Repeat opens bump view_count on the
	 * row so the admin can still see who keeps coming back.
	 */
	public function record_view( int $post_id, int $user_id, int $seconds = 0 ): int {
		if ( $user_id <= 0 ) {
			return (int) $this->db->scalar(
				'SELECT views_count FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE id = %d',
				$post_id
			);
		}

		$now      = current_time( 'mysql', true );
		$existing = $this->db->row(
			'SELECT id, seconds_watched FROM ' . $this->db->table( 'vip_post_views' ) . ' WHERE post_id = %d AND user_id = %d',
			$post_id,
			$user_id
		);

		if ( $existing ) {
			$this->db->update(
				'vip_post_views',
				[
					// Keep the longest watch, not the latest: a member who skims a video after
					// watching it fully has not watched less of it.
					'seconds_watched' => max( (int) $existing['seconds_watched'], max( 0, $seconds ) ),
					'viewed_at'       => $now,
				],
				[ 'id' => (int) $existing['id'] ]
			);
			$this->db->query(
				'UPDATE ' . $this->db->table( 'vip_post_views' ) . ' SET view_count = view_count + 1 WHERE id = %d',
				(int) $existing['id']
			);
		} else {
			$this->db->insert(
				'vip_post_views',
				[
					'post_id'         => $post_id,
					'user_id'         => $user_id,
					'seconds_watched' => max( 0, $seconds ),
					'view_count'      => 1,
					'first_viewed_at' => $now,
					'viewed_at'       => $now,
				]
			);
		}

		$count = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'vip_post_views' ) . ' WHERE post_id = %d',
			$post_id
		);

		$this->db->update( 'vip_posts', [ 'views_count' => $count ], [ 'id' => $post_id ] );

		return $count;
	}
}
