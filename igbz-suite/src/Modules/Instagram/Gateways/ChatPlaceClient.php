<?php
namespace IGBZ\Suite\Modules\Instagram\Gateways;

use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * ChatPlace client — the selected replacement for ManyChat (dm.provider =
 * chatplace). Flat ~$20/mo at any volume, built-in AI agent, official Meta
 * partner. The API mirrors the operations our funnels need: send a DM,
 * get subscriber info, tags, and flows. ManyChat stays inactive as a
 * fallback the senior admin can switch back to by changing dm.provider and
 * registering the ManyChat key.
 */
final class ChatPlaceClient {

	private string $api_key = '';

	public function __construct(
		private Http $http,
		private Logger $logger
	) {}

	public function for_key( string $api_key ): self {
		$this->api_key = $api_key;
		return $this;
	}

	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	private function base(): string {
		return rtrim( igbz()->settings()->string( 'chatplace.base_url', 'https://api.chatplace.io' ), '/' );
	}

	/** @return array<string,string> */
	private function headers(): array {
		return [ 'Authorization' => 'Bearer ' . $this->api_key, 'Accept' => 'application/json', 'Content-Type' => 'application/json' ];
	}

	/** Send a DM to a subscriber (comment-to-DM core). */
	public function send_dm( string $subscriber_id, string $message, array $buttons = [] ): array {
		$response = $this->http->post(
			$this->base() . '/v1/messages',
			[
				'json'    => [
					'subscriber_id' => $subscriber_id,
					'text'          => $message,
					'buttons'       => $buttons,
				],
				'headers' => $this->headers(),
				'channel' => 'manychat',
				'timeout' => 25,
			]
		);
		$body = $response->json();
		if ( ! $response->ok() ) {
			$this->logger->error( 'chatplace', 'DM send failed', [ 'subscriber' => $subscriber_id, 'error' => $response->error_message() ] );
			return [ 'ok' => false, 'error' => $response->error_message() ];
		}
		return [ 'ok' => true, 'message_id' => (string) ( $body['id'] ?? '' ), 'error' => '' ];
	}

	public function get_info( string $subscriber_id ): array {
		$response = $this->http->get( $this->base() . '/v1/subscribers/' . rawurlencode( $subscriber_id ), [ 'headers' => $this->headers(), 'channel' => 'manychat', 'timeout' => 25 ] );
		return $response->ok() ? $response->json() : [];
	}

	public function add_tag( string $subscriber_id, string $tag_name ): array {
		$response = $this->http->post(
			$this->base() . '/v1/subscribers/' . rawurlencode( $subscriber_id ) . '/tags',
			[ 'json' => [ 'tag' => $tag_name ], 'headers' => $this->headers(), 'channel' => 'manychat', 'timeout' => 25 ]
		);
		return [ 'ok' => $response->ok(), 'error' => $response->ok() ? '' : $response->error_message() ];
	}

	public function flows( bool $force = false ): array {
		$response = $this->http->get( $this->base() . '/v1/flows', [ 'headers' => $this->headers(), 'channel' => 'manychat', 'timeout' => 25 ] );
		return $response->ok() ? (array) $response->json() : [];
	}

	/** Send a flow (e.g. the funnel follow-up). */
	public function send_flow( string $subscriber_id, string $flow_id ): array {
		$response = $this->http->post(
			$this->base() . '/v1/flows/trigger',
			[ 'json' => [ 'subscriber_id' => $subscriber_id, 'flow_id' => $flow_id ], 'headers' => $this->headers(), 'channel' => 'manychat', 'timeout' => 25 ]
		);
		return [ 'ok' => $response->ok(), 'error' => $response->ok() ? '' : $response->error_message() ];
	}

	/** Compatibility with the funnel gateway: text message + optional button. */
	public function send_text( string $subscriber_id, string $text, string $button_label = '', string $button_url = '' ): array {
		$buttons = [];
		if ( '' !== $button_label && '' !== $button_url ) {
			$buttons[] = [ 'label' => $button_label, 'url' => $button_url ];
		}
		return $this->send_dm( $subscriber_id, $text, $buttons );
	}

	public function send_content( string $subscriber_id, array $messages, string $message_tag = '', array $actions = [], array $quick_replies = [] ): array {
		$text = '';
		foreach ( $messages as $m ) {
			$text .= (string) ( $m['text'] ?? '' ) . "\n";
		}
		return $this->send_dm( $subscriber_id, trim( $text ) );
	}

	public function set_custom_field_by_name( string $subscriber_id, string $field_name, mixed $value ): array {
		return $this->add_tag( $subscriber_id, (string) $value );
	}

	public function send_flow( string $subscriber_id, string $flow_ns ): array {
		$response = $this->http->post(
			$this->base() . '/v1/flows/trigger',
			[ 'json' => [ 'subscriber_id' => $subscriber_id, 'flow_id' => $flow_ns ], 'headers' => $this->headers(), 'channel' => 'manychat', 'timeout' => 25 ]
		);
		return [ 'ok' => $response->ok(), 'error' => $response->ok() ? '' : $response->error_message() ];
	}
}
