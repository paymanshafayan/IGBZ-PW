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
		$slides = max( 1, (int) ( $brief['slides'] ?? 1 ) );

		$prompt = $this->context( $account ) . "\n\n"
			. sprintf( "Task: design %s for Instagram.\n", $slides > 1 ? "a {$slides}-slide carousel" : 'a single static post graphic' )
			. sprintf( "Subject: %s\n", (string) ( $brief['subject'] ?? '' ) )
			. ( ! empty( $brief['key_points'] ) ? 'Key points to cover: ' . implode( '; ', (array) $brief['key_points'] ) . "\n" : '' )
			. ( ! empty( $brief['product_url'] ) ? sprintf( "Product page: %s\n", (string) $brief['product_url'] ) : '' )
			. ( ! empty( $brief['palette'] ) ? sprintf( "Brand palette: %s\n", (string) $brief['palette'] ) : '' )
			. "Format: 1080x1350 px, safe margins for the Instagram UI, legible Persian typography with correct RTL shaping.\n"
			. "Produce the final images and attach them to this task as PNG files.\n";

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

	// ------------------------------------------------- product registration

	/**
	 * Grade a photograph shot in the shop before any money is spent processing it.
	 *
	 * This is the gate at step 3 of the registration flow, and its whole value is in the
	 * rejection path: telling somebody "try again" is useless, so the schema forces a list of
	 * concrete, fixable reasons ("the product is cut off at the bottom", "the background is a
	 * patterned rug") rather than a score the app would have to invent an explanation for.
	 *
	 * `background_removal_ready` and `video_ready` are asked separately because they fail for
	 * different reasons: a busy background ruins the cutout but is fine for a video, while
	 * motion blur ruins both.
	 *
	 * @param array<string,mixed> $account
	 */
	public function photo_quality( array $account, string $hint = '' ): string {
		$threshold = igbz()->settings()->int( 'intake.quality_threshold', 60 );

		$prompt = $this->context( $account ) . "\n\n"
			. "Task: assess the attached product photograph for an e-commerce listing and an Instagram post.\n"
			. ( '' !== $hint ? "What the seller says this is: {$hint}\n" : '' )
			. "Judge it on: is the product the clear subject, is it fully in frame, is it in focus, is the lighting even, "
			. "is the background clean enough for automatic background removal, is the resolution adequate, and is there "
			. "any distracting clutter, glare, hand, or reflection.\n"
			. sprintf( "A total score below %d means the photo must be retaken.\n", $threshold )
			. "If the photo is not usable, every reason must be specific and actionable — say exactly what is wrong and "
			. "what the seller should do differently, in one short sentence each. Never give a vague reason.\n"
			. "Write the reasons and the suggestion in " . igbz()->settings()->string( 'manus.content_language', 'Persian (Farsi)' ) . ".\n"
			. "Answer with JSON only, no prose: "
			. '{"verdict": "accept" | "reject", "score": 0-100, "background_removal_ready": true|false, '
			. '"video_ready": true|false, "detected_product": "...", "reasons": ["..."], "suggestion": "..."}';

		return (string) apply_filters( 'igbz_manus_prompt_photo_quality', $prompt, $account, $hint );
	}

	/** The JSON shape enforced on the photo verdict, so the answer never has to be guessed at. */
	public function photo_quality_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'verdict'                  => [ 'type' => 'string', 'enum' => [ 'accept', 'reject' ] ],
				'score'                    => [ 'type' => 'integer' ],
				'background_removal_ready' => [ 'type' => 'boolean' ],
				'video_ready'              => [ 'type' => 'boolean' ],
				'detected_product'         => [ 'type' => 'string' ],
				'reasons'                  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'suggestion'               => [ 'type' => 'string' ],
			],
			'required'   => [ 'verdict', 'score', 'reasons' ],
		];
	}

	/**
	 * Turn the shop photo into something that can go on a product page.
	 *
	 * Step 4. The output is a catalogue image, not artwork: the instruction is explicit that the
	 * product itself must not be restyled, because an AI that "improves" a handbag's colour has
	 * produced a photograph of something the customer will not receive.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function product_image( array $account, array $brief ): string {
		$style = igbz()->settings()->string( 'intake.image_style', 'clean seamless studio background in a very light neutral tone with a soft natural shadow under the product' );

		$prompt = $this->context( $account ) . "\n\n"
			. "Task: turn the attached photograph into a commercial product image ready for an online store.\n"
			. ( ! empty( $brief['product'] ) ? sprintf( "Product: %s\n", (string) $brief['product'] ) : '' )
			. "Steps: cut the product out of its current background precisely, including thin details and edges; "
			. sprintf( "place it on a %s; ", $style )
			. "relight it so the illumination is even and the material reads correctly; correct the white balance; "
			. "straighten and centre it with comfortable margins.\n"
			. "Absolute rule: do not alter the product itself. Its shape, colour, texture, pattern, logo and any text on "
			. "it must stay exactly as photographed. Do not add, remove or invent any part of it. This image will be sold "
			. "against, so it has to be an honest photograph of the real item.\n"
			. "Produce a square 1500x1500 px JPEG for the storefront and a 1080x1350 px version for Instagram, and attach "
			. "both to this task.\n"
			. "Also attach a result.json containing: {\"main\": \"<file name of the square image>\", \"social\": \"<file name of the vertical image>\", \"notes\": \"...\"}";

		return (string) apply_filters( 'igbz_manus_prompt_product_image', $prompt, $account, $brief );
	}

	/**
	 * Write the listing from whatever the seller dictated or typed.
	 *
	 * Step 7. The hard constraint here is commercial rather than editorial: the seller owns the
	 * price, the stock and the category, and the model owns the words. An AI that infers "this
	 * looks like a 2,000,000 rial bag" would be inventing the one number a shop cannot afford to
	 * have invented, so the prompt is explicit that no price appears anywhere in the output.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function product_copy( array $account, array $brief ): string {
		$language  = igbz()->settings()->string( 'manus.content_language', 'Persian (Farsi)' );
		$languages = (array) ( $brief['languages'] ?? [] );

		$prompt = $this->context( $account ) . "\n\n"
			. "Task: write the WooCommerce listing for a product the shopkeeper has just registered from their phone.\n"
			. "What the shopkeeper said about it, verbatim:\n\"\"\"\n" . (string) ( $brief['description'] ?? '' ) . "\n\"\"\"\n"
			. ( ! empty( $brief['category'] ) ? sprintf( "Category chosen by the shopkeeper: %s\n", (string) $brief['category'] ) : '' )
			. "The product photograph is attached; use it to get details the shopkeeper did not mention, such as colour, "
			. "material and form.\n\n"
			. "Produce:\n"
			. "1. title: a short, searchable product name, at most 70 characters. No marketing adjectives stacked up.\n"
			. "2. short_description: two or three sentences, the reason to buy it.\n"
			. "3. description: 120 to 220 words of clean HTML using only <p>, <ul>, <li> and <strong>. Describe the item, "
			. "the material, how it is used and who it suits. Do not invent facts that are neither in the photo nor in "
			. "what the shopkeeper said.\n"
			. "4. specs: an object of attribute name to value, only for attributes you can actually see or that were "
			. "stated — for example colour, material, size, weight, country of origin. Leave it small rather than padding it.\n"
			. "5. tags: five to ten search terms.\n"
			. "6. seo_title and seo_description.\n"
			. "7. alt_text for the photograph.\n\n"
			. "Hard rules: never state, guess or imply a price, a discount, or a stock quantity — the shopkeeper sets "
			. "those and any number you write would be wrong. Never promise a delivery time or a warranty. "
			. sprintf( "Write everything in %s.\n", $language );

		if ( $languages ) {
			$prompt .= sprintf(
				"\nThe store is multilingual. Also translate title, short_description, description, specs values, tags, "
					. "seo_title and seo_description into: %s. Translate, do not re-invent: the meaning must match the "
					. "original exactly. Return them under a \"translations\" object keyed by the language code.\n",
				implode( ', ', array_map( 'strval', $languages ) )
			);
		}

		$prompt .= "\nAnswer with JSON only, no prose and no commentary.";

		return (string) apply_filters( 'igbz_manus_prompt_product_copy', $prompt, $account, $brief );
	}

	/** @param array<int,string> $languages */
	public function product_copy_schema( array $languages = [] ): array {
		$fields = [
			'title'             => [ 'type' => 'string' ],
			'short_description' => [ 'type' => 'string' ],
			'description'       => [ 'type' => 'string' ],
			'specs'             => [ 'type' => 'object' ],
			'tags'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			'seo_title'         => [ 'type' => 'string' ],
			'seo_description'   => [ 'type' => 'string' ],
			'alt_text'          => [ 'type' => 'string' ],
		];

		$schema = [
			'type'       => 'object',
			'properties' => $fields,
			'required'   => [ 'title', 'description' ],
		];

		if ( $languages ) {
			$schema['properties']['translations'] = [
				'type'       => 'object',
				'properties' => array_fill_keys(
					array_map( 'strval', $languages ),
					[ 'type' => 'object', 'properties' => $fields ]
				),
			];
		}

		return $schema;
	}

	/**
	 * Produce the Instagram video for a registered product.
	 *
	 * Step 10. Unlike the generic reel prompt this one is anchored to a real product photo and a
	 * real product code: the code has to be legible on screen for long enough to be typed into a
	 * comment, which is the entire mechanism by which the post turns into a sale.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function product_video( array $account, array $brief ): string {
		$seconds = (int) ( $brief['duration'] ?? igbz()->settings()->int( 'manus.reel_seconds', 25 ) );
		$code    = (string) ( $brief['code'] ?? '' );

		$prompt = $this->context( $account ) . "\n\n"
			. sprintf( "Task: produce a %d second vertical Instagram video (1080x1920, 30fps, MP4) for a product.\n", $seconds )
			. sprintf( "Product: %s\n", (string) ( $brief['title'] ?? '' ) )
			. ( ! empty( $brief['summary'] ) ? sprintf( "About it: %s\n", (string) $brief['summary'] ) : '' )
			. "The product photograph is attached and is the hero of the video; animate around it rather than replacing it.\n"
			. ( ! empty( $brief['prompt'] ) ? sprintf( "What the shopkeeper asked for, verbatim: \"\"\"\n%s\n\"\"\"\n", (string) $brief['prompt'] ) : '' )
			. "Include a hook in the first two seconds, three to five scenes, and burned-in subtitles with correct RTL "
			. "shaping.\n"
			. ( '' !== $code
				? sprintf(
					"Burn the product code %s onto the video in a clearly legible style, on screen for at least the last "
						. "four seconds and again near the start. It must be readable at a glance on a phone, because "
						. "viewers type it into the comments to get the purchase link. The code is digits: render it in "
						. "Western Arabic numerals (0-9) exactly as written, keeping any leading zeros, and never spell "
						. "it out in words or convert it to Persian digits.\n",
					$code
				)
				: '' )
			. "Pick a trending, licence-safe audio track suited to this niche.\n"
			. "Attach the finished MP4 and a cover.jpg thumbnail.";

		return (string) apply_filters( 'igbz_manus_prompt_product_video', $prompt, $account, $brief );
	}

	/**
	 * Stamp the code onto a still image and write the comment-to-DM caption.
	 *
	 * Step 11 for image posts. Both halves are one task on purpose: the caption tells people to
	 * comment the code and the image shows them the code, so a model that produced one without
	 * the other would ship a post that cannot convert.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function product_post( array $account, array $brief ): string {
		$code     = (string) ( $brief['code'] ?? '' );
		$language = igbz()->settings()->string( 'manus.content_language', 'Persian (Farsi)' );

		$prompt = $this->context( $account ) . "\n\n"
			. "Task: finish an Instagram post for a product that has just been listed in the shop.\n"
			. sprintf( "Product: %s\n", (string) ( $brief['title'] ?? '' ) )
			. ( ! empty( $brief['summary'] ) ? sprintf( "About it: %s\n", (string) $brief['summary'] ) : '' )
			. ( ! empty( $brief['price'] ) ? sprintf( "Price to mention if it fits naturally: %s\n", (string) $brief['price'] ) : '' )
			. "The product image is attached.\n\n"
			. ( '' !== $code
				? sprintf(
					"1. Overlay the product code %s onto the image. Place it where it does not cover the product, in a "
						. "high-contrast, unambiguous style, large enough to read on a phone at a glance. The code is "
						. "digits: render it in Western Arabic numerals (0-9) exactly as written, keeping any leading "
						. "zeros, and do not convert it to Persian digits or spell it out. Keep the "
						. "1080x1350 px format and attach the result as a PNG.\n",
					$code
				)
				: "1. Keep the attached image as it is and re-attach it as a PNG.\n" )
			. sprintf(
				"2. Write the caption in %s. It must open with a hook, give three to five short lines of real value about "
					. "the product, and end with one unmistakable call to action: comment the number \"%s\" under "
					. "this post and the purchase link is sent straight to their direct messages. Write the code exactly "
					. "as given, in Western Arabic numerals with any leading zeros intact, on its own line, so nobody "
					. "mistypes it.\n",
				$language,
				$code
			)
			. "3. Pick 15 to 25 hashtags: a few large, several mid-sized and several niche ones that this account can "
			. "realistically rank in. No banned or spammy tags.\n"
			. "4. Write alt_text for the image.\n\n"
			. "Answer with JSON only: {\"caption\": \"...\", \"hashtags\": [\"...\"], \"alt_text\": \"...\", \"first_comment\": \"...\"}";

		return (string) apply_filters( 'igbz_manus_prompt_product_post', $prompt, $account, $brief );
	}

	/**
	 * Transcribe a voice note.
	 *
	 * The fallback path when no dedicated speech-to-text endpoint is configured. Kept blunt: the
	 * one failure mode that matters is a model that decides to summarise or tidy up what it heard,
	 * because the transcript is then fed to the listing writer as if it were the seller's own
	 * words.
	 *
	 * @param array<string,mixed> $account
	 */
	public function transcription( array $account, string $language = '' ): string {
		$language = '' !== $language ? $language : igbz()->settings()->string( 'manus.content_language', 'Persian (Farsi)' );

		$prompt = $this->context( $account ) . "\n\n"
			. "Task: transcribe the attached audio recording.\n"
			. sprintf( "The speaker is talking in %s.\n", $language )
			. "Write down exactly what is said, word for word. Do not summarise it, do not correct the grammar, do not "
			. "tidy up the phrasing and do not add anything that was not spoken. Keep numbers and product details "
			. "precisely as spoken.\n"
			. "Answer with JSON only: {\"text\": \"...\"}";

		return (string) apply_filters( 'igbz_manus_prompt_transcription', $prompt, $account, $language );
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
