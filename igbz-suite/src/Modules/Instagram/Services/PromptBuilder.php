<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the natural-language instructions sent to Manus. Keeping them in one place makes the
 * whole content pipeline auditable and lets a store override any prompt with a filter.
 */
final class PromptBuilder {

	/** @param array<string,mixed> $account */
	private function context( array $account ): string {
		$lines = [
			sprintf( 'Instagram account: @%s', (string) ( $account['username'] ?? '' ) ),
		];
		if ( ! empty( $account['niche'] ) ) {
			$lines[] = sprintf( 'Niche / market: %s', (string) $account['niche'] );
		}
		if ( ! empty( $account['brand_voice'] ) ) {
			$lines[] = sprintf( 'Brand voice: %s', (string) $account['brand_voice'] );
		}
		$lines[] = sprintf( 'Audience timezone: %s', (string) ( $account['timezone'] ?? 'Asia/Tehran' ) );
		$lines[] = sprintf( 'Store: %s (%s)', get_bloginfo( 'name' ), home_url( '/' ) );

		$language = igbz()->settings()->string( 'manus.content_language', 'Persian (Farsi)' );
		$lines[]  = sprintf( 'Write all copy in %s.', $language );

		return implode( "\n", $lines );
	}

	/** @param array<string,mixed> $account */
	public function trend_research( array $account, string $topic = '' ): string {
		$prompt = $this->context( $account ) . "\n\n"
			. "Task: niche research and trend discovery for the next 7 days.\n"
			. ( '' !== $topic ? "Focus topic: {$topic}\n" : '' )
			. "1. Identify the 10 strongest current content trends, audio tracks, formats and hooks for this niche on Instagram.\n"
			. "2. For each trend give: a hook line, the best format (static post, carousel, reel, story), why it works now, and an estimated engagement upside.\n"
			. "3. Identify the 15 highest-value hashtags (mix of large, medium and niche) with their approximate size.\n"
			. "4. Recommend a posting cadence and the exact peak-engagement hours in the audience timezone, as 24h local times.\n"
			. "Return the answer as JSON with keys: trends[], hashtags[], peak_hours[], cadence.";

		return (string) apply_filters( 'igbz_manus_prompt_research', $prompt, $account, $topic );
	}

