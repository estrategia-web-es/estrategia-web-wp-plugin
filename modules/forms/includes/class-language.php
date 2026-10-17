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
 * nowhere else. The trap: WPML is not in this plugin's test environment (it
 * is paid). Since 17-10-2026 this class runs with real WPML in Lavandería's
 * tests (tests/forms-wpml.test.mjs: a submission on /en/contact/ with the
 * messages, the emails, the log and String Translation; and
 * tests/wpml-blocks.test.mjs: a page translated through WPML's flow); run
 * them there before every version that touches it.
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

	/**
	 * Registers a text of the settings in String Translation, IN THE SITE'S
	 * DEFAULT LANGUAGE: it is written on the settings screen, which shows and
	 * saves the original.
	 *
	 * The trap (17-10-2026, WPML ST 5.0.2, Lavandería local): without the
	 * language, WPML assumes ENGLISH for any domain that is not admin_texts_…
	 * (it treats it like a gettext string, WPML_Autoregister_Save_Strings::
	 * get_source_lang), so a Spanish success message was registered as
	 * English, String Translation offered it for translation into Spanish
	 * only, and /en/ always showed the Spanish text. No error anywhere.
	 */
	public static function register_string( string $name, string $value ): void {
		if ( self::wpml_active() ) {
			$source = (string) apply_filters( 'wpml_default_language', null );
			do_action( 'wpml_register_single_string', self::STRING_CONTEXT, $name, $value, false, $source );
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
