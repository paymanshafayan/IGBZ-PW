<?php
namespace IGBZ\Suite\Modules\MultiTenant\Wallet;

defined( 'ABSPATH' ) || exit;

final class WalletResult {

	private function __construct(
		public readonly bool $success,
		public readonly int $entry_id = 0,
		public readonly float $balance = 0.0,
		public readonly string $error_code = '',
		public readonly string $error_message = '',
		public readonly bool $duplicate = false
	) {}

	public static function ok( int $entry_id, float $balance ): self {
		return new self( true, $entry_id, $balance );
	}

	public static function duplicate( int $entry_id, float $balance ): self {
		return new self( true, $entry_id, $balance, '', '', true );
	}

	public static function failure( string $code, string $message, float $balance = 0.0 ): self {
		return new self( false, 0, $balance, $code, $message );
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'success'   => $this->success,
			'entry_id'  => $this->entry_id,
			'balance'   => $this->balance,
			'duplicate' => $this->duplicate,
			'error'     => $this->success ? null : [ 'code' => $this->error_code, 'message' => $this->error_message ],
		];
	}
}
