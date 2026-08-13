<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

final class PaymentVerifyResult {

	private function __construct(
		public readonly bool $success,
		public readonly string $reference_id = '',
		public readonly string $card_pan = '',
		public readonly float $fee = 0.0,
		public readonly bool $already_verified = false,
		public readonly string $error_code = '',
		public readonly string $error_message = ''
	) {}

	public static function ok( string $reference_id, string $card_pan = '', float $fee = 0.0 ): self {
		return new self( true, $reference_id, $card_pan, $fee );
	}

	public static function duplicate( string $reference_id ): self {
		return new self( true, $reference_id, '', 0.0, true );
	}

	public static function failure( string $code, string $message ): self {
		return new self( false, '', '', 0.0, false, $code, $message );
	}
}
