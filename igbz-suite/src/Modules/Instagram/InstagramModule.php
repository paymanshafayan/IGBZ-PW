<?php
namespace IGBZ\Suite\Modules\Instagram;

use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\ContentScheduler;
use IGBZ\Suite\Modules\Instagram\Services\FunnelService;
use IGBZ\Suite\Modules\Instagram\Services\InsightsService;
use IGBZ\Suite\Modules\Instagram\Services\ManusClient;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Modules\Instagram\Services\PromptBuilder;
use IGBZ\Suite\Modules\Instagram\Services\SubscriberService;
use IGBZ\Suite\Modules\Instagram\Webhooks\ManusWebhook;
use IGBZ\Suite\Modules\Instagram\Webhooks\ManyChatWebhook;
use IGBZ\Suite\Support\Cron;
use IGBZ\Suite\Support\ModuleInterface;
use IGBZ\Suite\Support\Modules;
use IGBZ\Suite\Support\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Port of the nopCommerce "IGBZ.InstagramCommerce" plugin.
 *
 * The single functional difference from the original: the Instagram Graph API is gone. Content
 * creation, scheduling and publishing run through Manus, and comment-to-DM funnels run through
 * ManyChat. Both sit behind PublisherInterface / ContentGeneratorInterface so a Graph adapter can
 * be dropped back in later without touching the rest of the module.
 */
final class InstagramModule implements ModuleInterface {

	public function id(): string {
		return Modules::INSTAGRAM;
	}

	public function title(): string {
		return __( 'Instagram commerce', 'igbz-suite' );
	}

	public function description(): string {
		return __( 'Manus content studio (research, Canva graphics, reels, captions, auto-publishing at peak hours) plus ManyChat comment-to-DM funnels.', 'igbz-suite' );
	}

	public function register( Plugin $plugin ): void {
		$this->bind_services( $plugin );

		( new ManyChatWebhook(
			$plugin->get( 'ig.funnels' ),
			$plugin->get( 'ig.subscribers' ),
			$plugin->logger(),
			$plugin->get( 'ig.credentials' )
		) )->register();

		( new ManusWebhook(
			$plugin->db(),
			$plugin->get( 'ig.manus' ),
			$plugin->logger(),
			$plugin->get( 'ig.credentials' )
		) )->register();

		add_action( Cron::HOOK_FIVE_MINUTES, [ $this, 'run_five_minutes' ] );
		add_action( Cron::HOOK_HOURLY, [ $this, 'run_hourly' ] );
		add_action( Cron::HOOK_DAILY, [ $this, 'run_daily' ] );

		// Products deleted in WooCommerce must not leave funnels pointing at a 404.
		add_action( 'before_delete_post', [ $this, 'detach_deleted_product' ] );

		if ( is_admin() ) {
			( new Admin\AccountsPage() )->register();
			( new Admin\ContentPage() )->register();
			( new Admin\FunnelsPage() )->register();
			( new Admin\SubscribersPage() )->register();
			( new Admin\InsightsPage() )->register();
		}
	}

	private function bind_services( Plugin $plugin ): void {
		$plugin->bind( 'ig.prompts', static fn () => new PromptBuilder() );
		$plugin->bind( 'ig.credentials', static fn ( Plugin $c ) => new AccountCredentials( $c->db() ) );
		$plugin->bind( 'ig.manus_client', static fn ( Plugin $c ) => new ManusClient( $c->http(), $c->logger() ) );
		$plugin->bind(
			'ig.manus',
			static fn ( Plugin $c ) => new ManusService(
				$c->db(),
				$c->get( 'ig.manus_client' ),
				$c->get( 'ig.prompts' ),
				$c->logger(),
				$c->get( 'ig.credentials' )
			)
		);
		$plugin->bind(
			'ig.scheduler',
			static fn ( Plugin $c ) => new ContentScheduler(
				$c->db(),
				$c->get( 'ig.manus' ),
				$c->logger(),
				$c->get( 'ig.credentials' )
			)
		);
		$plugin->bind(
			'ig.insights',
			static fn ( Plugin $c ) => new InsightsService( $c->db(), $c->get( 'ig.manus' ), $c->get( 'ig.prompts' ), $c->logger() )
		);
		$plugin->bind( 'ig.manychat', static fn ( Plugin $c ) => new ManyChatClient( $c->http(), $c->logger() ) );
		$plugin->bind(
			'ig.subscribers',
			static fn ( Plugin $c ) => new SubscriberService(
				$c->db(),
				$c->get( 'ig.manychat' ),
				$c->logger(),
				$c->get( 'ig.credentials' )
			)
		);
		$plugin->bind(
			'ig.funnels',
			static fn ( Plugin $c ) => new FunnelService(
				$c->db(),
				$c->get( 'ig.manychat' ),
				$c->get( 'ig.subscribers' ),
				$c->has( 'wallet' )
					? $c->get( 'wallet' )
					: new \IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService( $c->db(), $c->logger() ),
				$c->logger(),
				$c->get( 'ig.credentials' )
			)
		);
	}

