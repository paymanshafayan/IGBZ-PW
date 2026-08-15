<?php
namespace IGBZ\Suite\Modules\Hub\Services;

use IGBZ\Suite\Modules\MultiTenant\Repository\TenantRepository;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Custom-domain verification for tenant stores.
 *
 * Port note: the nop controller flipped `IsSslVerified` to true on request without checking
 * anything. Here a domain is only marked verified when DNS actually resolves the way we asked —
 * either a CNAME to the platform host, an A record to the platform IP, or a TXT record carrying
 * the per-domain verification token.
 */
final class DomainVerifier {

	public function __construct( private Db $db, private TenantRepository $tenants, private Logger $logger ) {}

	public function expected_cname(): string {
		$configured = igbz()->settings()->string( 'hub.cname_target', '' );
		if ( '' !== $configured ) {
			return strtolower( $configured );
		}
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	/**
	 * @return array{ok:bool,method:string,found:string,expected:string,message:string}
	 */
	public function check( int $domain_id ): array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE id = %d', $domain_id );
		if ( ! $row ) {
			return [
				'ok'       => false,
				'method'   => '',
				'found'    => '',
				'expected' => '',
				'message'  => __( 'Domain mapping not found.', 'igbz-suite' ),
			];
		}

		$domain   = (string) $row['domain'];
		$token    = (string) $row['verification_token'];
		$expected = $this->expected_cname();

		if ( ! function_exists( 'dns_get_record' ) ) {
			return [
				'ok'       => false,
				'method'   => '',
				'found'    => '',
				'expected' => $expected,
				'message'  => __( 'DNS lookups are disabled on this server; verify the record manually.', 'igbz-suite' ),
			];
		}

		// 1. TXT token — the most explicit proof of ownership.
		$txt = $this->records( '_igbz-verify.' . $domain, DNS_TXT );
		foreach ( $txt as $record ) {
			if ( isset( $record['txt'] ) && trim( (string) $record['txt'] ) === $token ) {
				return $this->pass( $domain_id, 'txt', (string) $record['txt'], $expected );
			}
		}

		// 2. CNAME to the platform host.
		$cname = $this->records( $domain, DNS_CNAME );
		foreach ( $cname as $record ) {
			$target = strtolower( rtrim( (string) ( $record['target'] ?? '' ), '.' ) );
			if ( $target === $expected ) {
				return $this->pass( $domain_id, 'cname', $target, $expected );
			}
		}

		// 3. A record pointing at the same IP as the platform host.
		$platform_ips = array_column( $this->records( $expected, DNS_A ), 'ip' );
		$domain_ips   = array_column( $this->records( $domain, DNS_A ), 'ip' );
		$shared       = array_intersect( array_filter( $platform_ips ), array_filter( $domain_ips ) );
		if ( $shared ) {
			return $this->pass( $domain_id, 'a', (string) reset( $shared ), $expected );
		}

		$this->logger->info( 'hub', 'Domain verification failed', [ 'domain' => $domain ] );

		return [
			'ok'       => false,
			'method'   => '',
			'found'    => implode( ', ', array_filter( $domain_ips ) ),
			'expected' => $expected,
			'message'  => sprintf(
				/* translators: 1: domain, 2: expected CNAME target */
				__( '%1$s does not point at %2$s yet, and no _igbz-verify TXT record was found.', 'igbz-suite' ),
				$domain,
				$expected
			),
		];
	}

	/** @return array<string,mixed> */
	private function pass( int $domain_id, string $method, string $found, string $expected ): array {
		$this->tenants->verify_domain( $domain_id );
		$this->logger->info( 'hub', 'Domain verified', [ 'domain_id' => $domain_id, 'method' => $method ] );

		return [
			'ok'       => true,
			'method'   => $method,
			'found'    => $found,
			'expected' => $expected,
			'message'  => __( 'The DNS record is in place. The domain is verified.', 'igbz-suite' ),
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function records( string $host, int $type ): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing record must not warn.
		$records = @dns_get_record( $host, $type );
		return is_array( $records ) ? $records : [];
	}

	/** DNS instructions rendered on the admin screen and returned by the hub API. */
	public function instructions( string $domain, string $token ): string {
		return sprintf(
			/* translators: 1: domain, 2: CNAME target, 3: verification token */
			__( 'Point %1$s with a CNAME record to %2$s, or add a TXT record on _igbz-verify.%1$s with the value %3$s.', 'igbz-suite' ),
			$domain,
			$this->expected_cname(),
			$token
		);
	}

	/** Re-check every unverified domain; called from the hourly cron. */
	public function recheck_pending( int $limit = 20 ): int {
		$rows = $this->db->results(
			'SELECT id FROM ' . $this->db->table( 'tenant_domains' ) . ' WHERE verified_at IS NULL ORDER BY id LIMIT %d',
			$limit
		);

		$verified = 0;
		foreach ( $rows as $row ) {
			if ( $this->check( (int) $row['id'] )['ok'] ) {
				$verified++;
			}
		}

		return $verified;
	}
}
