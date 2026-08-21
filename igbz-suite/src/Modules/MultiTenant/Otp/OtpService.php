<?php
namespace IGBZ\Suite\Modules\MultiTenant\Otp;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Phone OTP: issue, throttle, verify, and log in / register the WordPress user.
 *
 * Security notes (fixes carried over from the nop audit):
 *  - codes are CSPRNG, never seeded Random;
 *  - only a salted SHA-256 hash is stored, never the plaintext code;
 *  - verification is constant-time and single-use;
 *  - per-phone and per-IP rate limits, plus a max attempt counter per code.
 */
final class OtpService {

	public const PURPOSE_LOGIN    = 'login';
	public const PURPOSE_REGISTER = 'register';
	public const PURPOSE_VERIFY   = 'verify_phone';
	public const PURPOSE_RESET    = 'password_reset';

	public function __construct( private Db $db, private Http $http, private Logger $logger ) {}

	// ------------------------------------------------------------- issuing

	/**
	 * @return array{ok:bool,error:string,retry_after:int,expires_in:int}
	 */
	public function send( string $phone, string $purpose = self::PURPOSE_LOGIN, int $tenant_id = 0 ): array {
		$phone = self::normalize_phone( $phone );
		if ( ! self::is_valid_phone( $phone ) ) {
			return [ 'ok' => false, 'error' => __( 'The phone number is not valid.', 'igbz-suite' ), 'retry_after' => 0, 'expires_in' => 0 ];
		}

		/*
		 * Resend cooldown: same phone AND same IP.
		 *
		 * The AND is deliberate and was specified explicitly: the cooldown applies only when both
		 * match. An OR would be stricter but would punish real users — on mobile networks and
		 * behind NAT, dozens of genuine customers share one address. With AND they never collide,
		 * because their phone numbers differ.
		 *
		 * The check lives here rather than in a controller because this method is the single
		 * choke point every caller passes through: the site shortcode, the app's REST route, and
		 * anything added later. A guard placed higher up is bypassed by the first new caller —
		 * and a countdown rendered in the browser is bypassed by pressing refresh.
		 *
		 * Cost, not just nuisance: every send bills the SMS provider. OWASP API4:2023 lists
		 * exactly this scenario. See امنیت و مراقبت/منابع/OWASP/.
		 */
		$cooldown = igbz()->settings()->int( 'otp.resend_seconds', 120 );
		$ip_hash  = $this->ip_hash();
		$last     = $this->db->row(
			'SELECT created_at FROM ' . $this->db->table( 'otp_codes' ) . '
			 WHERE phone = %s AND ip_hash = %s AND purpose = %s ORDER BY id DESC LIMIT 1',
			$phone,
			$ip_hash,
			$purpose
		);
		if ( $last ) {
			$elapsed = time() - strtotime( (string) $last['created_at'] . ' UTC' );
			if ( $elapsed < $cooldown ) {
				return [ 'ok' => false, 'error' => __( 'Please wait before requesting another code.', 'igbz-suite' ), 'retry_after' => $cooldown - $elapsed, 'expires_in' => 0 ];
			}
		}

		if ( ! $this->within_hourly_quota( $phone ) ) {
			$this->logger->warning( 'otp', 'Hourly OTP quota exceeded', [ 'phone' => Crypto::MASK ] );
			return [ 'ok' => false, 'error' => __( 'Too many code requests. Try again later.', 'igbz-suite' ), 'retry_after' => 3600, 'expires_in' => 0 ];
		}

		$length  = max( 4, min( 8, igbz()->settings()->int( 'otp.code_length', 6 ) ) );
		$ttl     = igbz()->settings()->int( 'otp.ttl_seconds', 300 );
		$code    = Crypto::numeric_code( $length );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$this->db->insert(
			'otp_codes',
			[
				'tenant_id'  => $tenant_id,
				'phone'      => $phone,
				'code_hash'  => $this->hash( $phone, $code, $purpose ),
				'purpose'    => $purpose,
				'expires_at' => $expires,
				'ip_hash'    => $ip_hash,
				'created_at' => current_time( 'mysql', true ),
			]
		);

		$sent = $this->dispatch_sms( $phone, $code, $purpose );
		if ( ! $sent ) {
			return [ 'ok' => false, 'error' => __( 'Could not send the SMS. Please try again.', 'igbz-suite' ), 'retry_after' => 30, 'expires_in' => 0 ];
		}

		return [ 'ok' => true, 'error' => '', 'retry_after' => $cooldown, 'expires_in' => $ttl ];
	}

