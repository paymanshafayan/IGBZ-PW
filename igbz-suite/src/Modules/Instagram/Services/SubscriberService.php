<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Modules\Instagram\Gateways\ManyChatClient;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Local mirror of ManyChat subscribers, plus the link between an Instagram follower and a
 * WordPress/WooCommerce customer account.
 */
final class SubscriberService {

	public function __construct(
		private Db $db,
		private ManyChatClient $client,
		private Logger $logger,
		private AccountCredentials $credentials
	) {}

	/**
	 * ManyChat client bound to an account's page-scoped key.
	 *
	 * Callers usually know only the tenant, so account_id 0 resolves to that tenant's first active
	 * account. Passing an explicit account is always preferred where one is known.
	 */
	private function client_for( int $tenant_id, int $account_id = 0 ): ManyChatClient {
		$account = $account_id > 0
			? $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . ' WHERE id = %d', $account_id )
			: $this->db->row(
				'SELECT * FROM ' . $this->db->table( 'ig_accounts' ) . '
				 WHERE tenant_id = %d AND is_active = 1 ORDER BY id LIMIT 1',
				$tenant_id
			);

		$key = $account ? $this->credentials->key( $account, AccountCredentials::SERVICE_MANYCHAT ) : '';

		return $this->client->for_key( $key );
	}

	/** @return array<string,mixed>|null */
	public function find( string $manychat_subscriber_id ): ?array {
		return $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'ig_subscribers' ) . ' WHERE manychat_subscriber_id = %s',
			$manychat_subscriber_id
		);
	}

	/** @return array<string,mixed>|null */
	public function get( int $id ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'ig_subscribers' ) . ' WHERE id = %d', $id );
	}

	/**
	 * Insert or update from whatever the webhook gave us. Never calls the API.
	 *
	 * @param array<string,mixed> $data
	 */
	public function upsert( array $data, int $tenant_id = 0 ): int {
		$subscriber_id = trim( (string) ( $data['manychat_subscriber_id'] ?? $data['subscriber_id'] ?? '' ) );
		if ( '' === $subscriber_id ) {
			return 0;
		}

		$now      = current_time( 'mysql', true );
		$existing = $this->find( $subscriber_id );

		$payload = [
			'tenant_id'           => $tenant_id ?: (int) ( $existing['tenant_id'] ?? 0 ),
			'ig_username'         => ltrim( sanitize_text_field( (string) ( $data['ig_username'] ?? $existing['ig_username'] ?? '' ) ), '@' ),
			'ig_user_id'          => sanitize_text_field( (string) ( $data['ig_user_id'] ?? $existing['ig_user_id'] ?? '' ) ),
			'first_name'          => sanitize_text_field( (string) ( $data['first_name'] ?? $existing['first_name'] ?? '' ) ),
			'last_name'           => sanitize_text_field( (string) ( $data['last_name'] ?? $existing['last_name'] ?? '' ) ),
			'phone'               => sanitize_text_field( (string) ( $data['phone'] ?? $existing['phone'] ?? '' ) ),
			'email'               => sanitize_email( (string) ( $data['email'] ?? $existing['email'] ?? '' ) ),
			'last_interaction_at' => $now,
			'updated_at'          => $now,
		];

		if ( isset( $data['custom_fields'] ) ) {
			$payload['custom_fields'] = wp_json_encode( (array) $data['custom_fields'] );
		}
		if ( isset( $data['tags'] ) ) {
			$payload['tags'] = wp_json_encode( array_values( (array) $data['tags'] ) );
		}
		if ( isset( $data['user_id'] ) ) {
			$payload['user_id'] = (int) $data['user_id'];
		}

		if ( $existing ) {
			$this->db->update( 'ig_subscribers', $payload, [ 'id' => (int) $existing['id'] ] );
			return (int) $existing['id'];
		}

		$payload['manychat_subscriber_id'] = $subscriber_id;
		$payload['created_at']             = $now;

		return $this->db->insert( 'ig_subscribers', $payload );
	}

	/**
	 * Integration path #2: pull the full profile (name, custom fields, tags, last interaction)
	 * from the ManyChat API and persist it.
	 *
	 * @return array<string,mixed>|null
	 */
	public function sync_from_api( string $manychat_subscriber_id, int $tenant_id = 0, int $account_id = 0 ): ?array {
		$result = $this->client_for( $tenant_id, $account_id )->get_info( $manychat_subscriber_id );
		if ( ! $result['ok'] ) {
			$this->logger->warning( 'manychat', 'Profile sync failed', [ 'subscriber_id' => $manychat_subscriber_id, 'error' => $result['error'] ] );
			return null;
		}

		$id = $this->upsert( $this->normalize( $result['data'] ), $tenant_id );
		$this->maybe_link_user( $id );

		return $id > 0 ? $this->get( $id ) : null;
	}

	/**
	 * Map a ManyChat subscriber payload onto our columns.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function normalize( array $data ): array {
		$fields = [];
		foreach ( (array) ( $data['custom_fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && isset( $field['name'] ) ) {
				$fields[ (string) $field['name'] ] = $field['value'] ?? null;
			}
		}

		$tags = [];
		foreach ( (array) ( $data['tags'] ?? [] ) as $tag ) {
			$tags[] = is_array( $tag ) ? (string) ( $tag['name'] ?? '' ) : (string) $tag;
		}

		return [
			'manychat_subscriber_id' => (string) ( $data['id'] ?? '' ),
			'ig_username'            => (string) ( $data['ig_username'] ?? $data['name'] ?? '' ),
			'ig_user_id'             => (string) ( $data['ig_id'] ?? '' ),
			'first_name'             => (string) ( $data['first_name'] ?? '' ),
			'last_name'              => (string) ( $data['last_name'] ?? '' ),
			'phone'                  => (string) ( $data['phone'] ?? '' ),
			'email'                  => (string) ( $data['email'] ?? '' ),
			'custom_fields'          => $fields,
			'tags'                   => array_values( array_filter( $tags ) ),
		];
	}

	/**
	 * Best-effort match of a subscriber to an existing WordPress user, by e-mail then phone.
	 */
	public function maybe_link_user( int $subscriber_id ): int {
		$subscriber = $this->get( $subscriber_id );
		if ( ! $subscriber || (int) $subscriber['user_id'] > 0 ) {
			return (int) ( $subscriber['user_id'] ?? 0 );
		}

		$user_id = 0;
		if ( '' !== (string) $subscriber['email'] ) {
			$user    = get_user_by( 'email', (string) $subscriber['email'] );
			$user_id = $user ? (int) $user->ID : 0;
		}

		if ( 0 === $user_id && '' !== (string) $subscriber['phone'] ) {
			$phone = \IGBZ\Suite\Modules\MultiTenant\Otp\OtpService::normalize_phone( (string) $subscriber['phone'] );
			$found = get_users( [ 'meta_key' => 'igbz_phone', 'meta_value' => $phone, 'number' => 1, 'fields' => 'ID' ] );
			$user_id = $found ? (int) $found[0] : 0;
		}

		if ( $user_id > 0 ) {
			$this->db->update( 'ig_subscribers', [ 'user_id' => $user_id, 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $subscriber_id ] );
			update_user_meta( $user_id, 'igbz_manychat_subscriber_id', (string) $subscriber['manychat_subscriber_id'] );
			if ( '' !== (string) $subscriber['ig_username'] ) {
				update_user_meta( $user_id, 'igbz_instagram_username', (string) $subscriber['ig_username'] );
			}
			do_action( 'igbz_ig_subscriber_linked', $subscriber_id, $user_id );
		}

		return $user_id;
	}

	/** Push our own data back into ManyChat custom fields. @param array<string,mixed> $fields */
	public function push_fields( string $manychat_subscriber_id, array $fields, int $tenant_id = 0, int $account_id = 0 ): bool {
		return $this->client_for( $tenant_id, $account_id )->set_custom_fields( $manychat_subscriber_id, $fields );
	}

	/**
	 * @param array{tenant_id?:int,search?:string,limit?:int,offset?:int} $args
	 * @return array<int,array<string,mixed>>
	 */
	public function all( array $args = [] ): array {
		$where  = [ '1=1' ];
		$params = [];
		if ( isset( $args['tenant_id'] ) ) {
			$where[]  = 'tenant_id = %d';
			$params[] = (int) $args['tenant_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $this->db->wpdb()->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(ig_username LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR email LIKE %s)';
			$params   = array_merge( $params, [ $like, $like, $like, $like, $like ] );
		}
		$params[] = (int) ( $args['limit'] ?? 50 );
		$params[] = (int) ( $args['offset'] ?? 0 );

		return $this->db->results(
			'SELECT * FROM ' . $this->db->table( 'ig_subscribers' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY last_interaction_at DESC LIMIT %d OFFSET %d',
			...$params
		);
	}

	public function count( int $tenant_id = 0 ): int {
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_subscribers' ) . ' WHERE tenant_id = %d',
			$tenant_id
		);
	}
}
