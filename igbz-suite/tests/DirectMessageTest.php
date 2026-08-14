<?php
/**
 * Capability-routed direct messaging.
 *
 * The paid-post feature turns on a fact that is easy to forget and expensive to rediscover:
 * ManyChat will not send video to Instagram. Not "not yet" and not "not on this plan" — the
 * channel has no video message type at all. Any design that assumes one vendor can carry the whole
 * delivery path is therefore wrong, and this suite exists to keep that assumption from creeping
 * back in.
 *
 * What is pinned here:
 *
 *  1. ManyChat refuses video and post-sharing *locally*, without spending a request, and reports
 *     it as `unsupported` rather than `failure` so the retry loop leaves it alone.
 *  2. The router will reach past the configured provider to a gateway that can do the job, so an
 *     install can keep ManyChat for funnels and still deliver paid video elsewhere.
 *  3. The configurable gateway builds its body from a template rather than a hard-coded shape,
 *     because the vendor that can do video has published no REST contract and we refuse to guess
 *     one. Critically, values are injected into a decoded structure, so a caption containing a
 *     double quote cannot corrupt the request.
 *  4. `deliver_media()` prefers the native post over a raw file URL. A public URL to paid content
 *     is a leak, so it is the last resort and never the first.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Contracts\DirectMessageGatewayInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\DirectMessageResult;
use IGBZ\Suite\Modules\Instagram\Messaging\ConfigurableDmGateway;
use IGBZ\Suite\Modules\Instagram\Messaging\DirectMessenger;
use IGBZ\Suite\Modules\Instagram\Messaging\ManyChatGateway;
use IGBZ\Suite\Support\Crypto;

final class DirectMessageTest extends TestCase {

	/**
	 * An account with a working ManyChat key.
	 *
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function account( array $overrides = [] ): array {
		return array_merge(
			[
				'id'               => 7,
				'tenant_id'        => 3,
				'username'         => 'shop',
				'ig_user_id'       => '17841400000000000',
				'credential_mode'  => 'own',
				'manychat_api_key' => Crypto::encrypt( 'mc-key-123' ),
			],
			$overrides
		);
	}

	/**
	 * The module's container bindings are not registered in the test harness, which boots core
	 * services only, so the graph is assembled by hand exactly as InstagramModule assembles it.
	 */
	private function manychat_gateway(): ManyChatGateway {
		return new ManyChatGateway(
			new \IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient( igbz()->http(), igbz()->get( 'logger' ) ),
			new \IGBZ\Suite\Modules\Instagram\Services\AccountCredentials( new \IGBZ\Suite\Support\Db() ),
			igbz()->get( 'logger' )
		);
	}

	private function custom_gateway(): ConfigurableDmGateway {
		return new ConfigurableDmGateway( igbz()->http(), igbz()->settings(), igbz()->get( 'logger' ) );
	}

	private function messenger(): DirectMessenger {
		return new DirectMessenger(
			igbz()->settings(),
			igbz()->get( 'logger' ),
			$this->manychat_gateway(),
			$this->custom_gateway()
		);
	}

	/** @return array<int,array{url:string,method:string,body:string}> */
	private function requests(): array {
		return $GLOBALS['igbz_test_http_requests'];
	}

	/** The URL of a sent request, or a readable placeholder when no such request was made. */
	private function sent_url( int $index = 0 ): string {
		return $this->requests()[ $index ]['url'] ?? '(no request was made)';
	}

	/**
	 * The decoded body of a sent request.
	 *
	 * Decoding is done here rather than inline so that a template which interpolates itself into
	 * invalid JSON fails as a readable assertion instead of a TypeError twenty lines later.
	 *
	 * @return array<string,mixed>
	 */
	private function sent_body( int $index = 0 ): array {
		$requests = $this->requests();

		if ( ! isset( $requests[ $index ] ) ) {
			$this->assert_true( false, sprintf( 'Expected a request at index %d, but %d were made', $index, count( $requests ) ) );
			return [];
		}

		$decoded = json_decode( $requests[ $index ]['body'], true );

		if ( ! is_array( $decoded ) ) {
			$this->assert_true(
				false,
				sprintf( 'The request body is not valid JSON: %s', $requests[ $index ]['body'] )
			);
			return [];
		}

		return $decoded;
	}

	public function run(): void {
		$this->test_manychat_refuses_video_without_calling_out();
		$this->test_manychat_refuses_media_share();
		$this->test_manychat_sends_text();
		$this->test_tag_required_is_reported_as_a_closed_window();
		$this->test_router_falls_past_the_preferred_gateway_for_video();
		$this->test_router_reports_real_capabilities();
		$this->test_custom_gateway_only_claims_configured_capabilities();
		$this->test_custom_gateway_builds_body_from_template();
		$this->test_custom_gateway_escapes_quotes_in_values();
		$this->test_custom_gateway_prunes_empty_fields();
		$this->test_custom_gateway_honours_an_error_in_a_200_body();
		$this->test_deliver_media_prefers_the_native_post();
		$this->test_deliver_media_falls_back_to_the_file();
		$this->test_declared_capabilities_need_an_endpoint();
		$this->test_deliver_media_reports_when_nothing_can_send();
	}

	// ------------------------------------------------------------- ManyChat

	private function test_manychat_refuses_video_without_calling_out(): void {
		igbz_test_reset_settings();

		$gateway = $this->manychat_gateway();
		$result  = $gateway->send_video( $this->account(), 'sub-1', 'https://cdn.test/clip.mp4' );

		$this->assert_false( $result->ok, 'ManyChat must not claim to have sent a video' );
		$this->assert_same(
			DirectMessageResult::STATUS_UNSUPPORTED,
			$result->status,
			'A video send through ManyChat is unsupported, not a failure'
		);
		$this->assert_false( $result->is_retryable(), 'An unsupported send must never be retried' );
		$this->assert_true( $result->needs_another_gateway(), 'The caller should be told to try another gateway' );
		$this->assert_same( 0, count( $this->requests() ), 'No HTTP request may be made for a send ManyChat cannot do' );
	}

	private function test_manychat_refuses_media_share(): void {
		igbz_test_reset_settings();

		$result = $this->manychat_gateway()->send_media_share( $this->account(), 'sub-1', '17900000000000000' );

		$this->assert_same( DirectMessageResult::STATUS_UNSUPPORTED, $result->status, 'ManyChat cannot attach a published post' );
		$this->assert_same( 0, count( $this->requests() ), 'Post sharing must not reach the network through ManyChat' );
	}

	private function test_manychat_sends_text(): void {
		igbz_test_reset_settings();

		$result = $this->manychat_gateway()->send_text( $this->account(), 'sub-1', 'Hello', 'Buy', 'https://shop.test/p/1' );

		$this->assert_true( $result->ok, 'Text is the one thing every gateway can do' );
		$this->assert_same( 1, count( $this->requests() ), 'One text message is one request' );

		$this->assert_contains( 'sending/sendContent', $this->sent_url(), 'Text goes through sendContent' );
		$this->assert_contains(
			'https://shop.test/p/1',
			wp_json_encode( $this->sent_body() ),
			'The button URL must survive into the body'
		);
	}

	/**
	 * ManyChat answers 3021 when a message needs a tag. In practice that means the 24-hour window
	 * has shut, and the caller must park the delivery rather than treat it as broken.
	 */
	private function test_tag_required_is_reported_as_a_closed_window(): void {
		igbz_test_reset_settings();
		igbz_test_queue_manychat_error( 'Message tag required', 3021 );

		$result = $this->manychat_gateway()->send_text( $this->account(), 'sub-1', 'Hello' );

		$this->assert_same(
			DirectMessageResult::STATUS_WINDOW_CLOSED,
			$result->status,
			'A tag-required error is a closed 24-hour window'
		);
		$this->assert_false( $result->needs_another_gateway(), 'No other gateway can beat the 24-hour window either' );
	}

	// --------------------------------------------------------------- router

	/**
	 * The whole point of the layer: ManyChat stays the provider for everything it can do, and
	 * video quietly routes to the gateway that can.
	 */
	private function test_router_falls_past_the_preferred_gateway_for_video(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many(
			[
				'dm.provider'              => ManyChatGateway::ID,
				'dm.custom.endpoint'       => 'https://vendor.test/send',
				'dm.custom.capabilities'   => 'video,media_share',
				'dm.custom.body_template'  => '{"to":"{{subscriber_id}}","video":"{{url}}"}',
			]
		);

		$account = $this->account();

		$text = $this->messenger()->gateway_for( DirectMessageGatewayInterface::CAP_TEXT, $account );
		$this->assert_same( ManyChatGateway::ID, null === $text ? '(none)' : $text->id(), 'Text stays with the preferred gateway' );

		$video = $this->messenger()->gateway_for( DirectMessageGatewayInterface::CAP_VIDEO, $account );
		$this->assert_same( ConfigurableDmGateway::ID, null === $video ? '(none)' : $video->id(), 'Video must route to the gateway that supports it' );

		$result = $this->messenger()->send_video( $account, 'sub-9', 'https://cdn.test/clip.mp4' );
		$this->assert_true( $result->ok, 'The routed video send should succeed' );
		$this->assert_contains( 'vendor.test', $this->sent_url(), 'The video went to the custom endpoint' );
	}

	private function test_router_reports_real_capabilities(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many( [ 'dm.provider' => ManyChatGateway::ID ] );

		$account = $this->account();

		$capabilities = $this->messenger()->capabilities( $account );
		$this->assert_true( in_array( 'text', $capabilities, true ), 'ManyChat alone can do text' );
		$this->assert_false(
			in_array( 'video', $capabilities, true ),
			'With only ManyChat configured the install must not advertise video'
		);
		$this->assert_false(
			$this->messenger()->can( DirectMessageGatewayInterface::CAP_MEDIA_SHARE, $account ),
			'Nothing can share a post when only ManyChat is configured'
		);
	}

	// ----------------------------------------------------------- custom gateway

	private function test_custom_gateway_only_claims_configured_capabilities(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many(
			[ 'dm.custom.endpoint' => 'https://vendor.test/send', 'dm.custom.capabilities' => 'text,video' ]
		);

		$gateway = $this->custom_gateway();
		$this->assert_true( $gateway->supports( 'video' ), 'A declared capability is supported' );
		$this->assert_false( $gateway->supports( 'media_share' ), 'An undeclared capability is not' );

		$result = $gateway->send_media_share( $this->account(), 'sub-1', '123' );
		$this->assert_same(
			DirectMessageResult::STATUS_UNSUPPORTED,
			$result->status,
			'An undeclared capability is refused before the request'
		);
		$this->assert_same( 0, count( $this->requests() ), 'A refused capability costs no request' );
	}

	private function test_custom_gateway_builds_body_from_template(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many(
			[
				'dm.custom.endpoint'      => 'https://vendor.test/v1/messages',
				'dm.custom.capabilities'  => 'media_share',
				'dm.custom.api_key'       => 'secret-key',
				'dm.custom.auth_header'   => 'X-Api-Key',
				'dm.custom.auth_scheme'   => '',
				'dm.custom.body_template' => '{"recipient":{"id":"{{subscriber_id}}"},"post":{"ref":"{{media_ref}}"},"from":"{{ig_username}}"}',
			]
		);

		$result = $this->custom_gateway()->send_media_share( $this->account(), 'sub-42', 'POST-77' );

		$this->assert_true( $result->ok, 'A configured custom send should succeed' );

		$body = $this->sent_body();
		$this->assert_same( 'sub-42', $body['recipient']['id'], 'The subscriber token is interpolated' );
		$this->assert_same( 'POST-77', $body['post']['ref'], 'The media reference is interpolated' );
		$this->assert_same( 'shop', $body['from'], 'Account tokens are available to the template' );
	}

	/**
	 * The reason the template is decoded and walked rather than string-replaced: a caption is user
	 * text, and user text contains quotes.
	 */
	private function test_custom_gateway_escapes_quotes_in_values(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many(
			[
				'dm.custom.endpoint'      => 'https://vendor.test/send',
				'dm.custom.capabilities'  => 'text',
				'dm.custom.body_template' => '{"message":"{{text}}"}',
			]
		);

		$this->custom_gateway()->send_text( $this->account(), 'sub-1', 'He said "buy now" — today' );

		// sent_body() already fails loudly if the quote broke the JSON; this pins the value itself.
		$body = $this->sent_body();
		$this->assert_same( 'He said "buy now" — today', $body['message'] ?? '', 'The quoted text survives intact' );
	}

	private function test_custom_gateway_prunes_empty_fields(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many(
			[
				'dm.custom.endpoint'      => 'https://vendor.test/send',
				'dm.custom.capabilities'  => 'text',
				'dm.custom.body_template' => '{"to":"{{subscriber_id}}","text":"{{text}}","url":"{{url}}"}',
			]
		);

		$this->custom_gateway()->send_text( $this->account(), 'sub-1', 'Hello' );

		$body = $this->sent_body();
		$this->assert_false(
			array_key_exists( 'url', $body ),
			'An unused token must be dropped, not sent as an empty string'
		);
		$this->assert_same( 'Hello', $body['text'], 'A used token stays' );
	}

	private function test_custom_gateway_honours_an_error_in_a_200_body(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many(
			[
				'dm.custom.endpoint'      => 'https://vendor.test/send',
				'dm.custom.capabilities'  => 'text',
				'dm.custom.body_template' => '{"to":"{{subscriber_id}}"}',
			]
		);
		igbz_test_queue_http(
			[ 'status' => 200, 'body' => wp_json_encode( [ 'status' => 'error', 'message' => 'Outside the 24 hour window' ] ) ]
		);

		$result = $this->custom_gateway()->send_text( $this->account(), 'sub-1', 'Hello' );

		$this->assert_false( $result->ok, 'A 200 carrying an error payload is still a failure' );
		$this->assert_contains( '24 hour', $result->error, 'The endpoint message is surfaced' );
	}

	// --------------------------------------------------------- deliver_media

	/**
	 * Delivery order is a security property, not a preference: the native post cannot be forwarded
	 * to people who did not pay, and a file URL can.
	 */
	private function test_deliver_media_prefers_the_native_post(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many(
			[
				'dm.custom.endpoint'      => 'https://vendor.test/send',
				'dm.custom.capabilities'  => 'media_share,video',
				'dm.custom.body_template' => '{"to":"{{subscriber_id}}","kind":"{{capability}}","ref":"{{media_ref}}","url":"{{url}}"}',
			]
		);

		$result = $this->messenger()->deliver_media(
			$this->account(),
			'sub-5',
			'POST-99',
			'',
			'https://cdn.test/clip.mp4'
		);

		$this->assert_true( $result->ok, 'Delivery should succeed' );
		$this->assert_same( 1, count( $this->requests() ), 'Only one route should be taken when the first works' );

		$body = $this->sent_body();
		$this->assert_same( 'media_share', $body['kind'], 'The native post must be preferred over the file URL' );
		$this->assert_false( array_key_exists( 'url', $body ), 'The forwardable file URL must not be sent when the post will do' );
	}

	private function test_deliver_media_falls_back_to_the_file(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many(
			[
				'dm.custom.endpoint'      => 'https://vendor.test/send',
				'dm.custom.capabilities'  => 'video',
				'dm.custom.body_template' => '{"to":"{{subscriber_id}}","kind":"{{capability}}","url":"{{url}}"}',
			]
		);

		// A post reference is offered, but nothing can send one, so the file is the only route.
		$result = $this->messenger()->deliver_media(
			$this->account(),
			'sub-5',
			'POST-99',
			'',
			'https://cdn.test/clip.mp4'
		);

		$this->assert_true( $result->ok, 'The file route should carry the delivery' );

		$body = $this->sent_body();
		$this->assert_same( 'video', $body['kind'], 'Falls through to the video file' );
	}

	/**
	 * Declaring a capability is not the same as being able to use it. An endpoint left blank means
	 * nothing can be delivered, no matter what the capability list claims — the settings screen
	 * reads this to decide whether to warn that paid video will not work.
	 */
	private function test_declared_capabilities_need_an_endpoint(): void {
		$settings = igbz_test_reset_settings();
		$settings->set_many( [ 'dm.custom.endpoint' => '', 'dm.custom.capabilities' => 'text,video,media_share' ] );

		$account = $this->account();

		$this->assert_false( $this->custom_gateway()->is_configured(), 'No endpoint means not configured' );
		$this->assert_false(
			$this->messenger()->can( DirectMessageGatewayInterface::CAP_VIDEO, $account ),
			'A capability declared against a blank endpoint must not count as available'
		);
		$this->assert_same(
			null,
			$this->messenger()->gateway_for( DirectMessageGatewayInterface::CAP_VIDEO, $account ),
			'No gateway can be selected for video when the custom endpoint is blank'
		);
	}

	private function test_deliver_media_reports_when_nothing_can_send(): void {
		igbz_test_reset_settings();

		// ManyChat only: no video, no post sharing.
		$result = $this->messenger()->deliver_media(
			$this->account(),
			'sub-5',
			'POST-99',
			'',
			'https://cdn.test/clip.mp4'
		);

		$this->assert_false( $result->ok, 'Delivery must fail loudly when no route exists' );
		$this->assert_same(
			DirectMessageResult::STATUS_UNSUPPORTED,
			$result->status,
			'A missing capability is unsupported, so the caller refunds rather than retries'
		);
		$this->assert_same( 0, count( $this->requests() ), 'No request should be attempted when nothing can send' );
	}
}
