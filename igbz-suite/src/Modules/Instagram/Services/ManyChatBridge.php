<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Step 13: get the purchase link into ManyChat so the automatic direct message can send it.
 *
 * There is a real constraint hiding behind the innocuous phrase "send the link to ManyChat":
 * ManyChat has no inbound API for registering a link against a keyword. Its automation is built
 * around flows and subscriber fields, and a link only reaches a person when that person's
 * subscriber record has it. So the delivery genuinely happens per-subscriber at comment time,
 * which FunnelService already does.
 *
 * What this class does is everything that can usefully be done *ahead* of the first comment, so
 * that the first one is not the one that discovers a misconfiguration:
 *
 *   - the link is resolved and stored on the funnel, so no comment has to compute it;
 *   - a page-level bot field carries the newest product's code and link, which lets a store build
 *     one generic "latest drop" flow in the ManyChat UI that needs no per-product editing;
 *   - the custom fields and the tag the funnel will write are created up front, because ManyChat
 *     rejects a setCustomFieldByName for a field that does not exist yet — and it rejects it at
 *     the exact moment a customer is waiting for a reply.
 *
 * All of it is best-effort. A store on a free ManyChat plan has no API at all, and a product must
 * still be listable, so every failure here is logged and swallowed.
 */
final class ManyChatBridge {

	/** Bot fields describing the most recently published product. */
	public const FIELD_LATEST_CODE  = 'igbz_latest_code';
	public const FIELD_LATEST_LINK  = 'igbz_latest_link';
	public const FIELD_LATEST_TITLE = 'igbz_latest_title';

	/** Subscriber fields the funnel writes at delivery time; created here so they exist first. */
	private const SUBSCRIBER_FIELDS = [ 'igbz_link', 'igbz_coupon', 'igbz_message', 'igbz_funnel' ];

	public function __construct(
		private ManyChatClient $client,
		private AccountCredentials $credentials,
		private Logger $logger
	) {}

	/**
	 * Hand a freshly published product's purchase link to ManyChat.
	 *
	 * @param array<string,mixed> $account
	 * @return array{ok:bool,link:string,error:string}
	 */
	public function register_product( array $account, string $sku, string $link, string $title = '' ): array {
		if ( '' === $link ) {
			return [ 'ok' => false, 'link' => '', 'error' => 'missing_link' ];
		}

		$key = $this->credentials->key( $account, AccountCredentials::SERVICE_MANYCHAT );
		if ( '' === $key ) {
			// Not an error worth surfacing: the funnel falls back to answering inline, and plenty
			// of stores run ManyChat without giving the plugin an API key.
			$this->logger->debug(
				'manychat',
				'No API key for this account, so the purchase link was not pushed',
				[ 'sku' => $sku ]
			);
			return [ 'ok' => false, 'link' => $link, 'error' => 'manychat_key_missing' ];
		}

		$client = $this->client->for_key( $key );

		$this->ensure_fields( $client );

		$ok = $this->set_bot_fields(
			$client,
			[
				self::FIELD_LATEST_CODE  => $sku,
				self::FIELD_LATEST_LINK  => $link,
				self::FIELD_LATEST_TITLE => $title,
			]
		);

		$this->logger->info(
			'manychat',
			$ok ? 'Purchase link handed to ManyChat' : 'ManyChat did not accept the purchase link',
			[ 'sku' => $sku, 'link' => $link ]
		);

		do_action( 'igbz_manychat_product_registered', $sku, $link, $account, $ok );

		return [ 'ok' => $ok, 'link' => $link, 'error' => $ok ? '' : 'manychat_rejected' ];
	}

	/**
	 * Create the custom fields and the tag the funnel will use, if they are missing.
	 *
	 * Done once per product registration rather than once per delivery: it is two cheap calls at
	 * a moment when nobody is waiting, and it removes the single most common cause of a funnel
	 * that silently answers nothing.
	 */
	private function ensure_fields( ManyChatClient $client ): void {
		$existing = $this->names( $client->custom_fields() );

		foreach ( self::SUBSCRIBER_FIELDS as $field ) {
			if ( ! isset( $existing[ mb_strtolower( $field ) ] ) ) {
				$client->create_custom_field( $field, 'text' );
			}
		}
	}

	/**
	 * Field names from a getCustomFields / getBotFields envelope, lower-cased for comparison.
	 *
	 * ManyChat is inconsistent about where the list sits — sometimes `data` is the array,
	 * sometimes it wraps it in a named key — and about whether a field's name is under `name` or
	 * `caption`, so both shapes are accepted rather than assumed.
	 *
	 * @param array{ok:bool,data:array<string,mixed>,error:string,code:int} $response
	 * @return array<string,true>
	 */
	private function names( array $response ): array {
		if ( empty( $response['ok'] ) ) {
			return [];
		}

		$data = $response['data'] ?? [];
		if ( ! is_array( $data ) ) {
			return [];
		}

		// Unwrap a single wrapper key such as {"fields": [...]}.
		if ( $data && ! isset( $data[0] ) ) {
			foreach ( $data as $value ) {
				if ( is_array( $value ) && isset( $value[0] ) ) {
					$data = $value;
					break;
				}
			}
		}

		$names = [];
		foreach ( $data as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name = (string) ( $field['name'] ?? $field['caption'] ?? '' );
			if ( '' !== $name ) {
				$names[ mb_strtolower( $name ) ] = true;
			}
		}

		return $names;
	}

	/** @param array<string,string> $fields */
	private function set_bot_fields( ManyChatClient $client, array $fields ): bool {
		$existing = $this->names( $client->bot_fields() );

		$ok = true;
		foreach ( $fields as $name => $value ) {
			if ( ! isset( $existing[ mb_strtolower( $name ) ] ) ) {
				$client->create_bot_field( $name, 'text' );
			}

			$result = $client->set_bot_field_by_name( $name, (string) $value );
			if ( empty( $result['ok'] ) ) {
				$ok = false;
			}
		}

		return $ok;
	}
}
