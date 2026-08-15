<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Currency conversion for the Iranian PSPs.
 *
 * Every Iranian gateway settles in Rial, while almost every Iranian shop prices in Toman. The
 * conversion factor is a store setting (`payments.currency_multiplier`, 10 by default) rather than
 * a hardcoded 10, because a handful of stores really do price in Rial and one of them multiplying
 * by ten would charge the customer ten times over.
 */
final class Money {

	/** Rial-denominated currency codes: no conversion needed. */
	private const RIAL_CODES = [ 'IRR', 'RIAL' ];

	/** Toman-denominated currency codes. */
	private const TOMAN_CODES = [ 'IRT', 'TOMAN', 'TMN' ];

	/** Store amount -> integer Rial, as the gateways expect. */
	public static function to_rial( float $amount ): int {
		return (int) round( $amount * self::factor() );
	}

	/** Integer Rial coming back from a gateway -> store amount. */
	public static function from_rial( float $rial ): float {
		$factor = self::factor();
		return 0.0 === $factor ? (float) $rial : round( $rial / $factor, 2 );
	}

	/**
	 * Conversion factor from the store currency to Rial.
	 *
	 * A Rial-priced store is always 1 regardless of the configured multiplier, so a mistyped
	 * setting cannot inflate a charge.
	 */
	public static function factor(): float {
		$currency = strtoupper( igbz()->settings()->string( 'general.default_currency', 'IRT' ) );

		if ( in_array( $currency, self::RIAL_CODES, true ) ) {
			return 1.0;
		}

		if ( ! in_array( $currency, self::TOMAN_CODES, true ) ) {
			// A non-Iranian currency (a test store on USD, say) is passed through untouched.
			return 1.0;
		}

		$multiplier = igbz()->settings()->int( 'payments.currency_multiplier', 10 );

		return $multiplier > 0 ? (float) $multiplier : 10.0;
	}

	/** True when the store prices in Toman and amounts must be multiplied before they are sent. */
	/**
	 * Convert store currency to USD for crypto checkout.
	 *
	 * The operator sets nowpayments.usd_rate_irt (Rial per USD); the invoice
	 * price is rounded to cents. Falls back to a division by zero guard.
	 */
	public static function to_usd( float $amount ): float {
		$rate = (float) igbz()->settings()->float( 'nowpayments.usd_rate_irt', 0 );
		if ( $rate <= 0 ) {
			return 0.0;
		}
		return round( self::to_rial( $amount ) / $rate, 2 );
	}

	public static function store_is_toman(): bool {
		$currency = strtoupper( igbz()->settings()->string( 'general.default_currency', 'IRT' ) );
		return in_array( $currency, self::TOMAN_CODES, true );
	}
}
