<?php
namespace IGBZ\Suite\Modules\Instagram\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Creative side of the Instagram workflow: niche/trend research, graphic design (Canva),
 * reel production, caption + hashtag writing.
 */
interface ContentGeneratorInterface {

	public function id(): string;

	/**
	 * Start a research task for a niche.
	 *
	 * @param array<string,mixed> $account
	 * @return string Provider task id.
	 */
	public function research_trends( array $account, string $topic = '' ): string;

	/**
	 * Start a design task (static image or carousel), optionally through Canva.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 * @return string Provider task id.
	 */
	public function design_graphic( array $account, array $brief ): string;

	/**
	 * Start a short-video / reel production task.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 * @return string Provider task id.
	 */
	public function produce_reel( array $account, array $brief ): string;

	/**
	 * Start a caption + hashtag writing task.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 * @return string Provider task id.
	 */
	public function write_caption( array $account, array $brief ): string;

	/**
	 * Poll a task and return its current state plus any produced assets.
	 *
	 * The account is required because credentials are per account, not per install.
	 *
	 * @param array<string,mixed> $account
	 * @return array{status:string,messages:array<int,mixed>,attachments:array<int,array<string,mixed>>,output:array<string,mixed>}
	 */
	public function task_state( string $task_id, array $account = [] ): array;
}
