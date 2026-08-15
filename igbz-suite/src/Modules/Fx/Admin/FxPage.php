<?php
namespace IGBZ\Suite\Modules\Fx\Admin;

use IGBZ\Suite\Modules\Fx\FxMath;
use IGBZ\Suite\Modules\Fx\FxWalletService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * FX payments screen: top up the USD wallet with Rials, see the rate, the
 * prices the meter charges, and the wallet ledger.
 */
final class FxPage {

	public const SLUG = 'igbz-fx';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 16 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'FX payments', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_SUITE );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'FX payments', 'igbz-suite' ),
			__( 'Top up the USD credit wallet with Rials. The foreign-currency payout itself is handled by the payout adapter.', 'igbz-suite' )
		);

		$rates  = igbz()->get( 'fx.rates' );
		$wallet = igbz()->get( 'fx.wallet' );
		$tenant = (int) igbz()->tenancy()->id();
		$rate   = $rates->current();

		echo '<div class="igbz-cards">';
		printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( number_format( $rate, 0 ) ), esc_html__( 'IRT per USD', 'igbz-suite' ) );
		printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( number_format( $wallet->balance( $tenant )['balance_usd'], 2 ) ), esc_html__( 'USD credit', 'igbz-suite' ) );
		printf( '<div class="igbz-card"><strong>%1$s%%</strong><span>%2$s</span></div>', esc_html( (string) igbz()->settings()->float( 'fx.fee_percent', 10 ) ), esc_html__( 'Top-up fee', 'igbz-suite' ) );
		echo '</div>';

		$this->render_topup_form( $rate, $tenant );
		$this->render_prices();
		$this->render_ledger( $tenant );

		View::close();
	}

	private function render_topup_form( float $rate, int $tenant ): void {
		$payments = igbz()->has( 'payments' ) ? igbz()->get( 'payments' ) : null;
		$gateways = $payments ? $payments->enabled_gateways() : [];

		echo '<h2>' . esc_html__( 'Top up', 'igbz-suite' ) . '</h2>';

		if ( ! $payments ) {
			echo '<p>' . esc_html__( 'Enable the Multi-Tenant Stores module to charge with the Iranian gateways.', 'igbz-suite' ) . '</p>';
			return;
		}
		if ( $rate <= 0 ) {
			echo '<p>' . esc_html__( 'Set the exchange rate first: IGBZ → Settings → FX payments.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<form method="post" style="max-width:420px">';
		wp_nonce_field( 'igbz_fx_topup' );
		printf( '<input type="hidden" name="igbz_fx_action" value="topup" />' );

		$fee = (float) igbz()->settings()->float( 'fx.fee_percent', 10 );
		$sample = FxMath::quote( 10, $fee, $rate );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="igbz_fx_usd">' . esc_html__( 'USD amount', 'igbz-suite' ) . '</label></th><td>';
		printf( '<input type="number" id="igbz_fx_usd" name="usd" min="0.01" step="0.01" value="10" class="small-text" required />' );
		printf( ' <span class="description">%s</span>', esc_html( sprintf( '10 USD costs %s IRT incl. %s%% fee — you get 10.00 USD credit.', number_format( $sample['amount_irt'], 0 ), number_format( $fee, 0 ) ) ) );
		echo '</td></tr>';

		if ( count( $gateways ) > 1 ) {
			echo '<tr><th scope="row"><label for="igbz_fx_gateway">' . esc_html__( 'Gateway', 'igbz-suite' ) . '</label></th><td><select id="igbz_fx_gateway" name="gateway">';
			foreach ( $gateways as $gateway ) {
				printf( '<option value="%1$s">%2$s</option>', esc_attr( $gateway->id() ), esc_html( $gateway->title() ) );
			}
			echo '</select></td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Charge with Rials', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_prices(): void {
		$db   = igbz()->db();
		$rows = $db->results( 'SELECT * FROM ' . $db->table( 'fx_prices' ) . ' ORDER BY id' );

		echo '<h2>' . esc_html__( 'Prices', 'igbz-suite' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No prices seeded yet.', 'igbz-suite' ) . '</p>';
			return;
		}

		echo '<form method="post" style="max-width:520px">';
		wp_nonce_field( 'igbz_fx_price' );
		printf( '<input type="hidden" name="igbz_fx_action" value="price" />' );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Service', 'igbz-suite' ) . '</th><th>' . esc_html__( 'USD', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Active', 'igbz-suite' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$s</td><td><input type="number" name="price[%2$d]" min="0" step="0.01" value="%3$s" class="small-text" /></td><td>%4$s</td></tr>',
				esc_html( (string) $row['service'] ),
				(int) $row['id'],
				esc_attr( (string) $row['price_usd'] ),
				$row['is_active'] ? esc_html__( 'yes', 'igbz-suite' ) : esc_html__( 'no', 'igbz-suite' )
			);
		}
		echo '</tbody></table>';
		submit_button( __( 'Save prices', 'igbz-suite' ) );
		echo '</form>';
	}

	private function render_ledger( int $tenant ): void {
		$wallet = igbz()->get( 'fx.wallet' );
		$rows   = $wallet->ledger( $tenant, 50 );

		echo '<h2>' . esc_html__( 'Ledger', 'igbz-suite' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No entries yet.', 'igbz-suite' ) . '</p>';
			return;
		}

		$display = [];
		foreach ( $rows as $row ) {
			$display[] = [
				'id'        => '#' . (int) $row['id'],
				'reason'    => esc_html( (string) $row['reason'] ),
				'usd'       => sprintf( '%+.2f', (float) $row['amount_usd'] ),
				'irt'       => number_format( (float) $row['amount_irt'], 0 ),
				'reference' => esc_html( (string) $row['reference'] ),
				'when'      => esc_html( (string) $row['created_at'] ),
			];
		}

		View::table(
			[
				'#'         => '',
				'reason'    => __( 'Reason', 'igbz-suite' ),
				'usd'       => __( 'USD', 'igbz-suite' ),
				'irt'       => __( 'IRT', 'igbz-suite' ),
				'reference' => __( 'Reference', 'igbz-suite' ),
				'when'      => __( 'When', 'igbz-suite' ),
			],
			$display
		);
	}

	private function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified per action below.
		$action = isset( $_POST['igbz_fx_action'] ) ? sanitize_key( (string) $_POST['igbz_fx_action'] ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( 'topup' === $action ) {
			View::check_nonce( 'igbz_fx_topup' );

			$usd     = (float) ( $_POST['usd'] ?? 0 );
			$gateway = isset( $_POST['gateway'] ) ? sanitize_key( (string) $_POST['gateway'] ) : '';
			if ( $usd <= 0 ) {
				View::notice( __( 'Enter a positive USD amount.', 'igbz-suite' ), 'error' );
				return;
			}

			$result = igbz()->get( 'fx.topup' )->start(
				(int) igbz()->tenancy()->id(),
				get_current_user_id(),
				$usd,
				$gateway
			);

			if ( ! $result['ok'] ) {
				View::notice( $result['error'], 'error' );
				return;
			}

			wp_safe_redirect( (string) $result['redirect_url'] );
			exit;
		}

		if ( 'price' === $action ) {
			View::check_nonce( 'igbz_fx_price' );

			$prices = isset( $_POST['price'] ) && is_array( $_POST['price'] ) ? $_POST['price'] : [];
			$db     = igbz()->db();
			foreach ( $prices as $id => $price ) {
				$db->update(
					'fx_prices',
					[
						'price_usd'  => max( 0.0, (float) $price ),
						'updated_at' => current_time( 'mysql', true ),
					],
					[ 'id' => max( 1, (int) $id ) ]
				);
			}
			View::notice( __( 'Prices saved.', 'igbz-suite' ) );
		}
	}
}
