<?php
namespace IGBZ\Suite\Modules\Instagram\Vip;

use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The share landing page: /{vip.landing_slug}/p/{shortcode}
 *
 * This is where the Instagram share sheet drops somebody who picked our app. It has one job —
 * turn a stranger holding a link into either an app install or a payment — so it shows the teaser,
 * explains what the post is, and offers exactly the actions that make sense for it: subscribe, buy
 * this single post, or, on a public post, send a tip.
 *
 * It is deliberately a plain rewrite rule and not a WordPress page. A page would be one more thing
 * for the shop owner to create, keep published and not rename, and the slug has to be predictable
 * because it is baked into every share link ever sent.
 */
final class VipLandingPage {

	public const QUERY_VAR = 'igbz_vip_share';

	public function __construct(
		private VipPostService $posts,
		private VipAccessService $access,
		private VipBillingService $billing,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( 'init', [ $this, 'add_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	public function add_rules(): void {
		$slug = $this->slug();

		add_rewrite_rule(
			'^' . preg_quote( $slug, '/' ) . '/p/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * @param array<int,string> $vars
	 * @return array<int,string>
	 */
	public function add_query_var( $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	private function slug(): string {
		$slug = trim( $this->settings->string( 'vip.landing_slug', 'vip' ), '/' );
		return '' !== $slug ? $slug : 'vip';
	}

	/** Public URL for a shortcode — the thing that actually goes in the share sheet. */
	public function url( string $shortcode ): string {
		return home_url( '/' . $this->slug() . '/p/' . rawurlencode( $shortcode ) );
	}

	// ------------------------------------------------------------- rendering

	public function maybe_render(): void {
		$shortcode = get_query_var( self::QUERY_VAR );

		// The pretty rule needs flushed rewrites; ?igbz_vip_share= keeps every link working on a
		// site running plain permalinks, which is also what the unit test hits.
		if ( '' === $shortcode ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only page.
			$shortcode = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		}

		if ( '' === (string) $shortcode ) {
			return;
		}

		if ( ! $this->settings->bool( 'vip.enabled', true ) ) {
			$this->render_missing();
		}

		$post = $this->posts->post_by_shortcode( (string) $shortcode );
		if ( ! $post ) {
			$this->render_missing();
		}

		$notice = $this->handle_action( (array) $post );

		$this->render( (array) $post, $notice );
	}

	/**
	 * Handle a form post from the page itself.
	 *
	 * Subscribing and buying need an account, so a signed-out visitor is sent to sign in and
	 * returned here. A tip does not: asking a passer-by to register before they can hand over
	 * money is how you get no tips.
	 *
	 * @param array<string,mixed> $post
	 * @return array{type:string,text:string}|null
	 */
	private function handle_action( array $post ): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return null;
		}

		$action = isset( $_POST['igbz_vip_action'] ) ? sanitize_key( wp_unslash( $_POST['igbz_vip_action'] ) ) : '';
		if ( '' === $action ) {
			return null;
		}

		if ( ! isset( $_POST['_igbz_vip_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_igbz_vip_nonce'] ) ), 'igbz_vip_share' ) ) {
			return [ 'type' => 'error', 'text' => __( 'Your session expired. Please try again.', 'igbz-suite' ) ];
		}

		$user_id = get_current_user_id();

		if ( 'tip' === $action ) {
			$amount = (float) ( isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : 0 );
			$note   = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

			$result = $this->billing->tip( $user_id, $amount, (int) $post['id'], $note );
			if ( ! $result['ok'] ) {
				return [ 'type' => 'error', 'text' => (string) $result['error'] ];
			}

			$this->go( (string) $result['redirect_url'] );
		}

		if ( $user_id <= 0 ) {
			$this->go( wp_login_url( $this->url( (string) $post['shortcode'] ) ) );
		}

		if ( 'buy_post' === $action ) {
			$result = $this->billing->purchase_post( $user_id, (int) $post['id'] );
			if ( ! $result['ok'] ) {
				return [ 'type' => 'error', 'text' => (string) $result['error'] ];
			}
			if ( $result['granted'] ) {
				return [ 'type' => 'success', 'text' => __( 'This post is yours. Open it in the app.', 'igbz-suite' ) ];
			}
			$this->go( (string) $result['redirect_url'] );
		}

		if ( 'subscribe' === $action ) {
			$plan_id = (int) ( isset( $_POST['plan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_id'] ) ) : 0 );

			$result = $this->billing->subscribe( $user_id, $plan_id );
			if ( ! $result['ok'] ) {
				return [ 'type' => 'error', 'text' => (string) $result['error'] ];
			}
			if ( '' === (string) $result['redirect_url'] ) {
				return [ 'type' => 'success', 'text' => __( 'Your membership is active. Open the app to start watching.', 'igbz-suite' ) ];
			}
			$this->go( (string) $result['redirect_url'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return null;
	}

	private function go( string $url ): void {
		if ( '' === $url ) {
			return;
		}
		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- gateway hosts are external by nature.
		exit;
	}

	/**
	 * @param array<string,mixed>              $post
	 * @param array{type:string,text:string}|null $notice
	 */
	private function render( array $post, ?array $notice ): void {
		$user_id = get_current_user_id();
		$access  = $this->access->check_row( $user_id, $post );
		$media   = $this->posts->decode_media( $post );
		$cover   = $media[0] ?? [];
		$plans   = $this->access->plans( (int) $post['tenant_id'] );
		$is_free = VipAccessService::ACCESS_FREE === (string) $post['access'];
		$gone    = in_array( (string) $post['status'], [ VipPostService::STATUS_EXPIRED, VipPostService::STATUS_DELETED ], true );

		// Whether there is anything at all to sell. A shop that has not priced a plan yet, on a
		// members-only post, has neither offer — and an empty "Two ways to unlock it" heading over
		// nothing is worse than no heading: it tells the visitor the page is broken.
		$sell_membership = $access->can_subscribe() && [] !== $plans;
		$sell_post       = $access->can_buy_single();

		status_header( $gone ? 410 : 200 );
		nocache_headers();

		$this->head( $post, $cover );
		?>
<body class="igbz-vip-share">
<div class="igbz-vip-wrap">

	<?php if ( $notice ) : ?>
		<p class="igbz-vip-notice igbz-vip-<?php echo esc_attr( $notice['type'] ); ?>"><?php echo esc_html( $notice['text'] ); ?></p>
	<?php endif; ?>

	<div class="igbz-vip-card">
		<div class="igbz-vip-cover<?php echo $access->allowed ? '' : ' is-locked'; ?>">
			<?php $this->cover( $cover, $access->allowed ); ?>
			<?php if ( ! $access->allowed && ! $gone ) : ?>
				<span class="igbz-vip-lock" aria-hidden="true">&#128274;</span>
			<?php endif; ?>
		</div>

		<div class="igbz-vip-body">
			<h1><?php echo esc_html( $this->title( $post ) ); ?></h1>

			<?php if ( '' !== (string) ( $post['caption'] ?? '' ) ) : ?>
				<p class="igbz-vip-caption"><?php echo esc_html( $this->excerpt( (string) $post['caption'] ) ); ?></p>
			<?php endif; ?>

			<ul class="igbz-vip-meta">
				<li><?php echo esc_html( sprintf( /* translators: %s: number of likes */ __( '%s likes', 'igbz-suite' ), number_format_i18n( (int) $post['likes_count'] ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: %s: number of comments */ __( '%s comments', 'igbz-suite' ), number_format_i18n( (int) $post['comments_count'] ) ) ); ?></li>
				<?php if ( ! $gone && ! empty( $post['expires_at'] ) ) : ?>
					<li class="igbz-vip-expiry"><?php echo esc_html( $this->expiry_label( (string) $post['expires_at'] ) ); ?></li>
				<?php endif; ?>
			</ul>

			<?php if ( $gone ) : ?>
				<p class="igbz-vip-gone"><?php esc_html_e( 'This post is no longer available. Members see every new post the moment it goes live — join and you will not miss the next one.', 'igbz-suite' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! $gone ) : ?>
		<?php if ( $access->allowed ) : ?>
			<div class="igbz-vip-actions">
				<p class="igbz-vip-owned">
					<?php if ( $is_free ) : ?>
						<?php esc_html_e( 'This post is open to everyone. Open it in the app to watch it in full.', 'igbz-suite' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'You already have access to this post. Open it in the app.', 'igbz-suite' ); ?>
					<?php endif; ?>
				</p>
				<a class="igbz-vip-btn igbz-vip-primary" href="<?php echo esc_url( $this->deep_link( (string) $post['shortcode'] ), $this->allowed_schemes() ); ?>">
					<?php esc_html_e( 'Open in the app', 'igbz-suite' ); ?>
				</a>
			</div>
		<?php elseif ( $sell_membership || $sell_post ) : ?>
			<div class="igbz-vip-offers">
				<h2>
					<?php if ( $sell_membership && $sell_post ) : ?>
						<?php esc_html_e( 'Two ways to unlock it', 'igbz-suite' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'How to unlock it', 'igbz-suite' ); ?>
					<?php endif; ?>
				</h2>

				<?php if ( $sell_membership ) : ?>
					<form class="igbz-vip-offer" method="post">
						<?php wp_nonce_field( 'igbz_vip_share', '_igbz_vip_nonce' ); ?>
						<input type="hidden" name="igbz_vip_action" value="subscribe" />
						<h3><?php esc_html_e( 'Membership', 'igbz-suite' ); ?></h3>
						<p class="igbz-vip-hint"><?php esc_html_e( 'Every VIP post, this one included, for as long as your membership runs.', 'igbz-suite' ); ?></p>

						<div class="igbz-vip-plans">
							<?php foreach ( $plans as $i => $plan ) : ?>
								<label class="igbz-vip-plan">
									<input type="radio" name="plan_id" value="<?php echo (int) $plan['id']; ?>" <?php checked( 0, $i ); ?> />
									<span class="igbz-vip-plan-name"><?php echo esc_html( (string) $plan['name'] ); ?></span>
									<span class="igbz-vip-plan-price"><?php echo esc_html( $this->money( (float) $plan['price'], (string) $plan['currency'] ) ); ?></span>
									<span class="igbz-vip-plan-term">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of days */
												_n( '%d day', '%d days', (int) $plan['duration_days'], 'igbz-suite' ),
												(int) $plan['duration_days']
											)
										);
										?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>

						<button type="submit" class="igbz-vip-btn igbz-vip-primary"><?php esc_html_e( 'Become a member', 'igbz-suite' ); ?></button>
					</form>
				<?php endif; ?>

				<?php if ( $sell_post ) : ?>
					<form class="igbz-vip-offer" method="post">
						<?php wp_nonce_field( 'igbz_vip_share', '_igbz_vip_nonce' ); ?>
						<input type="hidden" name="igbz_vip_action" value="buy_post" />
						<h3><?php esc_html_e( 'Just this post', 'igbz-suite' ); ?></h3>
						<p class="igbz-vip-hint"><?php esc_html_e( 'A one-off payment. This post stays in your library, with no membership.', 'igbz-suite' ); ?></p>
						<p class="igbz-vip-price"><?php echo esc_html( $this->money( $access->price, '' ) ); ?></p>
						<button type="submit" class="igbz-vip-btn"><?php esc_html_e( 'Buy this post', 'igbz-suite' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="igbz-vip-actions">
				<p class="igbz-vip-hint"><?php esc_html_e( 'This post is for members. Open the app to see how to join.', 'igbz-suite' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! $access->allowed && VipAccess::DENY_ANONYMOUS === $access->reason ) : ?>
			<p class="igbz-vip-hint igbz-vip-signin">
				<a href="<?php echo esc_url( wp_login_url( $this->url( (string) $post['shortcode'] ) ) ); ?>"><?php esc_html_e( 'Already a member? Sign in.', 'igbz-suite' ); ?></a>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php $this->tip_box( $post, $is_free ); ?>
	<?php $this->download_box( $post ); ?>

	<div class="igbz-vip-explainer">
		<h2><?php esc_html_e( 'What is this?', 'igbz-suite' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: site name */
					__( 'This is a private post from %s. The version on Instagram is only a preview — the full one lives in our app, where members watch it without ads, comment, and message us directly.', 'igbz-suite' ),
					get_bloginfo( 'name' )
				)
			);
			?>
		</p>
		<p><?php esc_html_e( 'Install the app, sign in with the same phone number, and everything you buy here is waiting for you inside it.', 'igbz-suite' ); ?></p>
	</div>

</div>
<?php
		$this->foot();
		exit;
	}

	/**
	 * The tip box. Shown for a public post, which is the user-facing rule we agreed: financial
	 * support belongs on the posts anyone can see, not behind the paywall.
	 *
	 * @param array<string,mixed> $post
	 */
	private function tip_box( array $post, bool $is_free ): void {
		if ( ! $is_free || ! $this->settings->bool( 'vip.tips_enabled', true ) ) {
			return;
		}

		$presets = $this->billing->tip_presets();
		$min     = (float) $this->settings->int( 'vip.tip_min', 10000 );
		?>
	<form class="igbz-vip-tip" method="post">
		<?php wp_nonce_field( 'igbz_vip_share', '_igbz_vip_nonce' ); ?>
		<input type="hidden" name="igbz_vip_action" value="tip" />
		<h2><?php esc_html_e( 'Support this post', 'igbz-suite' ); ?></h2>
		<p class="igbz-vip-hint"><?php esc_html_e( 'Liked it? Buy us a coffee. No account needed.', 'igbz-suite' ); ?></p>

		<?php if ( $presets ) : ?>
			<div class="igbz-vip-tip-presets">
				<?php foreach ( $presets as $amount ) : ?>
					<button type="submit" name="amount" value="<?php echo esc_attr( (string) $amount ); ?>" class="igbz-vip-chip">
						<?php echo esc_html( $this->money( (float) $amount, '' ) ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<label class="igbz-vip-tip-custom">
			<span><?php esc_html_e( 'Another amount', 'igbz-suite' ); ?></span>
			<input type="number" name="amount" min="<?php echo esc_attr( (string) $min ); ?>" step="1000" inputmode="numeric" />
		</label>

		<label class="igbz-vip-tip-note">
			<span><?php esc_html_e( 'Add a note (optional)', 'igbz-suite' ); ?></span>
			<textarea name="message" rows="2" maxlength="255"></textarea>
		</label>

		<button type="submit" class="igbz-vip-btn"><?php esc_html_e( 'Send support', 'igbz-suite' ); ?></button>
	</form>
		<?php
	}

	/** @param array<string,mixed> $post */
	private function download_box( array $post ): void {
		$android = $this->settings->string( 'vip.app_android_url', '' );
		$ios     = $this->settings->string( 'vip.app_ios_url', '' );
		$apk     = $this->settings->string( 'vip.app_direct_apk_url', '' );

		if ( '' === $android && '' === $ios && '' === $apk ) {
			return;
		}
		?>
	<div class="igbz-vip-download">
		<h2><?php esc_html_e( 'Get the app', 'igbz-suite' ); ?></h2>
		<div class="igbz-vip-stores">
			<?php if ( '' !== $android ) : ?>
				<a class="igbz-vip-store" href="<?php echo esc_url( $android ); ?>" rel="nofollow noopener"><?php esc_html_e( 'Android', 'igbz-suite' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $ios ) : ?>
				<a class="igbz-vip-store" href="<?php echo esc_url( $ios ); ?>" rel="nofollow noopener"><?php esc_html_e( 'iPhone', 'igbz-suite' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $apk ) : ?>
				<a class="igbz-vip-store" href="<?php echo esc_url( $apk ); ?>" rel="nofollow noopener"><?php esc_html_e( 'Direct download (APK)', 'igbz-suite' ); ?></a>
			<?php endif; ?>
		</div>
		<p class="igbz-vip-hint">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: deep link */
					__( 'Already installed? This post opens at %s', 'igbz-suite' ),
					$this->deep_link( (string) $post['shortcode'] )
				)
			);
			?>
		</p>
	</div>
		<?php
	}

	/**
	 * Which image the cover shows.
	 *
	 * A locked post gets the stored placeholder and nothing else — the real URL is never printed
	 * on a public page, whoever is looking.
	 *
	 * `??` is the wrong operator for the unlocked branch: a media row always carries a `thumb`
	 * key, usually holding an empty string, so a null coalesce never falls through and the cover
	 * rendered blank. Fall back on emptiness, not on absence.
	 *
	 * @param array<string,mixed> $cover
	 */
	public function cover_src( array $cover, bool $unlocked ): string {
		$blur = (string) ( $cover['blur'] ?? '' );

		return $unlocked
			? ( (string) ( $cover['thumb'] ?? '' ) ?: $blur )
			: $blur;
	}

	/**
	 * @param array<string,mixed> $cover
	 */
	private function cover( array $cover, bool $unlocked ): void {
		// A locked post only ever exposes the blurred placeholder that was stored with it. The real
		// URL is never printed on a public page, whoever is looking.
		$src = $this->cover_src( $cover, $unlocked );

		if ( '' === $src ) {
			echo '<div class="igbz-vip-cover-empty" aria-hidden="true"></div>';
			return;
		}

		// Dimensions are optional: the admin form does not ask for them, and printing width="0"
		// height="0" makes a browser collapse the image to nothing. When they are unknown the
		// attributes are left off and the CSS aspect ratio takes over.
		$width  = (int) ( $cover['width'] ?? 0 );
		$height = (int) ( $cover['height'] ?? 0 );
		$size   = $width > 0 && $height > 0
			? sprintf( ' width="%1$d" height="%2$d"', $width, $height )
			: '';

		printf(
			'<img src="%1$s" alt="%2$s"%3$s />',
			esc_url( $src ),
			esc_attr__( 'Post preview', 'igbz-suite' ),
			$size // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from two ints above.
		);
	}

	/** Deep link that opens this post straight in the mobile app. */
	public function deep_link( string $shortcode ): string {
		return $this->scheme() . '://vip/p/' . rawurlencode( $shortcode );
	}

	private function scheme(): string {
		$scheme = $this->settings->string( 'vip.deep_link_scheme', 'igbz' );

		return strtolower( preg_replace( '/[^a-z0-9+.-]/i', '', $scheme ) ?: 'igbz' );
	}

	/**
	 * esc_url() drops any scheme not on its allow-list, and ours is a custom app scheme by
	 * definition — left to the default, the "Open in the app" button rendered href="", which looks
	 * exactly like a working button and goes nowhere.
	 *
	 * @return string[]
	 */
	public function allowed_schemes(): array {
		return array_values( array_unique( array_merge( wp_allowed_protocols(), [ $this->scheme() ] ) ) );
	}

	/** @param array<string,mixed> $post */
	private function title( array $post ): string {
		$caption = trim( wp_strip_all_tags( (string) ( $post['caption'] ?? '' ) ) );
		if ( '' !== $caption ) {
			$first = preg_split( '/\R/', $caption )[0] ?? $caption;
			return mb_substr( $first, 0, 70 );
		}

		return __( 'A VIP post', 'igbz-suite' );
	}

	private function excerpt( string $caption ): string {
		$clean = trim( wp_strip_all_tags( $caption ) );
		return mb_strlen( $clean ) > 240 ? mb_substr( $clean, 0, 240 ) . '…' : $clean;
	}

	private function expiry_label( string $expires_at ): string {
		$timestamp = strtotime( $expires_at . ' UTC' );
		if ( ! $timestamp || $timestamp <= time() ) {
			return __( 'Expiring now', 'igbz-suite' );
		}

		return sprintf(
			/* translators: %s: human readable time difference, e.g. "2 days" */
			__( 'Available for another %s', 'igbz-suite' ),
			human_time_diff( time(), $timestamp )
		);
	}

	private function money( float $amount, string $currency ): string {
		$currency = '' !== $currency ? $currency : $this->settings->string( 'general.default_currency', 'IRT' );
		$label    = 'IRT' === $currency ? __( 'Toman', 'igbz-suite' ) : $currency;

		return number_format_i18n( $amount ) . ' ' . $label;
	}

	/**
	 * @param array<string,mixed> $post
	 * @param array<string,mixed> $cover
	 */
	private function head( array $post, array $cover ): void {
		$title   = $this->title( $post );
		$excerpt = $this->excerpt( (string) ( $post['caption'] ?? '' ) );
		$image   = (string) ( $cover['blur'] ?? '' );
		$rtl     = is_rtl();

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>"<?php echo $rtl ? ' dir="rtl"' : ''; ?>>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, follow" />
<title><?php echo esc_html( $title . ' — ' . get_bloginfo( 'name' ) ); ?></title>
<meta property="og:type" content="article" />
<meta property="og:title" content="<?php echo esc_attr( $title ); ?>" />
<meta property="og:description" content="<?php echo esc_attr( $excerpt ); ?>" />
<meta property="og:url" content="<?php echo esc_url( $this->url( (string) $post['shortcode'] ) ); ?>" />
<?php if ( '' !== $image ) : ?>
<meta property="og:image" content="<?php echo esc_url( $image ); ?>" />
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image" />
<link rel="stylesheet" href="<?php echo esc_url( IGBZ_URL . 'assets/css/vip-share.css?ver=' . IGBZ_VERSION ); ?>" />
</head>
		<?php
	}

	private function foot(): void {
		echo '</body></html>';
	}

	private function render_missing(): void {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		printf(
			'<!DOCTYPE html><html%1$s><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />'
			. '<meta name="robots" content="noindex" /><title>%2$s</title>'
			. '<link rel="stylesheet" href="%3$s" /></head><body class="igbz-vip-share">'
			. '<div class="igbz-vip-wrap"><div class="igbz-vip-explainer"><h1>%2$s</h1><p>%4$s</p>'
			. '<p><a class="igbz-vip-btn" href="%5$s">%6$s</a></p></div></div></body></html>',
			is_rtl() ? ' dir="rtl"' : '',
			esc_html__( 'This post is not here', 'igbz-suite' ),
			esc_url( IGBZ_URL . 'assets/css/vip-share.css?ver=' . IGBZ_VERSION ),
			esc_html__( 'The link may be mistyped, or the post has been taken down. Members always see the latest posts in the app.', 'igbz-suite' ),
			esc_url( home_url( '/' ) ),
			esc_html__( 'Go to the site', 'igbz-suite' )
		);
		exit;
	}
}
