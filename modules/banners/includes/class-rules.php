<?php
/**
 * The pure part of the banners module: no WordPress, tested at level 1 with
 * the machine's PHP (tests/php/pure.php).
 *
 * - state():  is a banner showing now, waiting for its start, or over?
 * - pick():   which banners of a zone to show when the zone shows fewer than
 *             it has (weighted, without repetition). view.js does the same
 *             in the browser; this one is the server's first answer.
 * - from_adrotate(): what an AdRotate ad becomes (image + link, or code).
 *
 * THE CRITERION of from_adrotate(): an ad is an IMAGE banner only if its code
 * is exactly one link wrapping one image (that is what AdRotate's own image
 * ads are). Anything else —an iframe, a script, two links, text— stays as
 * CODE, as it was. The named edge case: AdRotate's demo ads (ids 1 and 2 on
 * Gastronòmicament) are a link and an image, but the image is on ajdg.solutions,
 * not in the media library: the CLI keeps those as code (it cannot attach a
 * file that is not on the site).
 *
 * The trap: AdRotate stores the code ENTITY-ENCODED AND SLASHED
 * (`&lt;a href=\&quot;http:\/\/…`). Decoding the entities is not enough: without
 * stripslashes every attribute keeps a `\"`, nothing matches and the
 * adserver code is published with backslashes in it.
 */

namespace EstrategiaWeb\Banners;

defined( 'ABSPATH' ) || exit;

final class Rules {

	public const ACTIVE    = 'active';
	public const SCHEDULED = 'scheduled';
	public const EXPIRED   = 'expired';
	public const OFF       = 'off';

	/**
	 * State of a banner. $start and $end are unix times; 0 means «no limit».
	 */
	public static function state( string $post_status, int $start, int $end, int $now ): string {
		if ( 'publish' !== $post_status ) {
			return self::OFF;
		}
		if ( $end > 0 && $now > $end ) {
			return self::EXPIRED;
		}
		if ( $start > 0 && $now < $start ) {
			return self::SCHEDULED;
		}
		return self::ACTIVE;
	}

	/**
	 * Picks $count ids from [id => weight], weighted, without repetition.
	 * $count 0 (or more than there are) returns them all, in the given order.
	 *
	 * @param array<int, int>       $weights id => weight (a weight below 1 counts as 1).
	 * @param callable(int):int     $rand    returns an integer in [0, $max).
	 * @return int[]
	 */
	public static function pick( array $weights, int $count, callable $rand ): array {
		if ( $count <= 0 || $count >= count( $weights ) ) {
			return array_keys( $weights );
		}
		$chosen = array();
		while ( count( $chosen ) < $count ) {
			$total = 0;
			foreach ( $weights as $w ) {
				$total += max( 1, $w );
			}
			$n = $rand( $total );
			foreach ( $weights as $id => $w ) {
				$n -= max( 1, $w );
				if ( $n < 0 ) {
					$chosen[] = $id;
					unset( $weights[ $id ] );
					break;
				}
			}
		}
		return $chosen;
	}

	/**
	 * What an AdRotate ad becomes.
	 *
	 * @param string $bannercode The column as stored (encoded and slashed).
	 * @param string $image      The «image» column (used when the code has %asset% or %image%).
	 * @return array{kind: string, link: string, image: string, code: string}
	 */
	public static function from_adrotate( string $bannercode, string $image ): array {
		$html = stripslashes( html_entity_decode( $bannercode, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$html = str_replace( array( '%asset%', '%image%' ), $image, $html );
		$html = trim( $html );
		if ( preg_match( '#^<a\s[^>]*?href=(["\'])(.+?)\1[^>]*>\s*<img\s[^>]*?src=(["\'])(.+?)\3[^>]*?/?>\s*</a>$#is', $html, $m ) ) {
			return array( 'kind' => 'image', 'link' => $m[2], 'image' => $m[4], 'code' => $html );
		}
		return array( 'kind' => 'code', 'link' => '', 'image' => '', 'code' => $html );
	}
}