	/**
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function graphic_design( array $account, array $brief ): string {
		$canva  = igbz()->settings()->bool( 'manus.use_canva', true );
		$slides = max( 1, (int) ( $brief['slides'] ?? 1 ) );

		$prompt = $this->context( $account ) . "\n\n"
			. sprintf( "Task: design %s for Instagram.\n", $slides > 1 ? "a {$slides}-slide carousel" : 'a single static post graphic' )
			. sprintf( "Subject: %s\n", (string) ( $brief['subject'] ?? '' ) )
			. ( ! empty( $brief['key_points'] ) ? 'Key points to cover: ' . implode( '; ', (array) $brief['key_points'] ) . "\n" : '' )
			. ( ! empty( $brief['product_url'] ) ? sprintf( "Product page: %s\n", (string) $brief['product_url'] ) : '' )
			. ( ! empty( $brief['palette'] ) ? sprintf( "Brand palette: %s\n", (string) $brief['palette'] ) : '' )
			. "Format: 1080x1350 px, safe margins for the Instagram UI, legible Persian typography with correct RTL shaping.\n";

		if ( $canva ) {
			$prompt .= "Use the Canva connector to build the design in Canva, then export every slide as a high quality PNG and attach the exported files to this task.\n";
		} else {
			$prompt .= "Produce the final images and attach them to this task as PNG files.\n";
		}

		$prompt .= "Also attach a captions.json file containing: caption, hashtags[], alt_text, first_comment.";

		return (string) apply_filters( 'igbz_manus_prompt_graphic', $prompt, $account, $brief );
	}

	/**
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function reel( array $account, array $brief ): string {
		$seconds = (int) ( $brief['duration'] ?? igbz()->settings()->int( 'manus.reel_seconds', 25 ) );

		$prompt = $this->context( $account ) . "\n\n"
			. sprintf( "Task: produce a %d second vertical Instagram reel (1080x1920, 30fps, MP4).\n", $seconds )
			. sprintf( "Subject: %s\n", (string) ( $brief['subject'] ?? '' ) )
			. ( ! empty( $brief['hook'] ) ? sprintf( "Opening hook (first 2 seconds): %s\n", (string) $brief['hook'] ) : '' )
			. ( ! empty( $brief['product_url'] ) ? sprintf( "Product page: %s\n", (string) $brief['product_url'] ) : '' )
			. "Include: a scroll-stopping hook, 3 to 5 fast scenes, burned-in Persian subtitles with correct RTL shaping, and a clear call to action at the end.\n"
			. "Pick a trending, licence-safe audio track appropriate for this niche and state its name in the summary.\n"
			. "Attach the finished MP4 plus a cover.jpg thumbnail and a captions.json file with: caption, hashtags[], alt_text, audio_track.";

		return (string) apply_filters( 'igbz_manus_prompt_reel', $prompt, $account, $brief );
	}

	/**
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function caption( array $account, array $brief ): string {
		$prompt = $this->context( $account ) . "\n\n"
			. "Task: write the caption for an Instagram post.\n"
			. sprintf( "Subject: %s\n", (string) ( $brief['subject'] ?? '' ) )
			. ( ! empty( $brief['keyword'] ) ? sprintf( "The call to action must ask people to comment the exact word \"%s\" to receive a DM with the link.\n", (string) $brief['keyword'] ) : '' )
			. "Requirements: a hook in the first line, 3 to 6 short lines of value, one clear call to action, tasteful emoji use, and 15 to 25 relevant hashtags placed at the end.\n"
			. "Return JSON only: {\"caption\": \"...\", \"hashtags\": [\"...\"], \"alt_text\": \"...\"}";

		return (string) apply_filters( 'igbz_manus_prompt_caption', $prompt, $account, $brief );
	}

	/**
	 * Publish / schedule instruction. Manus performs the upload itself, so no asset ever has to be
	 * downloaded and re-uploaded by hand.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $content
	 */
	public function publish( array $account, array $content, int $timestamp = 0 ): string {
		$kind  = (string) ( $content['kind'] ?? 'post' );
		$media = json_decode( (string) ( $content['media'] ?? '[]' ), true );
		$media = is_array( $media ) ? $media : [];

		$when = $timestamp > 0
			? sprintf(
				'Schedule it to publish at exactly %s (%s).',
				wp_date( 'Y-m-d H:i', $timestamp, new \DateTimeZone( (string) ( $account['timezone'] ?? 'Asia/Tehran' ) ) ),
				(string) ( $account['timezone'] ?? 'Asia/Tehran' )
			)
			: 'Publish it now.';

		$prompt = $this->context( $account ) . "\n\n"
			. sprintf( "Task: publish an Instagram %s to @%s using the connected Instagram account.\n", $kind, (string) ( $account['username'] ?? '' ) )
			. $when . "\n"
			. "Media files to use, in order:\n";

		foreach ( $media as $index => $item ) {
			$url     = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : (string) $item;
			$prompt .= sprintf( "  %d. %s\n", $index + 1, $url );
		}

		$prompt .= "Caption to use verbatim:\n\"\"\"\n" . (string) ( $content['caption'] ?? '' ) . "\n\"\"\"\n";

		$hashtags = json_decode( (string) ( $content['hashtags'] ?? '[]' ), true );
		if ( is_array( $hashtags ) && $hashtags ) {
			$prompt .= 'Hashtags: ' . implode( ' ', array_map( 'strval', $hashtags ) ) . "\n";
		}

		$prompt .= "Do not modify the caption. After publishing, report the resulting permalink and the media id as JSON: {\"permalink\": \"...\", \"media_id\": \"...\"}";

		return (string) apply_filters( 'igbz_manus_prompt_publish', $prompt, $account, $content, $timestamp );
	}

	/** @param array<string,mixed> $account */
	public function insights( array $account ): string {
		$prompt = $this->context( $account ) . "\n\n"
			. "Task: collect yesterday's Instagram analytics for this account.\n"
			. "Report follower count, reach, impressions, profile visits, website taps, and per-post engagement for the last 10 posts.\n"
			. "Also compute the three hours of the day with the highest engagement over the last 30 days, in the audience timezone.\n"
			. "Return JSON only: {\"metrics\": {\"followers\": 0, \"reach\": 0, \"impressions\": 0, \"profile_visits\": 0, \"website_taps\": 0}, \"posts\": [], \"peak_hours\": [\"18:00\"]}";

		return (string) apply_filters( 'igbz_manus_prompt_insights', $prompt, $account );
	}
}
