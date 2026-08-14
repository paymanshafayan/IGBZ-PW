<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\ContentScheduler;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;
use IGBZ\Suite\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Instagram accounts: brand voice, niche, timezone and the peak posting hours the scheduler
 * uses when it auto-publishes.
 */
final class AccountsPage {

	public const SLUG = 'igbz-ig-accounts';

	private const NONCE = 'igbz_ig_accounts';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 20 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'IG Accounts', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	private function manus(): ManusService {
		return igbz()->get( 'ig.manus' );
	}

	private function scheduler(): ContentScheduler {
		return igbz()->get( 'ig.scheduler' );
	}

	private function credentials(): AccountCredentials {
		return igbz()->get( 'ig.credentials' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$account_id = isset( $_GET['account'] ) ? (int) $_GET['account'] : 0;
		$new        = isset( $_GET['new'] );
		// phpcs:enable

		View::open(
			__( 'Instagram accounts', 'igbz-suite' ),
			__( 'Each account holds the brand brief Manus works from and the posting hours the scheduler targets. Leave peak hours empty to let the plugin learn them from the engagement insights.', 'igbz-suite' )
		);

		if ( $account_id || $new ) {
			$this->render_editor( $account_id );
			View::close();
			return;
		}

		$this->render_list();
		View::close();
	}

	private function render_list(): void {
		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( Menu::url( self::SLUG, [ 'new' => 1 ] ) ),
			esc_html__( 'Add account', 'igbz-suite' )
		);

		$db      = igbz()->db();
		$rows    = $this->manus()->accounts( igbz()->tenancy()->id(), false );
		$display = [];

		foreach ( $rows as $account ) {
			$id        = (int) $account['id'];
			$queued    = (int) $db->scalar(
				'SELECT COUNT(*) FROM ' . $db->table( 'ig_content' ) . ' WHERE account_id = %d AND status NOT IN (%s, %s)',
				$id,
				ManusService::STATUS_PUBLISHED,
				ManusService::STATUS_FAILED
			);
			$published = (int) $db->scalar(
				'SELECT COUNT(*) FROM ' . $db->table( 'ig_content' ) . ' WHERE account_id = %d AND status = %s',
				$id,
				ManusService::STATUS_PUBLISHED
			);

			$display[] = [
				'username' => sprintf(
					'<a href="%1$s"><strong>@%2$s</strong></a><br /><span class="description">%3$s</span>',
					esc_url( Menu::url( self::SLUG, [ 'account' => $id ] ) ),
					esc_html( (string) $account['username'] ),
					esc_html( (string) $account['display_name'] )
				),
				'niche'    => esc_html( (string) $account['niche'] ?: '—' ),
				'peak'     => esc_html( implode( ' · ', $this->scheduler()->peak_hours( $account ) ) ),
				'tz'       => esc_html( (string) $account['timezone'] ),
				'queue'    => esc_html( (string) $queued ),
				'live'     => esc_html( (string) $published ),
				'status'   => View::status_pill( $account['is_active'] ? 'ok' : 'warn' ),
				'actions'  => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s" onclick="return confirm(\'%4$s\')">%5$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'plan' => $id ] ), self::NONCE ) ),
					esc_html__( 'Plan week', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'delete' => $id ] ), self::NONCE ) ),
					esc_js( __( 'Delete this account? Content rows stay but lose their owner.', 'igbz-suite' ) ),
					esc_html__( 'Delete', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'username' => __( 'Account', 'igbz-suite' ),
				'niche'    => __( 'Niche', 'igbz-suite' ),
				'peak'     => __( 'Peak hours', 'igbz-suite' ),
				'tz'       => __( 'Timezone', 'igbz-suite' ),
				'queue'    => __( 'In queue', 'igbz-suite' ),
				'live'     => __( 'Published', 'igbz-suite' ),
				'status'   => __( 'Active', 'igbz-suite' ),
				'actions'  => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No Instagram accounts yet. Add one to start the Manus pipeline.', 'igbz-suite' )
		);
	}

