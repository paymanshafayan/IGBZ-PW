<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Modules\Instagram\Services\SubscriberService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * ManyChat subscribers mirrored locally: who commented, what ManyChat knows about them, and
 * which WordPress user they resolve to once a phone or email matches.
 */
final class SubscribersPage {

	public const SLUG = 'igbz-ig-subscribers';

	private const PER_PAGE = 25;

	private const NONCE = 'igbz_ig_subscribers';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 23 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'IG Subscribers', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	private function subscribers(): SubscriberService {
		return igbz()->get( 'ig.subscribers' );
	}

	private function manus(): ManusService {
		return igbz()->get( 'ig.manus' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$subscriber_id = isset( $_GET['subscriber'] ) ? (int) $_GET['subscriber'] : 0;
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged         = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		View::open(
			__( 'Instagram subscribers', 'igbz-suite' ),
			__( 'Rows are created by the ManyChat webhook and enriched from the API. Linking a subscriber to a WordPress user is what lets funnels pay wallet credit.', 'igbz-suite' )
		);

		if ( $subscriber_id ) {
			$this->render_detail( $subscriber_id );
			View::close();
			return;
		}

		$this->render_summary();
		$this->render_search( $search );
		$this->render_list( $search, $paged );

		View::close();
	}

	private function render_summary(): void {
		$db        = igbz()->db();
		$tenant_id = igbz()->tenancy()->id();
		$table     = $db->table( 'ig_subscribers' );

		$total  = $this->subscribers()->count( $tenant_id );
		$linked = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE tenant_id = %d AND user_id > 0", $tenant_id );
		$recent = (int) $db->scalar(
			"SELECT COUNT(*) FROM {$table} WHERE tenant_id = %d AND last_interaction_at >= %s",
			$tenant_id,
			gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
		);

		echo '<div class="igbz-cards">';
		foreach (
			[
				__( 'Subscribers', 'igbz-suite' )    => (string) $total,
				__( 'Linked to users', 'igbz-suite' ) => (string) $linked,
				__( 'Active this week', 'igbz-suite' ) => (string) $recent,
			] as $label => $value
		) {
			printf( '<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>', esc_html( $value ), esc_html( $label ) );
		}
		echo '</div>';

		// Keys live on the accounts, so warn only when none of this tenant's accounts has one.
		$credentials = $this->manus()->credentials();
		$keyed       = 0;
		foreach ( $this->manus()->accounts( igbz()->tenancy()->id(), true ) as $account ) {
			if ( $credentials->has_key( $account, AccountCredentials::SERVICE_MANYCHAT ) ) {
				++$keyed;
			}
		}
		if ( 0 === $keyed ) {
			View::notice( __( 'No account has a usable ManyChat API key — subscriber profiles cannot be refreshed from the API.', 'igbz-suite' ), 'warning' );
		}
	}

	private function render_search( string $search ): void {
		echo '<form method="get" class="igbz-filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		printf(
			'<input type="search" name="s" value="%1$s" placeholder="%2$s" /> ',
			esc_attr( $search ),
			esc_attr__( 'username, name, phone or email', 'igbz-suite' )
		);
		submit_button( __( 'Search', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_list( string $search, int $paged ): void {
		$tenant_id = igbz()->tenancy()->id();
		$rows      = $this->subscribers()->all(
			[
				'tenant_id' => $tenant_id,
				'search'    => $search,
				'limit'     => self::PER_PAGE,
				'offset'    => ( $paged - 1 ) * self::PER_PAGE,
			]
		);

		$display = [];
		foreach ( $rows as $row ) {
			$id   = (int) $row['id'];
			$user = (int) $row['user_id'] > 0 ? get_userdata( (int) $row['user_id'] ) : null;

			$display[] = [
				'who'     => sprintf(
					'<a href="%1$s"><strong>%2$s</strong></a><br /><span class="description">%3$s</span>',
					esc_url( Menu::url( self::SLUG, [ 'subscriber' => $id ] ) ),
					esc_html( $row['ig_username'] ? '@' . $row['ig_username'] : (string) $row['manychat_subscriber_id'] ),
					esc_html( trim( $row['first_name'] . ' ' . $row['last_name'] ) )
				),
				'contact' => esc_html( implode( ' · ', array_filter( [ (string) $row['phone'], (string) $row['email'] ] ) ) ?: '—' ),
				'user'    => $user
					? sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( (string) get_edit_user_link( $user->ID ) ),
						esc_html( $user->display_name )
					)
					: '<span class="description">' . esc_html__( 'not linked', 'igbz-suite' ) . '</span>',
				'hits'    => esc_html( (string) $this->hit_count( (string) $row['manychat_subscriber_id'] ) ),
				'last'    => esc_html( $this->local_time( $row['last_interaction_at'] ?? null ) ),
				'actions' => sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a> <a class="button button-small" href="%3$s">%4$s</a>',
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'sync' => $id ] ), self::NONCE ) ),
					esc_html__( 'Sync', 'igbz-suite' ),
					esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'link' => $id ] ), self::NONCE ) ),
					esc_html__( 'Link user', 'igbz-suite' )
				),
			];
		}

		View::table(
			[
				'who'     => __( 'Subscriber', 'igbz-suite' ),
				'contact' => __( 'Contact', 'igbz-suite' ),
				'user'    => __( 'WordPress user', 'igbz-suite' ),
				'hits'    => __( 'Funnel hits', 'igbz-suite' ),
				'last'    => __( 'Last interaction', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No subscribers yet. They appear as soon as the ManyChat webhook fires.', 'igbz-suite' )
		);

		View::pagination( $this->count_matching( $tenant_id, $search ), self::PER_PAGE, $paged, self::SLUG, [ 's' => $search ] );
	}

	private function render_detail( int $subscriber_id ): void {
		$subscriber = $this->subscribers()->get( $subscriber_id );
		if ( ! $subscriber ) {
			View::notice( __( 'Subscriber not found.', 'igbz-suite' ), 'error' );
			return;
		}

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'Back to subscribers', 'igbz-suite' )
		);

		$user   = (int) $subscriber['user_id'] > 0 ? get_userdata( (int) $subscriber['user_id'] ) : null;
		$fields = json_decode( (string) $subscriber['custom_fields'], true );
		$fields = is_array( $fields ) ? $fields : [];
		$tags   = json_decode( (string) $subscriber['tags'], true );
		$tags   = is_array( $tags ) ? $tags : [];

		echo '<table class="widefat striped"><tbody>';
		$this->detail_row( __( 'ManyChat ID', 'igbz-suite' ), (string) $subscriber['manychat_subscriber_id'] );
		$this->detail_row( __( 'Instagram', 'igbz-suite' ), $subscriber['ig_username'] ? '@' . $subscriber['ig_username'] : '—' );
		$this->detail_row( __( 'Name', 'igbz-suite' ), trim( $subscriber['first_name'] . ' ' . $subscriber['last_name'] ) ?: '—' );
		$this->detail_row( __( 'Phone', 'igbz-suite' ), (string) $subscriber['phone'] ?: '—' );
		$this->detail_row( __( 'Email', 'igbz-suite' ), (string) $subscriber['email'] ?: '—' );
		$this->detail_row( __( 'WordPress user', 'igbz-suite' ), $user ? $user->display_name . ' (#' . $user->ID . ')' : __( 'not linked', 'igbz-suite' ) );
		$this->detail_row( __( 'Tags', 'igbz-suite' ), $tags ? implode( ', ', array_map( 'strval', $tags ) ) : '—' );
		$this->detail_row( __( 'Last interaction', 'igbz-suite' ), $this->local_time( $subscriber['last_interaction_at'] ?? null ) );
		echo '</tbody></table>';

		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a></p>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'sync' => $subscriber_id, 'subscriber' => $subscriber_id ] ), self::NONCE ) ),
			esc_html__( 'Refresh from ManyChat', 'igbz-suite' ),
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'link' => $subscriber_id, 'subscriber' => $subscriber_id ] ), self::NONCE ) ),
			esc_html__( 'Try to link a user', 'igbz-suite' )
		);

		echo '<h2>' . esc_html__( 'Custom fields', 'igbz-suite' ) . '</h2>';
		if ( $fields ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $fields as $key => $value ) {
				$this->detail_row( (string) $key, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
			}
			echo '</tbody></table>';
		} else {
			printf( '<p class="description">%s</p>', esc_html__( 'Nothing stored yet.', 'igbz-suite' ) );
		}

		echo '<h2>' . esc_html__( 'Push a field back to ManyChat', 'igbz-suite' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="subscriber_id" value="%d" />', $subscriber_id );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="igbz_field_name">' . esc_html__( 'Field', 'igbz-suite' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="igbz_field_name" name="field_name" />';
		printf( '<p class="description">%s</p>', esc_html__( 'The ManyChat custom field name, e.g. igbz_coupon.', 'igbz-suite' ) );
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="igbz_field_value">' . esc_html__( 'Value', 'igbz-suite' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="igbz_field_value" name="field_value" />';
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Push field', 'igbz-suite' ), 'secondary' );
		echo '</form>';

		$this->render_subscriber_hits( (string) $subscriber['manychat_subscriber_id'] );
	}

	private function render_subscriber_hits( string $manychat_subscriber_id ): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT h.*, f.name AS funnel_name FROM ' . $db->table( 'ig_funnel_hits' ) . ' h
			 LEFT JOIN ' . $db->table( 'ig_funnels' ) . ' f ON f.id = h.funnel_id
			 WHERE h.manychat_subscriber_id = %s ORDER BY h.id DESC LIMIT %d',
			$manychat_subscriber_id,
			50
		);

		$display = [];
		foreach ( $rows as $row ) {
			$display[] = [
				'when'      => esc_html( $this->local_time( (string) $row['occurred_at'] ) ),
				'funnel'    => esc_html( (string) ( $row['funnel_name'] ?? '' ) ?: '#' . $row['funnel_id'] ),
				'comment'   => esc_html( wp_trim_words( (string) $row['comment_text'], 14 ) ),
				'coupon'    => esc_html( (string) $row['coupon_issued'] ?: '—' ),
				'delivered' => HitStatus::cell( $row ),
			];
		}

		echo '<h2>' . esc_html__( 'Funnel history', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'when'      => __( 'When', 'igbz-suite' ),
				'funnel'    => __( 'Funnel', 'igbz-suite' ),
				'comment'   => __( 'Comment', 'igbz-suite' ),
				'coupon'    => __( 'Coupon', 'igbz-suite' ),
				'delivered' => __( 'Delivery', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'This subscriber has not triggered a funnel yet.', 'igbz-suite' )
		);
	}

	private function detail_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:220px">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	// ------------------------------------------------------------ handlers

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
		View::check_nonce( self::NONCE );

		$subscriber_id = isset( $_POST['subscriber_id'] ) ? (int) $_POST['subscriber_id'] : 0;
		$field         = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : '';
		$value         = isset( $_POST['field_value'] ) ? sanitize_text_field( wp_unslash( $_POST['field_value'] ) ) : '';

		if ( $subscriber_id <= 0 || '' === $field ) {
			return;
		}

		$subscriber = $this->subscribers()->get( $subscriber_id );
		if ( ! $subscriber ) {
			View::notice( __( 'Subscriber not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$ok = $this->subscribers()->push_fields(
			(string) $subscriber['manychat_subscriber_id'],
			[ $field => $value ],
			(int) $subscriber['tenant_id']
		);
		View::notice(
			$ok ? __( 'Field pushed to ManyChat.', 'igbz-suite' ) : __( 'ManyChat rejected the update.', 'igbz-suite' ),
			$ok ? 'success' : 'error'
		);
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['sync'] ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );

			$subscriber = $this->subscribers()->get( (int) $_GET['sync'] );
			if ( ! $subscriber ) {
				View::notice( __( 'Subscriber not found.', 'igbz-suite' ), 'error' );
				return;
			}
			$fresh = $this->subscribers()->sync_from_api(
				(string) $subscriber['manychat_subscriber_id'],
				(int) $subscriber['tenant_id']
			);
			View::notice(
				$fresh ? __( 'Profile refreshed from the ManyChat API.', 'igbz-suite' ) : __( 'ManyChat did not return this subscriber.', 'igbz-suite' ),
				$fresh ? 'success' : 'error'
			);
		}

		if ( isset( $_GET['link'] ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );

			$user_id = $this->subscribers()->maybe_link_user( (int) $_GET['link'] );
			View::notice(
				$user_id > 0
					? sprintf( /* translators: %d: user id */ __( 'Linked to user #%d.', 'igbz-suite' ), $user_id )
					: __( 'No WordPress user matches this subscriber\'s phone or email.', 'igbz-suite' ),
				$user_id > 0 ? 'success' : 'warning'
			);
		}
		// phpcs:enable
	}

	// -------------------------------------------------------------- helpers

	private function count_matching( int $tenant_id, string $search ): int {
		if ( '' === $search ) {
			return $this->subscribers()->count( $tenant_id );
		}

		$db   = igbz()->db();
		$like = '%' . $db->wpdb()->esc_like( $search ) . '%';

		return (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'ig_subscribers' ) . '
			 WHERE tenant_id = %d
			   AND (ig_username LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR email LIKE %s)',
			$tenant_id,
			$like,
			$like,
			$like,
			$like,
			$like
		);
	}

	private function hit_count( string $manychat_subscriber_id ): int {
		$db = igbz()->db();
		return (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'ig_funnel_hits' ) . ' WHERE manychat_subscriber_id = %s',
			$manychat_subscriber_id
		);
	}

	private function local_time( ?string $mysql_utc ): string {
		if ( ! $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return '—';
		}
		return wp_date( 'Y-m-d H:i', (int) strtotime( $mysql_utc . ' UTC' ) ) ?: '—';
	}
}
