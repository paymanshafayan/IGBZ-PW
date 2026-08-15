<?php
namespace IGBZ\Suite\Modules\Instagram\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A speech-to-text engine.
 *
 * Voice is a first-class input in the registration flow — the shopkeeper dictates the product
 * description and, later, the video brief — and the engine that turns it into text is a moving
 * target: OpenAI Whisper, a self-hosted faster-whisper, or one of the Iranian providers, each
 * with its own field names and response shape. So the plugin never names one: it talks to this
 * interface, `HttpSpeechToText` covers every vendor that accepts a multipart upload and answers
 * with JSON, and `ManusSpeechToText` is the always-available fallback.
 *
 * Add another engine by implementing this interface and registering it on the
 * `igbz_speech_to_text_engines` filter; the one named by `stt.provider` wins.
 */
interface SpeechToTextInterface {

	/** Stable identifier used by the `stt.provider` setting. */
	public function id(): string;

	/** Human-readable name for the settings screen and the health check. */
	public function title(): string;

	/** Whether this engine has everything it needs to be called. */
	public function is_configured(): bool;

	/**
	 * Transcribe an audio file that is already on local disk.
	 *
	 * @param string              $path     Absolute path to the audio file.
	 * @param string              $language BCP-47 hint, '' to let the engine decide.
	 * @param array<string,mixed> $context  Optional extras, e.g. `account` for per-tenant keys.
	 */
	public function transcribe( string $path, string $language = '', array $context = [] ): TranscriptionResult;
}