	private function render_editor( int $account_id ): void {
		$account = $account_id > 0 ? $this->manus()->account( $account_id ) : null;
		if ( $account_id > 0 && ! $account ) {
			View::notice( __( 'Account not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$account = $account ?? [
			'username'         => '',
			'display_name'     => '',
			'manus_project_id' => '',
			'manychat_page_id' => '',
			'timezone'         => wp_timezone_string(),
			'niche'            => '',
			'brand_voice'      => '',
			'peak_hours'       => '',
			'is_active'        => 1,
			'credential_mode'  => AccountCredentials::MODE_OWN,
			'manus_api_key'    => '',
			'manychat_api_key' => '',
			'trial_tasks_used' => 0,
			'trial_expires_at' => '',
		];

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'Back to accounts', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="account_id" value="%d" />', $account_id );

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->text_row( 'username', __( 'Instagram username', 'igbz-suite' ), (string) $account['username'], __( 'Without the @.', 'igbz-suite' ) );
		$this->text_row( 'display_name', __( 'Display name', 'igbz-suite' ), (string) $account['display_name'] );
		$this->text_row( 'niche', __( 'Niche', 'igbz-suite' ), (string) $account['niche'], __( 'Feeds the Manus trend-research prompt, e.g. "handmade leather bags".', 'igbz-suite' ) );

		echo '<tr><th scope="row"><label for="igbz_brand_voice">' . esc_html__( 'Brand voice', 'igbz-suite' ) . '</label></th><td>';
		printf(
			'<textarea id="igbz_brand_voice" name="brand_voice" rows="5" class="large-text">%s</textarea>',
			esc_textarea( (string) $account['brand_voice'] )
		);
		printf( '<p class="description">%s</p>', esc_html__( 'Tone, forbidden words, emoji policy, target audience. Manus receives this verbatim.', 'igbz-suite' ) );
		echo '</td></tr>';

		$this->text_row(
			'peak_hours',
			__( 'Peak hours', 'igbz-suite' ),
			(string) $account['peak_hours'],
			__( 'Comma separated 24h times, e.g. 12:00,18:30,21:00. Leave empty to learn them from insights.', 'igbz-suite' )
		);

		echo '<tr><th scope="row"><label for="igbz_timezone">' . esc_html__( 'Timezone', 'igbz-suite' ) . '</label></th><td>';
		echo '<select id="igbz_timezone" name="timezone">';
		foreach ( timezone_identifiers_list() as $tz ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $tz ),
				selected( $tz, (string) $account['timezone'], false )
			);
		}
		echo '</select></td></tr>';

		$this->text_row( 'manus_project_id', __( 'Manus project ID', 'igbz-suite' ), (string) $account['manus_project_id'], __( 'Optional. Keeps this account\'s tasks inside one Manus project.', 'igbz-suite' ) );
		$this->text_row( 'manychat_page_id', __( 'ManyChat page ID', 'igbz-suite' ), (string) $account['manychat_page_id'], __( 'Optional. Used to route incoming funnel events to the right account.', 'igbz-suite' ) );

		echo '</tbody></table>';
		$this->render_credentials( $account, $account_id );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Active', 'igbz-suite' ) . '</th><td><label>';
		printf( '<input type="checkbox" name="is_active" value="1" %s /> ', checked( (int) $account['is_active'], 1, false ) );
		esc_html_e( 'The scheduler only touches active accounts.', 'igbz-suite' );
		echo '</label></td></tr>';
		echo '</tbody></table>';

		submit_button( $account_id > 0 ? __( 'Save account', 'igbz-suite' ) : __( 'Create account', 'igbz-suite' ) );
		echo '</form>';

		if ( $account_id > 0 ) {
			$this->render_planner( $account_id );
		}
	}