	// ------------------------------------------------------------------ cron

	public function run_five_minutes(): void {
		/** @var ContentScheduler $scheduler */
		$scheduler = igbz()->get( 'ig.scheduler' );
		$scheduler->tick();
	}

	public function run_hourly(): void {
		igbz()->get( 'ig.funnels' )->retry_failed();

		if ( igbz()->settings()->bool( 'manus.collect_insights', true ) ) {
			igbz()->get( 'ig.insights' )->reconcile();
		}
	}

	public function run_daily(): void {
		if ( igbz()->settings()->bool( 'manus.collect_insights', true ) ) {
			igbz()->get( 'ig.insights' )->collect_all();
		}
	}

	/** @param int $post_id */
	public function detach_deleted_product( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$db = igbz()->db();
		$db->update( 'ig_funnels', [ 'product_id' => 0 ], [ 'product_id' => $post_id ] );
		$db->update( 'ig_content', [ 'product_id' => 0 ], [ 'product_id' => $post_id ] );
	}

	// ---------------------------------------------------------------- health

	/** @return array<int,array{label:string,status:string,detail:string}> */
	public function health(): array {
		$settings = igbz()->settings();
		$db       = igbz()->db();
		$rows     = [];

		/** @var ManusService $manus */
		$manus = igbz()->get( 'ig.manus' );
		/** @var AccountCredentials $credentials */
		$credentials = igbz()->get( 'ig.credentials' );

		// Credentials are per account now, so health is a tally over the active accounts rather
		// than one look at a global option.
		$active     = $manus->all_accounts( true );
		$ready      = [ AccountCredentials::SERVICE_MANUS => 0, AccountCredentials::SERVICE_MANYCHAT => 0 ];
		$on_trial   = 0;
		$trial_dead = 0;
		foreach ( $active as $account ) {
			foreach ( array_keys( $ready ) as $service ) {
				if ( $credentials->has_key( $account, $service ) ) {
					++$ready[ $service ];
				}
			}
			if ( AccountCredentials::MODE_TRIAL === $credentials->mode( $account ) ) {
				++$on_trial;
				if ( ! $credentials->trial_is_open( $account ) ) {
					++$trial_dead;
				}
			}
		}
		$total = count( $active );

		foreach (
			[
				AccountCredentials::SERVICE_MANUS    => [
					__( 'Manus API', 'igbz-suite' ),
					sprintf(
						/* translators: %s: agent profile */
						__( 'Agent profile: %s', 'igbz-suite' ),
						$settings->string( 'manus.agent_profile', 'manus-1.6' )
					),
					__( 'No active account has a usable Manus key; content generation and publishing are disabled.', 'igbz-suite' ),
				],
				AccountCredentials::SERVICE_MANYCHAT => [
					__( 'ManyChat API', 'igbz-suite' ),
					__( 'A ManyChat Pro plan is required per page.', 'igbz-suite' ),
					__( 'No active account has a usable ManyChat key; subscriber lookups and flow sending are disabled.', 'igbz-suite' ),
				],
			] as $service => $labels
		) {
			[ $label, $note, $empty ] = $labels;
			$count                    = $ready[ $service ];

			$rows[] = [
				'label'  => $label,
				'status' => $count > 0 ? ( $count === $total ? 'ok' : 'warn' ) : 'error',
				'detail' => $count > 0
					? sprintf(
						/* translators: 1: accounts with a key, 2: total active accounts, 3: extra note */
						__( '%1$d of %2$d active accounts have a key. %3$s', 'igbz-suite' ),
						$count,
						$total,
						$note
					)
					: $empty,
			];
		}

		if ( $on_trial > 0 ) {
			$rows[] = [
				'label'  => __( 'Free trial accounts', 'igbz-suite' ),
				'status' => $trial_dead > 0 ? 'warn' : 'ok',
				'detail' => sprintf(
					/* translators: 1: accounts on trial, 2: accounts whose trial ended */
					__( '%1$d account(s) run on the shared trial key; %2$d have used it up or expired.', 'igbz-suite' ),
					$on_trial,
					$trial_dead
				),
			];
		}

		$untokened = 0;
		foreach ( $active as $account ) {
			if ( '' === (string) ( $account['manychat_webhook_token'] ?? '' ) ) {
				++$untokened;
			}
		}
		$rows[] = [
			'label'  => __( 'ManyChat webhook', 'igbz-suite' ),
			'status' => 0 === $untokened ? 'ok' : 'error',
			'detail' => 0 === $untokened
				? sprintf(
					/* translators: %s: webhook URL */
					__( 'Every account has its own External Request URL, e.g. %s', 'igbz-suite' ),
					esc_url_raw( add_query_arg( 'token', '***', rest_url( ManyChatWebhook::NAMESPACE . '/manychat/comment' ) ) )
				)
				: sprintf(
					/* translators: %d: number of accounts */
					__( '%d active account(s) have no webhook token; their incoming ManyChat requests are rejected. Re-save the account to mint one.', 'igbz-suite' ),
					$untokened
				),
		];

		$accounts = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'ig_accounts' ) . ' WHERE is_active = 1' );
		$rows[]   = [
			'label'  => __( 'Instagram accounts', 'igbz-suite' ),
			'status' => $accounts > 0 ? 'ok' : 'warn',
			'detail' => sprintf( /* translators: %d: count */ _n( '%d active account', '%d active accounts', $accounts, 'igbz-suite' ), $accounts ),
		];

		$funnels = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'ig_funnels' ) . ' WHERE is_active = 1' );
		$stuck   = (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'ig_funnel_hits' ) . ' WHERE delivered = 0 AND created_at >= %s',
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		);
		$rows[]  = [
			'label'  => __( 'Comment funnels', 'igbz-suite' ),
			'status' => $stuck > 0 ? 'warn' : 'ok',
			'detail' => sprintf(
				/* translators: 1: active funnels, 2: undelivered hits */
				__( '%1$d active funnel(s); %2$d undelivered hit(s) in the last 24h.', 'igbz-suite' ),
				$funnels,
				$stuck
			),
		];

		$failed = (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'ig_content' ) . ' WHERE status = %s',
			ManusService::STATUS_FAILED
		);
		// Publishing goes through a Manus task rather than a Graph API call that returns a media id,
		// so "finished" and "confirmed" are not the same thing. Count the rows we could not verify.
		$unverified = (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'ig_content' ) . " WHERE status = %s AND permalink = ''",
			ManusService::STATUS_PUBLISHED
		);

		$detail = sprintf( /* translators: %d: count */ __( '%d item(s) in the failed state.', 'igbz-suite' ), $failed );
		if ( $unverified > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: %d: count */
				_n(
					'%d published item returned no post link, so it could not be confirmed on Instagram.',
					'%d published items returned no post link, so they could not be confirmed on Instagram.',
					$unverified,
					'igbz-suite'
				),
				$unverified
			);
		}

		$rows[] = [
			'label'  => __( 'Content pipeline', 'igbz-suite' ),
			'status' => ( $failed > 0 || $unverified > 0 ) ? 'warn' : 'ok',
			'detail' => $detail,
		];

		return $rows;
	}
}
