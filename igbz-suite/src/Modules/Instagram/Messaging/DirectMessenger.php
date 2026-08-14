<?php
namespace IGBZ\Suite\Modules\Instagram\Messaging;

use IGBZ\Suite\Modules\Instagram\Contracts\DirectMessageGatewayInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\DirectMessageResult;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes each direct message to a gateway that can actually send it.
 *
 * The registry mirrors `SpeechToText`, and for the same reason: the capability the business needs
 * is spread across vendors rather than concentrated in one. ManyChat handles text and images and
 * refuses video outright; a configurable endpoint can be pointed at whatever vendor turns out to
 * support video and native post sharing. Asking the caller to know which is which would put vendor
 * trivia into the funnel and the checkout, so it lives here instead.
 *
 * Routing is per capability, not per install. `send_video()` will use the configured provider if
 * it can do video and otherwise look for any registered gateway that can, so an install that keeps
 * ManyChat for its funnels can still deliver paid video through a second gateway without changing
 * anything else.
 *
 * There is deliberately no fallback for a `window_closed` result. When the 24-hour window has
 * closed, no gateway can send, and pretending otherwise by trying the next one would waste calls
 * and mislead the caller. That result is returned untouched for the caller to park and retry when
 * the subscriber next interacts.
 */
final class DirectMessenger {

	/** @var array<string,DirectMessageGatewayInterface> */
	private array $gateways = [];

	public function __construct(
		private Settings $settings,
		private Logger $logger,
		ManyChatGateway $manychat,
		ConfigurableDmGateway $custom
	) {
		$this->gateways[ $manychat->id() ] = $manychat;
		$this->gateways[ $custom->id() ]   = $custom;

		/**
		 * Register another direct-message gateway.
		 *
		 * @param array<int|string,DirectMessageGatewayInterface> $gateways
		 */
		foreach ( (array) apply_filters( 'igbz_dm_gateways', [] ) as $gateway ) {
			if ( $gateway instanceof DirectMessageGatewayInterface ) {
				$this->gateways[ $gateway->id() ] = $gateway;
			}
		}
	}

	/** @return array<string,DirectMessageGatewayInterface> */
	public function gateways(): array {
		return $this->gateways;
	}

	public function gateway( string $id ): ?DirectMessageGatewayInterface {
		return $this->gateways[ $id ] ?? null;
	}

	/**
	 * The gateway this account prefers, whether or not it can do any particular job.
	 *
	 * An account may override the site default, because one tenant may have bought into a vendor
	 * the others have not.
	 *
	 * @param array<string,mixed> $account
	 */
	public function preferred( array $account = [] ): DirectMessageGatewayInterface {
		$id = trim( (string) ( $account['dm_provider'] ?? '' ) );
		if ( '' === $id ) {
			$id = $this->settings->string( 'dm.provider', ManyChatGateway::ID );
		}

		return $this->gateways[ $id ] ?? $this->gateways[ ManyChatGateway::ID ];
	}

	/**
	 * Pick a gateway for one capability.
	 *
	 * Preference order: the account's own gateway if it is configured and capable, then any other
	 * configured gateway that is capable. Configuration is checked before capability because a
	 * gateway that claims video but has no endpoint is not a real option.
	 *
	 * @param array<string,mixed> $account
	 */
	public function gateway_for( string $capability, array $account = [] ): ?DirectMessageGatewayInterface {
		$preferred = $this->preferred( $account );
		if ( $preferred->is_configured( $account ) && $preferred->supports( $capability ) ) {
			return $preferred;
		}

		foreach ( $this->gateways as $gateway ) {
			if ( $gateway->id() === $preferred->id() ) {
				continue;
			}
			if ( $gateway->is_configured( $account ) && $gateway->supports( $capability ) ) {
				return $gateway;
			}
		}

		return null;
	}

	/** Whether anything at all can send this capability for this account. */
	public function can( string $capability, array $account = [] ): bool {
		return null !== $this->gateway_for( $capability, $account );
	}

	/**
	 * Capabilities that are deliverable for this account right now.
	 *
	 * The settings screen and the health check use this to tell the operator what the install can
	 * really do, rather than what it was hoped it could do.
	 *
	 * @param array<string,mixed> $account
	 * @return array<int,string>
	 */
	public function capabilities( array $account = [] ): array {
		$all = [
			DirectMessageGatewayInterface::CAP_TEXT,
			DirectMessageGatewayInterface::CAP_IMAGE,
			DirectMessageGatewayInterface::CAP_VIDEO,
			DirectMessageGatewayInterface::CAP_MEDIA_SHARE,
			DirectMessageGatewayInterface::CAP_FLOW,
		];

		return array_values( array_filter( $all, fn ( string $c ): bool => $this->can( $c, $account ) ) );
	}

	/**
	 * @param array<string,mixed> $account
	 */
	private function no_gateway( string $capability ): DirectMessageResult {
		$this->logger->warning( 'dm', 'No gateway can send this message', [ 'capability' => $capability ] );

		return DirectMessageResult::unsupported(
			sprintf(
				/* translators: %s: capability name, e.g. "video". */
				__( 'No configured messaging gateway can send %s.', 'igbz-suite' ),
				$capability
			)
		);
	}

