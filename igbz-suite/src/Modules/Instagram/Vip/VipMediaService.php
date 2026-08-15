<?php
namespace IGBZ\Suite\Modules\Instagram\Vip;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Serving and purging VIP media.
 *
 * Deliberately light. A VIP post is worth about as much as an Instagram Close Friends post, and the
 * agreed ceiling is the same: a short-lived signed link that a logged-out stranger cannot open.
 * No device binding, no watermark, no screenshot blocking — that machinery belongs to the LMS,
 * where a single leaked course is worth far more than a single leaked post.
 */
final class VipMediaService {

	/** Query var the front controller listens on: /?igbz_vip_media=<post>&i=<index>&u=&e=&s= */
	public const QUERY_VAR = 'igbz_vip_media';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * Mint a signed URL for one media item of one post.
	 *
	 * The signature covers the viewer id, so a link copied out of one account's app session does not
	 * work in another's — the responder checks the signed user against the current session.
	 */
	public function signed_url( int $post_id, int $index, int $user_id, ?int $ttl = null ): string {
		$ttl     = $ttl ?? $this->settings->int( 'vip.media_link_ttl', 900 );
		$expires = time() + max( 60, $ttl );

		return add_query_arg(
			[
				self::QUERY_VAR => $post_id,
				'i'             => $index,
				'u'             => $user_id,
				'e'             => $expires,
				's'             => $this->sign( $post_id, $index, $user_id, $expires ),
			],
			home_url( '/' )
		);
	}

	public function verify( int $post_id, int $index, int $user_id, int $expires, string $signature ): bool {
		if ( $expires < time() ) {
			return false;
		}
		return Crypto::hmac_equals( $this->sign( $post_id, $index, $user_id, $expires ), $signature );
	}

	private function sign( int $post_id, int $index, int $user_id, int $expires ): string {
		$secret = $this->settings->required( 'vip.media_hmac_secret' );
		return Crypto::hmac( "vip|{$post_id}|{$index}|{$user_id}|{$expires}", $secret );
	}

	/**
	 * Resolve a stored media item to something the browser can fetch.
	 *
	 * Routed through a filter so a shop can keep its files on ArvanCloud, S3 or local uploads and
	 * swap in its own private-URL signer without touching the plugin.
	 *
	 * @param array<string,mixed> $item
	 */
	public function source_url( array $item, int $post_id, int $index, int $user_id ): string {
		$url = (string) apply_filters( 'igbz_vip_media_source', '', $item, $post_id, $index, $user_id );
		return '' !== $url ? $url : (string) ( $item['url'] ?? '' );
	}

	/**
	 * Delete the underlying files of expired or removed posts.
	 *
	 * Only attachment ids and paths inside the uploads directory are touched. An external URL is
	 * handed to a filter instead: we have no business issuing deletes against a bucket whose
	 * credentials and lifecycle rules belong to the shop owner.
	 *
	 * @param array<int,array<string,mixed>> $media
	 */
	public function purge( array $media ): int {
		$removed = 0;
		$uploads = wp_get_upload_dir();
		$basedir = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) );

		foreach ( $media as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$handled = (bool) apply_filters( 'igbz_vip_media_purge', false, $item );
			if ( $handled ) {
				++$removed;
				continue;
			}

			$attachment_id = (int) ( $item['attachment_id'] ?? 0 );
			if ( $attachment_id > 0 ) {
				wp_delete_attachment( $attachment_id, true );
				++$removed;
				continue;
			}

			$path = (string) ( $item['path'] ?? '' );
			if ( '' === $path || '' === $basedir ) {
				continue;
			}

			$full = path_is_absolute( $path ) ? $path : $basedir . ltrim( $path, '/' );
			$real = realpath( $full );

			// Refuse anything that resolves outside uploads. A crafted "../../wp-config.php" in a
			// media row must never become a delete.
			if ( false === $real || ! str_starts_with( $real, (string) realpath( $basedir ) ) ) {
				$this->logger->warning( 'vip', 'Refused to purge media outside uploads', [ 'path' => $path ] );
				continue;
			}

			if ( is_file( $real ) && wp_delete_file_from_directory( $real, $basedir ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Front-controller hook: answer a signed media request.
	 *
	 * Runs on `template_redirect`, before any theme output.
	 */
	public function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- HMAC-signed URL.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		$post_id   = absint( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$index     = isset( $_GET['i'] ) ? absint( wp_unslash( $_GET['i'] ) ) : 0;
		$user_id   = isset( $_GET['u'] ) ? absint( wp_unslash( $_GET['u'] ) ) : 0;
		$expires   = isset( $_GET['e'] ) ? absint( wp_unslash( $_GET['e'] ) ) : 0;
		$signature = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $this->verify( $post_id, $index, $user_id, $expires, $signature ) ) {
			$this->fail( 403 );
		}

		// A signed link is bound to the session it was minted for. Anonymous links are only ever
		// issued for free posts, where user_id is 0 and this check passes for everyone.
		if ( $user_id > 0 && $user_id !== get_current_user_id() ) {
			$this->fail( 403 );
		}

		$post = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'vip_posts' ) . ' WHERE id = %d',
			$post_id
		);
		if ( ! $post ) {
			$this->fail( 404 );
		}

		// Re-check access at serve time. A link minted while a membership was live must stop working
		// once that membership lapses — otherwise the TTL becomes the real expiry date.
		$access = igbz()->get( 'vip.access' );
		if ( $access instanceof VipAccessService && ! $access->check_row( $user_id, (array) $post )->allowed ) {
			$this->fail( 403 );
		}

		$media = json_decode( (string) ( $post['media'] ?? '[]' ), true );
		$item  = is_array( $media ) && isset( $media[ $index ] ) ? (array) $media[ $index ] : [];
		$url   = '' !== ( $item['url'] ?? '' ) || [] !== $item ? $this->source_url( $item, $post_id, $index, $user_id ) : '';

		if ( '' === $url ) {
			$this->fail( 404 );
		}

		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- storage host is chosen by the site owner.
		exit;
	}

	private function fail( int $code ): void {
		status_header( $code );
		nocache_headers();
		exit;
	}
}
