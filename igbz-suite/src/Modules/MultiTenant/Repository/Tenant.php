<?php
namespace IGBZ\Suite\Modules\MultiTenant\Repository;

defined( 'ABSPATH' ) || exit;

final class Tenant {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_TRIAL     = 'trial';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_CLOSED    = 'closed';

	/** @param array<string,mixed> $settings */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $name,
		public readonly int $owner_user_id,
		public readonly string $status,
		public readonly int $plan_id,
		public readonly string $currency,
		public readonly string $locale,
		public readonly string $theme = '',
		public readonly string $logo_url = '',
		public readonly string $primary_color = '',
		public readonly array $settings = [],
		public readonly ?string $trial_ends_at = null
	) {}

	/** @param array<string,mixed> $row */
	public static function from_row( array $row ): self {
		$settings = [];
		if ( ! empty( $row['settings'] ) ) {
			$decoded  = json_decode( (string) $row['settings'], true );
			$settings = is_array( $decoded ) ? $decoded : [];
		}
		return new self(
			(int) $row['id'],
			(string) $row['slug'],
			(string) $row['name'],
			(int) $row['owner_user_id'],
			(string) $row['status'],
			(int) $row['plan_id'],
			(string) ( $row['currency'] ?? 'IRT' ),
			(string) ( $row['locale'] ?? 'fa_IR' ),
			(string) ( $row['theme'] ?? '' ),
			(string) ( $row['logo_url'] ?? '' ),
			(string) ( $row['primary_color'] ?? '' ),
			$settings,
			isset( $row['trial_ends_at'] ) ? (string) $row['trial_ends_at'] : null
		);
	}

	public function is_active(): bool {
		if ( self::STATUS_TRIAL === $this->status ) {
			return null === $this->trial_ends_at || strtotime( $this->trial_ends_at ) > time();
		}
		return self::STATUS_ACTIVE === $this->status;
	}

	public function setting( string $key, mixed $default = null ): mixed {
		return $this->settings[ $key ] ?? $default;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'            => $this->id,
			'slug'          => $this->slug,
			'name'          => $this->name,
			'status'        => $this->status,
			'plan_id'       => $this->plan_id,
			'currency'      => $this->currency,
			'locale'        => $this->locale,
			'theme'         => $this->theme,
			'logo_url'      => $this->logo_url,
			'primary_color' => $this->primary_color,
			'is_active'     => $this->is_active(),
		];
	}
}
