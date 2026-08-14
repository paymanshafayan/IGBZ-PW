<?php
declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Services\PromptBuilder;

/**
 * The prompts are the contract with Manus, so the brand context and the JSON response shape must
 * always reach the model.
 */
final class PromptBuilderTest extends TestCase {

	public function run(): void {
		igbz_test_reset_settings()->set( 'manus.content_language', 'Persian (Farsi)' );

		$builder = new PromptBuilder();
		$account = [
			'id'          => 3,
			'username'    => 'igbz.shop',
			'niche'       => 'handmade leather goods',
			'brand_voice' => 'warm, confident, no hype',
			'timezone'    => 'Asia/Tehran',
		];

		$research = $builder->trend_research( $account, 'winter collection' );
		$this->assert_contains( '@igbz.shop', $research, 'research names the account' );
		$this->assert_contains( 'handmade leather goods', $research, 'research carries the niche' );
		$this->assert_contains( 'warm, confident, no hype', $research, 'research carries the brand voice' );
		$this->assert_contains( 'Asia/Tehran', $research, 'research carries the audience timezone' );
		$this->assert_contains( 'winter collection', $research, 'research honours the focus topic' );
		$this->assert_contains( 'Persian (Farsi)', $research, 'research pins the output language' );
		$this->assert_contains( 'peak_hours', $research, 'research asks for peak hours' );
		$this->assert_contains( 'JSON', $research, 'research pins a machine-readable answer' );

		$this->assert_false(
			str_contains( $builder->trend_research( $account ), 'Focus topic' ),
			'the focus topic line is omitted when no topic is given'
		);

		$carousel = $builder->graphic_design( $account, [ 'subject' => 'winter care', 'slides' => 5 ] );
		$this->assert_contains( '5-slide carousel', $carousel, 'a multi-slide brief asks for a carousel' );
		$this->assert_contains( 'winter care', $carousel, 'the subject reaches the prompt' );
		$this->assert_contains( '1080x1350', $carousel, 'the Instagram canvas size is specified' );
		$this->assert_contains( 'RTL', $carousel, 'Persian typography is called out' );

		$single = $builder->graphic_design( $account, [ 'subject' => 'new arrival' ] );
		$this->assert_contains( 'static post graphic', $single, 'a single-slide brief asks for a static post' );

		$reel = $builder->reel( $account, [ 'subject' => 'unboxing' ] );
		$this->assert_contains( 'unboxing', $reel, 'the reel brief reaches the prompt' );

		$caption = $builder->caption( $account, [ 'subject' => 'unboxing' ] );
		$this->assert_contains( '@igbz.shop', $caption, 'the caption prompt carries the account context' );

		$publish = $builder->publish( $account, [ 'kind' => 'reel', 'caption' => 'salam' ], time() + 3600 );
		$this->assert_contains( '@igbz.shop', $publish, 'the publish prompt names the account' );

		$insights = $builder->insights( $account );
		$this->assert_contains( '@igbz.shop', $insights, 'the insights prompt names the account' );

		// A blank account must not produce a broken prompt.
		$empty = $builder->trend_research( [ 'username' => '' ] );
		$this->assert_contains( 'Instagram account:', $empty, 'an empty account still yields a usable prompt' );

		$this->registration_prompts( $builder, $account );
	}

