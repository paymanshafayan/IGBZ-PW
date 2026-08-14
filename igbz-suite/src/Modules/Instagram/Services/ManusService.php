<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Contracts\ContentGeneratorInterface;
use IGBZ\Suite\Modules\Instagram\Contracts\PublishResult;
use IGBZ\Suite\Modules\Instagram\Contracts\PublisherInterface;
use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Manus-powered Instagram content engine.
 *
 * This is the replacement for the Instagram Graph API integration of the nopCommerce original.
 * Manus performs the whole workflow as an autonomous agent:
 *   research -> design (Canva) / reel production -> caption + hashtags -> schedule -> publish.
 * No asset is ever downloaded and re-uploaded manually.
 *
 * Every call is asynchronous: we store the returned task id on the content row and a cron worker
 * (ContentScheduler) reconciles the state, or the Manus webhook pushes it to us.
 */
final class ManusService implements ContentGeneratorInterface, PublisherInterface {

	public const KIND_POST     = 'post';
	public const KIND_CAROUSEL = 'carousel';
	public const KIND_STORY    = 'story';
	public const KIND_REEL     = 'reel';

	public const STATUS_DRAFT      = 'draft';
	public const STATUS_GENERATING = 'generating';
	public const STATUS_READY      = 'ready';
	public const STATUS_SCHEDULED  = 'scheduled';
	public const STATUS_PUBLISHING = 'publishing';
	public const STATUS_PUBLISHED  = 'published';
	public const STATUS_FAILED     = 'failed';

	public function __construct(
		private Db $db,
		private ManusClient $client,
		private PromptBuilder $prompts,
		private Logger $logger,
		private AccountCredentials $credentials
	) {}

	/**
	 * The Manus client bound to one account's own key.
	 *
	 * @param array<string,mixed> $account
	 */
	public function client_for( array $account ): ManusClient {
		return $this->client->for_key( $this->credentials->key( $account, AccountCredentials::SERVICE_MANUS ) );
	}

	/** @param array<string,mixed> $account */
	public function account_is_configured( array $account ): bool {
		return $this->credentials->has_key( $account, AccountCredentials::SERVICE_MANUS );
	}

	public function credentials(): AccountCredentials {
		return $this->credentials;
	}

	public function id(): string {
		return 'manus';
	}

	public function title(): string {
		return __( 'Manus', 'igbz-suite' );
	}

