<?php
namespace IGBZ\Suite\Modules\MultiTenant\Bnpl;

defined( 'ABSPATH' ) || exit;

/**
 * Credit provider behind a BNPL contract.
 *
 * Port note: the nop original shipped an ISnappPayBnplGateway / SnappPayBnplGateway pair that was
 * never registered anywhere (dead code). Here a single explicit contract is used; the built-in
 * 'internal' provider (store-funded credit) is the default and is always registered, and external
 * PSP providers can be added with the igbz_register_bnpl_providers action.
 */
interface BnplProviderInterface {

	public function id(): string;

	public function title(): string;

	public function is_configured(): bool;

	/**
	 * Ask the provider to underwrite a contract.
	 *
	 * @param array<string,mixed> $contract
	 * @return array{approved:bool,reference:string,message:string}
	 */
	public function underwrite( array $contract ): array;

	/**
	 * Notify the provider that an installment was collected.
	 *
	 * @param array<string,mixed> $installment
	 */
	public function report_payment( array $installment ): bool;

	/**
	 * Cancel / unwind a contract at the provider side.
	 */
	public function cancel( string $reference ): bool;
}
