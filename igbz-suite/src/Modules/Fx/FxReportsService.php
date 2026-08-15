<?php
namespace IGBZ\Suite\Modules\Fx;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Financial reports for the operator.
 *
 * Everything is derived from the FX ledger and the bills table, so the
 * numbers always agree with what actually moved. The summary is computed in
 * PHP over a bounded read (last N ledger rows) rather than a giant SQL
 * aggregate — the ledger is append-only and small, and this keeps the SQLite
 * translator happy.
 */
final class FxReportsService {

	public function __construct( private Db $db ) {}

	/**
	 * Operator-wide summary for a date range.
	 *
	 * @return array<string,mixed>
	 */
	public function operator_summary( string $from = '', string $to = '' ): array {
		[ $rows, $bills ] = $this->read( 0, $from, $to );

		$topups_irt  = 0.0;
		$topups_usd  = 0.0;
		$fees_usd    = 0.0;
		$task_spend  = 0.0;
		$subscriptions = 0.0;
		$refunds     = 0.0;
		$ramp_irt    = 0.0;
		$topup_count = 0;

		foreach ( $rows as $row ) {
			$usd   = (float) $row['amount_usd'];
			$irt   = (float) $row['amount_irt'];
			$meta  = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
			$meta  = is_array( $meta ) ? $meta : [];

			switch ( $row['reason'] ) {
				case FxWalletService::REASON_TOPUP:
					$topups_irt  += $irt;
					$topups_usd  += $usd;
					$fees_usd    += (float) ( $meta['fee_usd'] ?? 0 );
					++$topup_count;
					break;
				case FxWalletService::REASON_TASK:
					$task_spend += -1 * $usd; // debits are negative
					break;
				case FxWalletService::REASON_SUBSCRIPTION:
					$subscriptions += -1 * $usd;
					break;
				case FxWalletService::REASON_REFUND:
					$refunds += $usd;
					break;
				case FxRampService::REASON_RAMP:
					$ramp_irt += $irt;
					break;
			}
		}

		$paid_bills = 0;
		$paid_usd   = 0.0;
		$unpaid     = 0;
		foreach ( $bills as $bill ) {
			if ( FxBillingService::STATUS_PAID === $bill['status'] ) {
				++$paid_bills;
				$paid_usd += (float) $bill['amount_usd'];
			} elseif ( FxBillingService::STATUS_UNPAID === $bill['status'] ) {
				++$unpaid;
			}
		}

		return [
			'period'         => [ 'from' => $from, 'to' => $to ],
			'topup_count'    => $topup_count,
			'topups_irt'     => $topups_irt,
			'topups_usd'     => $topups_usd,
			'fees_usd'       => $fees_usd,
			'task_spend_usd' => $task_spend,
			'subscriptions_usd' => $subscriptions,
			'refunds_usd'    => $refunds,
			'ramp_irt'       => $ramp_irt,
			'bills_paid'     => $paid_bills,
			'bills_paid_usd' => $paid_usd,
			'bills_unpaid'   => $unpaid,
		];
	}

	/**
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
	 */
	private function read( int $tenant_id, string $from, string $to ): array {
		$where  = [];
		$params = [];

		if ( $tenant_id > 0 ) {
			$where[]  = 'tenant_id = %d';
			$params[] = $tenant_id;
		}
		if ( '' !== $from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$where[]  = 'created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}

		$sql = 'SELECT * FROM ' . $this->db->table( 'fx_ledger' )
			. ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
			. ' ORDER BY id ASC LIMIT 5000';

		$rows = $this->db->results( $sql, ...$params );

		$bills = $this->db->results(
			'SELECT status, amount_usd FROM ' . $this->db->table( 'fx_bills' )
			. ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
		);

		return [ $rows, $bills ];
	}
}
