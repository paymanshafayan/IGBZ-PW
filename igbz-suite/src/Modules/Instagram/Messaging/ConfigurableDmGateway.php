<?php
namespace IGBZ\Suite\Modules\Instagram\Messaging;

use IGBZ\Suite\Modules\Instagram\Contracts\DirectMessageGatewayInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\DirectMessageResult;
use IGBZ\Suite\Support\Http;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A direct-message gateway whose entire wire format lives in settings.
 *
 * The reason this class exists is specific. ChatPlace's automation builder advertises exactly the
 * two things the paid-post feature needs — a message block that carries video, and an action that
 * sends a post straight from the Instagram feed — but the vendor publishes no REST reference. Only
 * an MCP endpoint is documented. Writing a `ChatPlaceGateway` against a guessed URL and a guessed
 * body shape would produce code that compiles, ships, and fails on the customer's server, and the
 * failure would land on the revenue path.
 *
 * So nothing is guessed. The endpoint, the auth header, the HTTP method and the JSON body are all
 * configuration. When the real contract is known — from the vendor's support team, from a captured
 * webhook, from any vendor at all — it is entered in the settings screen and delivery starts
 * working without a release. If a vendor later turns out to deserve a first-class class of its
 * own, this one has already proved the shape of the contract.
 *
 * The same mechanism covers a self-hosted relay and any automation platform that can expose an
 * inbound webhook, which is the realistic fallback if no vendor's API is reachable directly.
 *
 * ## Templating
 *
 * The body is a JSON template with `{{placeholder}}` tokens. Substitution is type-aware: a token
 * that is the entire string value is replaced by the real typed value, so `"{{subscriber_id}}"`
 * can yield a JSON string while `"{{limit}}"` can yield a number. Tokens embedded in a larger
 * string interpolate as text. Values are never concatenated into raw JSON — the template is
 * decoded first and walked as a structure, so a caption containing a quote cannot break the body.
 *
 * Available tokens: subscriber_id, text, url, caption, media_ref, flow_ref, capability,
 * account_id, ig_user_id, ig_username, button_label, button_url, plus one per custom field as
 * field.<name>.
 */
final class ConfigurableDmGateway implements DirectMessageGatewayInterface {

	public const ID = 'custom';

	/** Capability names accepted in the `dm.custom.capabilities` setting. */
	private const KNOWN_CAPABILITIES = [
		self::CAP_TEXT,
		self::CAP_IMAGE,
		self::CAP_VIDEO,
		self::CAP_MEDIA_SHARE,
		self::CAP_FLOW,
	];

	/**
	 * Default body template. Deliberately close to the shape most vendors use, but it is only a
	 * starting point shown in the settings screen — it is expected to be replaced.
	 */
	public const DEFAULT_TEMPLATE = '{"recipient":{"id":"{{subscriber_id}}"},"type":"{{capability}}","text":"{{text}}","url":"{{url}}","media_ref":"{{media_ref}}","flow":"{{flow_ref}}"}';

