<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Services\ContentScheduler;
use IGBZ\Suite\Modules\Instagram\Services\InsightsService;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Engagement insights. Manus reports the numbers daily; the peak hours it learns here are what
 * the scheduler publishes against.
 */
final class InsightsPage {

	public const SLUG = 'igbz-ig-insights';

	private const NONCE = 'igbz_ig_insights';

	private const DAYS = 30;

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 24 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'IG Insights', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	private function insights(): InsightsService {
		return igbz()->get( 'ig.insights' );
	}

	private function manus(): ManusService {
		return igbz()->get( 'ig.manus' );
	}

	private function scheduler(): ContentScheduler {
		return igbz()->get( 'ig.scheduler' );
	}

	public function render(): void {
		$this->handle_get_actions();

		$accounts = $this->manus()->accounts( igbz()->tenancy()->id(), false );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$account_id = isset( $_GET['account_id'] ) ? (int) $_GET['account_id'] : (int) ( $accounts[0]['id'] ?? 0 );
		// phpcs:enable

		View::open(
			__( 'Instagram insights', 'igbz-suite' ),
			__( 'Collected once a day by a Manus task and reconciled hourly. Peak hours feed straight back into the publishing schedule.', 'igbz-suite' )
		);

		if ( ! $accounts ) {
			View::notice( __( 'Add an Instagram account first.', 'igbz-suite' ), 'warning' );
			View::close();
			return;
		}

		$this->render_picker( $accounts, $account_id );

		$account = $this->manus()->account( $account_id );
		if ( ! $account ) {
			View::notice( __( 'Account not found.', 'igbz-suite' ), 'error' );
			View::close();
			return;
		}

		$this->render_summary( $account_id );
		$this->render_peak_hours( $account );
		$this->render_series( $account_id );
		$this->render_publishing( $account_id );

		View::close();
	}

