<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which Manus / ManyChat credentials one Instagram account talks to.
 *
 * Every tenant is fully independent: keys live on the ig_accounts row, encrypted at rest, and are
 * never shared between accounts. This matters beyond tidiness -- a ManyChat API key is scoped to a
 * single page by ManyChat itself, so "one key for the whole install" can only ever drive one page.
 *
 * Two modes:
 *   own   - the account carries its own keys. The normal, unlimited mode.
 *   trial - the account borrows the operator's global keys, capped by a task quota and an expiry
 *           date so a free trial cannot quietly run up the operator's Manus bill.
 *
 * Trial accounting is deliberately in this class rather than in the clients: it is the one place
 * that already knows which key is about to be used, so the quota can never be bypassed by calling
 * a different client method.
 */
final class AccountCredentials {

	public const MODE_OWN   = 'own';
	public const MODE_TRIAL = 'trial';

	public const SERVICE_MANUS    = 'manus';
	public const SERVICE_MANYCHAT = 'manychat';

	public function __construct( private Db $db ) {}

	// ------------------------------------------------------------------ keys

	/**
	 * The API key this account should use, or '' when it has none.
	 *
	 * @param array<string,mixed> $account
	 */
	public function key( array $account, string $service ): string {
		if ( self::MODE_TRIAL === $this->mode( $account ) ) {
			return $this->trial_is_open( $account )
				? (string) igbz()->settings()->get( $service . '.api_key', '' )
				: '';
		}

		$stored = (string) ( $account[ $service . '_api_key' ] ?? '' );
		if ( '' === $stored ) {
			return '';
		}
		return (string) ( Crypto::decrypt( $stored ) ?? '' );
	}

	/** @param array<string,mixed> $account */
	public function has_key( array $account, string $service ): bool {
		return '' !== $this->key( $account, $service );
	}

	/** @param array<string,mixed> $account */
	public function mode( array $account ): string {
		return self::MODE_TRIAL === ( $account['credential_mode'] ?? self::MODE_OWN )
			? self::MODE_TRIAL
			: self::MODE_OWN;
	}

	/** Encrypt a key for storage. An empty string clears the column. */
	public function encrypt_key( string $plain ): ?string {
		$plain = trim( $plain );
		return '' === $plain ? null : Crypto::encrypt( $plain );
	}

	// ----------------------------------------------------------------- trial

	/** @param array<string,mixed> $account */
	public function trial_is_open( array $account ): bool {
		if ( self::MODE_TRIAL !== $this->mode( $account ) ) {
			return false;
		}
		if ( ! $this->trial_available() ) {
			return false;
		}
		return ! $this->trial_expired( $account ) && ! $this->trial_exhausted( $account );
	}

	/** @param array<string,mixed> $account */
	public function trial_expired( array $account ): bool {
		$expires = (string) ( $account['trial_expires_at'] ?? '' );
		if ( '' === $expires ) {
			return false;
		}
		return strtotime( $expires . ' UTC' ) < time();
	}

	/** @param array<string,mixed> $account */
	public function trial_exhausted( array $account ): bool {
		$quota = $this->trial_quota();
		if ( $quota <= 0 ) {
			return false; // 0 == unlimited task count, expiry still applies.
		}
		return (int) ( $account['trial_tasks_used'] ?? 0 ) >= $quota;
	}

	public function trial_quota(): int {
		return (int) igbz()->settings()->int( 'trial.task_quota', 25 );
	}

	public function trial_days(): int {
		return (int) igbz()->settings()->int( 'trial.days', 14 );
	}

	/**
	 * Is the operator's global key present at all, and is the trial switched on? Without both, no
	 * trial can start and an account in trial mode simply gets no key.
	 */
	public function trial_available(): bool {
		if ( ! igbz()->settings()->bool( 'trial.enabled', true ) ) {
			return false;
		}
		return '' !== (string) igbz()->settings()->get( 'manus.api_key', '' )
			|| '' !== (string) igbz()->settings()->get( 'manychat.api_key', '' );
	}

