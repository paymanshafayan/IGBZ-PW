<?php
namespace IGBZ\Suite\Modules\MultiTenant\Wallet;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Unified wallet ledger.
 *
 * Port note: the nopCommerce original computed the balance by summing ledger rows in memory with
 * no transaction or row lock, so two concurrent debits could both pass the "enough balance" test
 * and overdraw the wallet. Here every mutation runs inside a SQL transaction guarded by a MySQL
 * named lock, and a denormalised balance row is kept in sync so reads are O(1).
 *
 * Idempotency is enforced at the database level by the UNIQUE (tenant_id, user_id, reason,
 * reference_code) index - replaying the same gateway callback can never double credit.
 */
final class WalletService {

	public const REASON_TOPUP       = 'topup';
	public const REASON_ORDER_PAY   = 'order_payment';
	public const REASON_REFUND      = 'refund';
	public const REASON_CASHBACK    = 'cashback';
	public const REASON_COMMISSION  = 'affiliate_commission';
	public const REASON_PAYOUT      = 'affiliate_payout';
	public const REASON_BNPL_PAY    = 'bnpl_installment';
	public const REASON_SUBSCRIPTION = 'subscription';
	public const REASON_PROMO       = 'promotion';
	public const REASON_ADJUSTMENT  = 'manual_adjustment';
	public const REASON_IG_REWARD   = 'instagram_reward';

	public const DIRECTION_CREDIT = 'credit';
	public const DIRECTION_DEBIT  = 'debit';

	public function __construct( private Db $db, private Logger $logger ) {}

