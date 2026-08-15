<?php
namespace IGBZ\Suite\Modules\Instagram\Contracts;

defined( 'ABSPATH' ) || exit;

final class PublishResult {

	private function __construct(
		public readonly bool $success,
		public readonly string $status,
		public readonly string $external_id = '',
		public readonly string $permalink = '',
		public readonly string $error = ''
	) {}

	/** The publisher accepted the job but the work is still running (async). */
	public static function queued( string $external_id ): self {
		return new self( true, 'queued', $external_id );
	}

	public static function published( string $external_id, string $permalink = '' ): self {
		return new self( true, 'published', $external_id, $permalink );
	}

	public static function scheduled( string $external_id ): self {
		return new self( true, 'scheduled', $external_id );
	}

	public static function failure( string $error ): self {
		return new self( false, 'failed', '', '', $error );
	}
}
