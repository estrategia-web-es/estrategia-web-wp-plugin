<?php
/**
 * The form nonce: a timestamp signed with the site salt.
 *
 * PURE class (level 1): it receives the secret and the time, it does not
 * look them up.
 *
 * Why it is NOT wp_create_nonce(): a WordPress nonce is tied to the user,
 * and the REST API treats as ANONYMOUS any request with cookies that does
 * not carry the X-WP-Nonce header. An editor with an open session testing
 * their own form without JavaScript would always fail: the nonce was made
 * for their user and is checked against user 0. This token does the same
 * —proves the submission comes from a form this server rendered, for that
 * definition and recently— without depending on who is looking.
 *
 * And it doubles as the antispam clock: the timestamp is the moment the
 * form was rendered (or the first focus, with JS: see view.js), so «too
 * fast» and «expired» come out of the same check.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Token {

	public const OK       = 'ok';
	public const INVALID  = 'invalid';
	public const TOO_FAST = 'too_fast';
	public const EXPIRED  = 'expired';

	public static function create( string $definition_hash, int $now, string $secret ): string {
		return $now . '.' . self::sign( $definition_hash, $now, $secret );
	}

	/**
	 * @return string One of the constants OK, INVALID, TOO_FAST, EXPIRED.
	 */
	public static function verify( string $token, string $definition_hash, int $now, string $secret, int $min_seconds, int $max_age ): string {
		if ( ! preg_match( '/^(\d{9,12})\.([a-f0-9]{40})$/', $token, $m ) ) {
			return self::INVALID;
		}
		$issued = (int) $m[1];
		if ( ! hash_equals( self::sign( $definition_hash, $issued, $secret ), $m[2] ) ) {
			return self::INVALID;
		}
		$age = $now - $issued;
		if ( $age < $min_seconds ) {
			return self::TOO_FAST;
		}
		if ( $age > $max_age ) {
			return self::EXPIRED;
		}
		return self::OK;
	}

	private static function sign( string $definition_hash, int $issued, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'estrategia-web-forms|' . $definition_hash . '|' . $issued, $secret ), 0, 40 );
	}
}
