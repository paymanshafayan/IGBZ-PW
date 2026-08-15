<?php
namespace IGBZ\Suite\Modules\Instagram\Admin;

use IGBZ\Suite\Modules\Instagram\Services\FunnelService;
use IGBZ\Suite\Support\Admin\View;

defined( 'ABSPATH' ) || exit;

/**
 * How one funnel hit's delivery state is shown to an operator.
 *
 * The funnel screen and the subscriber screen both list hits, and both used to render this column
 * with their own copy of a two-branch expression: delivered, or a red pill showing whatever string
 * happened to be in delivery_error. That was wrong in the same two ways in both places — a hit
 * waiting for its follow-up looked like a failure, and a machine-readable marker like
 * `per_user_limit` was printed at the operator verbatim — so the logic lives here once.
 */
final class HitStatus {

	/**
	 * A pill plus a short phrase describing where this hit stands.
	 *
	 * @param array<string,mixed> $hit Row from ig_funnel_hits.
	 */
	public static function cell( array $hit ): string {
		$error = (string) ( $hit['delivery_error'] ?? '' );

		if ( 1 === (int) ( $hit['delivered'] ?? 0 ) ) {
			// Delivered, but nothing proved it: the reply was rendered by ManyChat from our
			// webhook response and no API call confirmed it arrived.
			if ( FunnelService::DELIVERY_UNCONFIRMED === $error ) {
				return View::status_pill( 'warn' ) . ' ' . esc_html__( 'sent, unconfirmed', 'igbz-suite' );
			}

			return View::status_pill( 'ok' ) . ' ' . esc_html__( 'delivered', 'igbz-suite' );
		}

		// Not a fault: the funnel's per-person allowance did its job.
		if ( FunnelService::DELIVERY_BLOCKED === $error ) {
			return View::status_pill( 'warn' ) . ' ' . esc_html__( 'blocked: per-user limit', 'igbz-suite' );
		}

		// In flight. Normal for the few seconds between the webhook answering and the follow-up
		// running, so it is not painted as an error.
		if ( '' === $error || in_array( $error, FunnelService::PENDING_STATES, true ) ) {
			return View::status_pill( 'warn' ) . ' ' . esc_html__( 'waiting to send', 'igbz-suite' );
		}

		return View::status_pill( 'error' ) . ' ' . esc_html( self::label( $error ) );
	}

	/**
	 * Readable text for the errors we write ourselves. Anything else is ManyChat's own message
	 * and is shown as-is, because that is what the operator needs to search for.
	 */
	public static function label( string $error ): string {
		return match ( $error ) {
			'missing_subscriber_id' => __( 'no subscriber id in the request', 'igbz-suite' ),
			'manychat_key_missing'  => __( 'no ManyChat API key on this account', 'igbz-suite' ),
			default                 => $error,
		};
	}
}
