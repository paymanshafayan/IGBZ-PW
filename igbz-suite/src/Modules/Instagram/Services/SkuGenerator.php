<?php
namespace IGBZ\Suite\Modules\Instagram\Services;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Mints the short product code that ties the whole flow together.
 *
 * One string does three jobs: it is the WooCommerce SKU, it is burned onto the Instagram image or
 * video, and it is the keyword the ManyChat funnel matches when somebody comments it. That makes
 * its shape a user-interface decision, not an implementation detail — a shopper has to read it off
 * a phone screen and type it into a comment box, on a Persian keyboard, without a second try.
 *
 * Hence the alphabet: digits and uppercase letters minus everything that is ambiguous in a
 * condensed font (0/O, 1/I/L, 5/S, 8/B, 2/Z). Four characters over that 26-symbol alphabet is
 * ~457k combinations, which is far more than any single store will register and short enough to
 * stay legible when overlaid on a photo.
 *
 * Uniqueness is checked against both the intake table and the WooCommerce SKU index, because a
 * code could have been typed by hand into a product created before this flow existed.
 */
final class SkuGenerator {

	/** Unambiguous characters only: no 0/O, 1/I/L, 2/Z, 5/S, 8/B. */
	private const ALPHABET = '34679ACDEFGHJKMNPQRTUVWXY';

	private const LENGTH = 4;

	/** Give up after this many collisions and lengthen the code instead of looping forever. */
	private const MAX_TRIES = 12;

	public function __construct( private Db $db ) {}

	public function prefix(): string {
		$prefix = igbz()->settings()->string( 'intake.sku_prefix', 'IGBZ' );
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '' );

		return '' === $prefix ? 'IGBZ' : substr( $prefix, 0, 8 );
	}

	/**
	 * A code that is free right now.
	 *
	 * Not reserved: the caller writes it onto the intake row, whose UNIQUE index is the real
	 * arbiter. Two concurrent registrations that draw the same code will have one insert fail,
	 * and the caller retries — cheaper than holding a lock across a user-facing request.
	 */
	public function generate(): string {
		$prefix = $this->prefix();

		for ( $attempt = 0; $attempt < self::MAX_TRIES; $attempt++ ) {
			// Widen the code once collisions suggest the space is getting crowded.
			$length    = self::LENGTH + (int) floor( $attempt / 4 );
			$candidate = $prefix . '-' . $this->random( $length );

			if ( $this->is_free( $candidate ) ) {
				return $candidate;
			}
		}

		// Fall back to something that cannot realistically collide.
		return $prefix . '-' . $this->random( 8 );
	}

	public function is_free( string $code ): bool {
		$taken = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_intake' ) . ' WHERE sku = %s',
			$code
		);
		if ( $taken > 0 ) {
			return false;
		}

		// A funnel keyword must be unique too, otherwise two products answer the same comment.
		$keyword = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_funnels' ) . ' WHERE keyword = %s',
			$this->keyword( $code )
		);
		if ( $keyword > 0 ) {
			return false;
		}

		return ! ( function_exists( 'wc_get_product_id_by_sku' ) && wc_get_product_id_by_sku( $code ) > 0 );
	}

	/**
	 * The comment keyword for a code.
	 *
	 * FunnelService canonicalises keywords to lower case before matching, so the keyword stored
	 * on the funnel has to be lower case as well or the funnel would never fire. The code shown
	 * to the shopper stays upper case; matching is case-insensitive on both sides.
	 */
	public function keyword( string $code ): string {
		return mb_strtolower( trim( $code ) );
	}

	private function random( int $length ): string {
		$alphabet = self::ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$out      = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, $max ) ];
		}

		return $out;
	}
}