	/**
	 * @param array<string,mixed> $account
	 */
	public function send_text(
		array $account,
		string $subscriber_id,
		string $text,
		string $button_label = '',
		string $button_url = ''
	): DirectMessageResult {
		$gateway = $this->gateway_for( DirectMessageGatewayInterface::CAP_TEXT, $account );
		if ( null === $gateway ) {
			return $this->no_gateway( DirectMessageGatewayInterface::CAP_TEXT );
		}

		return $gateway->send_text( $account, $subscriber_id, $text, $button_label, $button_url );
	}

	/** @param array<string,mixed> $account */
	public function send_image( array $account, string $subscriber_id, string $url, string $caption = '' ): DirectMessageResult {
		$gateway = $this->gateway_for( DirectMessageGatewayInterface::CAP_IMAGE, $account );
		if ( null === $gateway ) {
			return $this->no_gateway( DirectMessageGatewayInterface::CAP_IMAGE );
		}

		return $gateway->send_image( $account, $subscriber_id, $url, $caption );
	}

	/** @param array<string,mixed> $account */
	public function send_video( array $account, string $subscriber_id, string $url, string $caption = '' ): DirectMessageResult {
		$gateway = $this->gateway_for( DirectMessageGatewayInterface::CAP_VIDEO, $account );
		if ( null === $gateway ) {
			return $this->no_gateway( DirectMessageGatewayInterface::CAP_VIDEO );
		}

		return $gateway->send_video( $account, $subscriber_id, $url, $caption );
	}

	/** @param array<string,mixed> $account */
	public function send_media_share( array $account, string $subscriber_id, string $media_ref ): DirectMessageResult {
		$gateway = $this->gateway_for( DirectMessageGatewayInterface::CAP_MEDIA_SHARE, $account );
		if ( null === $gateway ) {
			return $this->no_gateway( DirectMessageGatewayInterface::CAP_MEDIA_SHARE );
		}

		return $gateway->send_media_share( $account, $subscriber_id, $media_ref );
	}

	/**
	 * @param array<string,mixed>  $account
	 * @param array<string,string> $fields
	 */
	public function send_flow( array $account, string $subscriber_id, string $flow_ref, array $fields = [] ): DirectMessageResult {
		$gateway = $this->gateway_for( DirectMessageGatewayInterface::CAP_FLOW, $account );
		if ( null === $gateway ) {
			return $this->no_gateway( DirectMessageGatewayInterface::CAP_FLOW );
		}

		return $gateway->send_flow( $account, $subscriber_id, $flow_ref, $fields );
	}

	/**
	 * Deliver paid media by the best route available, in descending order of safety.
	 *
	 * This is the method the paid-post pipeline calls, and the order encodes what has been learnt
	 * about the platform rather than a preference:
	 *
	 *   1. The post itself, as a native card. Nothing is hosted by us, there is no URL to forward,
	 *      and the 8 MB / 25 MB attachment ceilings do not apply. This is the closest thing to
	 *      Close Friends that an outside integration can reach.
	 *   2. A vendor-side flow. When the vendor's builder can attach the post but its API cannot be
	 *      told to, the flow is authored there and named here.
	 *   3. The raw file. Last, because a public URL is exactly what paid content should not have —
	 *      it can be forwarded to everyone who did not pay.
	 *
	 * A caller that has only a file passes only a file, and the first two routes are skipped.
	 *
	 * @param array<string,mixed>  $account
	 * @param array<string,string> $fields Custom fields for the flow route.
	 */
	public function deliver_media(
		array $account,
		string $subscriber_id,
		string $media_ref = '',
		string $flow_ref = '',
		string $file_url = '',
		string $caption = '',
		bool $is_video = true,
		array $fields = []
	): DirectMessageResult {
		$attempts = [];

		if ( '' !== trim( $media_ref ) && $this->can( DirectMessageGatewayInterface::CAP_MEDIA_SHARE, $account ) ) {
			$result = $this->send_media_share( $account, $subscriber_id, $media_ref );
			if ( $result->ok || ! $result->needs_another_gateway() ) {
				return $result;
			}
			$attempts[] = $result->error;
		}

		if ( '' !== trim( $flow_ref ) && $this->can( DirectMessageGatewayInterface::CAP_FLOW, $account ) ) {
			$result = $this->send_flow( $account, $subscriber_id, $flow_ref, $fields );
			if ( $result->ok || ! $result->needs_another_gateway() ) {
				return $result;
			}
			$attempts[] = $result->error;
		}

		if ( '' !== trim( $file_url ) ) {
			$result = $is_video
				? $this->send_video( $account, $subscriber_id, $file_url, $caption )
				: $this->send_image( $account, $subscriber_id, $file_url, $caption );
			if ( $result->ok || ! $result->needs_another_gateway() ) {
				return $result;
			}
			$attempts[] = $result->error;
		}

		$this->logger->error(
			'dm',
			'Paid media could not be delivered by any route',
			[ 'subscriber' => $subscriber_id, 'attempts' => $attempts ]
		);

		return DirectMessageResult::unsupported(
			__( 'The purchased content could not be delivered: no configured gateway can send it.', 'igbz-suite' )
		);
	}
}