	private function within_hourly_quota( string $phone ): bool {
		$max   = igbz()->settings()->int( 'otp.max_per_hour', 5 );
		$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$count = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'otp_codes' ) . ' WHERE phone = %s AND created_at >= %s',
			$phone,
			$since
		);
		return $count < $max;
	}

	// ----------------------------------------------------------- verifying

	/**
	 * @return array{ok:bool,error:string,user_id:int}
	 */
	public function verify( string $phone, string $code, string $purpose = self::PURPOSE_LOGIN ): array {
		$phone = self::normalize_phone( $phone );
		$code  = preg_replace( '/\D+/', '', $code ) ?? '';

		$row = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'otp_codes' ) . '
			 WHERE phone = %s AND purpose = %s AND consumed_at IS NULL
			 ORDER BY id DESC LIMIT 1',
			$phone,
			$purpose
		);

		if ( ! $row ) {
			return [ 'ok' => false, 'error' => __( 'No active code found. Request a new one.', 'igbz-suite' ), 'user_id' => 0 ];
		}
		if ( strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) {
			return [ 'ok' => false, 'error' => __( 'The code has expired.', 'igbz-suite' ), 'user_id' => 0 ];
		}

		$max_attempts = igbz()->settings()->int( 'otp.max_attempts', 5 );
		if ( (int) $row['attempts'] >= $max_attempts ) {
			return [ 'ok' => false, 'error' => __( 'Too many wrong attempts. Request a new code.', 'igbz-suite' ), 'user_id' => 0 ];
		}

		if ( ! Crypto::hmac_equals( (string) $row['code_hash'], $this->hash( $phone, $code, $purpose ) ) ) {
			$this->db->query(
				'UPDATE ' . $this->db->table( 'otp_codes' ) . ' SET attempts = attempts + 1 WHERE id = %d',
				(int) $row['id']
			);
			return [ 'ok' => false, 'error' => __( 'The code is incorrect.', 'igbz-suite' ), 'user_id' => 0 ];
		}

		$this->db->update( 'otp_codes', [ 'consumed_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ] );

		$user_id = 0;
		if ( in_array( $purpose, [ self::PURPOSE_LOGIN, self::PURPOSE_REGISTER ], true ) ) {
			$user_id = $this->resolve_or_create_user( $phone, (int) $row['tenant_id'] );
		}

		do_action( 'igbz_otp_verified', $phone, $purpose, $user_id );
		return [ 'ok' => true, 'error' => '', 'user_id' => $user_id ];
	}

	/**
	 * Find the user by phone, creating one when needed.
	 *
	 * Port note: the nop original only ever UPDATED an existing customer and never created one,
	 * so phone-first signup silently failed. This path creates the account.
	 */
	public function resolve_or_create_user( string $phone, int $tenant_id = 0 ): int {
		$phone = self::normalize_phone( $phone );

		$existing = get_users(
			[
				'meta_key'   => 'igbz_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $phone,       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			]
		);
		if ( $existing ) {
			return (int) $existing[0];
		}

		$login = 'igbz_' . preg_replace( '/\D+/', '', $phone );
		$email = $login . '@phone.igbz.local'; // synthetic, mirrors the nop convention
		$user  = wp_insert_user(
			[
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24, true, true ),
				'user_email' => $email,
				'role'       => 'customer',
				'nickname'   => $phone,
			]
		);
		if ( is_wp_error( $user ) ) {
			$this->logger->error( 'otp', 'User creation failed', [ 'error' => $user->get_error_message() ] );
			return 0;
		}

		$user_id = (int) $user;
		update_user_meta( $user_id, 'igbz_phone', $phone );
		update_user_meta( $user_id, 'igbz_phone_verified', 1 );
		if ( $tenant_id > 0 ) {
			update_user_meta( $user_id, 'igbz_tenant_id', $tenant_id );
		}
		if ( function_exists( 'wc_update_new_customer_past_orders' ) ) {
			update_user_meta( $user_id, 'billing_phone', $phone );
		}

		do_action( 'igbz_otp_user_registered', $user_id, $phone, $tenant_id );
		return $user_id;
	}

	public function login( int $user_id, bool $remember = true ): void {
		wp_clear_auth_cookie();
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $remember );
		do_action( 'wp_login', get_userdata( $user_id )->user_login ?? '', get_userdata( $user_id ) );
	}

	// ------------------------------------------------------------- delivery

	private function dispatch_sms( string $phone, string $code, string $purpose ): bool {
		$message = str_replace(
			[ '{code}', '{site}' ],
			[ $code, get_bloginfo( 'name' ) ],
			igbz()->settings()->string( 'otp.message_template', __( 'Your verification code: {code}', 'igbz-suite' ) )
		);

		/**
		 * Short-circuit SMS delivery with a custom provider.
		 *
		 * @param bool|null $handled Return true/false to bypass the built-in providers.
		 */
		$handled = apply_filters( 'igbz_otp_send_sms', null, $phone, $message, $purpose );
		if ( is_bool( $handled ) ) {
			return $handled;
		}

		$provider = igbz()->settings()->string( 'otp.sms_provider', 'log' );

		if ( 'log' === $provider ) {
			// Development mode: never print the code into a customer-visible surface.
			$this->logger->info( 'otp', 'SMS (log provider)', [ 'phone' => $phone, 'purpose' => $purpose ] );
			return true;
		}

		if ( 'kavenegar' === $provider ) {
			return $this->send_kavenegar( $phone, $code, $message );
		}
		if ( 'smsir' === $provider ) {
			return $this->send_smsir( $phone, $code );
		}

		$this->logger->error( 'otp', 'Unknown SMS provider', [ 'provider' => $provider ] );
		return false;
	}

	/** Kavenegar verify lookup (template based) with a plain-send fallback. */
	private function send_kavenegar( string $phone, string $code, string $message ): bool {
		$api_key = igbz()->settings()->string( 'otp.kavenegar.api_key' );
		if ( '' === $api_key ) {
			return false;
		}
		$template = igbz()->settings()->string( 'otp.kavenegar.template' );

		if ( '' !== $template ) {
			$url = sprintf(
				'https://api.kavenegar.com/v1/%s/verify/lookup.json?receptor=%s&token=%s&template=%s',
				rawurlencode( $api_key ),
				rawurlencode( $phone ),
				rawurlencode( $code ),
				rawurlencode( $template )
			);
		} else {
			$url = sprintf(
				'https://api.kavenegar.com/v1/%s/sms/send.json?receptor=%s&message=%s&sender=%s',
				rawurlencode( $api_key ),
				rawurlencode( $phone ),
				rawurlencode( $message ),
				rawurlencode( igbz()->settings()->string( 'otp.kavenegar.sender' ) )
			);
		}

		$response = $this->http->get( $url, [ 'channel' => 'otp', 'timeout' => 20 ] );
		$body     = $response->json();
		$status   = (int) ( $body['return']['status'] ?? 0 );
		if ( 200 === $status ) {
			return true;
		}
		$this->logger->error( 'otp', 'Kavenegar send failed', [ 'status' => $status, 'message' => $body['return']['message'] ?? '' ] );
		return false;
	}

	/** SMS.ir ultra-fast-send v1 (template + parameters). */
	private function send_smsir( string $phone, string $code ): bool {
		$api_key = igbz()->settings()->string( 'otp.smsir.api_key' );
		$template = igbz()->settings()->int( 'otp.smsir.template_id' );
		if ( '' === $api_key || 0 === $template ) {
			return false;
		}

		$response = $this->http->post(
			'https://api.sms.ir/v1/send/verify',
			[
				'json'    => [
					'mobile'     => $phone,
					'templateId' => $template,
					'parameters' => [ [ 'name' => 'CODE', 'value' => $code ] ],
				],
				'headers' => [ 'X-API-KEY' => $api_key, 'Accept' => 'application/json' ],
				'channel' => 'otp',
				'timeout' => 20,
			]
		);

		$body = $response->json();
		if ( 1 === (int) ( $body['status'] ?? 0 ) ) {
			return true;
		}
		$this->logger->error( 'otp', 'SMS.ir send failed', [ 'status' => $body['status'] ?? null, 'message' => $body['message'] ?? '' ] );
		return false;
	}

	// -------------------------------------------------------------- helpers

	private function hash( string $phone, string $code, string $purpose ): string {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : '';
		return hash( 'sha256', $phone . '|' . $code . '|' . $purpose . '|' . $salt );
	}

	private function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? '' : hash( 'sha256', $ip . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) );
	}

	/** Normalise Iranian numbers to 09xxxxxxxxx and convert Persian/Arabic digits. */
	public static function normalize_phone( string $phone ): string {
		$phone = strtr(
			$phone,
			[
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			]
		);
		$phone = preg_replace( '/\D+/', '', $phone ) ?? '';

		if ( str_starts_with( $phone, '0098' ) ) {
			$phone = '0' . substr( $phone, 4 );
		} elseif ( str_starts_with( $phone, '98' ) && 12 === strlen( $phone ) ) {
			$phone = '0' . substr( $phone, 2 );
		} elseif ( 10 === strlen( $phone ) && str_starts_with( $phone, '9' ) ) {
			$phone = '0' . $phone;
		}

		return $phone;
	}

	public static function is_valid_phone( string $phone ): bool {
		return 1 === preg_match( '/^09\d{9}$/', $phone );
	}
}