	private function render_planner( int $account_id ): void {
		echo '<hr /><h2>' . esc_html__( 'Quick brief', 'igbz-suite' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Drops one item into the queue. The five-minute cron sends it to Manus, absorbs the produced media, then schedules it at the next free peak slot.', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="igbz_ig_do" value="queue" />';
		printf( '<input type="hidden" name="account_id" value="%d" />', $account_id );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="igbz_brief_kind">' . esc_html__( 'Kind', 'igbz-suite' ) . '</label></th><td>';
		echo '<select id="igbz_brief_kind" name="kind">';
		foreach (
			[
				ManusService::KIND_POST     => __( 'Static post', 'igbz-suite' ),
				ManusService::KIND_CAROUSEL => __( 'Carousel', 'igbz-suite' ),
				ManusService::KIND_STORY    => __( 'Story', 'igbz-suite' ),
				ManusService::KIND_REEL     => __( 'Reel', 'igbz-suite' ),
			] as $value => $label
		) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $value ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		$this->text_row( 'subject', __( 'Subject', 'igbz-suite' ), '', __( 'What the piece is about.', 'igbz-suite' ) );
		$this->text_row( 'goal', __( 'Goal', 'igbz-suite' ), '', __( 'e.g. drive comments with the keyword LINK.', 'igbz-suite' ) );
		$this->text_row( 'product_id', __( 'Product ID', 'igbz-suite' ), '', __( 'Optional WooCommerce product to promote.', 'igbz-suite' ) );
		echo '</tbody></table>';

		submit_button( __( 'Queue content', 'igbz-suite' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * API keys and webhook endpoints for one account.
	 *
	 * Keys are per account on purpose: a ManyChat key is scoped to a single page by ManyChat, so a
	 * shared key could only ever drive one page. Stored keys are shown masked and only overwritten
	 * when a new value is typed.
	 *
	 * @param array<string,mixed> $account
	 */
	private function render_credentials( array $account, int $account_id ): void {
		$credentials = $this->credentials();
		$mode        = $credentials->mode( $account );
		$saved       = $account_id > 0;

		echo '<h2>' . esc_html__( 'API credentials', 'igbz-suite' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Each account talks to Manus and ManyChat with its own keys, so tenants never share a quota or a page.', 'igbz-suite' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Key source', 'igbz-suite' ) . '</th><td>';
		foreach (
			[
				AccountCredentials::MODE_OWN   => __( 'Own keys — unlimited.', 'igbz-suite' ),
				AccountCredentials::MODE_TRIAL => __( 'Free trial — borrows the shared keys of this site, limited.', 'igbz-suite' ),
			] as $value => $label
		) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="radio" name="credential_mode" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $value ),
				checked( $value, $mode, false ),
				esc_html( $label )
			);
		}
		if ( ! $credentials->trial_available() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'No shared key is set on this site yet, so the trial cannot run. Add manus.api_key / manychat.api_key in Settings.', 'igbz-suite' )
			);
		}
		echo '</td></tr>';

