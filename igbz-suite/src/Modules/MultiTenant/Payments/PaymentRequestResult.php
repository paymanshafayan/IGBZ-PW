<?php
namespace IGBZ\Suite\Modules\MultiTenant\Payments;

defined( 'ABSPATH' ) || exit;

final class PaymentRequestResult {

	private function __construct(
		public readonly bool $success,
		public readonly string $authority = '',
		public readonly string $redirect_url = '',
		public readonly string $error_code = '',
		public readonly string $error_message = ''
	) {}

	public static function ok( string $authority, string $redirect_url ): self {
		return new self( true, $authority, $redirect_url );
	}

	public static function failure( string $code, string $message ): self {
		return new self( false, '', '', $code, $message );
	}
}
