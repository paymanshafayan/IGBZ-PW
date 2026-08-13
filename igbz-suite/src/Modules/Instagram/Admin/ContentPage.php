<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Services\ContentScheduler;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The content pipeline: draft → generating → ready → scheduled → published.
 * Everything Manus produces lands here before it goes out.
 */
final class ContentPage {

	public const SLUG = 'igbz-ig-content';

	private const PER_PAGE = 20;

	private const NONCE = 'igbz_ig_content';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 21 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'IG Content', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	private function manus(): ManusService {
		return igbz()->get( 'ig.manus' );
	}

	private function scheduler(): ContentScheduler {
		return igbz()->get( 'ig.scheduler' );
	}

	public function render(): void {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$this->handle_post();
		}
		$this->handle_get_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$content_id = isset( $_GET['content'] ) ? (int) $_GET['content'] : 0;
		$account_id = isset( $_GET['account_id'] ) ? (int) $_GET['account_id'] : 0;
		$status     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		View::open(
			__( 'Instagram content', 'igbz-suite' ),
			__( 'Manus writes the caption, picks the hashtags and renders the media. The scheduler publishes at the account\'s peak hours — no manual download or upload.', 'igbz-suite' )
		);

		if ( $content_id ) {
			$this->render_detail( $content_id );
			View::close();
			return;
		}

		$this->render_counters( $account_id );
		$this->render_filters( $account_id, $status );
		$this->render_list( $account_id, $status, $paged );