	/**
	 * Whether *any* account on this install can reach Manus. Credentials are per account, so this
	 * is only a coarse health signal for the status screen -- use account_is_configured() before
	 * acting on a specific account.
	 */
	public function is_configured(): bool {
		$configured = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_accounts' ) . "
			 WHERE is_active = 1 AND manus_api_key IS NOT NULL AND manus_api_key <> ''"
		);
		return $configured > 0 || $this->credentials->trial_available();
	}

	public function supports( string $kind ): bool {
		return in_array( $kind, [ self::KIND_POST, self::KIND_CAROUSEL, self::KIND_STORY, self::KIND_REEL ], true );
	}

	/**
	 * The unbound client. It carries no key, so callers must bind one with for_key(); prefer
	 * client_for( $account ).
	 */
	public function client(): ManusClient {
		return $this->client;
	}

	// ------------------------------------------------------------- accounts

	/** @return array<string,mixed>|null */
	public function account( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE id = %d', $id );
	}

	/** @return array<int,array<string,mixed>> */
	public function accounts( int $tenant_id = 0, bool $active_only = true ): array {
		$sql = 'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE tenant_id = %d';
		if ( $active_only ) {
			$sql .= ' AND is_active = 1';
		}
		return $this->db->results( $sql . ' ORDER BY id', $tenant_id );
	}

	/**
	 * Every account on the install, ignoring tenancy. For site-wide health and cron sweeps only --
	 * anything user-facing must go through accounts() so it stays scoped to one tenant.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_accounts( bool $active_only = true ): array {
		$sql = 'SELECT * FROM ' . $this->db->table( 'ig_accounts' );
		if ( $active_only ) {
			$sql .= ' WHERE is_active = 1';
		}
		return $this->db->results( $sql . ' ORDER BY id' );
	}

	/** @param array<string,mixed> $data */
	public function save_account( array $data, int $id = 0 ): int {
		$now     = current_time( 'mysql', true );
		$payload = [
			'tenant_id'        => (int) ( $data['tenant_id'] ?? 0 ),
			'username'         => ltrim( sanitize_text_field( (string) ( $data['username'] ?? '' ) ), '@' ),
			'display_name'     => sanitize_text_field( (string) ( $data['display_name'] ?? '' ) ),
			'manus_project_id' => sanitize_text_field( (string) ( $data['manus_project_id'] ?? '' ) ),
			'manychat_page_id' => sanitize_text_field( (string) ( $data['manychat_page_id'] ?? '' ) ),
			'timezone'         => sanitize_text_field( (string) ( $data['timezone'] ?? wp_timezone_string() ) ),
			'niche'            => sanitize_text_field( (string) ( $data['niche'] ?? '' ) ),
			'brand_voice'      => sanitize_textarea_field( (string) ( $data['brand_voice'] ?? '' ) ),
			'peak_hours'       => sanitize_text_field( (string) ( $data['peak_hours'] ?? '' ) ),
			'is_active'        => empty( $data['is_active'] ) ? 0 : 1,
			'updated_at'       => $now,
		];

		if ( isset( $data['credential_mode'] ) ) {
			$payload['credential_mode'] = AccountCredentials::MODE_TRIAL === $data['credential_mode']
				? AccountCredentials::MODE_TRIAL
				: AccountCredentials::MODE_OWN;
		}

		// A key is only written when a new value was actually typed. The edit form renders stored
		// keys as a mask, so an untouched field must leave the ciphertext alone.
		foreach ( [ 'manus_api_key', 'manychat_api_key' ] as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = trim( (string) $data[ $field ] );
			if ( Crypto::MASK === $value ) {
				continue;
			}
			$payload[ $field ] = '' === $value ? null : $this->credentials->encrypt_key( $value );
		}

		if ( $id > 0 ) {
			$this->db->update( 'ig_accounts', $payload, [ 'id' => $id ] );
			$this->after_account_saved( $id, $payload );
			return $id;
		}

		$payload['created_at'] = $now;
		$new_id                = $this->db->insert( 'ig_accounts', $payload );
		if ( $new_id > 0 ) {
			$this->after_account_saved( $new_id, $payload );
		}
		return $new_id;
	}

	/**
	 * Give a freshly saved account the pieces it cannot work without: its own webhook tokens, and
	 * a trial clock when it is running on the shared key.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function after_account_saved( int $id, array $payload ): void {
		$account = $this->account( $id );
		if ( ! $account ) {
			return;
		}

		$this->credentials->webhook_token( $account, AccountCredentials::SERVICE_MANUS );
		$this->credentials->webhook_token( $account, AccountCredentials::SERVICE_MANYCHAT );

		if ( AccountCredentials::MODE_TRIAL === $this->credentials->mode( $account ) ) {
			$this->credentials->start_trial( $id );
		}
	}

	public function delete_account( int $id ): bool {
		return $this->db->delete( 'ig_accounts', [ 'id' => $id ] ) > 0;
	}

	// -------------------------------------------------------------- content

	/** @return array<string,mixed>|null */
	public function content( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_content' ) . ' WHERE id = %d', $id );
	}

	/**
	 * @param array{account_id?:int,tenant_id?:int,status?:string,limit?:int,offset?:int} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function contents( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];
		foreach ( [ 'account_id', 'tenant_id' ] as $column ) {
			if ( isset( $args[ $column ] ) ) {
				$where[]  = $column . ' = %d';
				$params[] = (int) $args[ $column ];
			}
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		$params[] = (int) ( $args['limit'] ?? 50 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_content' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
			...$params
		);
	}

	/** @param array<string,mixed> $data */
	public function save_content( array $data, int $id = 0 ): int {
		$now     = current_time( 'mysql', true );
		$payload = [
			'tenant_id'     => (int) ( $data['tenant_id'] ?? 0 ),
			'account_id'    => (int) ( $data['account_id'] ?? 0 ),
			'kind'          => $this->supports( (string) ( $data['kind'] ?? '' ) ) ? (string) $data['kind'] : self::KIND_POST,
			'title'         => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'brief'         => wp_json_encode( (array) ( $data['brief'] ?? [] ) ),
			'caption'       => (string) ( $data['caption'] ?? '' ),
			'hashtags'      => wp_json_encode( (array) ( $data['hashtags'] ?? [] ) ),
			'media'         => wp_json_encode( (array) ( $data['media'] ?? [] ) ),
			'product_id'    => (int) ( $data['product_id'] ?? 0 ),
			'funnel_id'     => (int) ( $data['funnel_id'] ?? 0 ),
			'provider'      => 'manus',
			'status'        => (string) ( $data['status'] ?? self::STATUS_DRAFT ),
			'scheduled_for' => ! empty( $data['scheduled_for'] ) ? (string) $data['scheduled_for'] : null,
			'updated_at'    => $now,
		];

		if ( $id > 0 ) {
			$this->db->update( 'ig_content', $payload, [ 'id' => $id ] );
			return $id;
		}
		$payload['created_at'] = $now;
		return $this->db->insert( 'ig_content', $payload );
	}

	public function delete_content( int $id ): bool {
		return $this->db->delete( 'ig_content', [ 'id' => $id ] ) > 0;
	}

	// ------------------------------------------------------- generator side

	public function research_trends( array $account, string $topic = '' ): string {
		return $this->dispatch(
			$this->prompts->trend_research( $account, $topic ),
			$account,
			sprintf( 'Trend research: @%s', (string) ( $account['username'] ?? '' ) )
		);
	}

	public function design_graphic( array $account, array $brief ): string {
		return $this->dispatch(
			$this->prompts->graphic_design( $account, $brief ),
			$account,
			sprintf( 'Design: %s', (string) ( $brief['subject'] ?? '' ) ),
			igbz()->settings()->bool( 'manus.use_canva', true ) ? [ 'canva' ] : []
		);
	}

	public function produce_reel( array $account, array $brief ): string {
		return $this->dispatch(
			$this->prompts->reel( $account, $brief ),
			$account,
			sprintf( 'Reel: %s', (string) ( $brief['subject'] ?? '' ) )
		);
	}

	public function write_caption( array $account, array $brief ): string {
		return $this->dispatch(
			$this->prompts->caption( $account, $brief ),
			$account,
			sprintf( 'Caption: %s', (string) ( $brief['subject'] ?? '' ) )
		);
	}

	/**
	 * @param array<string,mixed> $account
	 * @param array<int,string>   $connectors
	 */
	private function dispatch( string $prompt, array $account, string $title, array $connectors = [] ): string {
		if ( ! $this->account_is_configured( $account ) ) {
			$reason = $this->credentials->trial_blocked_reason( $account );
			$this->logger->error(
				'manus',
				'Task creation skipped: no usable Manus key for this account',
				[ 'account_id' => (int) ( $account['id'] ?? 0 ), 'reason' => $reason ]
			);
			return '';
		}

		$result = $this->client_for( $account )->create_task(
			$prompt,
			[
				'project_id' => (string) ( $account['manus_project_id'] ?? '' ),
				'title'      => $title,
				'connectors' => $connectors,
			]
		);

		if ( ! $result['ok'] ) {
			$this->logger->error( 'manus', 'Task creation failed', [ 'title' => $title, 'error' => $result['error'] ] );
			return '';
		}

		// Only a task that really reached Manus on the shared key costs the tenant trial quota.
		$this->credentials->consume_trial_task( $account );

		$this->logger->info( 'manus', 'Task created', [ 'task_id' => $result['task_id'], 'title' => $title ] );
		return $result['task_id'];
	}

	/**
	 * Kick off the full creative pipeline for one content row.
	 */
	public function generate( int $content_id ): bool {
		$content = $this->content( $content_id );
		if ( ! $content ) {
			return false;
		}
		$account = $this->account( (int) $content['account_id'] );
		if ( ! $account ) {
			return false;
		}

		$brief = json_decode( (string) $content['brief'], true );
		$brief = is_array( $brief ) ? $brief : [];
		$brief['subject'] = $brief['subject'] ?? (string) $content['title'];
		if ( (int) $content['product_id'] > 0 ) {
			$brief['product_url'] = get_permalink( (int) $content['product_id'] ) ?: '';
		}
		if ( (int) $content['funnel_id'] > 0 ) {
			$keyword = $this->db->scalar(
				'SELECT keyword FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE id = %d',
				(int) $content['funnel_id']
			);
			if ( $keyword ) {
				$brief['keyword'] = (string) $keyword;
			}
		}

		$task_id = match ( (string) $content['kind'] ) {
			self::KIND_REEL, self::KIND_STORY => $this->produce_reel( $account, $brief ),
			self::KIND_CAROUSEL               => $this->design_graphic( $account, $brief + [ 'slides' => (int) ( $brief['slides'] ?? 5 ) ] ),
			default                           => $this->design_graphic( $account, $brief ),
		};

		if ( '' === $task_id ) {
			$this->fail( $content_id, __( 'Manus rejected the generation task.', 'igbz-suite' ) );
			return false;
		}

		$this->db->update(
			'ig_content',
			[
				'provider_task_id' => $task_id,
				'provider_status'  => ManusClient::STATUS_RUNNING,
				'status'           => self::STATUS_GENERATING,
				'last_error'       => '',
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ 'id' => $content_id ]
		);

		do_action( 'igbz_ig_content_generating', $content_id, $task_id );
		return true;
	}

	/**
	 * Poll a generating row and absorb the produced assets.
	 */
	public function sync_generation( int $content_id ): string {
		$content = $this->content( $content_id );
		if ( ! $content || '' === (string) $content['provider_task_id'] ) {
			return self::STATUS_FAILED;
		}

		$account = $this->account( (int) $content['account_id'] );
		if ( ! $account ) {
			return self::STATUS_FAILED;
		}

		$state = $this->client_for( $account )->task_state( (string) $content['provider_task_id'] );

		if ( ManusClient::STATUS_ERROR === $state['status'] ) {
			$this->fail( $content_id, __( 'The Manus task ended with an error.', 'igbz-suite' ) );
			return self::STATUS_FAILED;
		}
		if ( ManusClient::STATUS_STOPPED !== $state['status'] ) {
			return self::STATUS_GENERATING;
		}
		if ( 'ask' === $state['stop_reason'] ) {
			$this->fail( $content_id, __( 'Manus is waiting for a human answer on this task.', 'igbz-suite' ) );
			return self::STATUS_FAILED;
		}

		$this->absorb_result( $content_id, $state );
		return self::STATUS_READY;
	}

	/**
	 * @param array{status:string,stop_reason:string,attachments:array<int,array<string,mixed>>,text:string} $state
	 */
	public function absorb_result( int $content_id, array $state ): void {
		$content = $this->content( $content_id );
		if ( ! $content ) {
			return;
		}

		$media = [];
		foreach ( $state['attachments'] as $attachment ) {
			$name = strtolower( (string) $attachment['file_name'] );
			if ( str_ends_with( $name, '.json' ) ) {
				continue;
			}
			$media[] = [
				'url'  => (string) $attachment['url'],
				'name' => (string) $attachment['file_name'],
				'type' => str_ends_with( $name, '.mp4' ) ? 'video' : 'image',
			];
		}

		$parsed   = $this->parse_json_block( $state['text'] );
		$caption  = (string) ( $parsed['caption'] ?? $content['caption'] );
		$hashtags = (array) ( $parsed['hashtags'] ?? json_decode( (string) $content['hashtags'], true ) ?: [] );

		$this->db->update(
			'ig_content',
			[
				'media'           => wp_json_encode( $media ?: json_decode( (string) $content['media'], true ) ?: [] ),
				'caption'         => $caption,
				'hashtags'        => wp_json_encode( array_values( array_map( 'strval', $hashtags ) ) ),
				'provider_status' => ManusClient::STATUS_STOPPED,
				'status'          => self::STATUS_READY,
				'updated_at'      => current_time( 'mysql', true ),
			],
			[ 'id' => $content_id ]
		);

		do_action( 'igbz_ig_content_ready', $content_id, $media );
	}

	/** @return array<string,mixed> */
	public function parse_json_block( string $text ): array {
		if ( '' === $text ) {
			return [];
		}
		if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $matches ) ) {
			$text = $matches[1];
		} elseif ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
			$text = $matches[0];
		}
		$decoded = json_decode( $text, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	// ------------------------------------------------------- publisher side

	public function publish( array $content ): PublishResult {
		$account = $this->account( (int) $content['account_id'] );
		if ( ! $account ) {
			return PublishResult::failure( __( 'The Instagram account no longer exists.', 'igbz-suite' ) );
		}

		$task_id = $this->dispatch(
			$this->prompts->publish( $account, $content, 0 ),
			$account,
			sprintf( 'Publish %s: %s', (string) $content['kind'], (string) $content['title'] )
		);

		return '' === $task_id
			? PublishResult::failure( __( 'Manus rejected the publish task.', 'igbz-suite' ) )
			: PublishResult::queued( $task_id );
	}

	public function schedule( array $content, int $timestamp ): PublishResult {
		$account = $this->account( (int) $content['account_id'] );
		if ( ! $account ) {
			return PublishResult::failure( __( 'The Instagram account no longer exists.', 'igbz-suite' ) );
		}

		$task_id = $this->dispatch(
			$this->prompts->publish( $account, $content, $timestamp ),
			$account,
			sprintf( 'Schedule %s: %s', (string) $content['kind'], (string) $content['title'] )
		);

		return '' === $task_id
			? PublishResult::failure( __( 'Manus rejected the scheduling task.', 'igbz-suite' ) )
			: PublishResult::scheduled( $task_id );
	}

	public function mark_published( int $content_id, string $permalink ): void {
		$this->db->update(
			'ig_content',
			[
				'status'       => self::STATUS_PUBLISHED,
				'permalink'    => esc_url_raw( $permalink ),
				'published_at' => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			],
			[ 'id' => $content_id ]
		);
		do_action( 'igbz_ig_content_published', $content_id, $permalink );
	}

	public function fail( int $content_id, string $error ): void {
		$this->db->query(
			'UPDATE ' . $this->db->table( 'ig_content' ) . '
			 SET status = %s, last_error = %s, retry_count = retry_count + 1, updated_at = %s
			 WHERE id = %d',
			self::STATUS_FAILED,
			mb_substr( $error, 0, 500 ),
			current_time( 'mysql', true ),
			$content_id
		);
		$this->logger->error( 'manus', 'Content failed', [ 'content_id' => $content_id, 'error' => $error ] );
		do_action( 'igbz_ig_content_failed', $content_id, $error );
	}

	/**
	 * @param array<string,mixed> $account
	 * @return array{status:string,messages:array<int,mixed>,attachments:array<int,array<string,mixed>>,output:array<string,mixed>}
	 */
	public function task_state( string $task_id, array $account = [] ): array {
		$state = $this->client_for( $account )->task_state( $task_id );
		return [
			'status'      => $state['status'],
			'messages'    => [],
			'attachments' => $state['attachments'],
			'output'      => $this->parse_json_block( $state['text'] ),
		];
	}
}
