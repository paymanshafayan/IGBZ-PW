<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\FunnelService;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * DM funnels — "comment the word X and I'll DM you the link".
 *
 * A funnel owns the keyword, the match mode, what gets delivered (URL, product, coupon or a
 * plain ManyChat flow) and the limits. Hits arrive from the ManyChat webhook.
 */
final class FunnelsPage {

	public const SLUG = 'igbz-ig-funnels';

	private const PER_PAGE = 30;

	private const NONCE = 'igbz_ig_funnels';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 22 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'DM Funnels', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	private function funnels(): FunnelService {
		return igbz()->get( 'ig.funnels' );
	}

	private function manus(): ManusService {
		return igbz()->get( 'ig.manus' );
	}

	private function manychat(): ManyChatClient {
		return igbz()->get( 'ig.manychat' );
	}

	/** A ManyChat client bound to one account's key, or null when that account has none. */
	private function client_for_account( int $account_id ): ?ManyChatClient {
		if ( $account_id <= 0 ) {
			return null;
		}
		$account = $this->manus()->account( $account_id );
		if ( ! $account ) {
			return null;
		}
		$credentials = $this->manus()->credentials();
		$key         = $credentials->key( $account, AccountCredentials::SERVICE_MANYCHAT );

		return '' === $key ? null : $this->manychat()->for_key( $key );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$funnel_id = isset( $_GET['funnel'] ) ? (int) $_GET['funnel'] : 0;
		$new       = isset( $_GET['new'] );
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		View::open(
			__( 'DM funnels', 'igbz-suite' ),
			__( 'ManyChat calls the webhook the moment a matching comment lands, and the reply goes out inside its ten second window. Everything slower — coupons, wallet credit — is pushed back afterwards.', 'igbz-suite' )
		);

		$this->render_webhook_hint();

		if ( $funnel_id || $new ) {
			$this->render_editor( $funnel_id, $paged );
			View::close();
			return;
		}

		$this->render_list();
		View::close();
	}

	/**
	 * Webhook URLs are per account, because the token is what tells us which account — and so
	 * which tenant — an incoming comment belongs to. There is no single URL to show here any more,
	 * so point the operator at the account that owns each funnel.
	 */
	private function render_webhook_hint(): void {
		$accounts = $this->manus()->accounts( igbz()->tenancy()->id(), true );

		echo '<div class="notice notice-info inline"><p>';
		if ( ! $accounts ) {
			esc_html_e( 'Add an Instagram account first: each account gets its own ManyChat External Request URL.', 'igbz-suite' );
			echo '</p></div>';
			return;
		}

		printf(
			/* translators: %s: link to the accounts screen */
			esc_html__( 'Each account has its own ManyChat External Request URL, including a token that identifies it. Copy it from %s.', 'igbz-suite' ),
			'<a href="' . esc_url( Menu::url( AccountsPage::SLUG ) ) . '">' . esc_html__( 'IG Accounts', 'igbz-suite' ) . '</a>'
		);
		echo '</p></div>';
	}

