<?php
namespace IGBZ\Suite\Modules\MultiTenant\Gamification;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Spin & Win: one spin per cooldown per user, cryptographically fair, and the
 * prize is a real WooCommerce coupon (never a decorative string).
 */
final class GamificationService {

	private const LAST_SPIN_META = 'igbz_last_spin_at';

	public function __construct(
		private Db $db,
		private Logger $logger
	) {}

	/**
	 * @return array{ok:bool,message:string,coupon_code:string,percent:float}
	 */
	public function spin( int $user_id ): array {
		if ( ! igbz()->settings()->bool( 'gamification.enabled', true ) ) {
			return [ 'ok' => false, 'message' => __( 'Gamification is disabled.', 'igbz-suite' ), 'coupon_code' => '', 'percent' => 0 ];
		}

		$cooldown_hours = (int) igbz()->settings()->int( 'gamification.spin_cooldown_hours', 24 );
		$last           = (int) get_user_meta( $user_id, self::LAST_SPIN_META, true );
		$remaining      = $last + $cooldown_hours * HOUR_IN_SECONDS - time();

		if ( $remaining > 0 ) {
			return [
				'ok'          => false,
				'message'     => sprintf( /* translators: %s: hours */ __( 'Try again in about %s hours.', 'igbz-suite' ), (string) (int) ceil( $remaining / HOUR_IN_SECONDS ) ),
				'coupon_code' => '',
				'percent'     => 0,
			];
		}

		$presets = array_values( array_filter( array_map( 'floatval', explode( ',', igbz()->settings()->string( 'gamification.spin_rewards', '5,10,20' ) ) ) ) );
		if ( ! $presets ) {
			$presets = [ 5, 10, 20 ];
		}

		// Cryptographic, unbiased selection.
		$percent = $presets[ random_int( 0, count( $presets ) - 1 ) ];
		$code    = igbz()->settings()->string( 'gamification.spin_coupon_prefix', 'SPIN' ) . '-' . $user_id . '-' . gmdate( 'ymdHis' );

		$this->create_coupon( $code, $percent );
		update_user_meta( $user_id, self::LAST_SPIN_META, time() );
		$this->logger->info( 'gamification', 'Spin reward issued', [ 'user_id' => $user_id, 'code' => $code, 'percent' => $percent ] );

		return [ 'ok' => true, 'message' => '', 'coupon_code' => $code, 'percent' => $percent ];
	}

	private function create_coupon( string $code, float $percent ): void {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $percent );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_date_expires( time() + 7 * DAY_IN_SECONDS );
		$coupon->save();
	}
}