	/** @param array<int,array<string,mixed>> $accounts */
	private function render_picker( array $accounts, int $account_id ): void {
		echo '<form method="get" class="igbz-filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );
		echo '<select name="account_id">';
		foreach ( $accounts as $account ) {
			printf(
				'<option value="%1$d" %2$s>@%3$s</option>',
				(int) $account['id'],
				selected( (int) $account['id'], $account_id, false ),
				esc_html( (string) $account['username'] )
			);
		}
		echo '</select> ';
		submit_button( __( 'Show', 'igbz-suite' ), 'secondary', '', false );
		printf(
			' <a class="button" href="%1$s">%2$s</a> <a class="button" href="%3$s">%4$s</a>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'collect', 'account_id' => $account_id ] ), self::NONCE ) ),
			esc_html__( 'Collect now', 'igbz-suite' ),
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'reconcile', 'account_id' => $account_id ] ), self::NONCE ) ),
			esc_html__( 'Reconcile tasks', 'igbz-suite' )
		);
		echo '</form>';
	}

	private function render_summary( int $account_id ): void {
		$summary = $this->insights()->summary( $account_id );

		if ( ! $summary ) {
			View::notice( __( 'No insights stored yet for this account. Run a collection and give the Manus task a few minutes.', 'igbz-suite' ), 'warning' );
			return;
		}

		echo '<div class="igbz-cards">';
		foreach ( $summary as $metric => $value ) {
			printf(
				'<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>',
				esc_html( $this->format_value( $metric, $value ) ),
				esc_html( $this->metric_label( $metric ) )
			);
		}
		echo '</div>';
	}

	/** @param array<string,mixed> $account */
	private function render_peak_hours( array $account ): void {
		$account_id = (int) $account['id'];
		$db         = igbz()->db();
		$rows       = $db->results(
			'SELECT dimension, value FROM ' . $db->table( 'ig_insights' ) . '
			 WHERE account_id = %d AND metric = %s AND captured_for = (
				SELECT MAX(captured_for) FROM ' . $db->table( 'ig_insights' ) . ' WHERE account_id = %d AND metric = %s
			 )
			 ORDER BY dimension',
			$account_id,
			'engagement_by_hour',
			$account_id,
			'engagement_by_hour'
		);

		echo '<h2>' . esc_html__( 'Engagement by hour', 'igbz-suite' ) . '</h2>';

		if ( ! $rows ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Nothing measured yet — the scheduler falls back to the default peak hours from the settings.', 'igbz-suite' )
			);
		} else {
			$max = 0.0;
			foreach ( $rows as $row ) {
				$max = max( $max, (float) $row['value'] );
			}

			echo '<div class="igbz-bars">';
			foreach ( $rows as $row ) {
				$value  = (float) $row['value'];
				$height = $max > 0 ? max( 4, (int) round( $value / $max * 100 ) ) : 4;
				printf(
					'<div class="igbz-bar" title="%1$s"><span style="height:%2$d%%"></span><em>%3$s</em></div>',
					esc_attr( $row['dimension'] . ' — ' . number_format_i18n( $value, 1 ) ),
					$height,
					esc_html( (string) $row['dimension'] )
				);
			}
			echo '</div>';
		}

		$peak = $this->scheduler()->peak_hours( $account );
		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Publishing at:', 'igbz-suite' ),
			esc_html( implode( ' · ', $peak ) )
		);
		printf(
			'<p><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Next free slot:', 'igbz-suite' ),
			esc_html( (string) wp_date( 'Y-m-d H:i', $this->scheduler()->next_peak_slot( $account ) ) )
		);

		if ( ! (string) $account['peak_hours'] ) {
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'learn', 'account_id' => $account_id ] ), self::NONCE ) ),
				esc_html__( 'Write the learned hours onto the account', 'igbz-suite' )
			);
		}
	}

	private function render_series( int $account_id ): void {
		echo '<h2>' . esc_html__( 'Last 30 days', 'igbz-suite' ) . '</h2>';

		$metrics = [ 'followers', 'reach', 'impressions', 'engagement_rate', 'profile_views' ];
		$columns = [ 'day' => __( 'Day', 'igbz-suite' ) ];
		$grid    = [];

		foreach ( $metrics as $metric ) {
			$series = $this->insights()->series( $account_id, $metric, self::DAYS );
			if ( ! $series ) {
				continue;
			}
			$columns[ $metric ] = $this->metric_label( $metric );
			foreach ( $series as $point ) {
				$day                    = (string) $point['captured_for'];
				$grid[ $day ]['day']    = $day;
				$grid[ $day ][ $metric ] = $this->format_value( $metric, (float) $point['value'] );
			}
		}

		if ( count( $columns ) < 2 ) {
			printf( '<p class="description">%s</p>', esc_html__( 'No daily series stored yet.', 'igbz-suite' ) );
			return;
		}

		krsort( $grid );
		$display = [];
		foreach ( $grid as $row ) {
			$line = [];
			foreach ( array_keys( $columns ) as $key ) {
				$line[ $key ] = esc_html( (string) ( $row[ $key ] ?? '—' ) );
			}
			$display[] = $line;
		}

		View::table(
			$columns,
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'No rows.', 'igbz-suite' )
		);
	}

	private function render_publishing( int $account_id ): void {
		$db   = igbz()->db();
		$rows = $db->results(
			'SELECT DATE(published_at) AS day, COUNT(*) AS total FROM ' . $db->table( 'ig_content' ) . '
			 WHERE account_id = %d AND status = %s AND published_at >= %s
			 GROUP BY DATE(published_at) ORDER BY day DESC',
			$account_id,
			ManusService::STATUS_PUBLISHED,
			gmdate( 'Y-m-d H:i:s', time() - self::DAYS * DAY_IN_SECONDS )
		);

		$display = [];
		foreach ( $rows as $row ) {
			$display[] = [
				'day'   => esc_html( (string) $row['day'] ),
				'total' => esc_html( (string) $row['total'] ),
			];
		}

		echo '<h2>' . esc_html__( 'Publishing cadence', 'igbz-suite' ) . '</h2>';
		View::table(
			[
				'day'   => __( 'Day', 'igbz-suite' ),
				'total' => __( 'Published', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Nothing published in the last thirty days.', 'igbz-suite' )
		);
	}

	// ------------------------------------------------------------ handlers

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['run'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE );
		Capabilities::require( Capabilities::MANAGE_INSTAGRAM );

		$run        = sanitize_key( wp_unslash( $_GET['run'] ) );
		$account_id = isset( $_GET['account_id'] ) ? (int) $_GET['account_id'] : 0;
		// phpcs:enable

		switch ( $run ) {
			case 'collect':
				$this->insights()->collect_all();
				View::notice( __( 'Collection tasks dispatched to Manus. Reconcile once they finish.', 'igbz-suite' ) );
				break;

			case 'reconcile':
				$this->insights()->reconcile();
				View::notice( __( 'Finished insight tasks absorbed.', 'igbz-suite' ) );
				break;

			case 'learn':
				$this->learn_peak_hours( $account_id );
				break;
		}
	}

	/** Freeze the measured top hours onto the account so they survive a quiet reporting week. */
	private function learn_peak_hours( int $account_id ): void {
		$account = $this->manus()->account( $account_id );
		if ( ! $account ) {
			View::notice( __( 'Account not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$hours = $this->scheduler()->peak_hours( $account );
		if ( ! $hours ) {
			View::notice( __( 'Nothing measured to write yet.', 'igbz-suite' ), 'warning' );
			return;
		}

		$account['peak_hours'] = implode( ',', array_slice( $hours, 0, 5 ) );
		$this->manus()->save_account( $account, $account_id );

		View::notice(
			sprintf(
				/* translators: %s: comma separated hours */
				__( 'Peak hours saved: %s', 'igbz-suite' ),
				(string) $account['peak_hours']
			)
		);
	}

	// -------------------------------------------------------------- labels

	private function metric_label( string $metric ): string {
		$labels = [
			'followers'          => __( 'Followers', 'igbz-suite' ),
			'follower_growth'    => __( 'Follower growth', 'igbz-suite' ),
			'reach'              => __( 'Reach', 'igbz-suite' ),
			'impressions'        => __( 'Impressions', 'igbz-suite' ),
			'profile_views'      => __( 'Profile views', 'igbz-suite' ),
			'website_clicks'     => __( 'Website clicks', 'igbz-suite' ),
			'engagement_rate'    => __( 'Engagement rate', 'igbz-suite' ),
			'comments'           => __( 'Comments', 'igbz-suite' ),
			'saves'              => __( 'Saves', 'igbz-suite' ),
			'shares'             => __( 'Shares', 'igbz-suite' ),
			'engagement_by_hour' => __( 'Engagement by hour', 'igbz-suite' ),
		];

		return $labels[ $metric ] ?? ucwords( str_replace( '_', ' ', $metric ) );
	}

	private function format_value( string $metric, float $value ): string {
		if ( str_contains( $metric, 'rate' ) || str_contains( $metric, 'percent' ) ) {
			return number_format_i18n( $value, 2 ) . '%';
		}
		return number_format_i18n( $value, $value == (int) $value ? 0 : 2 );
	}
}