		View::close();
	}

	private function render_counters( int $account_id ): void {
		$db     = igbz()->db();
		$where  = 'WHERE 1=1';
		$params = [];
		if ( $account_id > 0 ) {
			$where   .= ' AND account_id = %d';
			$params[] = $account_id;
		}

		$rows   = $db->results( 'SELECT status, COUNT(*) AS total FROM ' . $db->table( 'ig_content' ) . " {$where} GROUP BY status", ...$params );
		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		echo '<div class="igbz-cards">';
		foreach ( $this->statuses() as $key => $label ) {
			printf(
				'<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>',
				esc_html( (string) ( $counts[ $key ] ?? 0 ) ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	private function render_filters( int $account_id, string $status ): void {
		echo '<form method="get" class="igbz-filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );

		echo '<select name="account_id">';
		printf( '<option value="0">%s</option>', esc_html__( 'All accounts', 'igbz-suite' ) );
		foreach ( $this->manus()->accounts( igbz()->tenancy()->id(), false ) as $account ) {
			printf(
				'<option value="%1$d" %2$s>@%3$s</option>',
				(int) $account['id'],
				selected( (int) $account['id'], $account_id, false ),
				esc_html( (string) $account['username'] )
			);
		}
		echo '</select> ';

		echo '<select name="status">';
		printf( '<option value="">%s</option>', esc_html__( 'Any status', 'igbz-suite' ) );
		foreach ( $this->statuses() as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $key, $status, false ), esc_html( $label ) );
		}
		echo '</select> ';

		submit_button( __( 'Filter', 'igbz-suite' ), 'secondary', '', false );
		printf(
			' <a class="button" href="%1$s">%2$s</a>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'run' => 'tick' ] ), self::NONCE ) ),
			esc_html__( 'Run pipeline now', 'igbz-suite' )
		);
		echo '</form>';
	}

	private function render_list( int $account_id, string $status, int $paged ): void {
		$args = [
			'tenant_id' => igbz()->tenancy()->id(),
			'limit'     => self::PER_PAGE,
			'offset'    => ( $paged - 1 ) * self::PER_PAGE,
		];
		if ( $account_id > 0 ) {
			$args['account_id'] = $account_id;
		}
		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		$rows  = $this->manus()->contents( $args );
		$db    = igbz()->db();
		$total = (int) $db->scalar(
			'SELECT COUNT(*) FROM ' . $db->table( 'ig_content' ) . ' WHERE tenant_id = %d
			 AND ( %d = 0 OR account_id = %d ) AND ( %s = %s OR status = %s )',
			igbz()->tenancy()->id(),
			$account_id,
			$account_id,
			$status,
			'',
			$status
		);

		$display = [];
		foreach ( $rows as $row ) {
			$id        = (int) $row['id'];
			$account   = $this->manus()->account( (int) $row['account_id'] );
			$display[] = [
				'title'     => sprintf(
					'<a href="%1$s"><strong>%2$s</strong></a><br /><span class="description">@%3$s</span>',
					esc_url( Menu::url( self::SLUG, [ 'content' => $id ] ) ),
					esc_html( (string) $row['title'] ?: sprintf( '#%d', $id ) ),
					esc_html( $account ? (string) $account['username'] : '?' )
				),
				'kind'      => esc_html( $this->kind_label( (string) $row['kind'] ) ),
				'status'    => View::status_pill( $this->status_tone( (string) $row['status'] ) ) . ' '
					. esc_html( $this->statuses()[ (string) $row['status'] ] ?? (string) $row['status'] ),
				'scheduled' => esc_html( $this->local_time( $row['scheduled_for'] ?? null ) ),
				'published' => $row['permalink']
					? sprintf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( (string) $row['permalink'] ), esc_html__( 'View', 'igbz-suite' ) )
					: esc_html( $this->local_time( $row['published_at'] ?? null ) ),
				'actions'   => $this->row_actions( $row ),
			];
		}

		View::table(
			[
				'title'     => __( 'Item', 'igbz-suite' ),
				'kind'      => __( 'Kind', 'igbz-suite' ),
				'status'    => __( 'Status', 'igbz-suite' ),
				'scheduled' => __( 'Scheduled for', 'igbz-suite' ),
				'published' => __( 'Published', 'igbz-suite' ),
				'actions'   => __( 'Actions', 'igbz-suite' ),
			],
			$display,
			static fn ( array $row, string $key ): string => (string) $row[ $key ],
			__( 'Nothing in the pipeline yet. Queue a brief from the accounts screen.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, [ 'account_id' => $account_id, 'status' => $status ] );
	}

	/** @param array<string,mixed> $row */
	private function row_actions( array $row ): string {
		$id      = (int) $row['id'];
		$status  = (string) $row['status'];
		$buttons = [];

		if ( in_array( $status, [ ManusService::STATUS_DRAFT, ManusService::STATUS_FAILED ], true ) ) {
			$buttons[] = [ 'generate', __( 'Generate', 'igbz-suite' ) ];
		}
		if ( ManusService::STATUS_GENERATING === $status ) {
			$buttons[] = [ 'sync', __( 'Sync', 'igbz-suite' ) ];
		}
		if ( in_array( $status, [ ManusService::STATUS_READY, ManusService::STATUS_SCHEDULED ], true ) ) {
			$buttons[] = [ 'publish', __( 'Publish now', 'igbz-suite' ) ];
		}
		if ( ManusService::STATUS_READY === $status ) {
			$buttons[] = [ 'schedule', __( 'Schedule at peak', 'igbz-suite' ) ];
		}

		$html = '';
		foreach ( $buttons as $button ) {
			$html .= sprintf(
				'<a class="button button-small" href="%1$s">%2$s</a> ',
				esc_url( wp_nonce_url( Menu::url( self::SLUG, [ $button[0] => $id ] ), self::NONCE ) ),
				esc_html( $button[1] )
			);
		}

		$html .= sprintf(
			'<a class="button button-small" href="%1$s" onclick="return confirm(\'%2$s\')">%3$s</a>',
			esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'delete' => $id ] ), self::NONCE ) ),
			esc_js( __( 'Delete this item?', 'igbz-suite' ) ),
			esc_html__( 'Delete', 'igbz-suite' )
		);

		return $html;
	}

	private function render_detail( int $content_id ): void {
		$content = $this->manus()->content( $content_id );
		if ( ! $content ) {
			View::notice( __( 'Content not found.', 'igbz-suite' ), 'error' );
			return;
		}

		$account  = $this->manus()->account( (int) $content['account_id'] );
		$media    = json_decode( (string) $content['media'], true );
		$media    = is_array( $media ) ? $media : [];
		$hashtags = json_decode( (string) $content['hashtags'], true );
		$hashtags = is_array( $hashtags ) ? $hashtags : [];

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'Back to the pipeline', 'igbz-suite' )
		);

		echo '<table class="widefat striped"><tbody>';
		$this->detail_row( __( 'Account', 'igbz-suite' ), '@' . ( $account ? (string) $account['username'] : '?' ) );
		$this->detail_row( __( 'Kind', 'igbz-suite' ), $this->kind_label( (string) $content['kind'] ) );
		$this->detail_row(
			__( 'Status', 'igbz-suite' ),
			$this->statuses()[ (string) $content['status'] ] ?? (string) $content['status']
		);
		$this->detail_row( __( 'Manus task', 'igbz-suite' ), (string) $content['provider_task_id'] ?: '—' );
		$this->detail_row( __( 'Provider status', 'igbz-suite' ), (string) $content['provider_status'] ?: '—' );
		$this->detail_row( __( 'Scheduled for', 'igbz-suite' ), $this->local_time( $content['scheduled_for'] ?? null ) );
		$this->detail_row( __( 'Published at', 'igbz-suite' ), $this->local_time( $content['published_at'] ?? null ) );
		$this->detail_row( __( 'Retries', 'igbz-suite' ), (string) $content['retry_count'] );
		if ( (string) $content['last_error'] ) {
			$this->detail_row( __( 'Last error', 'igbz-suite' ), (string) $content['last_error'] );
		}
		echo '</tbody></table>';

		if ( $media ) {
			echo '<h2>' . esc_html__( 'Generated media', 'igbz-suite' ) . '</h2><div class="igbz-media-grid">';
			foreach ( $media as $item ) {
				$url  = (string) ( $item['url'] ?? '' );
				$type = (string) ( $item['type'] ?? 'image' );
				if ( '' === $url ) {
					continue;
				}
				echo '<figure class="igbz-media">';
				if ( 'video' === $type ) {
					printf( '<video controls preload="metadata" src="%s"></video>', esc_url( $url ) );
				} else {
					printf( '<img src="%1$s" alt="%2$s" />', esc_url( $url ), esc_attr( (string) ( $item['name'] ?? '' ) ) );
				}
				printf(
					'<figcaption><a href="%1$s" target="_blank" rel="noopener">%2$s</a></figcaption>',
					esc_url( $url ),
					esc_html( (string) ( $item['name'] ?? $url ) )
				);
				echo '</figure>';
			}
			echo '</div>';
		}

		echo '<h2>' . esc_html__( 'Caption and hashtags', 'igbz-suite' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="content_id" value="%d" />', $content_id );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="igbz_title">' . esc_html__( 'Title', 'igbz-suite' ) . '</label></th><td>';
		printf( '<input type="text" class="regular-text" id="igbz_title" name="title" value="%s" />', esc_attr( (string) $content['title'] ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="igbz_caption">' . esc_html__( 'Caption', 'igbz-suite' ) . '</label></th><td>';
		printf( '<textarea id="igbz_caption" name="caption" rows="8" class="large-text">%s</textarea>', esc_textarea( (string) $content['caption'] ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="igbz_hashtags">' . esc_html__( 'Hashtags', 'igbz-suite' ) . '</label></th><td>';
		printf(
			'<textarea id="igbz_hashtags" name="hashtags" rows="3" class="large-text">%s</textarea>',
			esc_textarea( implode( ' ', array_map( 'strval', $hashtags ) ) )
		);
		printf( '<p class="description">%s</p>', esc_html__( 'Space separated. The # is added automatically when missing.', 'igbz-suite' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="igbz_scheduled_for">' . esc_html__( 'Schedule at', 'igbz-suite' ) . '</label></th><td>';
		printf(
			'<input type="datetime-local" id="igbz_scheduled_for" name="scheduled_for" value="%s" />',
			esc_attr( $content['scheduled_for'] ? (string) wp_date( 'Y-m-d\TH:i', $this->to_local_ts( (string) $content['scheduled_for'] ) ) : '' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Site time. Leave empty and the scheduler will pick the next peak slot itself.', 'igbz-suite' )
		);
		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save content', 'igbz-suite' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Brief sent to Manus', 'igbz-suite' ) . '</h2>';
		printf(
			'<pre class="igbz-pre">%s</pre>',
			esc_html( wp_json_encode( json_decode( (string) $content['brief'], true ) ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ?: '{}' )
		);
	}

	private function detail_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:220px">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	// ------------------------------------------------------------- handlers

	private function handle_post(): void {
		Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
		View::check_nonce( self::NONCE );

		$content_id = isset( $_POST['content_id'] ) ? (int) $_POST['content_id'] : 0;
		if ( $content_id <= 0 ) {
			return;
		}

		$hashtags_raw = isset( $_POST['hashtags'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hashtags'] ) ) : '';
		$hashtags     = [];
		foreach ( preg_split( '/[\s,]+/u', $hashtags_raw ) ?: [] as $tag ) {
			$tag = trim( (string) $tag );
			if ( '' === $tag ) {
				continue;
			}
			$hashtags[] = str_starts_with( $tag, '#' ) ? $tag : '#' . $tag;
		}

		$data = [
			'title'    => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'caption'  => isset( $_POST['caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['caption'] ) ) : '',
			'hashtags' => $hashtags,
		];

		$when = isset( $_POST['scheduled_for'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_for'] ) ) : '';
		if ( '' !== $when ) {
			$timestamp             = (int) get_gmt_from_date( str_replace( 'T', ' ', $when ), 'U' );
			$data['scheduled_for'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			$data['status']        = ManusService::STATUS_SCHEDULED;
		}

		$this->manus()->save_content( $data, $content_id );
		View::notice( __( 'Content saved.', 'igbz-suite' ) );
	}

	private function handle_get_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$actions = [ 'generate', 'sync', 'publish', 'schedule', 'delete' ];
		foreach ( $actions as $action ) {
			if ( ! isset( $_GET[ $action ] ) ) {
				continue;
			}
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$this->run_action( $action, (int) $_GET[ $action ] );
			return;
		}

		if ( isset( $_GET['run'] ) && 'tick' === sanitize_key( wp_unslash( $_GET['run'] ) ) ) {
			check_admin_referer( self::NONCE );
			Capabilities::require( Capabilities::MANAGE_INSTAGRAM );
			$this->scheduler()->tick();
			View::notice( __( 'Pipeline run finished.', 'igbz-suite' ) );
		}
		// phpcs:enable
	}

	private function run_action( string $action, int $content_id ): void {
		$manus   = $this->manus();
		$content = $manus->content( $content_id );
		if ( ! $content ) {
			View::notice( __( 'Content not found.', 'igbz-suite' ), 'error' );
			return;
		}

		switch ( $action ) {
			case 'generate':
				$ok = $manus->generate( $content_id );
				View::notice(
					$ok ? __( 'Manus task started.', 'igbz-suite' ) : __( 'Could not start the Manus task.', 'igbz-suite' ),
					$ok ? 'success' : 'error'
				);
				break;

			case 'sync':
				$status = $manus->sync_generation( $content_id );
				View::notice(
					sprintf( /* translators: %s: status */ __( 'Sync finished with status: %s', 'igbz-suite' ), $status )
				);
				break;

			case 'publish':
				$result = $manus->publish( $content );
				View::notice(
					$result->success
						? __( 'Sent to the publisher.', 'igbz-suite' )
						: sprintf( /* translators: %s: error */ __( 'Publish failed: %s', 'igbz-suite' ), $result->error ),
					$result->success ? 'success' : 'error'
				);
				break;

			case 'schedule':
				$account = $manus->account( (int) $content['account_id'] );
				if ( ! $account ) {
					View::notice( __( 'The owning account is gone.', 'igbz-suite' ), 'error' );
					break;
				}
				$timestamp = $this->scheduler()->next_peak_slot( $account );
				$manus->schedule( $content, $timestamp );
				View::notice(
					sprintf(
						/* translators: %s: date */
						__( 'Scheduled for %s.', 'igbz-suite' ),
						wp_date( 'Y-m-d H:i', $timestamp )
					)
				);
				break;

			case 'delete':
				$manus->delete_content( $content_id );
				View::notice( __( 'Content deleted.', 'igbz-suite' ) );
				break;
		}
	}

	// -------------------------------------------------------------- helpers

	/** @return array<string,string> */
	private function statuses(): array {
		return [
			ManusService::STATUS_DRAFT      => __( 'Draft', 'igbz-suite' ),
			ManusService::STATUS_GENERATING => __( 'Generating', 'igbz-suite' ),
			ManusService::STATUS_READY      => __( 'Ready', 'igbz-suite' ),
			ManusService::STATUS_SCHEDULED  => __( 'Scheduled', 'igbz-suite' ),
			ManusService::STATUS_PUBLISHING => __( 'Publishing', 'igbz-suite' ),
			ManusService::STATUS_PUBLISHED  => __( 'Published', 'igbz-suite' ),
			ManusService::STATUS_FAILED     => __( 'Failed', 'igbz-suite' ),
		];
	}

	private function status_tone( string $status ): string {
		return match ( $status ) {
			ManusService::STATUS_PUBLISHED, ManusService::STATUS_READY => 'ok',
			ManusService::STATUS_FAILED                                => 'error',
			default                                                    => 'warn',
		};
	}

	private function kind_label( string $kind ): string {
		return match ( $kind ) {
			ManusService::KIND_CAROUSEL => __( 'Carousel', 'igbz-suite' ),
			ManusService::KIND_STORY    => __( 'Story', 'igbz-suite' ),
			ManusService::KIND_REEL     => __( 'Reel', 'igbz-suite' ),
			default                     => __( 'Post', 'igbz-suite' ),
		};
	}

	private function local_time( ?string $mysql_utc ): string {
		if ( ! $mysql_utc || '0000-00-00 00:00:00' === $mysql_utc ) {
			return '—';
		}
		return wp_date( 'Y-m-d H:i', $this->to_local_ts( $mysql_utc ) ) ?: '—';
	}

	private function to_local_ts( string $mysql_utc ): int {
		return (int) strtotime( $mysql_utc . ' UTC' );
	}
}
