<?php
namespace IGBZ\Suite\Modules\MultiTenant\Lms;

defined( 'ABSPATH' ) || exit;

/**
 * Signed, expiring video URLs for the LMS (ArvanCloud-style VOD).
 *
 * The lesson's stored video path is never served directly; the player gets a
 * URL signed with the VOD secret, bound to an expiry (and optionally to the
 * viewer's IP), matching the HLS secure-link convention of Iranian VOD
 * providers. The existing lms.video_hmac_secret / video_link_ttl settings
 * remain for the self-hosted path; this service covers the VOD path.
 */
final class LmsVodService {

	public function is_configured(): bool {
		return '' !== igbz()->settings()->string( 'lms.vod_secure_key' )
			&& '' !== igbz()->settings()->string( 'lms.vod_base_url' );
	}

	/**
	 * Build a signed playback URL for a video path.
	 *
	 * @param string $video_path e.g. /videos/lesson-1/index.m3u8
	 */
	public function signed_url( string $video_path, string $user_ip = '' ): string {
		if ( ! $this->is_configured() ) {
			return '';
		}

		$base      = rtrim( igbz()->settings()->string( 'lms.vod_base_url' ), '/' );
		$key       = (string) igbz()->settings()->string( 'lms.vod_secure_key' );
		$ttl       = (int) igbz()->settings()->int( 'lms.vod_ttl_seconds', 7200 );
		$expire    = time() + $ttl;
		$bind_ip   = igbz()->settings()->bool( 'lms.vod_bind_ip', true );
		$ip        = $bind_ip && '' !== $user_ip ? $user_ip : '';

		// Same convention as ArvanCloud-style secure links: MD5(key + path + ip + expire).
		$hash = md5( $key . $video_path . $ip . $expire );
		$url  = $base . $video_path . '?h=' . $hash . '&e=' . $expire;
		if ( '' !== $ip ) {
			$url .= '&ip=' . rawurlencode( $ip );
		}

		return $url;
	}
}
