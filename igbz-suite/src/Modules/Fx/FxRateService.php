<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Current Rial-per-USD rate, locked per top-up.
 *
 * The source is `fx.rate_source`: `auto` fetches a rate from `fx.rate_url`
 * (a dotted `fx.rate_json_path` picks the number out of the JSON response,
 * same convention as the STT response path), `manual` uses `fx.rate_manual`.
 * A failed auto fetch falls back to the manual value rather than refusing a
 * top-up. The result is cached in an option for `fx.rate_cache_ttl` seconds.
 */
class FxRateService {

	public const SOURCE_AUTO   = 'auto';
	public const SOURCE_MANUAL = 'manual';

	private const CACHE_OPTION = 'igbz_fx_rate_cache';

	public function __construct(
		private Db $db,
		private Settings $settings,
		private Http $http
	) {}

	/** The rate the UI shows and a fresh top-up would lock. */
	public function current(): float {
		$cached = get_option( self::CACHE_OPTION, null );
		$ttl    = max( 60, $this->settings->int( 'fx.rate_cache_ttl', 3600 ) );

		if ( is_array( $cached ) && isset( $cached['rate'], $cached['at'] ) ) {
			$age = time() - (int) $cached['at'];
			if ( $age >= 0 && $age < $ttl ) {
				return (float) $cached['rate'];
			}
		}

		$rate = $this->resolve();
		update_option( self::CACHE_OPTION, [ 'rate' => $rate, 'at' => time() ], true );

		return $rate;
	}

	/** Insert a row in fx_rates and return its id — the number a top-up is locked to. */
	public function lock_rate(): int {
		$rate   = $this->current();
		$source = $this->settings->string( 'fx.rate_source', self::SOURCE_MANUAL );

		$id = $this->db->insert(
			'fx_rates',
			[
				'rate_irt_per_usd' => $rate,
				'source'           => $source,
				'captured_at'      => current_time( 'mysql', true ),
			]
		);

		return (int) $id;
	}

	/** Purge the cache so the next read refetches. */
	public function refresh(): void {
		delete_option( self::CACHE_OPTION );
	}

	private function resolve(): float {
		$manual = (float) $this->settings->float( 'fx.rate_manual', 0 );

		if ( self::SOURCE_AUTO === $this->settings->string( 'fx.rate_source', self::SOURCE_MANUAL ) ) {
			$auto = $this->fetch_auto_rate();
			if ( $auto > 0 ) {
				return $auto;
			}
		}

		return $manual;
	}

	/**
	 * Fetch the rate from the configured endpoint. Separated so tests can
	 * override it without touching the network.
	 */
	protected function fetch_auto_rate(): float {
		$url  = trim( $this->settings->string( 'fx.rate_url', '' ) );
		if ( '' === $url ) {
			return 0.0;
		}

		$response = $this->http->get( $url );
		if ( ! $response->ok() ) {
			return 0.0;
		}
		$decoded = $response->json();
		if ( ! is_array( $decoded ) ) {
			return 0.0;
		}

		$path = trim( $this->settings->string( 'fx.rate_json_path', '' ) );
		if ( '' !== $path ) {
			$value = $decoded;
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					return 0.0;
				}
				$value = $value[ $segment ];
			}
			return (float) $value;
		}

		foreach ( [ 'price', 'rate', 'usdt', 'price_irt' ] as $key ) {
			if ( isset( $decoded[ $key ] ) ) {
				return (float) $decoded[ $key ];
			}
		}

		return 0.0;
	}
}