	/**
	 * Human-readable reason the trial is closed, for admin screens. '' when it is open.
	 *
	 * @param array<string,mixed> $account
	 */
	public function trial_blocked_reason( array $account ): string {
		if ( self::MODE_TRIAL !== $this->mode( $account ) ) {
			return '';
		}
		if ( ! $this->trial_available() ) {
			return __( 'No shared trial key is configured on this site.', 'igbz-suite' );
		}
		if ( $this->trial_expired( $account ) ) {
			return __( 'The free trial period has ended. Add your own API keys to continue.', 'igbz-suite' );
		}
		if ( $this->trial_exhausted( $account ) ) {
			return __( 'The free trial task quota is used up. Add your own API keys to continue.', 'igbz-suite' );
		}
		return '';
	}

	/**
	 * Start (or restart) the trial clock for an account. Idempotent: an account already on a
	 * running trial keeps its original dates so re-saving the form cannot extend it.
	 */
	public function start_trial( int $account_id ): void {
		$account = $this->account( $account_id );
		if ( ! $account ) {
			return;
		}
		if ( '' !== (string) ( $account['trial_started_at'] ?? '' ) ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$this->db->update(
			'ig_accounts',
			[
				'trial_started_at' => $now,
				'trial_expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + ( $this->trial_days() * DAY_IN_SECONDS ) ),
				'trial_tasks_used' => 0,
			],
			[ 'id' => $account_id ]
		);
	}

	/**
	 * Count one billable trial task. Called only when the shared key was actually used, so an
	 * account on its own keys never burns quota.
	 *
	 * @param array<string,mixed> $account
	 */
	public function consume_trial_task( array $account ): void {
		if ( self::MODE_TRIAL !== $this->mode( $account ) ) {
			return;
		}
		$id = (int) ( $account['id'] ?? 0 );
		if ( $id <= 0 ) {
			return;
		}
		$table = $this->db->table( 'ig_accounts' );
		$this->db->query(
			$this->db->prepare( "UPDATE {$table} SET trial_tasks_used = trial_tasks_used + 1 WHERE id = %d", $id )
		);
	}

	// -------------------------------------------------------------- webhooks

	/**
	 * Find the account a webhook call belongs to from its token alone.
	 *
	 * The token IS the identity: the tenant is read from the matched row, never from the request
	 * body. Before per-account tokens a caller could post someone else's tenant_id and fire their
	 * funnels, spending their coupons and wallet credit.
	 *
	 * @return array<string,mixed>|null
	 */
	public function account_by_webhook_token( string $token, string $service ): ?array {
		$token = trim( $token );
		if ( '' === $token ) {
			return null;
		}
		$column = self::SERVICE_MANUS === $service ? 'manus_webhook_token' : 'manychat_webhook_token';

		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . " WHERE {$column} = %s",
			$token
		);
	}

	/** Issue a fresh webhook token for one service on one account. */
	public function rotate_webhook_token( int $account_id, string $service ): string {
		$column = self::SERVICE_MANUS === $service ? 'manus_webhook_token' : 'manychat_webhook_token';
		$token  = Crypto::token( 24 );

		$this->db->update( 'ig_accounts', [ $column => $token ], [ 'id' => $account_id ] );
		return $token;
	}

	/**
	 * The token for one service, minting one on first use so an account is never left without a
	 * usable webhook URL.
	 *
	 * @param array<string,mixed> $account
	 */
	public function webhook_token( array $account, string $service ): string {
		$column = self::SERVICE_MANUS === $service ? 'manus_webhook_token' : 'manychat_webhook_token';
		$token  = (string) ( $account[ $column ] ?? '' );
		if ( '' !== $token ) {
			return $token;
		}
		$id = (int) ( $account['id'] ?? 0 );
		return $id > 0 ? $this->rotate_webhook_token( $id, $service ) : '';
	}

	/** @param array<string,mixed> $account */
	public function webhook_url( array $account, string $service ): string {
		$token = $this->webhook_token( $account, $service );
		if ( '' === $token ) {
			return '';
		}
		$route = self::SERVICE_MANUS === $service ? '/igbz/v1/manus/task' : '/igbz/v1/manychat/comment';

		return add_query_arg( 'token', $token, home_url( '/?rest_route=' . $route ) );
	}

	// ----------------------------------------------------------------- utils

	/** @return array<string,mixed>|null */
	private function account( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE id = %d', $id );
	}
}
