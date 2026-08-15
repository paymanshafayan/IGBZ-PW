<?php
namespace IGBZ\Suite\Modules\MultiTenant\Bnpl;

defined( 'ABSPATH' ) || exit;

/** Holds the available BNPL credit providers. */
final class ProviderRegistry {

	/** @var array<string,BnplProviderInterface> */
	private array $providers = [];

	public function __construct() {
		$this->add( new InternalBnplProvider() );
		/**
		 * Register extra BNPL providers.
		 *
		 * @param ProviderRegistry $registry
		 */
		do_action( 'igbz_register_bnpl_providers', $this );
	}

	public function add( BnplProviderInterface $provider ): void {
		$this->providers[ $provider->id() ] = $provider;
	}

	public function get( string $id ): BnplProviderInterface {
		return $this->providers[ $id ] ?? $this->providers['internal'];
	}

	/** @return array<string,BnplProviderInterface> */
	public function all(): array {
		return $this->providers;
	}

	public function default(): BnplProviderInterface {
		return $this->get( igbz()->settings()->string( 'bnpl.provider', 'internal' ) );
	}
}
