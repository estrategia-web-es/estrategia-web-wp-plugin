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
 *
 * THE ENTRY'S CONTEXT (2.14.0, 24-11-2026): context() signs the id of
 * the entry the form is shown in (an obituary) and context_id() reads it
 * back, so the {{post_*}} markers (class-markers.php) read THAT entry and
 * nothing a visitor typed. It travels as the hidden field _af_ctx. Same
 * secret as the token, another label in the HMAC («…-context|»), so a token
 * can never pass for a context or the other way round; and bound to the
 * definition hash, so the context of one form cannot be pasted into another.
 * It does not expire: a cached page keeps working, and what decides is
 * whether the entry is STILL public when the submission arrives
 * (Rest::context_post()).
 * - Edge case, by name: someone copies the _af_ctx of obituary A into the
 *   form of obituary B. The signature is bound to B's definition hash, which
 *   carries B's post_id, so it does not match: no context, the condolence
 *   goes to the funeral home without Cc and without a title. Never to A's
 *   family.
 * - The trap: a context that does not verify is NOT a rejection. The
 *   submission goes on without it (the funeral home still gets the message);
 *   only the entry markers come out empty.
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

	/** «<post id>.<signature>», or '' for no entry. */
	public static function context( int $post_id, string $definition_hash, string $secret ): string {
		return $post_id > 0 ? $post_id . '.' . self::sign_context( $post_id, $definition_hash, $secret ) : '';
	}

	/** The entry id a context carries, or 0 when it is missing, malformed or its signature does not match. */
	public static function context_id( string $context, string $definition_hash, string $secret ): int {
		if ( ! preg_match( '/^([1-9]\d{0,18})\.([a-f0-9]{40})$/', $context, $m ) ) {
			return 0;
		}
		return hash_equals( self::sign_context( (int) $m[1], $definition_hash, $secret ), $m[2] ) ? (int) $m[1] : 0;
	}

	private static function sign_context( int $post_id, string $definition_hash, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'estrategia-web-forms-context|' . $definition_hash . '|' . $post_id, $secret ), 0, 40 );
	}

	private static function sign( string $definition_hash, int $issued, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'estrategia-web-forms|' . $definition_hash . '|' . $issued, $secret ), 0, 40 );
	}
}
