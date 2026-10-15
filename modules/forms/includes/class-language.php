<?php
/**
 * Language and WPML: the ONLY place in the plugin that knows WPML exists.
 *
 * Without WPML everything works the same: the «language» is the WordPress
 * locale (es_ES) and texts come out as they are. With WPML, the language is
 * its code (es, en, ca) and its public hooks are used:
 *   - wpml_current_language, wpml_active_languages, wpml_switch_language
 *   - wpml_register_single_string / wpml_translate_single_string (settings)
 *   - wpml_object_id (the translated privacy page)
 *
 * The texts of EACH form do not go through here: they are block attributes
 * and WPML translates them with the page, according to wpml-config.xml.
 *
 * How to maintain: if a WPML version changes a hook, it is changed here and
 * nowhere else. The trap: WPML is not in the test environment (it is paid),
 * so this class is tested by hand on a site with WPML before every version
 * that touches it — noted in the README, «Versiones».
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Language {

	public const STRING_CONTEXT = 'estrategia-web-forms';

	public static function wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** Language of what is being rendered now: WPML code or WP locale. */
	public static function current(): string {
		if ( self::wpml_active() ) {
			$code = apply_filters( 'wpml_current_language', null );
			if ( is_string( $code ) && '' !== $code ) {
				return $code;
			}
		}
		return determine_locale();
	}

	public static function locale_for( string $lang ): string {
		if ( self::wpml_active() ) {
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $languages ) && isset( $languages[ $lang ]['default_locale'] ) ) {
				return (string) $languages[ $lang ]['default_locale'];
			}
		}
		return preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?/', $lang ) ? $lang : determine_locale();
	}

	/**
	 * Switches WPML and gettext to the submission's language while the
	 * emails and messages are built. Returns what is needed to switch back.
	 *
	 * @return array{0: bool, 1: ?string}
	 */
	public static function switch_to( string $lang ): array {
		$previous = null;
		if ( self::wpml_active() ) {
			$previous = apply_filters( 'wpml_current_language', null );
			do_action( 'wpml_switch_language', $lang );
		}
		$switched = switch_to_locale( self::locale_for( $lang ) );
		return array( $switched, $previous );
	}

	/** @param array{0: bool, 1: ?string} $state */
	public static function restore( array $state ): void {
		if ( $state[0] ) {
			restore_previous_locale();
		}
		if ( self::wpml_active() && null !== $state[1] ) {
			do_action( 'wpml_switch_language', $state[1] );
		}
	}

	public static function register_string( string $name, string $value ): void {
		if ( self::wpml_active() ) {
			do_action( 'wpml_register_single_string', self::STRING_CONTEXT, $name, $value );
		}
	}

	public static function translate_string( string $name, string $value, string $lang ): string {
		if ( self::wpml_active() ) {
			return (string) apply_filters( 'wpml_translate_single_string', $value, self::STRING_CONTEXT, $name, $lang );
		}
		return $value;
	}

	/** The id of an object's translation (page, post) in $lang, or the same id. */
	public static function object_id( int $id, string $type, string $lang ): int {
		if ( $id && self::wpml_active() ) {
			return (int) apply_filters( 'wpml_object_id', $id, $type, true, $lang );
		}
		return $id;
	}
}
