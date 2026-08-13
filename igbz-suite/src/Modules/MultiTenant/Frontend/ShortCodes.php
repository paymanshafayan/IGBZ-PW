<?php
namespace IGBZ\Suite\Modules\MultiTenant\Frontend;

use IGBZ\Suite\Modules\MultiTenant\Bnpl\BnplService;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Modules\MultiTenant\Otp\OtpService;
use IGBZ\Suite\Modules\MultiTenant\Plans\PlanService;

defined( 'ABSPATH' ) || exit;

/**
 * Storefront shortcodes: course catalogue and player, plan pricing table, BNPL calculator,
 * wallet balance badge and the phone/OTP login form.
 *
 * Every shortcode is self-contained so a tenant can drop it into any page builder.
 */
final class ShortCodes {

	public function register(): void {
		add_shortcode( 'igbz_courses', [ $this, 'courses' ] );
		add_shortcode( 'igbz_course', [ $this, 'course' ] );
		add_shortcode( 'igbz_plans', [ $this, 'plans' ] );
		add_shortcode( 'igbz_bnpl_calculator', [ $this, 'bnpl_calculator' ] );
		add_shortcode( 'igbz_wallet_balance', [ $this, 'wallet_balance' ] );
		add_shortcode( 'igbz_otp_login', [ $this, 'otp_login' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'wp_ajax_nopriv_igbz_otp', [ $this, 'ajax_otp' ] );
		add_action( 'wp_ajax_igbz_otp', [ $this, 'ajax_otp' ] );
		add_action( 'template_redirect', [ $this, 'maybe_stream_video' ] );
	}

	public function register_assets(): void {
		wp_register_style( 'igbz-front', IGBZ_URL . 'assets/css/front.css', [], IGBZ_VERSION );
		wp_register_script( 'igbz-front', IGBZ_URL . 'assets/js/front.js', [], IGBZ_VERSION, true );
		wp_localize_script(
			'igbz-front',
			'igbzFront',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'igbz_front' ),
				'i18n'    => [
					'sending'   => __( 'Sending…', 'igbz-suite' ),
					'sendCode'  => __( 'Send code', 'igbz-suite' ),
					'verifying' => __( 'Verifying…', 'igbz-suite' ),
					'copied'    => __( 'Copied!', 'igbz-suite' ),
				],
			]
		);
	}

	private function assets(): void {
		wp_enqueue_style( 'igbz-front' );
		wp_enqueue_script( 'igbz-front' );
	}

	// ------------------------------------------------------------- catalogue

	/** @param array<string,string>|string $atts */
	public function courses( $atts = [] ): string {
		$atts = shortcode_atts(
			[ 'limit' => 12, 'level' => '', 'columns' => 3 ],
			(array) $atts,
			'igbz_courses'
		);
		$this->assets();

		/** @var LmsService $lms */
		$lms     = igbz()->get( 'lms' );
		$courses = $lms->courses(
			[
				'tenant_id' => igbz()->tenancy()->id(),
				'published' => true,
				'limit'     => (int) $atts['limit'],
			]
		);

		if ( '' !== $atts['level'] ) {
			$courses = array_values( array_filter( $courses, static fn ( $c ) => $c['level'] === $atts['level'] ) );
		}

		if ( ! $courses ) {
			return '<p class="igbz-empty">' . esc_html__( 'No courses published yet.', 'igbz-suite' ) . '</p>';
		}

		ob_start();
		printf( '<div class="igbz-course-grid igbz-cols-%d">', (int) $atts['columns'] );
		foreach ( $courses as $course ) {
			echo '<article class="igbz-course-card">';
			if ( ! empty( $course['cover_url'] ) ) {
				printf( '<img src="%1$s" alt="%2$s" loading="lazy" />', esc_url( (string) $course['cover_url'] ), esc_attr( (string) $course['title'] ) );
			}
			printf( '<h3>%s</h3>', esc_html( (string) $course['title'] ) );
			if ( ! empty( $course['summary'] ) ) {
				printf( '<p>%s</p>', esc_html( wp_trim_words( (string) $course['summary'], 24 ) ) );
			}
			echo '<footer>';
			if ( (int) $course['duration_minutes'] > 0 ) {
				printf(
					'<span>%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: minutes */
							__( '%d min', 'igbz-suite' ),
							(int) $course['duration_minutes']
						)
					)
				);
			}
			$product_id = (int) $course['product_id'];
			$link       = $product_id > 0 ? get_permalink( $product_id ) : $this->course_url( (string) $course['slug'] );
			printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( (string) $link ), esc_html__( 'View course', 'igbz-suite' ) );
			echo '</footer></article>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	/** Course player. Reads ?igbz_course=<slug> when no slug attribute is given. */
	public function course( $atts = [] ): string {
		$atts = shortcode_atts( [ 'slug' => '' ], (array) $atts, 'igbz_course' );
		$this->assets();

		$slug = '' !== $atts['slug'] ? sanitize_title( $atts['slug'] ) : '';
		if ( '' === $slug && isset( $_GET['igbz_course'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_title( wp_unslash( $_GET['igbz_course'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $slug ) {
			return '';
		}

		/** @var LmsService $lms */
		$lms    = igbz()->get( 'lms' );
		$course = $lms->course_by_slug( $slug, igbz()->tenancy()->id() );

		if ( ! $course || ! $course['is_published'] ) {
			return '<p class="igbz-empty">' . esc_html__( 'Course not found.', 'igbz-suite' ) . '</p>';
		}

		$user_id  = get_current_user_id();
		$enrolled = $user_id > 0 && $lms->is_enrolled( (int) $course['id'], $user_id );
		$lessons  = $lms->lessons( (int) $course['id'] );

		ob_start();
		echo '<div class="igbz-course-player">';
		printf( '<h2>%s</h2>', esc_html( (string) $course['title'] ) );
		if ( ! empty( $course['description'] ) ) {
			echo '<div class="igbz-course-description">' . wp_kses_post( wpautop( (string) $course['description'] ) ) . '</div>';
		}

		if ( ! $enrolled ) {
			$product_id = (int) $course['product_id'];
			printf(
				'<p class="igbz-locked">%1$s %2$s</p>',
				esc_html__( 'You need access to view the lessons.', 'igbz-suite' ),
				$product_id > 0
					? sprintf(
						'<a class="button" href="%1$s">%2$s</a>',
						esc_url( (string) get_permalink( $product_id ) ),
						esc_html__( 'Enrol now', 'igbz-suite' )
					)
					: ''
			);
		}

		echo '<ol class="igbz-lesson-list">';
		foreach ( $lessons as $lesson ) {
			$open = $enrolled || (int) $lesson['is_free_preview'] === 1;
			printf( '<li class="%s">', esc_attr( $open ? 'igbz-open' : 'igbz-locked' ) );
			printf( '<strong>%s</strong>', esc_html( (string) $lesson['title'] ) );
			if ( (int) $lesson['duration_minutes'] > 0 ) {
				printf(
					' <small>%s</small>',
					esc_html(
						sprintf(
							/* translators: %d: minutes */
							__( '%d min', 'igbz-suite' ),
							(int) $lesson['duration_minutes']
						)
					)
				);
			}

			if ( $open && ! empty( $lesson['video_key'] ) ) {
				printf(
					'<video controls preload="none" src="%s"></video>',
					esc_url( $lms->signed_video_url( (string) $lesson['video_key'], $user_id ) )
				);
			}
			if ( $open && ! empty( $lesson['content'] ) ) {
				echo '<div class="igbz-lesson-body">' . wp_kses_post( wpautop( (string) $lesson['content'] ) ) . '</div>';
			}
			if ( $open && ! empty( $lesson['attachment_url'] ) ) {
				printf(
					'<a class="igbz-attachment" href="%1$s" download>%2$s</a>',
					esc_url( (string) $lesson['attachment_url'] ),
					esc_html__( 'Download attachment', 'igbz-suite' )
				);
			}
			echo '</li>';
		}
		echo '</ol></div>';

		return (string) ob_get_clean();
	}

	private function course_url( string $slug ): string {
		$page_id = (int) igbz()->settings()->get( 'lms.course_page_id', 0 );
		$base    = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' );
		return add_query_arg( 'igbz_course', $slug, $base ?: home_url( '/' ) );
	}

	/**
	 * Signed video responder: /?igbz_video=<key>&u=&e=&s=
	 *
	 * The key is resolved to a real URL through a filter so a tenant can point it at S3, ArvanCloud
	 * or a local uploads path without changing the plugin.
	 */
	public function maybe_stream_video(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- HMAC-signed URL.
		if ( empty( $_GET['igbz_video'] ) ) {
			return;
		}
		$key       = sanitize_text_field( wp_unslash( $_GET['igbz_video'] ) );
		$user_id   = isset( $_GET['u'] ) ? absint( wp_unslash( $_GET['u'] ) ) : 0;
		$expires   = isset( $_GET['e'] ) ? absint( wp_unslash( $_GET['e'] ) ) : 0;
		$signature = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/** @var LmsService $lms */
		$lms = igbz()->get( 'lms' );

		if ( $user_id !== get_current_user_id() || ! $lms->verify_video_signature( $key, $user_id, $expires, $signature ) ) {
			status_header( 403 );
			nocache_headers();
			exit;
		}

		$url = (string) apply_filters( 'igbz_lms_video_source', '', $key, $user_id );
		if ( '' === $url ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		nocache_headers();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- storage host is set by the site owner.
		exit;
	}

	// ----------------------------------------------------------------- plans

	/** @param array<string,string>|string $atts */
	public function plans( $atts = [] ): string {
		$atts = shortcode_atts( [ 'highlight' => '' ], (array) $atts, 'igbz_plans' );
		$this->assets();

		/** @var PlanService $service */
		$service = igbz()->get( 'plans' );
		$plans   = $service->plans( true );

		if ( ! $plans ) {
			return '<p class="igbz-empty">' . esc_html__( 'No plans are available.', 'igbz-suite' ) . '</p>';
		}

		ob_start();
		echo '<div class="igbz-plan-grid">';
		foreach ( $plans as $plan ) {
			$features = json_decode( (string) ( $plan['features'] ?? '[]' ), true );
			$features = is_array( $features ) ? $features : [];

			printf(
				'<article class="igbz-plan-card %s">',
				esc_attr( $atts['highlight'] === $plan['slug'] ? 'igbz-featured' : '' )
			);
			printf( '<h3>%s</h3>', esc_html( (string) $plan['name'] ) );
			printf(
				'<div class="igbz-plan-price">%1$s<small>/%2$s</small></div>',
				wp_kses_post( wc_price( (float) $plan['price'] ) ),
				esc_html( $this->interval_label( (string) $plan['billing_interval'] ) )
			);
			if ( (int) $plan['trial_days'] > 0 ) {
				printf(
					'<p class="igbz-plan-trial">%s</p>',
					esc_html(
						sprintf(
							/* translators: %d: days */
							__( '%d-day free trial', 'igbz-suite' ),
							(int) $plan['trial_days']
						)
					)
				);
			}
			echo '<ul>';
			foreach ( $features as $label => $value ) {
				printf(
					'<li>%1$s%2$s</li>',
					esc_html( is_string( $label ) ? $this->feature_label( $label ) : '' ),
					esc_html( is_scalar( $value ) ? ': ' . $value : '' )
				);
			}
			echo '</ul>';
			printf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( add_query_arg( 'igbz_plan', (string) $plan['slug'], wc_get_page_permalink( 'myaccount' ) ) ),
				esc_html__( 'Choose plan', 'igbz-suite' )
			);
			echo '</article>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	private function interval_label( string $interval ): string {
		return match ( $interval ) {
			'day'   => __( 'day', 'igbz-suite' ),
			'week'  => __( 'week', 'igbz-suite' ),
			'year'  => __( 'year', 'igbz-suite' ),
			default => __( 'month', 'igbz-suite' ),
		};
	}

	private function feature_label( string $key ): string {
		return ucwords( str_replace( [ '_', '.' ], ' ', $key ) );
	}

	// ------------------------------------------------------------------ bnpl

	/** @param array<string,string>|string $atts */
	public function bnpl_calculator( $atts = [] ): string {
		$atts = shortcode_atts( [ 'amount' => 0, 'counts' => '2,3,4,6,12' ], (array) $atts, 'igbz_bnpl_calculator' );
		$this->assets();

		$amount = (float) $atts['amount'];
		if ( $amount <= 0 && function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_the_ID() );
			$amount  = $product ? (float) $product->get_price() : 0;
		}
		if ( $amount <= 0 ) {
			return '';
		}

		/** @var BnplService $bnpl */
		$bnpl   = igbz()->get( 'bnpl' );
		$counts = array_filter( array_map( 'intval', explode( ',', (string) $atts['counts'] ) ) );

		ob_start();
		echo '<div class="igbz-bnpl-calculator">';
		printf( '<h4>%s</h4>', esc_html__( 'Pay in instalments', 'igbz-suite' ) );
		echo '<table><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Instalments', 'igbz-suite' ) );
		printf( '<th>%s</th>', esc_html__( 'Today', 'igbz-suite' ) );
		printf( '<th>%s</th>', esc_html__( 'Then', 'igbz-suite' ) );
		printf( '<th>%s</th>', esc_html__( 'Total', 'igbz-suite' ) );
		echo '</tr></thead><tbody>';

		foreach ( $counts as $count ) {
			$quote = $bnpl->quote( $amount, $count );
			$rest  = $quote['installments'];
			array_shift( $rest );
			$next = $rest ? (float) $rest[0]['amount'] : 0.0;

			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				$count,
				wp_kses_post( wc_price( (float) $quote['down_payment'] ) ),
				$next > 0
					? wp_kses_post(
						sprintf(
							/* translators: 1: amount, 2: count */
							esc_html__( '%1$s × %2$d', 'igbz-suite' ),
							wp_strip_all_tags( wc_price( $next ) ),
							count( $rest )
						)
					)
					: '—',
				wp_kses_post( wc_price( (float) $quote['total'] ) )
			);
		}

		echo '</tbody></table>';
		printf( '<small>%s</small>', esc_html__( 'Subject to credit approval at checkout.', 'igbz-suite' ) );
		echo '</div>';

		return (string) ob_get_clean();
	}

	// ---------------------------------------------------------------- wallet

	public function wallet_balance(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$balance = igbz()->get( 'wallet' )->balance( get_current_user_id(), igbz()->tenancy()->id() );

		return sprintf(
			'<a class="igbz-wallet-badge" href="%1$s">%2$s <strong>%3$s</strong></a>',
			esc_url( (string) wc_get_account_endpoint_url( AccountEndpoints::EP_WALLET ) ),
			esc_html__( 'Wallet', 'igbz-suite' ),
			wp_kses_post( wc_price( $balance ) )
		);
	}

	// ------------------------------------------------------------- otp login

	public function otp_login(): string {
		if ( is_user_logged_in() ) {
			return '';
		}
		$this->assets();

		ob_start();
		echo '<form class="igbz-otp-form" method="post">';
		printf( '<h3>%s</h3>', esc_html__( 'Sign in with your phone', 'igbz-suite' ) );
		printf(
			'<p><label for="igbz_otp_phone">%1$s</label><input type="tel" id="igbz_otp_phone" name="phone" inputmode="numeric" autocomplete="tel" required placeholder="09121234567" /></p>'
			. '<p class="igbz-otp-step-2" hidden><label for="igbz_otp_code">%2$s</label><input type="text" id="igbz_otp_code" name="code" inputmode="numeric" autocomplete="one-time-code" /></p>'
			. '<p><button type="button" class="button igbz-otp-send">%3$s</button> '
			. '<button type="button" class="button igbz-otp-verify" hidden>%4$s</button></p>'
			. '<p class="igbz-otp-message" role="status"></p>',
			esc_html__( 'Mobile number', 'igbz-suite' ),
			esc_html__( 'Verification code', 'igbz-suite' ),
			esc_html__( 'Send code', 'igbz-suite' ),
			esc_html__( 'Sign in', 'igbz-suite' )
		);
		echo '</form>';

		return (string) ob_get_clean();
	}

	/** admin-ajax handler for both OTP steps. */
	public function ajax_otp(): void {
		check_ajax_referer( 'igbz_front', 'nonce' );

		$step  = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		/** @var OtpService $otp */
		$otp = igbz()->get( 'otp' );

		if ( 'send' === $step ) {
			$result = $otp->send( $phone, OtpService::PURPOSE_LOGIN, igbz()->tenancy()->id() );
			if ( ! $result['ok'] ) {
				wp_send_json_error( [ 'message' => $result['error'], 'retryAfter' => $result['retry_after'] ] );
			}
			wp_send_json_success(
				[
					'message'   => __( 'We sent you a code.', 'igbz-suite' ),
					'expiresIn' => $result['expires_in'],
				]
			);
		}

		if ( 'verify' === $step ) {
			$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
			$result = $otp->verify( $phone, $code, OtpService::PURPOSE_LOGIN );

			if ( ! $result['ok'] ) {
				wp_send_json_error( [ 'message' => $result['error'] ] );
			}
			if ( (int) $result['user_id'] <= 0 ) {
				wp_send_json_error( [ 'message' => __( 'The account could not be created.', 'igbz-suite' ) ] );
			}

			$otp->login( (int) $result['user_id'] );
			wp_send_json_success( [ 'redirect' => wc_get_page_permalink( 'myaccount' ) ] );
		}

		wp_send_json_error( [ 'message' => __( 'Unknown step.', 'igbz-suite' ) ] );
	}
}
