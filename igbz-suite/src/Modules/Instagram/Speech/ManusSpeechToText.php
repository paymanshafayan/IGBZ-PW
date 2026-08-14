<?php
namespace IGBZ\Suite\Modules\Instagram\Speech;

use IGBZ\Suite\Modules\Instagram\Contracts\SpeechToTextInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\TranscriptionResult;
use IGBZ\Suite\Modules\Instagram\Services\ManusService;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Fallback transcription through Manus.
 *
 * Every install already has a Manus key — it is what drives the whole content pipeline — so this
 * engine needs no extra configuration and is what runs when no dedicated STT endpoint is set up.
 * The cost is latency: a Manus task is asynchronous, so this returns `pending` with a task id and
 * the intake row waits for the webhook (or the five-minute poll) to bring the transcript back.
 *
 * The audio is attached by URL, which means it must be reachable from the internet. The intake
 * controller stores voice notes in the media library precisely so that a public URL exists.
 */
final class ManusSpeechToText implements SpeechToTextInterface {

	public const ID = 'manus';

	public function __construct( private ManusService $manus, private Logger $logger ) {}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Manus (asynchronous fallback)', 'igbz-suite' );
	}

	/** Configuration is per account, so the coarse answer is the honest one here. */
	public function is_configured(): bool {
		return $this->manus->is_configured();
	}

	public function transcribe( string $path, string $language = '', array $context = [] ): TranscriptionResult {
		$account = (array) ( $context['account'] ?? [] );
		if ( ! $account ) {
			return TranscriptionResult::failure( __( 'Transcription through Manus needs an Instagram account for its API key.', 'igbz-suite' ), self::ID );
		}

		// Manus fetches the file over HTTP; a local path is of no use to it.
		$url = (string) ( $context['url'] ?? '' );
		if ( '' === $url ) {
			$this->logger->error( 'stt', 'Manus transcription needs a public URL for the audio', [ 'path' => $path ] );
			return TranscriptionResult::failure( __( 'The voice note has no public URL, so Manus cannot fetch it.', 'igbz-suite' ), self::ID );
		}

		$task_id = $this->manus->transcribe_audio( $account, $url, '' !== $language ? $language : (string) ( $context['language'] ?? '' ) );

		if ( '' === $task_id ) {
			return TranscriptionResult::failure( __( 'Manus did not accept the transcription task.', 'igbz-suite' ), self::ID );
		}

		return TranscriptionResult::pending( $task_id, self::ID );
	}
}