	/**
	 * The prompts behind the phone-to-Instagram registration flow.
	 *
	 * Each one has a single instruction it exists to carry, and losing that instruction breaks
	 * the feature quietly rather than loudly — a grader that returns a bare score gives the seller
	 * nothing to fix, a listing writer that invents a price puts a wrong number in a shop, an
	 * image task that "improves" the product sells something that will not arrive.
	 *
	 * @param array<string,mixed> $account
	 */
	private function registration_prompts( PromptBuilder $builder, array $account ): void {
		igbz()->settings()->set( 'intake.quality_threshold', 65 );

		$quality = $builder->photo_quality( $account, 'a leather tote' );
		$this->assert_contains( 'background removal', $quality, 'the photo check judges background-removal suitability' );
		$this->assert_contains( '65', $quality, 'the store\'s own threshold is stated to the grader' );
		$this->assert_contains( 'actionable', $quality, 'rejection reasons are required to be actionable' );
		$this->assert_contains( 'a leather tote', $quality, 'the seller\'s hint reaches the grader' );
		$this->assert_contains( 'Persian (Farsi)', $quality, 'reasons come back in the shopkeeper\'s language' );

		$schema = $builder->photo_quality_schema();
		$this->assert_same( [ 'accept', 'reject' ], $schema['properties']['verdict']['enum'], 'the verdict is constrained to two values' );
		$this->assert_true( in_array( 'reasons', $schema['required'], true ), 'the schema forces reasons to be returned' );

		$image = $builder->product_image( $account, [ 'product' => 'leather tote' ] );
		$this->assert_contains( 'do not alter the product', $image, 'the product itself must never be restyled' );
		$this->assert_contains( 'honest photograph', $image, 'the image has to represent the real item' );
		$this->assert_contains( 'leather tote', $image, 'the detected product reaches the image prompt' );

		$copy = $builder->product_copy(
			$account,
			[ 'description' => 'hand stitched', 'category' => 'Bags', 'languages' => [ 'en', 'ar' ] ]
		);
		$this->assert_contains( 'hand stitched', $copy, 'the seller\'s own words are quoted verbatim' );
		$this->assert_contains( 'never state, guess or imply a price', $copy, 'the model is forbidden from inventing commerce fields' );
		$this->assert_contains( 'en, ar', $copy, 'the translation targets are named' );
		$this->assert_contains( 'Translate, do not re-invent', $copy, 'translations must match the original' );

		$single = $builder->product_copy( $account, [ 'description' => 'hand stitched' ] );
		$this->assert_false( str_contains( $single, 'multilingual' ), 'a single-language store gets no translation instructions' );

		$copy_schema = $builder->product_copy_schema( [ 'en' ] );
		$this->assert_true( isset( $copy_schema['properties']['translations']['properties']['en'] ), 'the schema carries a slot per language' );
		$this->assert_false(
			isset( $builder->product_copy_schema()['properties']['translations'] ),
			'no translation slot exists when the store is single-language'
		);

		$video = $builder->product_video( $account, [ 'code' => '0047', 'title' => 'Tote', 'prompt' => 'pack it for a trip' ] );
		$this->assert_contains( '0047', $video, 'the code is burned onto the video' );
		$this->assert_contains( 'type it into the comments', $video, 'the video explains why the code has to be legible' );
		$this->assert_contains( 'leading zeros', $video, 'a padded code loses its meaning if the leading zeros are dropped' );
		$this->assert_contains( 'never spell it out', $video, 'a spelled-out code cannot be typed into a comment' );
		$this->assert_contains( 'pack it for a trip', $video, 'the seller\'s brief is passed through verbatim' );

		$post = $builder->product_post( $account, [ 'code' => '0047', 'title' => 'Tote' ] );
		$this->assert_contains( 'Overlay the product code 0047', $post, 'the code is stamped onto the image' );
		$this->assert_contains( 'comment the number "0047"', $post, 'the caption asks for the exact code' );
		$this->assert_contains( 'not convert it to Persian digits', $post, 'a Persian-digit overlay would not match the typed comment' );
		$this->assert_contains( 'on its own line', $post, 'the code is isolated so nobody mistypes it' );
		$this->assert_contains( 'hashtags', $post, 'hashtags are requested' );

		$transcript = $builder->transcription( $account, 'Persian (Farsi)' );
		$this->assert_contains( 'word for word', $transcript, 'the transcript must be verbatim' );
		$this->assert_contains( 'Do not summarise it', $transcript, 'summarising a dictated description would lose detail' );
	}
}
