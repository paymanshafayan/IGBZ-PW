<?php
namespace IGBZ\Suite\Modules\Instagram\Vip;

defined( 'ABSPATH' ) || exit;

/**
 * The answer to "may this user open this post", and — just as importantly — why not.
 *
 * A bare bool was the first design and it was wrong: the app has to render a different screen for
 * "buy a membership", "buy this one post", "your membership expired" and "this post is gone". A
 * caller that only knows `false` has to re-derive that reason itself, which is exactly how two
 * copies of the access rules end up in the codebase.
 */
final class VipAccess {

	public const ALLOW_FREE       = 'free';
	public const ALLOW_MEMBERSHIP = 'membership';
	public const ALLOW_PURCHASE   = 'purchase';
	public const ALLOW_AUTHOR     = 'author';

	public const DENY_ANONYMOUS   = 'anonymous';
	public const DENY_NO_MEMBER   = 'not_a_member';
	public const DENY_EXPIRED     = 'membership_expired';
	public const DENY_UNPURCHASED = 'not_purchased';
	public const DENY_GONE        = 'post_expired';
	public const DENY_MISSING     = 'post_not_found';
	public const DENY_UNPUBLISHED = 'post_not_published';

	/**
	 * @param array<int,array<string,mixed>> $plans
	 */
	private function __construct(
		public readonly bool $allowed,
		public readonly string $reason,
		public readonly float $price = 0.0,
		public readonly array $plans = [],
		public readonly ?string $membership_ends_at = null
	) {}

	/** @param array<int,array<string,mixed>> $plans */
	public static function allow( string $reason ): self {
		return new self( true, $reason );
	}

	/** @param array<int,array<string,mixed>> $plans */
	public static function deny( string $reason, float $price = 0.0, array $plans = [], ?string $ends_at = null ): self {
		return new self( false, $reason, $price, $plans, $ends_at );
	}

	/** True when buying this single post is an option the app should offer. */
	public function can_buy_single(): bool {
		return ! $this->allowed && $this->price > 0 && self::DENY_GONE !== $this->reason && self::DENY_MISSING !== $this->reason;
	}

	/** True when a membership would unlock this post. */
	public function can_subscribe(): bool {
		return ! $this->allowed && [] !== $this->plans && self::DENY_GONE !== $this->reason && self::DENY_MISSING !== $this->reason;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'allowed'            => $this->allowed,
			'reason'             => $this->reason,
			'price'              => $this->price,
			'can_buy_single'     => $this->can_buy_single(),
			'can_subscribe'      => $this->can_subscribe(),
			'plans'              => $this->plans,
			'membership_ends_at' => $this->membership_ends_at,
		];
	}
}
