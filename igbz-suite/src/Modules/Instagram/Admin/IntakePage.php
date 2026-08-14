<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Services\ProductIntakeService;
use IGBZ\Suite\Support\Admin\Menu;
use IGBZ\Suite\Support\Admin\View;
use IGBZ\Suite\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Watch the registrations coming in from the phone.
 *
 * Read-mostly by design. Products are registered in the app and nowhere else, so this screen is
 * not an editor — it exists so the shop owner can see where a registration got to, read why a
 * photo was refused, and unstick one that failed. The only actions are the ones the app cannot
 * offer: retry a failed step, and discard an abandoned draft.
 */
final class IntakePage {

	public const SLUG = 'igbz-ig-intake';

	private const PER_PAGE = 20;

	private const NONCE = 'igbz_ig_intake';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ], 23 );
	}

	public function add_page(): void {
		Menu::add( self::SLUG, __( 'Registrations', 'igbz-suite' ), [ $this, 'render' ], Capabilities::MANAGE_INSTAGRAM );
	}

	private function intake(): ProductIntakeService {
		return igbz()->get( 'ig.intake' );
	}

	public function render(): void {
		$this->handle_actions();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$intake_id = isset( $_GET['intake'] ) ? (int) $_GET['intake'] : 0;
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable

		View::open(
			__( 'Product registrations', 'igbz-suite' ),
			__( 'Every product here was photographed in the app. The assistant graded the photo, cleaned it up, wrote the listing, created the product and built the Instagram post — nobody opened the WooCommerce editor.', 'igbz-suite' )
		);

		if ( $intake_id > 0 ) {
			$this->render_detail( $intake_id );
			View::close();
			return;
		}

		$this->render_counters();
		$this->render_filters( $status );
		$this->render_list( $status, $paged );

		View::close();
	}

	private function handle_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['retry'] ) ) {
			View::check_nonce( self::NONCE );
			$this->retry( (int) $_GET['retry'] );
		}
		if ( isset( $_GET['discard'] ) ) {
			View::check_nonce( self::NONCE );
			$this->discard( (int) $_GET['discard'] );
		}
		// phpcs:enable
	}

	/**
	 * Push a failed registration back into the pipeline at the step it died on.
	 *
	 * Rather than restarting from the photo, which would waste the work already done and make the
	 * shopkeeper repeat themselves, the row is resumed from whatever it does have: copy but no
	 * product means create the product; an edited image but no description means wait for the
	 * app; nothing but a photo means grade it again.
	 */
	private function retry( int $id ): void {
		$row = $this->intake()->get( $id );
		if ( ! $row ) {
			View::notice( __( 'That registration no longer exists.', 'igbz-suite' ), 'error' );
			return;
		}

		$intake = $this->intake();
		$intake->update( $id, [ 'last_error' => '', 'retry_count' => 0, 'provider_task_id' => '', 'provider_stage' => '' ] );

		if ( (int) $row['product_id'] > 0 ) {
			// The product exists, so what failed was the post.
			$intake->update( $id, [ 'status' => ProductIntakeService::STATUS_AWAITING_KIND ] );
			View::notice( __( 'Resumed at the Instagram post. Choose image or video in the app.', 'igbz-suite' ) );
			return;
		}

		if ( $intake->copy( $row ) ) {
			$result = igbz()->get( 'ig.publisher' )->publish( (array) $intake->get( $id ) );
			View::notice(
				$result['ok']
					? __( 'The product was created.', 'igbz-suite' )
					: sprintf( /* translators: %s: error */ __( 'Still failing: %s', 'igbz-suite' ), $result['error'] ),
				$result['ok'] ? 'success' : 'error'
			);
			return;
		}

		if ( '' !== trim( (string) $row['raw_description'] ) ) {
			$intake->start_writing( $id, igbz()->get( 'ig.translations' )->target_languages() );
			View::notice( __( 'The listing is being written again.', 'igbz-suite' ) );
			return;
		}

		if ( '' !== (string) $row['clean_url'] ) {
			$intake->update( $id, [ 'status' => ProductIntakeService::STATUS_READY_TO_EDIT ] );
			View::notice( __( 'Resumed at the editor. Continue in the app.', 'igbz-suite' ) );
			return;
		}

		$intake->start_grading( $id );
		View::notice( __( 'The photo is being checked again.', 'igbz-suite' ) );
	}

	private function discard( int $id ): void {
		$row = $this->intake()->get( $id );

		if ( $row && (int) $row['product_id'] > 0 ) {
			View::notice(
				__( 'This registration created a product, so it is kept as a record. Delete the product itself if you want it gone.', 'igbz-suite' ),
				'error'
			);
			return;
		}

		$this->intake()->delete( $id );
		View::notice( __( 'Registration discarded.', 'igbz-suite' ) );
	}

	private function render_counters(): void {
		$counts = $this->intake()->counts_by_status( igbz()->tenancy()->id() );

		echo '<div class="igbz-cards">';
		foreach ( $this->grouped_counts( $counts ) as $label => $total ) {
			printf(
				'<div class="igbz-card"><strong>%1$s</strong><span>%2$s</span></div>',
				esc_html( (string) $total ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Fifteen statuses is too many to show as fifteen counters, so they are grouped by what the
	 * shop owner would actually do about them.
	 *
	 * @param array<string,int> $counts
	 * @return array<string,int>
	 */
	private function grouped_counts( array $counts ): array {
		$sum = static fn ( array $keys ): int => array_sum( array_map( static fn ( string $k ): int => $counts[ $k ] ?? 0, $keys ) );

		return [
			__( 'In progress', 'igbz-suite' )   => $sum(
				[
					ProductIntakeService::STATUS_UPLOADED,
					ProductIntakeService::STATUS_GRADING,
					ProductIntakeService::STATUS_GRADED,
					ProductIntakeService::STATUS_PROCESSING,
					ProductIntakeService::STATUS_READY_TO_EDIT,
					ProductIntakeService::STATUS_EDITED,
					ProductIntakeService::STATUS_DESCRIBING,
					ProductIntakeService::STATUS_TRANSCRIBING,
					ProductIntakeService::STATUS_WRITING,
				]
			),
			__( 'Photo refused', 'igbz-suite' ) => $sum( [ ProductIntakeService::STATUS_REJECTED ] ),
			__( 'Product made', 'igbz-suite' )  => $sum(
				[
					ProductIntakeService::STATUS_PRODUCT_CREATED,
					ProductIntakeService::STATUS_AWAITING_KIND,
					ProductIntakeService::STATUS_PRODUCING_VIDEO,
					ProductIntakeService::STATUS_VIDEO_REVIEW,
					ProductIntakeService::STATUS_COMPOSING,
				]
			),
			__( 'Posted', 'igbz-suite' )        => $sum( [ ProductIntakeService::STATUS_SCHEDULED, ProductIntakeService::STATUS_PUBLISHED ] ),
			__( 'Failed', 'igbz-suite' )        => $sum( [ ProductIntakeService::STATUS_FAILED ] ),
		];
	}

	private function render_filters( string $status ): void {
		echo '<form method="get" class="igbz-filters">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );

		echo '<select name="status">';
		printf( '<option value="">%s</option>', esc_html__( 'Any status', 'igbz-suite' ) );
		foreach ( $this->statuses() as $key => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( $key, $status, false ), esc_html( $label ) );
		}
		echo '</select> ';

		submit_button( __( 'Filter', 'igbz-suite' ), 'secondary', '', false );
		echo '</form>';
	}

	private function render_list( string $status, int $paged ): void {
		$args = [
			'tenant_id' => igbz()->tenancy()->id(),
			'limit'     => self::PER_PAGE,
			'offset'    => ( $paged - 1 ) * self::PER_PAGE,
		];
		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		$rows  = $this->intake()->all( $args );
		$total = $this->intake()->count( $args );

		View::table(
			[
				'sku'     => __( 'Code', 'igbz-suite' ),
				'photo'   => __( 'Photo', 'igbz-suite' ),
				'title'   => __( 'Product', 'igbz-suite' ),
				'status'  => __( 'Stage', 'igbz-suite' ),
				'created' => __( 'Registered', 'igbz-suite' ),
				'actions' => __( 'Actions', 'igbz-suite' ),
			],
			$rows,
			[ $this, 'cell' ],
			__( 'No products have been registered from the app yet.', 'igbz-suite' )
		);

		View::pagination( $total, self::PER_PAGE, $paged, self::SLUG, '' !== $status ? [ 'status' => $status ] : [] );
	}

	/** @param array<string,mixed> $row */
	public function cell( array $row, string $key ): string {
		$id = (int) $row['id'];

		switch ( $key ) {
			case 'sku':
				return sprintf(
					'<a href="%1$s"><code>%2$s</code></a>',
					esc_url( Menu::url( self::SLUG, [ 'intake' => $id ] ) ),
					esc_html( (string) $row['sku'] )
				);

			case 'photo':
				$url = $this->intake()->best_image( $row );
				return '' === $url
					? '&mdash;'
					: sprintf(
						'<img src="%s" alt="" style="width:52px;height:52px;object-fit:cover;border-radius:4px" />',
						esc_url( $url )
					);

			case 'title':
				$copy  = $this->intake()->copy( $row );
				$title = (string) ( $copy['title'] ?? '' );

				if ( (int) $row['product_id'] > 0 ) {
					return sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( (string) get_edit_post_link( (int) $row['product_id'] ) ),
						esc_html( '' !== $title ? $title : (string) $row['sku'] )
					);
				}

				return '' !== $title ? esc_html( $title ) : '<em>' . esc_html__( 'not written yet', 'igbz-suite' ) . '</em>';

			case 'status':
				return $this->status_cell( $row );

			case 'created':
				return esc_html( (string) $row['created_at'] );

			case 'actions':
				$links = [];

				if ( ProductIntakeService::STATUS_FAILED === (string) $row['status'] ) {
					$links[] = sprintf(
						'<a href="%s">%s</a>',
						esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'retry' => $id ] ), self::NONCE ) ),
						esc_html__( 'Retry', 'igbz-suite' )
					);
				}
				if ( (int) $row['product_id'] <= 0 ) {
					$links[] = sprintf(
						'<a href="%s" style="color:#d63638">%s</a>',
						esc_url( wp_nonce_url( Menu::url( self::SLUG, [ 'discard' => $id ] ), self::NONCE ) ),
						esc_html__( 'Discard', 'igbz-suite' )
					);
				}

				return implode( ' | ', $links ) ?: '&mdash;';
		}

		return '';
	}

	/** @param array<string,mixed> $row */
	private function status_cell( array $row ): string {
		$status = (string) $row['status'];
		$label  = $this->statuses()[ $status ] ?? $status;

		$tone = match ( $status ) {
			ProductIntakeService::STATUS_FAILED    => 'error',
			ProductIntakeService::STATUS_REJECTED  => 'warn',
			ProductIntakeService::STATUS_PUBLISHED,
			ProductIntakeService::STATUS_SCHEDULED => 'ok',
			default                                => '',
		};

		$cell = '' !== $tone
			? sprintf(
				'<span style="color:%1$s;font-weight:600">%2$s</span>',
				'error' === $tone ? '#d63638' : ( 'warn' === $tone ? '#dba617' : '#00a32a' ),
				esc_html( $label )
			)
			: esc_html( $label );

		if ( '' !== (string) $row['last_error'] ) {
			$cell .= '<br /><small style="color:#d63638">' . esc_html( (string) $row['last_error'] ) . '</small>';
		}

		return $cell;
	}

	private function render_detail( int $id ): void {
		$row = $this->intake()->get( $id );

		if ( ! $row ) {
			View::notice( __( 'That registration does not exist.', 'igbz-suite' ), 'error' );
			return;
		}

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p>',
			esc_url( Menu::url( self::SLUG ) ),
			esc_html__( 'All registrations', 'igbz-suite' )
		);

		$copy    = $this->intake()->copy( $row );
		$quality = $this->intake()->quality( $row );

		echo '<h2>' . esc_html( (string) ( $copy['title'] ?? $row['sku'] ) ) . '</h2>';

		// The images, side by side, because the whole first half of the flow is about them.
		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">';
		foreach (
			[
				'source_url' => __( 'As photographed', 'igbz-suite' ),
				'clean_url'  => __( 'Prepared', 'igbz-suite' ),
				'edited_url' => __( 'Edited in the app', 'igbz-suite' ),
			] as $column => $caption
		) {
			$url = (string) ( $row[ $column ] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			printf(
				'<figure style="margin:0"><img src="%1$s" alt="" style="max-width:220px;border-radius:6px;border:1px solid #dcdcde" />'
					. '<figcaption style="text-align:center;color:#646970">%2$s</figcaption></figure>',
				esc_url( $url ),
				esc_html( $caption )
			);
		}
		echo '</div>';

		if ( $quality && ! empty( $quality['reasons'] ) ) {
			$refused = ProductIntakeService::STATUS_REJECTED === (string) $row['status'];

			printf(
				'<div class="notice notice-%1$s inline"><p><strong>%2$s</strong></p><ul style="list-style:disc;margin-left:20px">',
				$refused ? 'warning' : 'info',
				esc_html(
					$refused
						? sprintf(
							/* translators: %d: score out of 100 */
							__( 'The photo was refused (scored %d).', 'igbz-suite' ),
							(int) $row['quality_score']
						)
						: sprintf(
							/* translators: %d: score out of 100 */
							__( 'Photo notes (scored %d).', 'igbz-suite' ),
							(int) $row['quality_score']
						)
				)
			);
			foreach ( (array) $quality['reasons'] as $reason ) {
				echo '<li>' . esc_html( (string) $reason ) . '</li>';
			}
			echo '</ul>';
			if ( ! empty( $quality['suggestion'] ) ) {
				echo '<p>' . esc_html( (string) $quality['suggestion'] ) . '</p>';
			}
			echo '</div>';
		}

		$rows = [
			[ 'k' => __( 'Product code', 'igbz-suite' ), 'v' => '<code>' . esc_html( (string) $row['sku'] ) . '</code>' ],
			[ 'k' => __( 'Stage', 'igbz-suite' ), 'v' => $this->status_cell( $row ) ],
			[ 'k' => __( 'Photo attempts', 'igbz-suite' ), 'v' => esc_html( (string) $row['attempt'] ) ],
			[
				'k' => __( 'Price', 'igbz-suite' ),
				'v' => esc_html( View::money( (float) $row['price'] ) ),
			],
			[ 'k' => __( 'Stock', 'igbz-suite' ), 'v' => esc_html( (string) $row['stock'] ) ],
			[
				'k' => __( 'Described by', 'igbz-suite' ),
				'v' => ProductIntakeService::INPUT_VOICE === (string) $row['input_mode']
					? esc_html__( 'Voice', 'igbz-suite' )
					: esc_html__( 'Typing', 'igbz-suite' ),
			],
			[
				'k' => __( 'What the shopkeeper said', 'igbz-suite' ),
				'v' => nl2br( esc_html( (string) $row['raw_description'] ) ),
			],
		];

		if ( (int) $row['product_id'] > 0 ) {
			$rows[] = [
				'k' => __( 'Product', 'igbz-suite' ),
				'v' => sprintf(
					'<a href="%1$s">%2$s</a> &middot; <a href="%3$s">%4$s</a>',
					esc_url( (string) get_edit_post_link( (int) $row['product_id'] ) ),
					esc_html__( 'Edit', 'igbz-suite' ),
					esc_url( (string) get_permalink( (int) $row['product_id'] ) ),
					esc_html__( 'View', 'igbz-suite' )
				),
			];
		}

		if ( (int) $row['funnel_id'] > 0 ) {
			$rows[] = [
				'k' => __( 'Comment funnel', 'igbz-suite' ),
				'v' => sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( Menu::url( FunnelsPage::SLUG, [ 'funnel' => (int) $row['funnel_id'] ] ) ),
					esc_html(
						sprintf(
							/* translators: %s: keyword */
							__( 'Comments matching “%s”', 'igbz-suite' ),
							(string) $row['sku']
						)
					)
				),
			];
		}

		if ( (int) $row['content_id'] > 0 ) {
			$rows[] = [
				'k' => __( 'Instagram post', 'igbz-suite' ),
				'v' => sprintf(
					'<a href="%1$s">%2$s</a>',
					esc_url( Menu::url( ContentPage::SLUG, [ 'content' => (int) $row['content_id'] ] ) ),
					esc_html__( 'Open in the content queue', 'igbz-suite' )
				),
			];
		}

		if ( '' !== (string) $row['video_url'] ) {
			$rows[] = [
				'k' => __( 'Video', 'igbz-suite' ),
				'v' => sprintf(
					'<a href="%1$s" target="_blank" rel="noopener">%2$s</a> %3$s',
					esc_url( (string) $row['video_url'] ),
					esc_html__( 'Watch', 'igbz-suite' ),
					(int) $row['video_approved'] ? esc_html__( '(approved)', 'igbz-suite' ) : esc_html__( '(awaiting approval)', 'igbz-suite' )
				),
			];
		}

		$translations = $this->intake()->translations( $row );
		if ( $translations ) {
			$rows[] = [
				'k' => __( 'Translations', 'igbz-suite' ),
				'v' => esc_html( implode( ', ', array_keys( $translations ) ) ),
			];
		}

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $rows as $item ) {
			printf(
				'<tr><th style="width:220px">%1$s</th><td>%2$s</td></tr>',
				esc_html( (string) $item['k'] ),
				wp_kses_post( (string) $item['v'] )
			);
		}
		echo '</tbody></table>';

		if ( ! empty( $copy['description'] ) ) {
			echo '<h3>' . esc_html__( 'Listing text', 'igbz-suite' ) . '</h3>';
			echo '<div style="max-width:900px;padding:12px;background:#fff;border:1px solid #dcdcde">'
				. wp_kses_post( (string) $copy['description'] ) . '</div>';
		}
	}

	/** @return array<string,string> */
	private function statuses(): array {
		return [
			ProductIntakeService::STATUS_UPLOADED        => __( 'Photo received', 'igbz-suite' ),
			ProductIntakeService::STATUS_GRADING         => __( 'Checking the photo', 'igbz-suite' ),
			ProductIntakeService::STATUS_REJECTED        => __( 'Photo refused', 'igbz-suite' ),
			ProductIntakeService::STATUS_GRADED          => __( 'Photo accepted', 'igbz-suite' ),
			ProductIntakeService::STATUS_PROCESSING      => __( 'Preparing the image', 'igbz-suite' ),
			ProductIntakeService::STATUS_READY_TO_EDIT   => __( 'Waiting in the editor', 'igbz-suite' ),
			ProductIntakeService::STATUS_EDITED          => __( 'Image ready', 'igbz-suite' ),
			ProductIntakeService::STATUS_DESCRIBING      => __( 'Description received', 'igbz-suite' ),
			ProductIntakeService::STATUS_TRANSCRIBING    => __( 'Transcribing the voice note', 'igbz-suite' ),
			ProductIntakeService::STATUS_WRITING         => __( 'Writing the listing', 'igbz-suite' ),
			ProductIntakeService::STATUS_PRODUCT_CREATED => __( 'Product created', 'igbz-suite' ),
			ProductIntakeService::STATUS_AWAITING_KIND   => __( 'Image or video?', 'igbz-suite' ),
			ProductIntakeService::STATUS_PRODUCING_VIDEO => __( 'Making the video', 'igbz-suite' ),
			ProductIntakeService::STATUS_VIDEO_REVIEW    => __( 'Video awaiting approval', 'igbz-suite' ),
			ProductIntakeService::STATUS_COMPOSING       => __( 'Composing the post', 'igbz-suite' ),
			ProductIntakeService::STATUS_SCHEDULED       => __( 'Post scheduled', 'igbz-suite' ),
			ProductIntakeService::STATUS_PUBLISHED       => __( 'Posted', 'igbz-suite' ),
			ProductIntakeService::STATUS_FAILED          => __( 'Failed', 'igbz-suite' ),
		];
	}
}
