<?php
declare( strict_types=1 );

use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;

/**
 * quote() is pure arithmetic, and it is the number the customer signs for, so the rounding must
 * never lose or invent rials.
 */
final class BnplQuoteTest extends TestCase {

	public function run(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'bnpl.default_installments', 4 );
		$settings->set( 'bnpl.interval_days', 30 );
		$settings->set( 'bnpl.fee_percent', 0 );

		$db     = new Db();
		$logger = new Logger( $settings );
		$service = new BnplService( $db, new WalletService( $db, $logger ), $logger );

		$quote = $service->quote( 1000000.0 );
		$this->assert_same( 4, count( $quote['installments'] ), 'four installments by default' );
		$this->assert_same( 250000.0, $quote['down_payment'], 'the down payment is one quarter' );
		$this->assert_same( 0.0, $quote['fee'], 'no fee when fee_percent is zero' );
		$this->assert_same( 1000000.0, $quote['total'], 'total equals principal without a fee' );
		$this->assert_same( 1000000.0, $this->sum( $quote ), 'the schedule sums back to the total' );
		$this->assert_same( 0, (int) $quote['installments'][0]['sequence'], 'the down payment is sequence 0' );
		$this->assert_same( gmdate( 'Y-m-d' ), $quote['installments'][0]['due_date'], 'the down payment is due today' );

		// An amount that does not divide evenly: the remainder must land on the last installment.
		$quote = $service->quote( 1000000.0, 3 );
		$this->assert_same( 3, count( $quote['installments'] ), 'three installments when asked for three' );
		$this->assert_same( 1000000.0, $this->sum( $quote ), 'an indivisible amount still sums exactly' );

		$quote = $service->quote( 999999.0, 7 );
		$this->assert_same( 999999.0, $this->sum( $quote ), 'an odd amount over seven installments sums exactly' );

		// With a fee the customer pays principal + fee, and the fee applies only to the financed part.
		$settings->set( 'bnpl.fee_percent', 10 );
		$quote = $service->quote( 1000000.0, 4 );
		$this->assert_same( 75000.0, $quote['fee'], 'the fee applies to the financed remainder only' );
		$this->assert_same( 1075000.0, $quote['total'], 'total is principal plus fee' );
		$this->assert_same( 1075000.0, $this->sum( $quote ), 'the schedule sums to the total with a fee' );

		// An explicit down payment overrides the equal split and is clamped to the principal.
		$settings->set( 'bnpl.fee_percent', 0 );
		$quote = $service->quote( 1000000.0, 4, 400000.0 );
		$this->assert_same( 400000.0, $quote['down_payment'], 'an explicit down payment is honoured' );
		$this->assert_same( 1000000.0, $this->sum( $quote ), 'an explicit down payment still sums exactly' );

		$quote = $service->quote( 1000000.0, 4, 5000000.0 );
		$this->assert_same( 1000000.0, $quote['down_payment'], 'a too-large down payment is clamped to the principal' );

		$quote = $service->quote( 1000000.0, 4, -50.0 );
		$this->assert_same( 0.0, $quote['down_payment'], 'a negative down payment is clamped to zero' );
		$this->assert_same( 1000000.0, $this->sum( $quote ), 'a zero down payment still sums exactly' );

		// A single installment means pay-in-full, never a division by zero.
		$quote = $service->quote( 250000.0, 1 );
		$this->assert_same( 250000.0, $this->sum( $quote ), 'one installment sums to the principal' );

		$quote = $service->quote( 250000.0, 0 );
		$this->assert_true( count( $quote['installments'] ) >= 1, 'a zero count is coerced to at least one installment' );

		// Due dates must march forward on the configured interval.
		$settings->set( 'bnpl.interval_days', 30 );
		$quote  = $service->quote( 1200000.0, 4 );
		$dates  = array_column( $quote['installments'], 'due_date' );
		$sorted = $dates;
		sort( $sorted );
		$this->assert_same( $sorted, $dates, 'due dates are in ascending order' );
		$gap = ( strtotime( $dates[2] ) - strtotime( $dates[1] ) ) / DAY_IN_SECONDS;
		$this->assert_same( 30, (int) round( $gap ), 'consecutive installments are one interval apart' );
	}

	/** @param array<string,mixed> $quote */
	private function sum( array $quote ): float {
		return round( array_sum( array_column( $quote['installments'], 'amount' ) ), 2 );
	}
}
