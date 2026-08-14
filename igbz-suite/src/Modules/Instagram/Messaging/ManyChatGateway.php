<?php
namespace IGBZ\Suite\Modules\Instagram\Messaging;

use IGBZ\Suite\Modules\Instagram\Contracts\DirectMessageGatewayInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\DirectMessageResult;
use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * ManyChat as a direct-message gateway.
 *
 * This is the vendor the plugin already speaks to, and for text and images it is entirely
 * adequate. What it cannot do is video: ManyChat's own response reference states plainly that
 * "sending video files is not supported by WhatsApp and Instagram channels", and the same applies
 * to audio and file attachments — on the Instagram channel only `text`, `image` and `cards` exist.
 * There is no flag to turn that on and no plan tier that changes it.
 *
 * So `send_video()` here does not attempt a request. It returns `unsupported`, which the delivery
 * service reads as "ask another gateway" rather than "retry me later". Being honest about the
 * limit in code is what keeps the paid-post pipeline from silently dropping a purchase.
 *
 * `send_media_share()` is the same story. ManyChat has no way to attach one of the account's own
 * published posts, so native post delivery has to come from elsewhere. `send_flow()` is the
 * partial way out: an automation authored in ManyChat's builder can do things its API cannot be
 * told to do directly, so where a ManyChat-only install needs richer delivery, the flow is built
 * there and named here.
 *
 * ## Channel note
 *
 * ManyChatClient's base URL is the `/fb/` path. That is not a Messenger-only endpoint — it is the
 * historical prefix for the whole public API, and the Instagram channel is selected by the content
 * envelope rather than the path, which is why `content.type` is set to `instagram` on every send.
 */
final class ManyChatGateway implements DirectMessageGatewayInterface {

	public const ID = 'manychat';

	/**
	 * Errors ManyChat reports when the subscriber cannot be messaged right now. 3021 is the
	 * "message tag required" case, which in practice means the 24-hour window has closed and the
	 * send would need a tag we are not entitled to use.
	 */
	private const WINDOW_ERRORS = [ ManyChatClient::ERROR_TAG_REQUIRED ];

	public function __construct(
		private ManyChatClient $client,
		private AccountCredentials $credentials,
		private Logger $logger
	) {}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'ManyChat', 'igbz-suite' );
	}

	public function is_configured( array $account = [] ): bool {
		if ( ! $account ) {
			return false;
		}
		return $this->credentials->has_key( $account, AccountCredentials::SERVICE_MANYCHAT );
	}

	public function supports( string $capability ): bool {
		return in_array( $capability, [ self::CAP_TEXT, self::CAP_IMAGE, self::CAP_FLOW ], true );
	}

	/** A client bound to this account's page key. */
	private function client( array $account ): ManyChatClient {
		return $this->client->for_key( $this->credentials->key( $account, AccountCredentials::SERVICE_MANYCHAT ) );
	}

	/**
	 * Turn the client's array response into a result, mapping the error codes that mean something
	 * other than "it broke".
	 *
	 * @param array{ok:bool,data:array<string,mixed>,error:string,code:int} $response
	 */
	private function interpret( array $response ): DirectMessageResult {
		if ( $response['ok'] ) {
			$id = (string) ( $response['data']['message_id'] ?? $response['data']['id'] ?? '' );
			return DirectMessageResult::sent( $id, self::ID );
		}

		if ( in_array( $response['code'], self::WINDOW_ERRORS, true ) ) {
			return DirectMessageResult::window_closed( $response['error'], self::ID );
		}

		if ( ManyChatClient::ERROR_INVALID_CONTENT === $response['code'] ) {
			// ManyChat rejected the envelope itself. Retrying an identical body will not help.
			return DirectMessageResult::unsupported( $response['error'], self::ID );
		}

		return DirectMessageResult::failure( $response['error'], self::ID, $response['code'] );
	}

	private function not_configured(): DirectMessageResult {
		return DirectMessageResult::failure(
			__( 'This Instagram account has no ManyChat API key.', 'igbz-suite' ),
			self::ID
		);
	}

	public function send_text(
		array $account,
		string $subscriber_id,
		string $text,
		string $button_label = '',
		string $button_url = ''
	): DirectMessageResult {
		if ( ! $this->is_configured( $account ) ) {
			return $this->not_configured();
		}

		return $this->interpret(
			$this->client( $account )->send_text( $subscriber_id, $text, $button_label, $button_url )
		);
	}

	public function send_image( array $account, string $subscriber_id, string $url, string $caption = '' ): DirectMessageResult {
		if ( ! $this->is_configured( $account ) ) {
			return $this->not_configured();
		}
		if ( '' === trim( $url ) ) {
			return DirectMessageResult::failure( __( 'No image URL was given.', 'igbz-suite' ), self::ID );
		}

		$messages = [];
		if ( '' !== trim( $caption ) ) {
			$messages[] = [ 'type' => 'text', 'text' => mb_substr( $caption, 0, 1000 ) ];
		}
		$messages[] = [ 'type' => 'image', 'url' => $url ];

		return $this->interpret( $this->client( $account )->send_content( $subscriber_id, $messages ) );
	}

	/**
	 * Always unsupported.
	 *
	 * Not a stub and not a TODO — ManyChat's Instagram channel has no video message type. The call
	 * is refused locally so no request is wasted and no retry is scheduled.
	 */
	public function send_video( array $account, string $subscriber_id, string $url, string $caption = '' ): DirectMessageResult {
		unset( $account, $subscriber_id, $url, $caption );

		return DirectMessageResult::unsupported(
			__( 'ManyChat cannot send video to Instagram direct messages. Configure a gateway that supports video, or deliver the post itself instead of the file.', 'igbz-suite' ),
			self::ID
		);
	}

	/**
	 * Always unsupported.
	 *
	 * ManyChat offers no way to attach one of the account's own published posts to a message.
	 */
	public function send_media_share( array $account, string $subscriber_id, string $media_ref ): DirectMessageResult {
		unset( $account, $subscriber_id, $media_ref );

		return DirectMessageResult::unsupported(
			__( 'ManyChat cannot attach a published Instagram post to a direct message.', 'igbz-suite' ),
			self::ID
		);
	}

	/**
	 * Run a ManyChat automation, setting custom fields first so the flow can interpolate them.
	 *
	 * Field writes are best-effort: a flow whose optional field failed to set is still better than
	 * no delivery, so a failed write is logged and the flow runs anyway.
	 */
	public function send_flow( array $account, string $subscriber_id, string $flow_ref, array $fields = [] ): DirectMessageResult {
		if ( ! $this->is_configured( $account ) ) {
			return $this->not_configured();
		}
		if ( '' === trim( $flow_ref ) ) {
			return DirectMessageResult::failure( __( 'No ManyChat flow was named.', 'igbz-suite' ), self::ID );
		}

		$client = $this->client( $account );

		foreach ( $fields as $name => $value ) {
			$written = $client->set_custom_field_by_name( $subscriber_id, (string) $name, (string) $value );
			if ( ! $written['ok'] ) {
				$this->logger->warning(
					'manychat',
					'Could not set a custom field before running a flow',
					[ 'field' => (string) $name, 'error' => $written['error'] ]
				);
			}
		}

		return $this->interpret( $client->send_flow( $subscriber_id, $flow_ref ) );
	}
}
