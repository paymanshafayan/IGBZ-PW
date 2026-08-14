<?php
/**
 * Per-account Manus / ManyChat credential resolution.
 *
 * Credentials used to be one global key per install. That was wrong in two ways that this suite
 * pins down:
 *
 *  1. A ManyChat API key is scoped by ManyChat to a single page, so one shared key can only ever
 *     drive one Instagram account no matter how many tenants the install serves.
 *  2. The webhook token was global too, which meant an authenticated caller could post any
 *     tenant_id in the body and fire another tenant's funnels — spending their coupons and wallet
 *     credit. The token is now the identity and the tenant is read from the matched row.
 *
 * The trial engine keeps the old shared key alive as a metered free trial, so the tests below also
 * cover the two ways a trial closes (expiry and quota) and, most importantly, that a closed trial
 * yields NO key rather than quietly falling through to the operator's.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;

final class AccountCredentialsTest extends TestCase {

	private function credentials(): AccountCredentials {
		return new AccountCredentials( new Db() );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function account( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                     => 7,
				'tenant_id'              => 3,
				'is_active'              => 1,
				'credential_mode'        => AccountCredentials::MODE_OWN,
				'manus_api_key'          => null,
				'manychat_api_key'       => null,
				'manus_webhook_token'    => '',
				'manychat_webhook_token' => '',
				'trial_started_at'       => null,
				'trial_expires_at'       => null,
				'trial_tasks_used'       => 0,
			],
			$overrides
		);
	}

	public function run(): void {
		$this->test_own_keys_are_decrypted();
		$this->test_own_mode_never_borrows_the_shared_key();
		$this->test_trial_uses_the_shared_key();
		$this->test_expired_trial_yields_no_key();
		$this->test_exhausted_trial_yields_no_key();
		$this->test_zero_quota_means_unlimited_tasks();
		$this->test_trial_needs_a_shared_key_to_exist();
		$this->test_claim_is_free_for_own_accounts();
		$this->test_claim_is_guarded_by_the_quota_in_sql();
		$this->test_claim_closes_the_trial_when_the_last_task_goes();
		$this->test_lost_race_returns_false();
		$this->test_release_hands_a_failed_task_back();
		$this->test_default_quota_is_a_single_request();
		$this->test_webhook_token_is_minted_on_first_use();
		$this->test_webhook_url_carries_the_token();
	}

	private function test_own_keys_are_decrypted(): void {
		igbz_test_reset_settings();
		$credentials = $this->credentials();

		$account = $this->account(
			[
				'manus_api_key'    => Crypto::encrypt( 'manus-secret' ),
				'manychat_api_key' => Crypto::encrypt( 'manychat-secret' ),
			]
		);

		$this->assert_same( 'manus-secret', $credentials->key( $account, AccountCredentials::SERVICE_MANUS ), 'Manus key round-trips' );
		$this->assert_same( 'manychat-secret', $credentials->key( $account, AccountCredentials::SERVICE_MANYCHAT ), 'ManyChat key round-trips' );
		$this->assert_true( $credentials->has_key( $account, AccountCredentials::SERVICE_MANUS ), 'has_key sees the stored key' );
	}

	/**
	 * The whole point of "own" mode: a paying tenant must never be silently switched onto the
	 * operator's key, because that key is metered and billed to the operator.
	 */
	private function test_own_mode_never_borrows_the_shared_key(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );

		$account = $this->account(); // own mode, no key of its own

		$this->assert_same( '', $this->credentials()->key( $account, AccountCredentials::SERVICE_MANUS ), 'own mode with no key returns nothing' );
	}

	private function test_trial_uses_the_shared_key(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );
		$settings->set( 'trial.task_quota', 25 );

		$account = $this->account(
			[
				'credential_mode'  => AccountCredentials::MODE_TRIAL,
				'trial_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'trial_tasks_used' => 3,
			]
		);

		$credentials = $this->credentials();
		$this->assert_same( 'operator-key', $credentials->key( $account, AccountCredentials::SERVICE_MANUS ), 'open trial borrows the shared key' );
		$this->assert_true( $credentials->trial_is_open( $account ), 'trial is open' );
		$this->assert_same( '', $credentials->trial_blocked_reason( $account ), 'no blocked reason while open' );
	}

	private function test_expired_trial_yields_no_key(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );

		$account = $this->account(
			[
				'credential_mode'  => AccountCredentials::MODE_TRIAL,
				'trial_expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			]
		);

		$credentials = $this->credentials();
		$this->assert_true( $credentials->trial_expired( $account ), 'trial reads as expired' );
		$this->assert_false( $credentials->trial_is_open( $account ), 'expired trial is closed' );
		$this->assert_same( '', $credentials->key( $account, AccountCredentials::SERVICE_MANUS ), 'expired trial gets no key' );
		$this->assert_true( '' !== $credentials->trial_blocked_reason( $account ), 'expiry is explained to the admin' );
	}

	private function test_exhausted_trial_yields_no_key(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );
		$settings->set( 'trial.task_quota', 5 );

		$account = $this->account(
			[
				'credential_mode'  => AccountCredentials::MODE_TRIAL,
				'trial_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'trial_tasks_used' => 5,
			]
		);

		$credentials = $this->credentials();
		$this->assert_true( $credentials->trial_exhausted( $account ), 'quota reached counts as exhausted' );
		$this->assert_same( '', $credentials->key( $account, AccountCredentials::SERVICE_MANUS ), 'exhausted trial gets no key' );
	}

	private function test_zero_quota_means_unlimited_tasks(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );
		$settings->set( 'trial.task_quota', 0 );

		$account = $this->account(
			[
				'credential_mode'  => AccountCredentials::MODE_TRIAL,
				'trial_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'trial_tasks_used' => 9999,
			]
		);

		$credentials = $this->credentials();
		$this->assert_false( $credentials->trial_exhausted( $account ), 'quota 0 disables task counting' );
		$this->assert_same( 'operator-key', $credentials->key( $account, AccountCredentials::SERVICE_MANUS ), 'unlimited trial still gets the key' );
	}

	private function test_trial_needs_a_shared_key_to_exist(): void {
		igbz_test_reset_settings();
		$credentials = $this->credentials();

		$this->assert_false( $credentials->trial_available(), 'no shared key means no trial' );

		$account = $this->account( [ 'credential_mode' => AccountCredentials::MODE_TRIAL ] );
		$this->assert_true( '' !== $credentials->trial_blocked_reason( $account ), 'the missing shared key is explained' );
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function open_trial( array $overrides = [] ): array {
		return $this->account(
			array_merge(
				[
					'credential_mode'  => AccountCredentials::MODE_TRIAL,
					'trial_started_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					'trial_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					'trial_tasks_used' => 0,
				],
				$overrides
			)
		);
	}

	/** An account on its own key has no quota to burn, so claiming must be a free no-op. */
	private function test_claim_is_free_for_own_accounts(): void {
		igbz_test_reset_settings();
		$wpdb          = $GLOBALS['wpdb'];
		$wpdb->queries = [];

		$this->assert_true( $this->credentials()->claim_trial_task( $this->account() ), 'own-mode account is always allowed' );
		$this->assert_same( 0, count( $wpdb->queries ), 'own-mode account issues no quota write' );
	}

	/**
	 * With a quota of one, "check then increment" is a race: two cron ticks can both read
	 * trial_tasks_used = 0. The ceiling therefore has to be part of the UPDATE itself.
	 */
	private function test_claim_is_guarded_by_the_quota_in_sql(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );

		$wpdb          = $GLOBALS['wpdb'];
		$wpdb->queries = [];

		$this->assert_true( $this->credentials()->claim_trial_task( $this->open_trial() ), 'an open trial hands out its task' );

		$claim = $wpdb->queries[0] ?? '';
		$this->assert_contains( 'trial_tasks_used = trial_tasks_used + 1', $claim, 'the counter moves' );
		// The quota literal is asserted loosely because the wpdb double quotes %d placeholders.
		$this->assert_contains( 'trial_tasks_used <', $claim, 'the quota bounds the UPDATE itself' );
		$this->assert_contains( 'credential_mode =', $claim, 'and only a trial row can be claimed' );
	}

	/** The trial is one request, so consuming it must close the account, not leave it dangling. */
	private function test_claim_closes_the_trial_when_the_last_task_goes(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );

		$wpdb          = $GLOBALS['wpdb'];
		$wpdb->queries = [];

		$this->credentials()->claim_trial_task( $this->open_trial() );

		$this->assert_same( 2, count( $wpdb->queries ), 'claiming writes the counter and the closure' );
		$this->assert_contains( 'trial_expires_at', $wpdb->queries[1], 'the expiry is stamped once the quota is gone' );
		$this->assert_contains( 'trial_tasks_used >=', $wpdb->queries[1], 'only a used-up trial is closed' );
	}

	/** Zero affected rows is how the database says another worker took the last task. */
	private function test_lost_race_returns_false(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );

		$wpdb                 = $GLOBALS['wpdb'];
		$wpdb->queries        = [];
		$wpdb->next_affected  = [ 0 ];

		$this->assert_false( $this->credentials()->claim_trial_task( $this->open_trial() ), 'the loser of the race gets nothing' );
		$this->assert_same( 1, count( $wpdb->queries ), 'a lost claim does not go on to close the trial' );

		$wpdb->next_affected = [];
	}

	/** A network failure must not cost the tenant their only free request. */
	private function test_release_hands_a_failed_task_back(): void {
		igbz_test_reset_settings();
		$wpdb          = $GLOBALS['wpdb'];
		$wpdb->queries = [];

		$credentials = $this->credentials();

		$credentials->release_trial_task( $this->account() );
		$this->assert_same( 0, count( $wpdb->queries ), 'own-mode account has nothing to give back' );

		$credentials->release_trial_task( $this->open_trial( [ 'trial_tasks_used' => 1 ] ) );
		$this->assert_contains( 'trial_tasks_used = trial_tasks_used - 1', $wpdb->queries[0] ?? '', 'the counter is rolled back' );
		$this->assert_contains( 'trial_expires_at', $wpdb->queries[1] ?? '', 'the trial window is reopened' );
	}

	/** The product decision: the free trial is exactly one request, then it is over. */
	private function test_default_quota_is_a_single_request(): void {
		$settings = igbz_test_reset_settings();
		$settings->set( 'manus.api_key', 'operator-key' );

		$credentials = $this->credentials();
		$this->assert_same( 1, $credentials->trial_quota(), 'the trial is a single request by default' );

		$fresh = $this->open_trial();
		$this->assert_same( 1, $credentials->trial_remaining( $fresh ), 'an untouched trial has its one request' );
		$this->assert_same( 'operator-key', $credentials->key( $fresh, AccountCredentials::SERVICE_MANUS ), 'and it can reach the shared key' );

		$spent = $this->open_trial( [ 'trial_tasks_used' => 1 ] );
		$this->assert_same( 0, $credentials->trial_remaining( $spent ), 'one task empties it' );
		$this->assert_true( $credentials->trial_exhausted( $spent ), 'the trial reads as exhausted' );
		$this->assert_same( '', $credentials->key( $spent, AccountCredentials::SERVICE_MANUS ), 'and the shared key is withdrawn' );
	}

	/**
	 * An account without a token would reject every webhook ManyChat sends it, so the token is
	 * minted lazily rather than left empty.
	 */
	private function test_webhook_token_is_minted_on_first_use(): void {
		igbz_test_reset_settings();
		$wpdb          = $GLOBALS['wpdb'];
		$wpdb->queries = [];

		$credentials = $this->credentials();

		$existing = $credentials->webhook_token( $this->account( [ 'manychat_webhook_token' => 'already-set' ] ), AccountCredentials::SERVICE_MANYCHAT );
		$this->assert_same( 'already-set', $existing, 'an existing token is reused' );
		$this->assert_same( 0, count( $wpdb->queries ), 'reusing a token writes nothing' );

		$minted = $credentials->webhook_token( $this->account(), AccountCredentials::SERVICE_MANYCHAT );
		$this->assert_true( '' !== $minted, 'a token is minted when missing' );
		$this->assert_same( 'wp_igbz_ig_accounts', $wpdb->last_write['table'], 'the token is persisted on the account' );
		$this->assert_true( isset( $wpdb->last_write['data']['manychat_webhook_token'] ), 'the right column is written' );
	}

	private function test_webhook_url_carries_the_token(): void {
		igbz_test_reset_settings();

		$url = $this->credentials()->webhook_url(
			$this->account( [ 'manychat_webhook_token' => 'tok-123' ] ),
			AccountCredentials::SERVICE_MANYCHAT
		);

		$this->assert_contains( 'rest_route=/igbz/v1/manychat/comment', $url, 'the ManyChat route is used' );
		$this->assert_contains( 'token=tok-123', $url, 'the per-account token is in the URL' );

		$manus = $this->credentials()->webhook_url(
			$this->account( [ 'manus_webhook_token' => 'tok-abc' ] ),
			AccountCredentials::SERVICE_MANUS
		);
		$this->assert_contains( 'rest_route=/igbz/v1/manus/task', $manus, 'the Manus route is used' );
	}
}