	private function render_list(): void {
		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( Menu::url( self::SLUG, [ 'new' => 1 ] ) ),
			esc_html__( 'Add funnel', 'igbz-suite' ),
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'retry' ] ), self::NONCE ) ),
			esc_html__( 'Retry undelivered hits', 'igbz-suite' )
		);

		$display = [];
		foreach ( $this->funnels()->all( [ 'tenant_id' => igbz()->tenancy()->id() ] ) as $funnel ) {
			$id      = (int) $funnel['id'];
			$stats   = $this->funnels()->stats( $id );
			$account = (int) $funnel['account_id'] > 0 ? $this->manus()->account( (int) $funnel['account_id'] ) : null;

			$display[] = [
				'name'    => sprintf(
					'<a href="%1$s"><strong>%2$s</strong></a><br /><span class="description">%3$s</span>',
					esc_url( Menu::url( self::SLUG, [ 'funnel' => $id ] ) ),
					esc_html( (string) $funnel['name'] ),
					esc_html( $account ? '@' . $account['username'] : __( 'all accounts', 'igbz-suite' ) )
				),
				'keyword' => sprintf(
					'<code>%1$s</code><br /><span class="description">%2$s</span>',
					esc_html( (string) $funnel['keyword'] ),
					esc_html( $this->match_modes()[ (string) $funnel['match_mode'] ] ?? (string) $funnel['match_mode'] )
				),
				'target'  => esc_html( $this->describe_target( $funnel ) ),
				'hits'    => esc_html( (string) $stats['hits'] ),
				'conv'    => esc_html( $stats['conversions'] . ' (' . $stats['rate'] . '%)' ),
				'subs'    => esc_html( (string) $stats['subscribers'] ),
				'status'  => View::status_pill( $this->funnel_tone( $funnel ) ),
				'actions' => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s" onclick="return confirm(\'%4$s\')">%5$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'toggle' => $id ] ), self::NONCE ) ),
					$funnel['is_active'] ? esc_html__( 'Pause', 'igbz-suite' ) : esc_html__( 'Activate', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'delete' => $id ] ), self::NONCE ) ),
					esc_js( __( 'Delete this funnel? Its hit log stays for reporting.', 'igbz-suite' ) ),
					esc_html__( 'Delete', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'name'    => __( 'Funnel', 'igbz-suite' ),
				'keyword' => __( 'Keyword', 'igbz-suite' ),
				'target'  => __( 'Delivers', 'igbz-suite' ),
				'hits'    => __( 'Hits', 'igbz-suite' ),
				'conv'    => __( 'Conversions', 'igbz-suite' ),
				'subs'    => __( 'Subscribers', 'igbz-suite' ),
				'status'  => __( 'Active', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No funnels yet. Create one and reference its keyword in the caption Manus writes.', 'igbz-suite' )
		);
	}

	private function render_editor( int $funnel_id, int $paged ): void {
		$funnel = $funnel_id > 0 ? $this->funnels()->get( $funnel_id ) : null;
		if ( $funnel_id > 0 && ! $funnel ) {
			View::notice( __( 'Funnel not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$funnel = $funnel ?? [
			'name'                => '',
			'account_id'          => 0,
			'keyword'             => '',
			'match_mode'          => FunnelService::MATCH_CONTAINS,
			'post_id'             => '',
			'reply_text'          => '',
			'target_type'         => FunnelService::TARGET_URL,
			'target_url'          => '',
			'product_id'          => 0,
			'coupon_code'         => '',
			'manychat_flow_ns'    => '',
			'manychat_tag'        => '',
			'grant_wallet_credit' => 0,
			'per_user_limit'      => 1,
			'total_limit'         => 0,
			'starts_at'           => null,
			'ends_at'             => null,
			'is_active'           => 1,
		];

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'Back to funnels', 'igbz-suite' )
		);

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="funnel_id" value="%d" />', $funnel_id );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text_row( 'name', __( 'Name', 'igbz-suite' ), (string) $funnel['name'] );

		echo '<tr><th scope="row"><label for="igbz_account_id">' . esc_html__( 'Account', 'igbz-suite' ) . '</label></th><td>';
		echo '<select id="igbz_account_id" name="account_id">';
		printf( '<option value="0">%s</option>', esc_html__( 'Any account', 'igbz-suite' ) );
		foreach ( $this->manus()->accounts( igbz()->tenancy()->id(), false ) as $account ) {
			printf(
				'<option value="%1$d" %2$s>@%3$s</option>',
				(int) $account['id'],
				selected( (int) $account['id'], (int) $funnel['account_id'], false ),
				esc_html( (string) $account['username'] )
			);
		}
		echo '</select></td></tr>';

		$this->text_row( 'keyword', __( 'Keyword', 'igbz-suite' ), (string) $funnel['keyword'], __( 'Arabic/Persian letters and digits are normalised, so "لينك" and "لینک" both match.', 'igbz-suite' ) );

		$this->select_row( 'match_mode', __( 'Match mode', 'igbz-suite' ), $this->match_modes(), (string) $funnel['match_mode'] );

		$this->text_row( 'post_id', __( 'Post ID', 'igbz-suite' ), (string) $funnel['post_id'], __( 'Optional. Scope the funnel to a single post — post-scoped funnels win over global ones.', 'igbz-suite' ) );

		$this->select_row(
			'target_type',
			__( 'Delivers', 'igbz-suite' ),
			[
				FunnelService::TARGET_URL     => __( 'A link', 'igbz-suite' ),
				FunnelService::TARGET_PRODUCT => __( 'A WooCommerce product page', 'igbz-suite' ),
				FunnelService::TARGET_COUPON  => __( 'A coupon code', 'igbz-suite' ),
				FunnelService::TARGET_FLOW    => __( 'Just a ManyChat flow', 'igbz-suite' ),
			],
			(string) $funnel['target_type']
		);

		$this->text_row( 'target_url', __( 'Link', 'igbz-suite' ), (string) $funnel['target_url'] );
		$this->text_row( 'product_id', __( 'Product ID', 'igbz-suite' ), (string) $funnel['product_id'] );
		$this->text_row( 'coupon_code', __( 'Coupon code', 'igbz-suite' ), (string) $funnel['coupon_code'], __( 'Leave empty with instagram.unique_coupons on and each subscriber gets their own single-use code.', 'igbz-suite' ) );

		echo '<tr><th scope="row"><label for="igbz_reply_text">' . esc_html__( 'Reply text', 'igbz-suite' ) . '</label></th><td>';
		printf( '<textarea id="igbz_reply_text" name="reply_text" rows="4" class="large-text">%s</textarea>', esc_textarea( (string) $funnel['reply_text'] ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Placeholders: {first_name}, {link}, {coupon}, {product}. Sent as the DM body when no flow is set.', 'igbz-suite' )
		);
		echo '</td></tr>';

		$this->flow_row( (string) $funnel['manychat_flow_ns'], (int) $funnel['account_id'] );
		$this->text_row( 'manychat_tag', __( 'Tag the subscriber', 'igbz-suite' ), (string) $funnel['manychat_tag'], __( 'Optional ManyChat tag applied on every hit.', 'igbz-suite' ) );

		$this->text_row( 'grant_wallet_credit', __( 'Wallet credit', 'igbz-suite' ), (string) $funnel['grant_wallet_credit'], __( 'Credited once the subscriber is linked to a WordPress user. 0 disables it.', 'igbz-suite' ) );
		$this->text_row( 'per_user_limit', __( 'Per subscriber limit', 'igbz-suite' ), (string) $funnel['per_user_limit'] );
		$this->text_row( 'total_limit', __( 'Total limit', 'igbz-suite' ), (string) $funnel['total_limit'], __( '0 means unlimited.', 'igbz-suite' ) );

		$this->datetime_row( 'starts_at', __( 'Starts', 'igbz-suite' ), $funnel['starts_at'] ?? null );
		$this->datetime_row( 'ends_at', __( 'Ends', 'igbz-suite' ), $funnel['ends_at'] ?? null );

		echo '<tr><th scope="row">' . esc_html__( 'Active', 'igbz-suite' ) . '</th><td><label>';
		printf( '<input type="checkbox" name="is_active" value="1" %s /> ', checked( (int) $funnel['is_active'], 1, false ) );
		esc_html_e( 'Paused funnels ignore incoming comments.', 'igbz-suite' );
		echo '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( $funnel_id > 0 ? __( 'Save funnel', 'igbz-suite' ) : __( 'Create funnel', 'igbz-suite' ) );
		echo '</form>';

		if ( $funnel_id > 0 ) {
			$this->render_hits( $funnel_id, $paged );
		}
	}

	private function render_hits( int $funnel_id, int $paged ): void {
		$stats = $this->funnels()->stats( $funnel_id );

		echo '<hr /><h2>' . esc_html__( 'Recent hits', 'igbz-suite' ) . '</h2>';
		echo '<div class="igbz-cards">';
		foreach (
			[
				__( 'Hits', 'igbz-suite' )        => (string) $stats['hits'],
				__( 'Conversions', 'igbz-suite' ) => (string) $stats['conversions'],
				__( 'Subscribers', 'igbz-suite' ) => (string) $stats['subscribers'],
				__( 'Rate', 'igbz-suite' )        => $stats['rate'] . '%',
			] as $label => $value
		) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';

		$rows    = $this->funnels()->hits( $funnel_id, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$display = [];

		foreach ( $rows as $row ) {
			$display[] = [
				'when'      => esc_html( $this->local_time( (string) $row['occurred_at'] ) ),
				'who'       => esc_html( $row['ig_username'] ? '@' . $row['ig_username'] : (string) $row['manychat_subscriber_id'] ),
				'comment'   => esc_html( wp_trim_words( (string) $row['comment_text'], 14 ) ),
				'post'      => esc_html( (string) $row['post_id'] ?: '—' ),
				'coupon'    => esc_html( (string) $row['coupon_issued'] ?: '—' ),
				'delivered' => $row['delivered']
					? View::status_pill( 'ok' ) . ' ' . esc_html__( 'delivered', 'igbz-suite' )
					: View::status_pill( 'error' ) . ' ' . esc_html( (string) $row['delivery_error'] ?: __( 'pending', 'igbz-suite' ) ),
			];
		}

		View::table(
			[
				'when'      => __( 'When', 'igbz-suite' ),
				'who'       => __( 'Subscriber', 'igbz-suite' ),
				'comment'   => __( 'Comment', 'igbz-suite' ),
				'post'      => __( 'Post', 'igbz-suite' ),
				'coupon'    => __( 'Coupon', 'igbz-suite' ),
				'delivered' => __( 'Delivery', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No hits recorded for this funnel yet.', 'igbz-suite' )
		);

		$db    = igbz()->db();
		$total = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'ig_funnel_hits' ) . ' WHERE funnel_id = %d', $funnel_id );
		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'funnel' => $funnel_id ] );
	}

	// -------------------------------------------------------- form helpers

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

	/** @param array<string,string> $options */
	private function select_row( string $name, string $label, array $options, string $current ): void {
		$id = 'igbz_' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
		foreach ( $options as $value => $text ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, $current, false ),
				esc_html( $text )
			);
		}
		echo '</select></td></tr>';
	}

	private function datetime_row( string $name, string $label, ?string $mysql_utc ): void {
		$id    = 'igbz_' . $name;
		$value = $mysql_utc ? (string) wp_date( 'Y-m-d\TH:i', (int) strtotime( $mysql_utc . ' UTC' ) ) : '';
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="datetime-local" id="%1$s" name="%2$s" value="%3$s" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);
		echo '</td></tr>';
	}

	/**
	 * The flow picker has to ask ManyChat, and ManyChat keys are per account, so the list is
	 * fetched with the key of the account this funnel belongs to. With no account chosen yet there
	 * is no key to use and the field degrades to a free-text namespace input.
	 */
	private function flow_row( string $current, int $account_id ): void {
		echo '<tr><th scope="row"><label for="igbz_manychat_flow_ns">' . esc_html__( 'ManyChat flow', 'igbz-suite' ) . '</label></th><td>';

		$client = $this->client_for_account( $account_id );
		$result = $client && $client->is_configured() ? $client->flows() : [ 'ok' => false, 'data' => [] ];
		$flows  = ! empty( $result['ok'] ) && is_array( $result['data'] ) ? ( $result['data']['flows'] ?? $result['data'] ) : [];
		if ( is_array( $flows ) && $flows ) {
			echo '<select id="igbz_manychat_flow_ns" name="manychat_flow_ns">';
			printf( '<option value="">%s</option>', esc_html__( 'No flow — send the reply text', 'igbz-suite' ) );
			foreach ( $flows as $flow ) {
				if ( ! is_array( $flow ) ) {
					continue;
				}
				$ns = (string) ( $flow['ns'] ?? $flow['flow_ns'] ?? '' );
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $ns ),
					selected( $ns, $current, false ),
					esc_html( (string) ( $flow['name'] ?? $ns ) )
				);
			}
			echo '</select>';
		} else {
			printf(
				'<input type="text" class="regular-text" id="igbz_manychat_flow_ns" name="manychat_flow_ns" value="%s" placeholder="content20180221085508_278589" />',
				esc_attr( $current )
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Add a ManyChat API key to pick from a list. The flow_ns is the last segment of the automation edit URL.', 'igbz-suite' )
			);
		}

		echo '</td></tr>';
	}

	// ------------------------------------------------------------ handlers

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
		View::check_nonce( self::NONCE );

		$funnel_id = isset( $_POST['funnel_id'] ) ? (int) $_POST['funnel_id'] : 0;

		$data = [
			'tenant_id'           => igbz()->tenancy()->id(),
			'account_id'          => isset( $_POST['account_id'] ) ? absint( wp_unslash( $_POST['account_id'] ) ) : 0,
			'name'                => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'keyword'             => isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '',
			'match_mode'          => isset( $_POST['match_mode'] ) ? sanitize_key( wp_unslash( $_POST['match_mode'] ) ) : FunnelService::MATCH_CONTAINS,
			'post_id'             => isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : '',
			'reply_text'          => isset( $_POST['reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reply_text'] ) ) : '',
			'target_type'         => isset( $_POST['target_type'] ) ? sanitize_key( wp_unslash( $_POST['target_type'] ) ) : FunnelService::TARGET_URL,
			'target_url'          => isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '',
			'product_id'          => isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0,
			'coupon_code'         => isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '',
			'manychat_flow_ns'    => isset( $_POST['manychat_flow_ns'] ) ? sanitize_text_field( wp_unslash( $_POST['manychat_flow_ns'] ) ) : '',
			'manychat_tag'        => isset( $_POST['manychat_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['manychat_tag'] ) ) : '',
			'grant_wallet_credit' => isset( $_POST['grant_wallet_credit'] ) ? (float) wp_unslash( $_POST['grant_wallet_credit'] ) : 0.0,
			'per_user_limit'      => isset( $_POST['per_user_limit'] ) ? absint( wp_unslash( $_POST['per_user_limit'] ) ) : 1,
			'total_limit'         => isset( $_POST['total_limit'] ) ? absint( wp_unslash( $_POST['total_limit'] ) ) : 0,
			'starts_at'           => $this->post_datetime( 'starts_at' ),
			'ends_at'             => $this->post_datetime( 'ends_at' ),
			'is_active'           => ! empty( $_POST['is_active'] ),
		];

		if ( '' === $data['name'] || '' === $data['keyword'] ) {
			View::notice( __( 'A name and a keyword are required.', 'igbz-suite' ), 'error' );
			return;
		}

		$saved = $this->funnels()->save( $data, $funnel_id );
		View::notice(
			$funnel_id > 0
				? __( 'Funnel saved.', 'igbz-suite' )
				: sprintf( /* translators: %d: funnel id */ __( 'Funnel #%d created.', 'igbz-suite' ), $saved )
		);
	}

	private function post_datetime( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$raw = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		if ( '' === $raw ) {
			return null;
		}
		return get_gmt_from_date( str_replace( 'T', ' ', $raw ) );
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['delete'] ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$this->funnels()->delete( (int) $_GET['delete'] );
			View::notice( __( 'Funnel deleted.', 'igbz-suite' ) );
		}

		if ( isset( $_GET['toggle'] ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$funnel = $this->funnels()->get( (int) $_GET['toggle'] );
			if ( $funnel ) {
				$funnel['is_active'] = empty( $funnel['is_active'] );
				$this->funnels()->save( $funnel, (int) $funnel['id'] );
				View::notice( $funnel['is_active'] ? __( 'Funnel activated.', 'igbz-suite' ) : __( 'Funnel paused.', 'igbz-suite' ) );
			}
		}

		if ( isset( $_GET['run'] ) && 'retry' === sanitize_key( wp_unslash( $_GET['run'] ) ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$count = $this->funnels()->retry_failed( 50 );
			View::notice(
				sprintf( /* translators: %d: number of hits */ __( 'Retried %d undelivered hits.', 'igbz-suite' ), $count )
			);
		}
		// phpcs:enable
	}

	// -------------------------------------------------------------- labels

	/** @return array<string,string> */
	private function match_modes(): array {
		return [
			FunnelService::MATCH_EXACT    => __( 'Exactly this word', 'igbz-suite' ),
			FunnelService::MATCH_CONTAINS => __( 'Comment contains it', 'igbz-suite' ),
			FunnelService::MATCH_STARTS   => __( 'Comment starts with it', 'igbz-suite' ),
			FunnelService::MATCH_REGEX    => __( 'Regular expression', 'igbz-suite' ),
		];
	}

	/** @param array<string,mixed> $funnel */
	private function describe_target( array $funnel ): string {
		return match ( (string) $funnel['target_type'] ) {
			FunnelService::TARGET_PRODUCT => (string) get_the_title( (int) $funnel['product_id'] ) ?: __( 'a product', 'igbz-suite' ),
			FunnelService::TARGET_COUPON  => (string) $funnel['coupon_code'] ?: __( 'a unique coupon', 'igbz-suite' ),
			FunnelService::TARGET_FLOW    => __( 'a ManyChat flow', 'igbz-suite' ),
			default                       => (string) $funnel['target_url'] ?: __( 'a link', 'igbz-suite' ),
		};
	}

	/** @param array<string,mixed> $funnel */
	private function funnel_tone( array $funnel ): string {
		if ( empty( $funnel['is_active'] ) ) {
			return 'warn';
		}
		$now = current_time( 'mysql', true );
		if ( ! empty( $funnel['ends_at'] ) && (string) $funnel['ends_at'] < $now ) {
			return 'error';
		}
		return 'ok';
	}

	private function local_time( string $mysql_utc ): string {
		if ( '' === $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return '—';
		}
		return wp_date( 'Y-m-d H:i', (int) strtotime( $mysql_utc . ' UTC' ) ) ?: '—';
	}
}
