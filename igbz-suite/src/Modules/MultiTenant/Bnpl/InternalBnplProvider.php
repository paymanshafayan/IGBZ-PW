<?php
namespace IGBZ\Suite\Modules\MultiTenant\Bnpl;

defined( 'ABSPATH' ) || exit;

/**
 * Store-funded BNPL: the merchant carries the credit itself. Underwriting is the local scoring
 * engine in BnplService, so approval here is unconditional once eligibility passed.
 */
final class InternalBnplProvider implements BnplProviderInterface {

	public function id(): string {
		return 'internal';
	}

	public function title(): string {
		return __( 'Store credit (internal)', 'igbz-suite' );
	}

	public function is_configured(): bool {
		return true;
	}

	public function underwrite( array $contract ): array {
		return [
			'approved'  => true,
			'reference' => 'internal:' . (int) ( $contract['id'] ?? 0 ),
			'message'   => '',
		];
	}

	public function report_payment( array $installment ): bool {
		return true;
	}

	public function cancel( string $reference ): bool {
		return true;
	}
}
