<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\AiStudio\GiveawayService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Comment giveaways: create a giveaway for a published post and draw a
 * winner from the real comment hits.
 */
final class GiveawayPage {

	public const SLUG = 'igbz-giveaways';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 18 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Giveaways', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	public function render(): void {
		$this->handle_post();

		View::open(
			__( 'Comment giveaways', 'igbz-suite' ),
			__( 'Create a giveaway on a published post and draw a winner from the real comments (ManyChat hits).', 'igbz-suite' )
		);

		echo '<h2>' . esc_html__( 'New giveaway', 'igbz-suite' ) . '</h2>';
		echo '<form method="post" style="max-width:480px">';
		wp_nonce_field( 'igbz_giveaway_new' );
		printf( '<input type="hidden" name="igbz_gw_action" value="create" />' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="gw_title">' . esc_html__( 'Title', 'igbz-suite' ) . '</label></th><td><input type="text" id="gw_title" name="title" class="regular-text" required /></td></tr>';
		echo '<tr><th><label for="gw_post">' . esc_html__( 'Post id', 'igbz-suite' ) . '</label></th><td><input type="text" id="gw_post" name="post_id" class="regular-text" required placeholder="178…" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Create', 'igbz-suite' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Giveaways', 'igbz-suite' ) . '</h2>';
		$rows = $this->service()->all( 50 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'None yet.', 'igbz-suite' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Title', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Post', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Entries', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Status', 'igbz-suite' ) . '</th><th>' . esc_html__( 'Winner', 'igbz-suite' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				printf(
					'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$d</td><td>%5$s</td><td>%6$s</td><td>',
					(int) $row['id'],
					esc_html( (string) $row['title'] ),
					esc_html( (string) $row['ig_post_id'] ),
					(int) $row['entries_count'],
					esc_html( (string) $row['status'] ),
					esc_html( (string) $row['winner_subscriber'] )
				);
				if ( GiveawayService::STATUS_OPEN === $row['status'] ) {
					printf(
						'<form method="post" style="display:inline">%s<input type="hidden" name="igbz_gw_action" value="draw" /><input type="hidden" name="giveaway_id" value="%d" /><button class="button button-small">%s</button></form>',
						wp_nonce_field( 'igbz_giveaway_draw', '_wpnonce', true, false ),
						(int) $row['id'],
						esc_html__( 'Draw winner', 'igbz-suite' )
					);
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		View::close();
	}

	private function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['igbz_gw_action'] ) ? sanitize_key( (string) $_POST['igbz_gw_action'] ) : '';
		// phpcs:enable
		if ( '' === $action ) {
			return;
		}

		$service = $this->service();

		if ( 'create' === $action ) {
			View::check_nonce( 'igbz_giveaway_new' );
			$result = $service->create(
				(int) igbz()->tenancy()->id(),
				0,
				sanitize_text_field( (string) ( $_POST['ig_post_id'] ?? '' ) ),
				sanitize_text_field( (string) ( $_POST['title'] ?? '' ) )
			);
			View::notice( $result['ok'] ? __( 'Giveaway created.', 'igbz-suite' ) : $result['message'], $result['ok'] ? 'success' : 'error' );
			return;
		}

		if ( 'draw' === $action ) {
			View::check_nonce( 'igbz_giveaway_draw' );
			$result = $service->draw( (int) ( $_POST['giveaway_id'] ?? 0 ) );
			View::notice( $result['ok'] ? sprintf( 'Winner: %s', $result['winner_subscriber'] ) : $result['message'], $result['ok'] ? 'success' : 'error' );
		}
	}

	private function service(): GiveawayService {
		return igbz()->get( 'giveaways' );
	}
}
