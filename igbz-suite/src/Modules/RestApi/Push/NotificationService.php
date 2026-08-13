<?php
namespace IGBZ\Suite\Modules\RestApi\Push;

use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Turns store events into push notifications.
 *
 * The nop plugin only ever sent a manual "test" notification from the admin area; nothing in the
 * shop actually notified anyone. These hooks are the reason the device table exists: order status
 * changes, instalments coming due and completed downloads all reach the app.
 */
final class NotificationService {

	public function __construct( private FcmService $fcm, private DeviceRepository $devices, private Logger $logger ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status' ], 10, 4 );
		add_action( 'igbz_bnpl_reminder_due', [ $this, 'on_installment_due' ], 10, 4 );
		add_action( 'igbz_bnpl_contract_settled', [ $this, 'on_contract_settled' ], 10, 2 );
		add_action( 'igbz_wallet_entry_created', [ $this, 'on_wallet_entry' ], 10, 5 );
		add_action( 'igbz_lms_enrolled', [ $this, 'on_enrolled' ], 10, 3 );
		add_action( 'igbz_payment_verified', [ $this, 'on_payment_verified' ], 10, 2 );
	}

	private function enabled(): bool {
		return $this->fcm->is_enabled();
	}

	/** @param mixed $order */
	public function on_order_status( int $order_id, string $from, string $to, $order = null ): void {
		if ( ! $this->enabled() || ! igbz()->settings()->bool( 'api.push_order_updates', true ) ) {
			return;
		}

		$order = $order ?: ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null );
		if ( ! $order ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$titles = [
			'processing' => __( 'Your order has been paid', 'igbz-suite' ),
			'completed'  => __( 'Your order is complete', 'igbz-suite' ),
			'cancelled'  => __( 'Your order was cancelled', 'igbz-suite' ),
			'refunded'   => __( 'Your order was refunded', 'igbz-suite' ),
			'on-hold'    => __( 'Your order is on hold', 'igbz-suite' ),
		];

		if ( ! isset( $titles[ $to ] ) ) {
			return;
		}

		$this->fcm->send_to_user(
			$user_id,
			[
				'title' => $titles[ $to ],
				'body'  => sprintf(
					/* translators: 1: order number, 2: order status */
					__( 'Order %1$s is now "%2$s".', 'igbz-suite' ),
					$order->get_order_number(),
					function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $to ) : $to
				),
				'type'  => 'order_status',
				'link'  => 'igbz://orders/' . $order_id,
				'data'  => [ 'order_id' => $order_id, 'status' => $to ],
			]
		);
	}

	public function on_installment_due( int $installment_id, int $user_id, float $amount = 0.0, string $due_date = '' ): void {
		if ( ! $this->enabled() || $user_id <= 0 ) {
			return;
		}

		$db  = igbz()->db();
		$row = $db->row( 'SELECT * FROM ' . $db->table( 'bnpl_installments' ) . ' WHERE id = %d', $installment_id );
		if ( ! $row ) {
			return;
		}

		$this->fcm->send_to_user(
			$user_id,
			[
				'title' => __( 'An instalment is due', 'igbz-suite' ),
				'body'  => sprintf(
					/* translators: 1: amount, 2: due date */
					__( 'Instalment of %1$s is due on %2$s.', 'igbz-suite' ),
					$this->money( $amount > 0 ? $amount : (float) $row['amount'] ),
					'' !== $due_date ? $due_date : (string) $row['due_date']
				),
				'type'  => 'installment_due',
				'link'  => 'igbz://instalments/' . (int) $row['contract_id'],
				'data'  => [ 'installment_id' => $installment_id, 'contract_id' => (int) $row['contract_id'] ],
			]
		);
	}

	public function on_contract_settled( int $contract_id, int $user_id ): void {
		if ( ! $this->enabled() || $user_id <= 0 ) {
			return;
		}

		$this->fcm->send_to_user(
			$user_id,
			[
				'title' => __( 'Instalment plan settled', 'igbz-suite' ),
				'body'  => __( 'You have paid the final instalment. Nothing is outstanding.', 'igbz-suite' ),
				'type'  => 'contract_settled',
				'link'  => 'igbz://instalments/' . $contract_id,
				'data'  => [ 'contract_id' => $contract_id ],
			]
		);
	}

	/**
	 * Fires for every ledger row; only real credits the customer cares about get a notification.
	 * `$signed_amount` is negative for debits.
	 */
	public function on_wallet_entry( int $entry_id, int $user_id, float $signed_amount, string $reason, int $tenant_id = 0 ): void {
		if ( ! $this->enabled() || $user_id <= 0 || $signed_amount <= 0 ) {
			return;
		}

		// Internal bookkeeping movements are not worth a buzz in someone's pocket.
		$quiet = (array) apply_filters(
			'igbz_push_quiet_wallet_reasons',
			[ 'order_payment', 'subscription', 'bnpl_installment', 'manual_adjustment' ]
		);
		if ( in_array( $reason, $quiet, true ) ) {
			return;
		}

		$titles = [
			'topup'                => __( 'Your wallet was topped up', 'igbz-suite' ),
			'refund'               => __( 'A refund landed in your wallet', 'igbz-suite' ),
			'cashback'             => __( 'You earned cashback', 'igbz-suite' ),
			'affiliate_commission' => __( 'You earned a commission', 'igbz-suite' ),
			'instagram_reward'     => __( 'You received a reward', 'igbz-suite' ),
		];

		$this->fcm->send_to_user(
			$user_id,
			[
				'title' => $titles[ $reason ] ?? __( 'Your wallet balance changed', 'igbz-suite' ),
				'body'  => sprintf(
					/* translators: %s: amount */
					__( '%s was added to your wallet.', 'igbz-suite' ),
					$this->money( $signed_amount )
				),
				'type'  => 'wallet_credit',
				'link'  => 'igbz://wallet',
				'data'  => [ 'entry_id' => $entry_id, 'amount' => $signed_amount, 'reason' => $reason, 'tenant_id' => $tenant_id ],
			]
		);
	}

	public function on_enrolled( int $enrollment_id, int $course_id, int $user_id ): void {
		if ( ! $this->enabled() || $user_id <= 0 ) {
			return;
		}

		$db     = igbz()->db();
		$title  = (string) $db->scalar( 'SELECT title FROM ' . $db->table( 'courses' ) . ' WHERE id = %d', $course_id );

		$this->fcm->send_to_user(
			$user_id,
			[
				'title' => __( 'You have been enrolled', 'igbz-suite' ),
				'body'  => '' !== $title
					? sprintf(
						/* translators: %s: course title */
						__( 'The course "%s" is now available in your library.', 'igbz-suite' ),
						$title
					)
					: __( 'A new course is available in your library.', 'igbz-suite' ),
				'type'  => 'course_enrolled',
				'link'  => 'igbz://courses/' . $course_id,
				'data'  => [ 'enrollment_id' => $enrollment_id, 'course_id' => $course_id ],
			]
		);
	}

	/**
	 * @param array<string,mixed> $result
	 */
	public function on_payment_verified( int $payment_id, array $result = [] ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$db  = igbz()->db();
		$row = $db->row( 'SELECT * FROM ' . $db->table( 'payments' ) . ' WHERE id = %d', $payment_id );
		if ( ! $row || (int) $row['user_id'] <= 0 ) {
			return;
		}

		// Order payments are already covered by the WooCommerce status hook.
		if ( 'order' === (string) $row['purpose'] ) {
			return;
		}

		$this->fcm->send_to_user(
			(int) $row['user_id'],
			[
				'title' => __( 'Payment successful', 'igbz-suite' ),
				'body'  => sprintf(
					/* translators: %s: amount */
					__( 'Your payment of %s was confirmed.', 'igbz-suite' ),
					$this->money( (float) $row['amount'] )
				),
				'type'  => 'payment_verified',
				'link'  => 'igbz://payments/' . $payment_id,
				'data'  => [ 'payment_id' => $payment_id, 'purpose' => (string) $row['purpose'] ],
			]
		);
	}

	private function money( float $amount ): string {
		return function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount ) ) : (string) $amount;
	}

	/**
	 * Manual broadcast from the admin screen.
	 *
	 * @param array<string,mixed> $message
	 * @param array<string,mixed> $audience
	 * @return array{ok:bool,sent:int,invalid:int,failed:int,total:int,error:string}
	 */
	public function broadcast( array $message, array $audience = [] ): array {
		$result = $this->fcm->send( $message, $audience );

		$this->logger->info( 'push', 'Manual broadcast', [ 'title' => (string) ( $message['title'] ?? '' ), 'sent' => $result['sent'] ] );

		return $result;
	}

	public function devices(): DeviceRepository {
		return $this->devices;
	}
}