		if ( AccountCredentials::MODE_TRIAL === $mode && $saved ) {
			$quota   = $credentials->trial_quota();
			$used    = (int) ( $account['trial_tasks_used'] ?? 0 );
			$expires = (string) ( $account['trial_expires_at'] ?? '' );
			$reason  = $credentials->trial_blocked_reason( $account );

			echo '<tr><th scope="row">' . esc_html__( 'Trial status', 'igbz-suite' ) . '</th><td>';
			if ( $quota <= 0 ) {
				$usage = sprintf(
					/* translators: %d: number of tasks already used */
					__( 'Tasks used: %d (no task limit, the expiry date applies)', 'igbz-suite' ),
					$used
				);
			} elseif ( 1 === $quota ) {
				$usage = $used > 0
					? __( 'The single free request has been used. Switch to your own API keys to keep going.', 'igbz-suite' )
					: __( 'One free request available. It is spent on the first task this account sends, and the trial closes straight after.', 'igbz-suite' );
			} else {
				$usage = sprintf(
					/* translators: 1: tasks used, 2: task quota */
					__( 'Tasks used: %1$d of %2$d', 'igbz-suite' ),
					$used,
					$quota
				);
			}
			printf( '<p>%s</p>', esc_html( $usage ) );
			if ( '' !== $expires ) {
				printf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: expiry date */
							__( 'Expires: %s UTC', 'igbz-suite' ),
							$expires
						)
					)
				);
			}
			if ( '' !== $reason ) {
				printf( '<p><strong>%s</strong></p>', esc_html( $reason ) );
			}
			echo '</td></tr>';
		}

		$this->secret_row(
			'manus_api_key',
			__( 'Manus API key', 'igbz-suite' ),
			! empty( $account['manus_api_key'] ),
			__( 'Sent as the x-manus-api-key header. Only used when the key source is "Own keys".', 'igbz-suite' )
		);
		$this->secret_row(
			'manychat_api_key',
			__( 'ManyChat API key', 'igbz-suite' ),
			! empty( $account['manychat_api_key'] ),
			__( 'ManyChat → Settings → API. The key belongs to one Instagram page.', 'igbz-suite' )
		);

		if ( $saved ) {
			$this->webhook_row(
				__( 'ManyChat webhook URL', 'igbz-suite' ),
				$credentials->webhook_url( $account, AccountCredentials::SERVICE_MANYCHAT ),
				__( 'Paste into the External Request action of your ManyChat comment flow. The token identifies this account — do not share it.', 'igbz-suite' )
			);
			$this->webhook_row(
				__( 'Manus webhook URL', 'igbz-suite' ),
				$credentials->webhook_url( $account, AccountCredentials::SERVICE_MANUS ),
				__( 'Register in Manus so finished tasks report back without waiting for the polling cron.', 'igbz-suite' )
			);

			printf(
				'<tr><th scope="row">%1$s</th><td><a class="button button-small" href="%2$s" onclick="return confirm(\'%3$s\')">%4$s</a></td></tr>',
				esc_html__( 'Rotate tokens', 'igbz-suite' ),
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'account' => $account_id, 'rotate' => $account_id ] ), self::NONCE ) ),
				esc_js( __( 'Rotate both webhook tokens? The old URLs stop working immediately.', 'igbz-suite' ) ),
				esc_html__( 'Rotate webhook tokens', 'igbz-suite' )
			);
		}

		echo '</tbody></table>';
	}

	private function secret_row( string $name, string $label, bool $has_value, string $help = '' ): void {
		$id = 'igbz_' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" autocomplete="off" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $has_value ? Crypto::MASK : '' )
		);
		if ( '' !== $help ) {
			printf( '<p class="description">%s</p>', esc_html( $help ) );
		}
		if ( $has_value ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'A key is stored. Leave the mask untouched to keep it, or clear the field to remove it.', 'igbz-suite' )
			);
		}
		echo '</td></tr>';
	}

	private function webhook_row( string $label, string $url, string $help ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		printf(
			'<input type="text" class="large-text code" value="%s" readonly onfocus="this.select()" />',
			esc_attr( $url )
		);
		printf( '<p class="description">%s</p>', esc_html( $help ) );
		echo '</td></tr>';
	}

	private function text_row( string $name, string $label, string $value, string $help = '' ): void {
		$id = 'igbz_' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);
		if ( '' !== $help ) {
			printf( '<p class="description">%s</p>', esc_html( $help ) );
		}
		echo '</td></tr>';
	}

	// ------------------------------------------------------------- handlers

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
		View::check_nonce( self::NONCE );

		$account_id = isset( $_POST['account_id'] ) ? (int) $_POST['account_id'] : 0;

		if ( 'queue' === ( isset( $_POST['igbz_ig_do'] ) ? sanitize_key( wp_unslash( $_POST['igbz_ig_do'] ) ) : '' ) ) {
			$brief = [
				'subject'    => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'goal'       => isset( $_POST['goal'] ) ? sanitize_text_field( wp_unslash( $_POST['goal'] ) ) : '',
				'product_id' => isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0,
			];
			$kind  = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : ManusService::KIND_POST;

			$this->scheduler()->queue( $account_id, $kind, $brief, igbz()->tenancy()->id() );
			View::notice( __( 'Queued. Manus starts on the next five-minute run.', 'igbz-suite' ) );
			return;
		}

		$data = [
			'tenant_id'        => igbz()->tenancy()->id(),
			'username'         => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '',
			'display_name'     => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
			'manus_project_id' => isset( $_POST['manus_project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['manus_project_id'] ) ) : '',
			'manychat_page_id' => isset( $_POST['manychat_page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['manychat_page_id'] ) ) : '',
			'timezone'         => isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : wp_timezone_string(),
			'niche'            => isset( $_POST['niche'] ) ? sanitize_text_field( wp_unslash( $_POST['niche'] ) ) : '',
			'brand_voice'      => isset( $_POST['brand_voice'] ) ? sanitize_textarea_field( wp_unslash( $_POST['brand_voice'] ) ) : '',
			'peak_hours'       => isset( $_POST['peak_hours'] ) ? sanitize_text_field( wp_unslash( $_POST['peak_hours'] ) ) : '',
			'is_active'        => ! empty( $_POST['is_active'] ),
			'credential_mode'  => isset( $_POST['credential_mode'] ) ? sanitize_key( wp_unslash( $_POST['credential_mode'] ) ) : AccountCredentials::MODE_OWN,
			'manus_api_key'    => isset( $_POST['manus_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['manus_api_key'] ) ) : '',
			'manychat_api_key' => isset( $_POST['manychat_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['manychat_api_key'] ) ) : '',
		];

		if ( '' === $data['username'] ) {
			View::notice( __( 'A username is required.', 'igbz-suite' ), 'error' );
			return;
		}

		$saved = $this->manus()->save_account( $data, $account_id );
		View::notice(
			$account_id > 0
				? __( 'Account updated.', 'igbz-suite' )
				: sprintf( /* translators: %d: account id */ __( 'Account #%d created.', 'igbz-suite' ), $saved )
		);
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['delete'] ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$this->manus()->delete_account( (int) $_GET['delete'] );
			View::notice( __( 'Account deleted.', 'igbz-suite' ) );
		}

		if ( isset( $_GET['rotate'] ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$rotate = (int) $_GET['rotate'];
			$this->credentials()->rotate_webhook_token( $rotate, AccountCredentials::SERVICE_MANUS );
			$this->credentials()->rotate_webhook_token( $rotate, AccountCredentials::SERVICE_MANYCHAT );
			View::notice( __( 'Webhook tokens rotated. Update the URLs in Manus and ManyChat.', 'igbz-suite' ) );
		}

		if ( isset( $_GET['plan'] ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$this->plan_week( (int) $_GET['plan'] );
		}
		// phpcs:enable
	}

	/**
	 * Ask Manus for a week of ideas and queue one draft per slot. The research task returns a
	 * JSON block; until it resolves we queue placeholder briefs so the pipeline never idles.
	 */
	private function plan_week( int $account_id ): void {
		$account = $this->manus()->account( $account_id );
		if ( ! $account ) {
			View::notice( __( 'Account not found.', 'igbz-suite' ), 'error' );
			return;
		}
		if ( ! $this->manus()->account_is_configured( $account ) ) {
			$reason = $this->manus()->credentials()->trial_blocked_reason( $account );
			View::notice(
				'' !== $reason
					? $reason
					: __( 'This account has no Manus API key. Add one on the account, or switch it to the free trial.', 'igbz-suite' ),
				'error'
			);
			return;
		}

		$task_id = $this->manus()->research_trends( $account );
		if ( '' === $task_id ) {
			View::notice( __( 'Manus did not accept the research task.', 'igbz-suite' ), 'error' );
			return;
		}

		$slots = igbz()->settings()->int( 'manus.weekly_slots', 5 );
		$kinds = [ ManusService::KIND_POST, ManusService::KIND_REEL, ManusService::KIND_CAROUSEL, ManusService::KIND_STORY ];

		for ( $i = 0; $i < max( 1, $slots ); $i++ ) {
			$this->scheduler()->queue(
				$account_id,
				$kinds[ $i % count( $kinds ) ],
				[
					'subject'          => sprintf(
						/* translators: 1: niche, 2: slot number */
						__( 'Weekly plan for %1$s — slot %2$d', 'igbz-suite' ),
						(string) $account['niche'],
						$i + 1
					),
					'goal'             => __( 'Grow reach and pull comments into the DM funnel.', 'igbz-suite' ),
					'research_task_id' => $task_id,
				],
				(int) $account['tenant_id']
			);
		}

		View::notice(
			sprintf(
				/* translators: 1: slots, 2: task id */
				__( 'Queued %1$d drafts. Manus research task: %2$s', 'igbz-suite' ),
				max( 1, $slots ),
				$task_id
			)
		);
	}
}
