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
	}
}
