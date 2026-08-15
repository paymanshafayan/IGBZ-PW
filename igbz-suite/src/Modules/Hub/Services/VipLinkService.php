<?php
namespace IGBZ\Suite\Modules\Hub\Services;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Short-lived signed hand-off links between the mother site and a tenant store.
 *
 * The mother site (a separate front end, deliberately decoupled in the original project so it can
 * be hosted away from the shops) needs to drop a visitor into a tenant store already signed in.
 * Instead of passing a long-lived JWT around, we mint an HMAC-signed, single-use, TTL-bounded
 * ticket and burn it on redemption.
 *
 *   /?igbz_vip=<payload>.<signature>
 *
 * payload = base64url( json{ v, t (tenant), u (user), n (nonce), e (expiry), r (redirect) } )
 */
final class VipLinkService {

	private const VERSION = 1;

	private const USED_PREFIX = 'igbz_vip_used_';

	public function __construct( private Db $db, private Logger $logger ) {}

	public function secret(): string {
		$secret = igbz()->settings()->string( 'hub.vip_link_secret', '' );
		if ( '' === $secret ) {
			$secret = Crypto::token( 32 );
			igbz()->settings()->set( 'hub.vip_link_secret', $secret );
		}
		return $secret;
	}

	public function ttl(): int {
		return max( 60, min( 86400, igbz()->settings()->int( 'hub.vip_link_ttl', 900 ) ) );
	}

	/**
	 * Mint a ticket.
	 *
	 * @param string $redirect Relative path on the destination store, e.g. /my-account/.
	 */
	public function issue( int $tenant_id, int $user_id = 0, string $redirect = '/' ): string {
		$payload = [
			'v' => self::VERSION,
			't' => $tenant_id,
			'u' => $user_id,
			'n' => Crypto::token( 8 ),
			'e' => time() + $this->ttl(),
			'r' => '/' . ltrim( wp_parse_url( $redirect, PHP_URL_PATH ) ?? '/', '/' ),
		];

		$encoded   = self::b64_encode( (string) wp_json_encode( $payload ) );
		$signature = Crypto::hmac( $encoded, $this->secret() );

		return $encoded . '.' . $signature;
	}

	/** Full URL on the tenant's own domain. */
	public function issue_url( int $tenant_id, int $user_id = 0, string $redirect = '/' ): string {
		$base = home_url( '/' );

		$domain = ( new \IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository( $this->db ) )->primary_domain( $tenant_id );
		if ( '' !== $domain ) {
			$base = 'https://' . $domain . '/';
		}

		return add_query_arg( 'igbz_vip', rawurlencode( $this->issue( $tenant_id, $user_id, $redirect ) ), $base );
	}

	/**
	 * Validate a ticket without consuming it.
	 *
	 * @return array{ok:bool,error:string,tenant_id:int,user_id:int,redirect:string,nonce:string}
	 */
	public function inspect( string $ticket ): array {
		$fail = static fn ( string $error ): array => [
			'ok'        => false,
			'error'     => $error,
			'tenant_id' => 0,
			'user_id'   => 0,
			'redirect'  => '/',
			'nonce'     => '',
		];

		$parts = explode( '.', $ticket );
		if ( 2 !== count( $parts ) ) {
			return $fail( 'malformed' );
		}

		[ $encoded, $signature ] = $parts;

		if ( ! Crypto::hmac_equals( Crypto::hmac( $encoded, $this->secret() ), $signature ) ) {
			return $fail( 'bad_signature' );
		}

		$decoded = json_decode( (string) self::b64_decode( $encoded ), true );
		if ( ! is_array( $decoded ) || self::VERSION !== (int) ( $decoded['v'] ?? 0 ) ) {
			return $fail( 'bad_payload' );
		}
		if ( (int) ( $decoded['e'] ?? 0 ) < time() ) {
			return $fail( 'expired' );
		}

		return [
			'ok'        => true,
			'error'     => '',
			'tenant_id' => (int) ( $decoded['t'] ?? 0 ),
			'user_id'   => (int) ( $decoded['u'] ?? 0 ),
			'redirect'  => (string) ( $decoded['r'] ?? '/' ),
			'nonce'     => (string) ( $decoded['n'] ?? '' ),
		];
	}

	/**
	 * Validate and burn. A ticket can only be redeemed once; the nonce is remembered for as long
	 * as the ticket could still be replayed.
	 *
	 * @return array{ok:bool,error:string,tenant_id:int,user_id:int,redirect:string,nonce:string}
	 */
	public function redeem( string $ticket ): array {
		$result = $this->inspect( $ticket );
		if ( ! $result['ok'] ) {
			$this->logger->warning( 'hub', 'VIP link rejected', [ 'reason' => $result['error'] ] );
			return $result;
		}

		$key = self::USED_PREFIX . md5( $result['nonce'] );
		if ( false !== get_transient( $key ) ) {
			$this->logger->warning( 'hub', 'VIP link replay blocked', [ 'tenant_id' => $result['tenant_id'] ] );
			$result['ok']    = false;
			$result['error'] = 'already_used';
			return $result;
		}
		set_transient( $key, 1, $this->ttl() + 60 );

		return $result;
	}

	/** Consume ?igbz_vip= on the storefront: sign the visitor in and bounce them to the target. */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the ticket is itself an HMAC.
		$ticket = isset( $_GET['igbz_vip'] ) ? sanitize_text_field( wp_unslash( $_GET['igbz_vip'] ) ) : '';
		if ( '' === $ticket ) {
			return;
		}

		$result = $this->redeem( $ticket );
		if ( ! $result['ok'] ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( $result['user_id'] > 0 && get_current_user_id() !== $result['user_id'] && get_userdata( $result['user_id'] ) ) {
			wp_set_current_user( $result['user_id'] );
			wp_set_auth_cookie( $result['user_id'], false );
			do_action( 'wp_login', get_userdata( $result['user_id'] )->user_login, get_userdata( $result['user_id'] ) );
		}

		if ( $result['tenant_id'] > 0 ) {
			igbz()->tenancy()->force( $result['tenant_id'] );
		}

		$this->logger->info( 'hub', 'VIP link redeemed', [ 'tenant_id' => $result['tenant_id'], 'user_id' => $result['user_id'] ] );

		wp_safe_redirect( home_url( $result['redirect'] ) );
		exit;
	}

	private static function b64_encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private static function b64_decode( string $encoded ): string {
		$padded = str_pad( strtr( $encoded, '-_', '+/' ), (int) ( 4 * ceil( strlen( $encoded ) / 4 ) ), '=' );
		return (string) base64_decode( $padded, true );
	}
}
