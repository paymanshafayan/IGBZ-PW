<?php
/**
 * Publishing without the Graph API leaves one genuinely ambiguous outcome, and this pins it down.
 *
 * The Graph API answered a publish call synchronously with a media id: the post either existed or
 * it did not. Manus publishes through an asynchronous task instead, and a task can stop with
 * status "finished" while never handing back the post URL. The row is then almost certainly live
 * on Instagram, but nothing in the system can prove it.
 *
 * The rule these tests hold in place: such a row stays PUBLISHED (demoting it to failed would
 * invite a duplicate post), and the missing link is surfaced rather than stored silently.
 */

declare( strict_types=1 );

use IGBZ\Suite\Modules\Instagram\Services\AccountCredentials;
use IGBZ\Suite\Modules\Instagram\Services\ManusClient;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Modules\Instagram\Services\PromptBuilder;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Http;

final class PublishVerificationTest extends TestCase {

	/**
	 * Built by hand: the test bootstrap registers core services only, and mark_published() needs
	 * nothing from the Instagram module's container bindings.
	 */
	private function manus(): ManusService {
		$db     = new Db();
		$logger = igbz()->get( 'logger' );

		return new ManusService(
			$db,
			new ManusClient( new Http( $logger ), $logger ),
			new PromptBuilder(),
			$logger,
			new AccountCredentials( $db )
		);
	}

	public function run(): void {
		$this->test_permalink_is_stored_and_row_is_published();
		$this->test_missing_permalink_still_publishes();
		$this->test_missing_permalink_fires_the_unverified_action();
		$this->test_a_returned_permalink_is_not_flagged();
		$this->test_unverified_count_targets_published_rows_only();
	}

	private function test_permalink_is_stored_and_row_is_published(): void {
		igbz_test_reset_settings();
		$wpdb = $GLOBALS['wpdb'];

		$this->manus()->mark_published( 11, 'https://instagram.com/p/abc123/' );

		$write = $this->content_write( $wpdb );
		$this->assert_same( 'wp_igbz_ig_content', $write['table'], 'the content row is updated' );
		$this->assert_same( ManusService::STATUS_PUBLISHED, $write['data']['status'], 'the row is published' );
		$this->assert_same( 'https://instagram.com/p/abc123/', $write['data']['permalink'], 'the link is stored' );
	}

	/**
	 * The important half of the contract: no link is NOT a failure. Marking it failed would offer
	 * the operator a retry button on a post that is already live.
	 */
	private function test_missing_permalink_still_publishes(): void {
		igbz_test_reset_settings();
		$wpdb = $GLOBALS['wpdb'];

		$this->manus()->mark_published( 12, '' );

		// The warning log is itself a write, so last_write points at the log table by now: read the
		// content update out of the recorded writes instead of assuming it was the last one.
		$write = $this->content_write( $wpdb );

		$this->assert_same( ManusService::STATUS_PUBLISHED, $write['data']['status'], 'a missing link does not demote the row' );
		$this->assert_same( '', $write['data']['permalink'], 'the empty link is stored as empty' );
		$this->assert_true( '' !== (string) $write['data']['published_at'], 'the publish time is still recorded' );
	}

	/**
	 * The most recent write aimed at ig_content.
	 *
	 * @return array<string,mixed>
	 */
	private function content_write( $wpdb ): array {
		foreach ( array_reverse( $wpdb->writes ) as $write ) {
			if ( 'wp_igbz_ig_content' === ( $write['table'] ?? '' ) ) {
				return $write;
			}
		}

		return [ 'data' => [] ];
	}

	private function test_missing_permalink_fires_the_unverified_action(): void {
		igbz_test_reset_settings();

		$GLOBALS['igbz_test_unverified'] = [];
		add_action(
			'igbz_ig_content_published_unverified',
			static function ( $content_id ): void {
				$GLOBALS['igbz_test_unverified'][] = (int) $content_id;
			}
		);

		$this->manus()->mark_published( 13, '' );

		$this->assert_same( [ 13 ], $GLOBALS['igbz_test_unverified'], 'the unverified hook carries the content id' );
	}

	private function test_a_returned_permalink_is_not_flagged(): void {
		igbz_test_reset_settings();

		$GLOBALS['igbz_test_unverified'] = [];
		add_action(
			'igbz_ig_content_published_unverified',
			static function ( $content_id ): void {
				$GLOBALS['igbz_test_unverified'][] = (int) $content_id;
			}
		);

		$this->manus()->mark_published( 14, 'https://instagram.com/p/ok/' );

		$this->assert_same( [], $GLOBALS['igbz_test_unverified'], 'a confirmed post raises no warning' );
	}

	/** The count is derived from the rows, so a later-filled permalink clears itself. */
	private function test_unverified_count_targets_published_rows_only(): void {
		igbz_test_reset_settings();
		$wpdb = $GLOBALS['wpdb'];

		$wpdb->next_results = [ 4 ];
		$this->assert_same( 4, $this->manus()->unverified_publish_count(), 'the count comes back as an int' );

		$sql = $wpdb->last_query();
		$this->assert_contains( "permalink = ''", $sql, 'only rows without a link are counted' );
		$this->assert_contains( 'published', $sql, 'and only published ones' );

		$wpdb->next_results = [ 1 ];
		$this->manus()->unverified_publish_count( 9 );
		$this->assert_contains( 'account_id', $wpdb->last_query(), 'the count can be scoped to one account' );
	}
}