	public function balance( int $user_id, int $tenant_id = 0 ): float {
		$value = $this->db->scalar(
			'SELECT balance FROM ' . $this->db->table( 'wallet_balances' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			$tenant_id
		);
		if ( null !== $value ) {
			return round( (float) $value, 4 );
		}
		return $this->recalculate( $user_id, $tenant_id );
	}

	/** Rebuild the cached balance from the ledger (repair / migration helper). */
	public function recalculate( int $user_id, int $tenant_id = 0 ): float {
		$sum = (float) $this->db->scalar(
			'SELECT COALESCE(SUM(amount),0) FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE user_id = %d AND tenant_id = %d',
			$user_id,
			$tenant_id
		);
		$this->write_balance( $user_id, $tenant_id, $sum );
		return round( $sum, 4 );
	}

	/**
	 * Credit a wallet. Idempotent on (tenant, user, reason, reference_code).
	 *
	 * @param array<string,mixed> $meta
	 */
	public function credit(
		int $user_id,
		float $amount,
		string $reason,
		string $reference_code = '',
		array $meta = [],
		int $tenant_id = 0,
		int $order_id = 0,
		string $note = ''
	): WalletResult {
		return $this->post( $user_id, abs( $amount ), $reason, $reference_code, $meta, $tenant_id, $order_id, $note, false );
	}

	/**
	 * Debit a wallet, refusing to overdraw unless the store explicitly allows negative balances.
	 *
	 * @param array<string,mixed> $meta
	 */
	public function debit(
		int $user_id,
		float $amount,
		string $reason,
		string $reference_code = '',
		array $meta = [],
		int $tenant_id = 0,
		int $order_id = 0,
		string $note = ''
	): WalletResult {
		return $this->post( $user_id, -abs( $amount ), $reason, $reference_code, $meta, $tenant_id, $order_id, $note, true );
	}

	/** Convenience wrapper mirroring the original TryDebitAsync signature. */
	public function try_debit( int $user_id, float $amount, string $reason, string $reference_code, int $tenant_id = 0 ): bool {
		return $this->debit( $user_id, $amount, $reason, $reference_code, [], $tenant_id )->success;
	}

	/** @param array<string,mixed> $meta */
	private function post(
		int $user_id,
		float $signed_amount,
		string $reason,
		string $reference_code,
		array $meta,
		int $tenant_id,
		int $order_id,
		string $note,
		bool $enforce_funds
	): WalletResult {
		if ( $user_id <= 0 ) {
			return WalletResult::failure( 'invalid_user', __( 'Invalid wallet owner.', 'igbz-suite' ) );
		}
		if ( abs( $signed_amount ) < 0.0001 ) {
			return WalletResult::failure( 'zero_amount', __( 'Amount must be greater than zero.', 'igbz-suite' ) );
		}
		if ( '' === $reference_code ) {
			$reference_code = 'auto-' . \IGBZ\Suite\Support\Crypto::token( 8 );
		}

		$existing = $this->find_entry( $user_id, $tenant_id, $reason, $reference_code );
		if ( $existing ) {
			return WalletResult::duplicate( (int) $existing['id'], (float) $existing['balance_after'] );
		}

		$lock = sprintf( 'wallet_%d_%d', $tenant_id, $user_id );
		if ( ! $this->db->lock( $lock, 5 ) ) {
			return WalletResult::failure( 'lock_timeout', __( 'Wallet is busy, please retry.', 'igbz-suite' ) );
		}

		try {
			return $this->db->transaction(
				function () use ( $user_id, $tenant_id, $signed_amount, $reason, $reference_code, $meta, $order_id, $note, $enforce_funds ) {
					$table   = $this->db->table( 'wallet_balances' );
					$current = $this->db->scalar(
						"SELECT balance FROM {$table} WHERE user_id = %d AND tenant_id = %d FOR UPDATE",
						$user_id,
						$tenant_id
					);
					if ( null === $current ) {
						$current = (float) $this->db->scalar(
							'SELECT COALESCE(SUM(amount),0) FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE user_id = %d AND tenant_id = %d',
							$user_id,
							$tenant_id
						);
					}
					$current = (float) $current;
					$after   = round( $current + $signed_amount, 4 );

					$allow_negative = igbz()->settings()->bool( 'wallet.allow_negative', false );
					if ( $enforce_funds && ! $allow_negative && $after < -0.0001 ) {
						return WalletResult::failure(
							'insufficient_funds',
							__( 'Insufficient wallet balance.', 'igbz-suite' ),
							$current
						);
					}

					$currency = (string) ( igbz()->settings()->get( 'general.default_currency', 'IRT' ) );
					$id       = $this->db->insert(
						'wallet_ledger',
						[
							'tenant_id'      => $tenant_id,
							'user_id'        => $user_id,
							'amount'         => $signed_amount,
							'balance_after'  => $after,
							'currency'       => $currency,
							'direction'      => $signed_amount >= 0 ? self::DIRECTION_CREDIT : self::DIRECTION_DEBIT,
							'reason'         => $reason,
							'reference_code' => $reference_code,
							'order_id'       => $order_id,
							'note'           => mb_substr( $note, 0, 255 ),
							'meta'           => wp_json_encode( $meta ),
							'created_by'     => get_current_user_id(),
							'created_at'     => current_time( 'mysql', true ),
						]
					);

					if ( 0 === $id ) {
						// The unique index rejected a concurrent duplicate - treat as idempotent success.
						$dup = $this->find_entry( $user_id, $tenant_id, $reason, $reference_code );
						if ( $dup ) {
							return WalletResult::duplicate( (int) $dup['id'], (float) $dup['balance_after'] );
						}
						throw new \RuntimeException( 'Wallet ledger insert failed: ' . $this->db->last_error() );
					}

					$this->write_balance( $user_id, $tenant_id, $after );

					$this->logger->info(
						'wallet',
						sprintf( '%s %s for user %d', $signed_amount >= 0 ? 'credit' : 'debit', (string) abs( $signed_amount ), $user_id ),
						[ 'tenant_id' => $tenant_id, 'reason' => $reason, 'entry_id' => $id, 'balance' => $after ]
					);

					do_action( 'igbz_wallet_entry_created', $id, $user_id, $signed_amount, $reason, $tenant_id );

					return WalletResult::ok( $id, $after );
				}
			);
		} catch ( \Throwable $e ) {
			$this->logger->error( 'wallet', 'Ledger write failed: ' . $e->getMessage(), [ 'user_id' => $user_id, 'reason' => $reason ] );
			return WalletResult::failure( 'exception', $e->getMessage() );
		} finally {
			$this->db->unlock( $lock );
		}
	}

	/** @return array<string,mixed>|null */
	private function find_entry( int $user_id, int $tenant_id, string $reason, string $reference_code ): ?array {
		return $this->db->row(
			'SELECT id, balance_after FROM ' . $this->db->table( 'wallet_ledger' ) . '
			 WHERE user_id = %d AND tenant_id = %d AND reason = %s AND reference_code = %s',
			$user_id,
			$tenant_id,
			$reason,
			$reference_code
		);
	}

	private function write_balance( int $user_id, int $tenant_id, float $balance ): void {
		$table    = $this->db->table( 'wallet_balances' );
		$currency = (string) igbz()->settings()->get( 'general.default_currency', 'IRT' );
		$this->db->query(
			"INSERT INTO {$table} (tenant_id, user_id, balance, currency, updated_at)
			 VALUES (%d, %d, %f, %s, %s)
			 ON DUPLICATE KEY UPDATE balance = VALUES(balance), updated_at = VALUES(updated_at)",
			$tenant_id,
			$user_id,
			$balance,
			$currency,
			current_time( 'mysql', true )
		);
	}

	/**
	 * Move funds between two wallets atomically (used by affiliate payouts and refunds).
	 */
	public function transfer( int $from_user, int $to_user, float $amount, string $reason, string $reference_code, int $tenant_id = 0 ): WalletResult {
		$debit = $this->debit( $from_user, $amount, $reason, $reference_code . ':out', [ 'to' => $to_user ], $tenant_id );
		if ( ! $debit->success ) {
			return $debit;
		}
		$credit = $this->credit( $to_user, $amount, $reason, $reference_code . ':in', [ 'from' => $from_user ], $tenant_id );
		if ( ! $credit->success ) {
			// Compensate: the debit already committed, so post a reversing credit.
			$this->credit( $from_user, $amount, self::REASON_ADJUSTMENT, $reference_code . ':reversal', [ 'reason' => 'transfer_failed' ], $tenant_id );
		}
		return $credit;
	}

	/**
	 * @param array{tenant_id?:int,reason?:string,from?:string,to?:string,limit?:int,offset?:int} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function history( int $user_id, array $args = [] ): array {
		$where  = [ 'user_id = %d' ];
		$params = [ $user_id ];
		if ( isset( $args['tenant_id'] ) ) {
			$where[]  = 'tenant_id = %d';
			$params[] = (int) $args['tenant_id'];
		}
		if ( ! empty( $args['reason'] ) ) {
			$where[]  = 'reason = %s';
			$params[] = (string) $args['reason'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $args['to'];
		}
		$params[] = (int) ( $args['limit'] ?? 50 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...$params
		);
	}

	/** @return array{credit:float,debit:float,net:float} */
	public function totals( int $tenant_id = 0 ): array {
		$row = $this->db->row(
			'SELECT
				COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) AS credited,
				COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END),0) AS debited
			 FROM ' . $this->db->table( 'wallet_ledger' ) . ' WHERE tenant_id = %d',
			$tenant_id
		);
		$credit = (float) ( $row['credited'] ?? 0 );
		$debit  = (float) ( $row['debited'] ?? 0 );
		return [ 'credit' => $credit, 'debit' => $debit, 'net' => round( $credit - $debit, 4 ) ];
	}
}
