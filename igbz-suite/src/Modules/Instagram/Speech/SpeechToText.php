<?php
namespace IGBZ\Suite\Modules\Instagram\Speech;

use IGBZ\Suite\Modules\Instagram\Contracts\SpeechToTextInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\TranscriptionResult;
use IGBZ\Suite\Support\Logger;
use IGBZ\Suite\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the speech-to-text engine and falls back when it is not usable.
 *
 * The rule is deliberately simple: use the configured engine, and if it is not configured (or it
 * fails outright) use Manus, which every install has. A dictated product description is the only
 * thing standing between the shopkeeper and a finished listing, so "no engine available" must
 * never be an outcome the user has to think about.
 *
 * A failure of the primary engine is retried on the fallback rather than surfaced, but it is
 * logged at error level — a Whisper endpoint that has quietly stopped answering should show up in
 * the log even though the user never noticed.
 */
final class SpeechToText {

	/** @var array<string,SpeechToTextInterface> */
	private array $engines = [];

	public function __construct(
		private Settings $settings,
		private Logger $logger,
		HttpSpeechToText $http,
		ManusSpeechToText $manus
	) {
		$this->engines[ $http->id() ]  = $http;
		$this->engines[ $manus->id() ] = $manus;

		/**
		 * Register another speech-to-text engine.
		 *
		 * @param array<string,SpeechToTextInterface> $engines
		 */
		foreach ( (array) apply_filters( 'igbz_speech_to_text_engines', [] ) as $engine ) {
			if ( $engine instanceof SpeechToTextInterface ) {
				$this->engines[ $engine->id() ] = $engine;
			}
		}
	}

	/** @return array<string,SpeechToTextInterface> */
	public function engines(): array {
		return $this->engines;
	}

	public function engine( string $id ): ?SpeechToTextInterface {
		return $this->engines[ $id ] ?? null;
	}

	/** The engine the site asked for, whether or not it is usable. */
	public function preferred(): SpeechToTextInterface {
		$id = $this->settings->string( 'stt.provider', HttpSpeechToText::ID );
		return $this->engines[ $id ] ?? $this->engines[ ManusSpeechToText::ID ];
	}

	public function fallback(): SpeechToTextInterface {
		return $this->engines[ ManusSpeechToText::ID ];
	}

	/**
	 * Transcribe, falling back to Manus when the preferred engine cannot do it.
	 *
	 * @param array<string,mixed> $context
	 */
	public function transcribe( string $path, string $language = '', array $context = [] ): TranscriptionResult {
		$primary = $this->preferred();

		if ( $primary->is_configured() ) {
			$result = $primary->transcribe( $path, $language, $context );
			if ( $result->ok || $result->is_pending() ) {
				return $result;
			}

			$this->logger->error(
				'stt',
				'The primary speech-to-text engine failed; falling back to Manus',
				[ 'engine' => $primary->id(), 'error' => $result->error ]
			);
		}

		$fallback = $this->fallback();
		if ( $fallback->id() === $primary->id() ) {
			// Already tried, and there is nothing else to try.
			return TranscriptionResult::failure( __( 'No speech-to-text engine is available.', 'igbz-suite' ), $primary->id() );
		}

		return $fallback->transcribe( $path, $language, $context );
	}
}