	public function __construct(
		private Http $http,
		private Settings $settings,
		private Logger $logger
	) {}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		$label = trim( $this->settings->string( 'dm.custom.title', '' ) );
		return '' !== $label ? $label : __( 'Custom messaging endpoint', 'igbz-suite' );
	}

	private function endpoint(): string {
		return trim( $this->settings->string( 'dm.custom.endpoint', '' ) );
	}

	public function is_configured( array $account = [] ): bool {
		unset( $account );
		return '' !== $this->endpoint();
	}

	/**
	 * Capabilities are declared by the operator, because only they know what the endpoint they
	 * pointed us at can do. Nothing is assumed beyond text, which every messaging API supports.
	 */
	public function supports( string $capability ): bool {
		$configured = trim( $this->settings->string( 'dm.custom.capabilities', self::CAP_TEXT ) );
		if ( '' === $configured ) {
			return self::CAP_TEXT === $capability;
		}

		$allowed = array_filter( array_map( 'trim', explode( ',', strtolower( $configured ) ) ) );
		return in_array( $capability, $allowed, true ) && in_array( $capability, self::KNOWN_CAPABILITIES, true );
	}

	/** @return array<string,string> */
	private function headers(): array {
		$headers = [
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		];

		$key = $this->settings->string( 'dm.custom.api_key', '' );
		if ( '' !== $key ) {
			$header = trim( $this->settings->string( 'dm.custom.auth_header', 'Authorization' ) );
			$scheme = trim( $this->settings->string( 'dm.custom.auth_scheme', 'Bearer' ) );
			if ( '' === $header ) {
				$header = 'Authorization';
			}
			$headers[ $header ] = '' !== $scheme ? $scheme . ' ' . $key : $key;
		}

		/**
		 * Extra headers for endpoints with their own requirements.
		 *
		 * @param array<string,string> $headers
		 */
		return (array) apply_filters( 'igbz_dm_custom_headers', $headers );
	}

	/**
	 * Replace `{{token}}` placeholders throughout a decoded JSON structure.
	 *
	 * Walking the decoded structure rather than the raw string is what makes this injection-safe:
	 * a value is placed into the tree as a PHP value and re-encoded by wp_json_encode, so quotes,
	 * newlines and non-ASCII in a caption are escaped by the encoder rather than by us.
	 *
	 * @param mixed                $node
	 * @param array<string,scalar> $tokens
	 * @return mixed
	 */
	private function interpolate( mixed $node, array $tokens ): mixed {
		if ( is_array( $node ) ) {
			$out = [];
			foreach ( $node as $key => $value ) {
				$out[ is_string( $key ) ? $this->interpolate_string( $key, $tokens ) : $key ] = $this->interpolate( $value, $tokens );
			}
			return $out;
		}

		if ( is_string( $node ) ) {
			// A lone token keeps the value's own type, so numbers and booleans survive.
			if ( preg_match( '/^\{\{\s*([a-z0-9_.]+)\s*\}\}$/i', $node, $matches ) ) {
				return $tokens[ strtolower( $matches[1] ) ] ?? '';
			}
			return $this->interpolate_string( $node, $tokens );
		}

		return $node;
	}

	/** @param array<string,scalar> $tokens */
	private function interpolate_string( string $subject, array $tokens ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
			static fn ( array $m ): string => (string) ( $tokens[ strtolower( $m[1] ) ] ?? '' ),
			$subject
		);
	}

	/**
	 * Strip keys whose value came out empty.
	 *
	 * A template has to name every token it might ever need, but sending `"url":""` to an endpoint
	 * that validates its input is a rejection. Empty leaves are dropped so one template can serve
	 * every capability.
	 *
	 * @param array<string|int,mixed> $body
	 * @return array<string|int,mixed>
	 */
	private function prune( array $body ): array {
		$out = [];
		foreach ( $body as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = $this->prune( $value );
				if ( [] === $value ) {
					continue;
				}
			} elseif ( '' === $value || null === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Build the request body for one send.
	 *
	 * @param array<string,scalar> $tokens
	 * @return array<string|int,mixed>|null Null when the template is not valid JSON.
	 */
	private function body( array $tokens ): ?array {
		$template = trim( $this->settings->string( 'dm.custom.body_template', '' ) );
		if ( '' === $template ) {
			$template = self::DEFAULT_TEMPLATE;
		}

		$decoded = json_decode( $template, true );
		if ( ! is_array( $decoded ) ) {
			$this->logger->error( 'dm', 'The custom gateway body template is not valid JSON', [] );
			return null;
		}

		$body = $this->interpolate( $decoded, $tokens );
		if ( ! is_array( $body ) ) {
			return null;
		}

		return $this->prune( $body );
	}

	/**
	 * Perform one send.
	 *
	 * @param array<string,mixed>  $account
	 * @param array<string,scalar> $extra
	 */
	private function dispatch( array $account, string $capability, string $subscriber_id, array $extra ): DirectMessageResult {
		if ( ! $this->is_configured() ) {
			return DirectMessageResult::failure( __( 'No custom messaging endpoint is configured.', 'igbz-suite' ), self::ID );
		}
		if ( ! $this->supports( $capability ) ) {
			return DirectMessageResult::unsupported(
				sprintf(
					/* translators: %s: capability name, e.g. "video". */
					__( 'The custom messaging endpoint is not configured to send %s.', 'igbz-suite' ),
					$capability
				),
				self::ID
			);
		}
		if ( '' === trim( $subscriber_id ) ) {
			return DirectMessageResult::failure( __( 'No subscriber was given.', 'igbz-suite' ), self::ID );
		}

		$tokens = array_merge(
			[
				'subscriber_id' => $subscriber_id,
				'capability'    => $capability,
				'text'          => '',
				'url'           => '',
				'caption'       => '',
				'media_ref'     => '',
				'flow_ref'      => '',
				'button_label'  => '',
				'button_url'    => '',
				'account_id'    => (string) ( $account['id'] ?? '' ),
				'ig_user_id'    => (string) ( $account['ig_user_id'] ?? '' ),
				'ig_username'   => (string) ( $account['username'] ?? '' ),
			],
			$extra
		);

		/**
		 * Adjust the tokens available to the body template.
		 *
		 * @param array<string,scalar> $tokens
		 * @param array<string,mixed>  $account
		 */
		$tokens = (array) apply_filters( 'igbz_dm_custom_tokens', $tokens, $account, $capability );

		$body = $this->body( $tokens );
		if ( null === $body ) {
			return DirectMessageResult::failure( __( 'The custom messaging body template is not valid JSON.', 'igbz-suite' ), self::ID );
		}

		$method   = strtoupper( trim( $this->settings->string( 'dm.custom.method', 'POST' ) ) );
		$response = $this->http->request(
			in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ? $method : 'POST',
			$this->endpoint(),
			[
				'json'    => $body,
				'headers' => $this->headers(),
				'channel' => 'dm',
				'timeout' => max( 5, $this->settings->int( 'dm.custom.timeout', 20 ) ),
				'retries' => 0,
			]
		);

		if ( ! $response->ok() ) {
			// 4xx is the endpoint refusing this request; only 5xx and transport errors are worth
			// another attempt, and Http has already exhausted those.
			return DirectMessageResult::failure( $response->error_message(), self::ID, $response->status );
		}

		$json = $response->json();

		// Some endpoints answer 200 with a failure in the payload. Honour the common shapes.
		if ( isset( $json['status'] ) && in_array( strtolower( (string) $json['status'] ), [ 'error', 'failed', 'failure' ], true ) ) {
			$message = (string) ( $json['message'] ?? $json['error'] ?? __( 'The messaging endpoint reported an error.', 'igbz-suite' ) );
			return DirectMessageResult::failure( $message, self::ID, (int) ( $json['code'] ?? 0 ) );
		}
		if ( isset( $json['ok'] ) && false === $json['ok'] ) {
			$message = (string) ( $json['error'] ?? $json['message'] ?? __( 'The messaging endpoint reported an error.', 'igbz-suite' ) );
			return DirectMessageResult::failure( $message, self::ID );
		}

		$path       = trim( $this->settings->string( 'dm.custom.message_id_path', '' ) );
		$message_id = '' !== $path ? $this->dig( $json, $path ) : (string) ( $json['message_id'] ?? $json['id'] ?? '' );

		return DirectMessageResult::sent( $message_id, self::ID );
	}

	/**
	 * Read a dotted path out of a decoded response, e.g. `data.message.id`.
	 *
	 * @param array<string,mixed> $json
	 */
	private function dig( array $json, string $path ): string {
		$node = $json;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $node ) && array_key_exists( $segment, $node ) ) {
				$node = $node[ $segment ];
				continue;
			}
			return '';
		}
		return is_scalar( $node ) ? (string) $node : '';
	}

	public function send_text(
		array $account,
		string $subscriber_id,
		string $text,
		string $button_label = '',
		string $button_url = ''
	): DirectMessageResult {
		return $this->dispatch(
			$account,
			self::CAP_TEXT,
			$subscriber_id,
			[ 'text' => $text, 'button_label' => $button_label, 'button_url' => $button_url ]
		);
	}

	public function send_image( array $account, string $subscriber_id, string $url, string $caption = '' ): DirectMessageResult {
		return $this->dispatch( $account, self::CAP_IMAGE, $subscriber_id, [ 'url' => $url, 'caption' => $caption, 'text' => $caption ] );
	}

	public function send_video( array $account, string $subscriber_id, string $url, string $caption = '' ): DirectMessageResult {
		return $this->dispatch( $account, self::CAP_VIDEO, $subscriber_id, [ 'url' => $url, 'caption' => $caption, 'text' => $caption ] );
	}

	public function send_media_share( array $account, string $subscriber_id, string $media_ref ): DirectMessageResult {
		return $this->dispatch( $account, self::CAP_MEDIA_SHARE, $subscriber_id, [ 'media_ref' => $media_ref ] );
	}

	public function send_flow( array $account, string $subscriber_id, string $flow_ref, array $fields = [] ): DirectMessageResult {
		$extra = [ 'flow_ref' => $flow_ref ];
		foreach ( $fields as $name => $value ) {
			$extra[ 'field.' . strtolower( (string) $name ) ] = (string) $value;
		}
		return $this->dispatch( $account, self::CAP_FLOW, $subscriber_id, $extra );
	}
}
