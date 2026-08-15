<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

interface ModuleInterface {

	/** Stable module id, one of the Modules::* constants. */
	public function id(): string;

	/** Human readable module title. */
	public function title(): string;

	/** Short description rendered on the modules screen. */
	public function description(): string;

	/** Wire hooks and services. Only called when the module is enabled. */
	public function register( Plugin $plugin ): void;

	/**
	 * Self-check rows for the status screen.
	 *
	 * @return array<int,array{label:string,status:string,detail:string}>
	 */
	public function health(): array;
}
