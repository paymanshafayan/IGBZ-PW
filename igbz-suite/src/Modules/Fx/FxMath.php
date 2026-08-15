<?php
namespace IGBZ\Suite\Modules\Fx;

defined( 'ABSPATH' ) || exit;

/**
 * Pure FX arithmetic.
 *
 * The client's rule: a Rial top-up must add the FX fee on top of the requested
 * USD amount, so a 10 USD request at a 10% fee charges 11 USD worth of Rials
 * while only 10 USD of credit lands in the wallet. Keeping the math here, free
 * of settings and database, makes the fee impossible to mis-apply elsewhere.
 */
final class FxMath {

	/**
	 * @return array{gross_usd:float,fee_usd:float,net_usd:float,amount_irt:float}
	 */
	public static function quote( float $usd_requested, float $fee_percent, float $rate_irt_per_usd ): array {
		$usd_requested = max( 0.0, $usd_requested );
		$fee_percent   = max( 0.0, min( 100.0, $fee_percent ) );
		$rate_irt_per_usd = max( 0.0, $rate_irt_per_usd );

		$gross_usd = round( $usd_requested * ( 1 + $fee_percent / 100 ), 4 );
		$fee_usd   = round( $gross_usd - $usd_requested, 4 );

		return [
			'gross_usd'  => $gross_usd,
			'fee_usd'    => $fee_usd,
			'net_usd'    => $usd_requested,
			'amount_irt' => round( $gross_usd * $rate_irt_per_usd, 0 ),
		];
	}
}
