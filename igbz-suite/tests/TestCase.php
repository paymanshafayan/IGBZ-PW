<?php
declare( strict_types=1 );

/** A three-method assertion helper; a full framework would be more machinery than the suite needs. */
abstract class TestCase {

	public static int $passed = 0;

	/** @var string[] */
	public static array $failures = [];

	protected function assert_true( bool $condition, string $message ): void {
		$this->record( $condition, $message );
	}

	protected function assert_false( bool $condition, string $message ): void {
		$this->record( ! $condition, $message );
	}

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	protected function assert_same( $expected, $actual, string $message ): void {
		$this->record(
			$expected === $actual,
			$message . sprintf( ' (expected %s, got %s)', var_export( $expected, true ), var_export( $actual, true ) )
		);
	}

	protected function assert_contains( string $needle, string $haystack, string $message ): void {
		$this->record( str_contains( $haystack, $needle ), $message );
	}

	protected function assert_not_same( $expected, $actual, string $message ): void {
		$this->record( $expected !== $actual, $message . sprintf( ' (expected not %s, got %s)', var_export( $expected, true ), var_export( $actual, true ) ) );
	}

	protected function assert_not_contains( string $needle, string $haystack, string $message ): void {
		$this->record( ! str_contains( $haystack, $needle ), $message );
	}

	private function record( bool $ok, string $message ): void {
		if ( $ok ) {
			++self::$passed;
			return;
		}
		self::$failures[] = static::class . ': ' . $message;
	}

	abstract public function run(): void;
}
