<?php
declare( strict_types=1 );

use IGBZ\Suite\Modules\MultiTenant\Payments\Money;

/**
 * Every Iranian PSP settles in Rial while nearly every Iranian shop prices in Toman, so a wrong
 * factor here overcharges the customer tenfold. This pins the conversion in both directions.
 */
final class MoneyTest extends TestCase {

	public function run(): void {
		$settings = igbz_test_reset_settings();

		// Toman store, default multiplier.
		$settings->set( 'general.default_currency', 'IRT' );
		$this->assert_same( 10.0, Money::factor(), 'a Toman store multiplies by ten by default' );
		$this->assert_same( 1000000, Money::to_rial( 100000.0 ), '100,000 Toman is 1,000,000 Rial' );
		$this->assert_same( 100000.0, Money::from_rial( 1000000.0 ), 'the conversion reverses exactly' );
		$this->assert_true( Money::store_is_toman(), 'IRT is recognised as Toman' );

		// Rial store: never converted, even if a multiplier is left over in the settings.
		$settings->set( 'general.default_currency', 'IRR' );
		$settings->set( 'payments.currency_multiplier', 10 );
		$this->assert_same( 1.0, Money::factor(), 'a Rial store is never multiplied' );
		$this->assert_same( 100000, Money::to_rial( 100000.0 ), 'a Rial amount passes through untouched' );
		$this->assert_false( Money::store_is_toman(), 'IRR is not Toman' );

		// A non-Iranian currency is passed through rather than silently multiplied.
		$settings->set( 'general.default_currency', 'USD' );
		$this->assert_same( 1.0, Money::factor(), 'a foreign currency is not converted' );

		// The multiplier is honoured when it is deliberately changed.
		$settings->set( 'general.default_currency', 'IRT' );
		$settings->set( 'payments.currency_multiplier', 1 );
		$this->assert_same( 1.0, Money::factor(), 'a multiplier of one is honoured' );
		$this->assert_same( 5000, Money::to_rial( 5000.0 ), 'no conversion with a multiplier of one' );

		// A nonsensical multiplier must fall back rather than zero out the charge.
		$settings->set( 'payments.currency_multiplier', 0 );
		$this->assert_same( 10.0, Money::factor(), 'a zero multiplier falls back to ten' );
		$settings->set( 'payments.currency_multiplier', -5 );
		$this->assert_same( 10.0, Money::factor(), 'a negative multiplier falls back to ten' );

		// Rounding: gateways only accept integer Rial.
		$settings->set( 'payments.currency_multiplier', 10 );
		$this->assert_same( 12346, Money::to_rial( 1234.56 ), 'fractional Toman rounds to whole Rial' );
		$this->assert_same( 0, Money::to_rial( 0.0 ), 'zero converts to zero' );

		$settings->set( 'general.default_currency', 'TOMAN' );
		$this->assert_same( 10.0, Money::factor(), 'the TOMAN code is recognised' );
		$settings->set( 'general.default_currency', 'irt' );
		$this->assert_same( 10.0, Money::factor(), 'the currency code is case insensitive' );
	}
}
