<?php
namespace IGBZ\Suite\Modules\MultiTenant\Lms;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Courses, lessons, enrollment, progress, quizzes and certificates.
 * Enrollment is granted automatically when a WooCommerce order containing a linked product completes.
 */
final class LmsService {

	public function __construct( private Db $db ) {}

	// -------------------------------------------------------------- courses

	/** @return array<string,mixed>|null */
	public function course( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'courses' ) . ' WHERE id = %d', $id );
	}

	/** @return array<string,mixed>|null */
	public function course_by_slug( string $slug, int $tenant_id = 0 ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'courses' ) . ' WHERE slug = %s AND tenant_id = %d',
			$slug,
			$tenant_id
		);
	}

	/** @return array<string,mixed>|null */
	public function course_by_product( int $product_id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'courses' ) . ' WHERE product_id = %d', $product_id );
	}

	/**
	 * @param array{tenant_id?:int,published?:bool,limit?:int,offset?:int,search?:string} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function courses( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];
		if ( isset( $args['tenant_id'] ) ) {
			$where[]  = 'tenant_id = %d';
			$params[] = (int) $args['tenant_id'];
		}
		if ( ! empty( $args['published'] ) ) {
			$where[] = 'is_published = 1';
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $this->db->wpdb()->esc_like( (string) $args['search'] ) . '%';
		}
		$params[] = (int) ( $args['limit'] ?? 20 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'courses' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...$params
		);
	}

	/** @param array<string,mixed> $data */
	public function save_course( array $data, int $id = 0 ): int {
		$now     = current_time( 'mysql', true );
		$payload = [
			'tenant_id'           => (int) ( $data['tenant_id'] ?? 0 ),
			'product_id'          => (int) ( $data['product_id'] ?? 0 ),
			'title'               => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'slug'                => sanitize_title( (string) ( $data['slug'] ?? $data['title'] ?? 'course' ) ),
			'summary'             => sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ),
			'description'         => wp_kses_post( (string) ( $data['description'] ?? '' ) ),
			'cover_url'           => esc_url_raw( (string) ( $data['cover_url'] ?? '' ) ),
			'level'               => in_array( $data['level'] ?? 'beginner', [ 'beginner', 'intermediate', 'advanced' ], true ) ? (string) $data['level'] : 'beginner',
			'duration_minutes'    => (int) ( $data['duration_minutes'] ?? 0 ),
			'instructor_user_id'  => (int) ( $data['instructor_user_id'] ?? get_current_user_id() ),
			'certificate_enabled' => empty( $data['certificate_enabled'] ) ? 0 : 1,
			'pass_score'          => (int) ( $data['pass_score'] ?? 60 ),
			'is_published'        => empty( $data['is_published'] ) ? 0 : 1,
			'updated_at'          => $now,
		];

		if ( $id > 0 ) {
			$this->db->update( 'courses', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = $now;
		return $this->db->insert( 'courses', $payload );
	}

	public function delete_course( int $id ): bool {
		$this->db->delete( 'lessons', [ 'course_id' => $id ] );
		$this->db->delete( 'quizzes', [ 'course_id' => $id ] );
		$this->db->delete( 'enrollments', [ 'course_id' => $id ] );
		return $this->db->delete( 'courses', [ 'id' => $id ] ) > 0;
	}

	// -------------------------------------------------------------- lessons

	/** @return array<int,array<string,mixed>> */
	public function lessons( int $course_id ): array {
		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'lessons' ) . ' WHERE course_id = %d ORDER BY sort_order, id',
			$course_id
		);
	}

	/** @return array<string,mixed>|null */
	public function lesson( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'lessons' ) . ' WHERE id = %d', $id );
	}

	/** @param array<string,mixed> $data */
	public function save_lesson( array $data, int $id = 0 ): int {
		$payload = [
			'course_id'        => (int) ( $data['course_id'] ?? 0 ),
			'tenant_id'        => (int) ( $data['tenant_id'] ?? 0 ),
			'title'            => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'content'          => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
			'video_key'        => sanitize_text_field( (string) ( $data['video_key'] ?? '' ) ),
			'attachment_url'   => esc_url_raw( (string) ( $data['attachment_url'] ?? '' ) ),
			'duration_minutes' => (int) ( $data['duration_minutes'] ?? 0 ),
			'sort_order'       => (int) ( $data['sort_order'] ?? 0 ),
			'is_free_preview'  => empty( $data['is_free_preview'] ) ? 0 : 1,
		];

		if ( $id > 0 ) {
			$this->db->update( 'lessons', $payload, [ 'id' => $id ] );
			return $id;
		}
		return $this->db->insert( 'lessons', $payload );
	}

	public function delete_lesson( int $id ): bool {
		return $this->db->delete( 'lessons', [ 'id' => $id ] ) > 0;
	}

	// ------------------------------------------------------------ enrollment

	/** @return array<string,mixed>|null */
	public function enrollment( int $course_id, int $user_id ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'enrollments' ) . ' WHERE course_id = %d AND user_id = %d',
			$course_id,
			$user_id
		);
	}

	public function is_enrolled( int $course_id, int $user_id ): bool {
		$enrollment = $this->enrollment( $course_id, $user_id );
		if ( ! $enrollment ) {
			return false;
		}
		if ( ! empty( $enrollment['expires_at'] ) && strtotime( (string) $enrollment['expires_at'] ) < time() ) {
			return false;
		}
		return true;
	}

	public function enroll( int $course_id, int $user_id, int $order_id = 0, ?int $access_days = null ): int {
		$existing = $this->enrollment( $course_id, $user_id );
		if ( $existing ) {
			return (int) $existing['id'];
		}
		$course = $this->course( $course_id );
		if ( ! $course ) {
			return 0;
		}

		$id = $this->db->insert(
			'enrollments',
			[
				'tenant_id'  => (int) $course['tenant_id'],
				'course_id'  => $course_id,
				'user_id'    => $user_id,
				'order_id'   => $order_id,
				'expires_at' => $access_days ? gmdate( 'Y-m-d H:i:s', time() + $access_days * DAY_IN_SECONDS ) : null,
				'created_at' => current_time( 'mysql', true ),
			]
		);

		do_action( 'igbz_lms_enrolled', $id, $course_id, $user_id );
		return $id;
	}

	/** Grant course access for every LMS-linked product in a completed order. */
	public function enroll_from_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = $item->get_product_id();
			$course     = $this->course_by_product( $product_id );
			if ( $course ) {
				$this->enroll( (int) $course['id'], $user_id, $order_id );
			}
		}
	}

	/** @return array<int,array<string,mixed>> */
	public function enrollments_for_user( int $user_id, int $tenant_id = 0 ): array {
		return $this->db->results(
			'SELECT e.*, c.title, c.slug, c.cover_url
			 FROM ' . $this->db->table( 'enrollments' ) . ' e
			 INNER JOIN ' . $this->db->table( 'courses' ) . ' c ON c.id = e.course_id
			 WHERE e.user_id = %d AND e.tenant_id = %d ORDER BY e.id DESC',
			$user_id,
			$tenant_id
		);
	}

	// -------------------------------------------------------------- progress

	public function record_progress( int $enrollment_id, int $lesson_id, int $seconds_watched, bool $completed = false ): void {
		$enrollment = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'enrollments' ) . ' WHERE id = %d', $enrollment_id );
		if ( ! $enrollment ) {
			return;
		}

		$table = $this->db->table( 'lesson_progress' );
		$this->db->query(
			"INSERT INTO {$table} (enrollment_id, lesson_id, user_id, seconds_watched, completed, completed_at, updated_at)
			 VALUES (%d, %d, %d, %d, %d, %s, %s)
			 ON DUPLICATE KEY UPDATE
				seconds_watched = GREATEST(seconds_watched, VALUES(seconds_watched)),
				completed = GREATEST(completed, VALUES(completed)),
				completed_at = COALESCE(completed_at, VALUES(completed_at)),
				updated_at = VALUES(updated_at)",
			$enrollment_id,
			$lesson_id,
			(int) $enrollment['user_id'],
			$seconds_watched,
			$completed ? 1 : 0,
			$completed ? current_time( 'mysql', true ) : null,
			current_time( 'mysql', true )
		);

		$this->refresh_progress( $enrollment_id );
	}

	public function refresh_progress( int $enrollment_id ): int {
		$enrollment = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'enrollments' ) . ' WHERE id = %d', $enrollment_id );
		if ( ! $enrollment ) {
			return 0;
		}
		$total = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'lessons' ) . ' WHERE course_id = %d',
			(int) $enrollment['course_id']
		);
		if ( 0 === $total ) {
			return 0;
		}
		$done = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'lesson_progress' ) . ' WHERE enrollment_id = %d AND completed = 1',
			$enrollment_id
		);

		$percent = (int) floor( $done / $total * 100 );
		$data    = [ 'progress_percent' => $percent ];

		if ( $percent >= 100 && empty( $enrollment['completed_at'] ) ) {
			$data['completed_at'] = current_time( 'mysql', true );
			$course               = $this->course( (int) $enrollment['course_id'] );
			if ( $course && ! empty( $course['certificate_enabled'] ) ) {
				$data['certificate_code'] = $this->certificate_code( $enrollment_id );
			}
			do_action( 'igbz_lms_course_completed', $enrollment_id, (int) $enrollment['user_id'], (int) $enrollment['course_id'] );
		}

		$this->db->update( 'enrollments', $data, [ 'id' => $enrollment_id ] );
		return $percent;
	}

	private function certificate_code( int $enrollment_id ): string {
		return strtoupper( 'IGBZ-' . substr( hash( 'sha256', $enrollment_id . '|' . microtime( true ) ), 0, 12 ) );
	}

	// ------------------------------------------------------------ protected video

	/**
	 * Signed, expiring video URL. The HMAC secret is generated at install time, never hardcoded.
	 */
	public function signed_video_url( string $video_key, int $user_id, ?int $ttl = null ): string {
		$ttl     = $ttl ?? igbz()->settings()->int( 'lms.video_link_ttl', 7200 );
		$expires = time() + $ttl;
		$secret  = igbz()->settings()->required( 'lms.video_hmac_secret' );
		$payload = $video_key . '|' . $user_id . '|' . $expires;

		return add_query_arg(
			[
				'igbz_video' => rawurlencode( $video_key ),
				'u'          => $user_id,
				'e'          => $expires,
				's'          => Crypto::hmac( $payload, $secret ),
			],
			home_url( '/' )
		);
	}

	public function verify_video_signature( string $video_key, int $user_id, int $expires, string $signature ): bool {
		if ( $expires < time() ) {
			return false;
		}
		$secret = igbz()->settings()->required( 'lms.video_hmac_secret' );
		return Crypto::hmac_equals( Crypto::hmac( $video_key . '|' . $user_id . '|' . $expires, $secret ), $signature );
	}

	// -------------------------------------------------------------- quizzes

	/** @return array<string,mixed>|null */
	public function quiz( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'quizzes' ) . ' WHERE id = %d', $id );
	}

	/** @param array<string,mixed> $data */
	public function save_quiz( array $data, int $id = 0 ): int {
		$payload = [
			'course_id'          => (int) ( $data['course_id'] ?? 0 ),
			'lesson_id'          => (int) ( $data['lesson_id'] ?? 0 ),
			'tenant_id'          => (int) ( $data['tenant_id'] ?? 0 ),
			'title'              => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'questions'          => wp_json_encode( (array) ( $data['questions'] ?? [] ) ),
			'pass_score'         => (int) ( $data['pass_score'] ?? 60 ),
			'max_attempts'       => (int) ( $data['max_attempts'] ?? 3 ),
			'time_limit_minutes' => (int) ( $data['time_limit_minutes'] ?? 0 ),
		];
		if ( $id > 0 ) {
			$this->db->update( 'quizzes', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = current_time( 'mysql', true );
		return $this->db->insert( 'quizzes', $payload );
	}

	/**
	 * Grade a quiz submission server-side. Correct answers are never exposed to the client.
	 *
	 * @param array<int|string,mixed> $answers
	 * @return array{score:int,passed:bool,attempt_id:int,remaining_attempts:int}
	 */
	public function submit_quiz( int $quiz_id, int $user_id, array $answers ): array {
		$quiz = $this->quiz( $quiz_id );
		if ( ! $quiz ) {
			throw new \RuntimeException( __( 'Quiz not found.', 'igbz-suite' ) );
		}

		$used = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'quiz_attempts' ) . ' WHERE quiz_id = %d AND user_id = %d',
			$quiz_id,
			$user_id
		);
		$max = (int) $quiz['max_attempts'];
		if ( $max > 0 && $used >= $max ) {
			throw new \RuntimeException( __( 'You have used all your attempts for this quiz.', 'igbz-suite' ) );
		}

		$questions = json_decode( (string) $quiz['questions'], true );
		$questions = is_array( $questions ) ? $questions : [];
		$total     = count( $questions );
		$correct   = 0;

		foreach ( $questions as $index => $question ) {
			$key      = (string) ( $question['id'] ?? $index );
			$given    = $answers[ $key ] ?? ( $answers[ $index ] ?? null );
			$expected = $question['answer'] ?? null;
			if ( is_array( $expected ) ) {
				$given_set    = array_map( 'strval', (array) $given );
				$expected_set = array_map( 'strval', $expected );
				sort( $given_set );
				sort( $expected_set );
				if ( $given_set === $expected_set ) {
					$correct++;
				}
			} elseif ( null !== $given && (string) $given === (string) $expected ) {
				$correct++;
			}
		}

		$score  = $total > 0 ? (int) round( $correct / $total * 100 ) : 0;
		$passed = $score >= (int) $quiz['pass_score'];

		$attempt_id = $this->db->insert(
			'quiz_attempts',
			[
				'quiz_id'     => $quiz_id,
				'user_id'     => $user_id,
				'tenant_id'   => (int) $quiz['tenant_id'],
				'answers'     => wp_json_encode( $answers ),
				'score'       => $score,
				'passed'      => $passed ? 1 : 0,
				'started_at'  => current_time( 'mysql', true ),
				'finished_at' => current_time( 'mysql', true ),
			]
		);

		do_action( 'igbz_lms_quiz_submitted', $attempt_id, $quiz_id, $user_id, $score, $passed );

		return [
			'score'              => $score,
			'passed'             => $passed,
			'attempt_id'         => $attempt_id,
			'remaining_attempts' => $max > 0 ? max( 0, $max - $used - 1 ) : -1,
		];
	}
}
