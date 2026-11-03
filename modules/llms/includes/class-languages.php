<?php
namespace EstrategiaWeb\Llms;

defined( 'ABSPATH' ) || exit;

/**
 * Languages and WPML: the ONLY place of this module that knows WPML exists.
 *
 * Without WPML there is one language, whose code is the site locale's
 * language (es_ES → «es»): the same code WPML would give the default
 * language, so a text written before installing WPML stays where it was.
 *
 * With WPML, its public hooks: wpml_active_languages, wpml_default_language,
 * wpml_current_language, wpml_setting (language_negotiation_type: 1
 * directories, 2 a domain per language, 3 ?lang=), wpml_permalink (the URL of
 * the other languages' files, whatever the mode), wpml_switch_language and
 * wpml_translate_single_string (Company details translated in String
 * Translation, for the draft).
 *
 * How to maintain: if WPML changes a hook, it changes here and nowhere else.
 * The trap: WPML is paid and is not in the normal test WordPress; the
 * behaviour with it is modules/llms/tests/llms-wpml.test.mjs, against the
 * WPML WordPress (tests/run.mjs, «WPML»).
 */
final class Languages {

	public static function wpml(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** Code of the default language. */
	public static function default(): string {
		if ( self::wpml() ) {
			$code = apply_filters( 'wpml_default_language', null );
			if ( is_string( $code ) && '' !== $code ) {
				return $code;
			}
		}
		return strtolower( (string) strtok( get_locale(), '_' ) );
	}

	/**
	 * The site's languages, the default one first.
	 *
	 * @return array<string, array{name: string, locale: string}>
	 */
	public static function all(): array {
		$default = self::default();
		$out     = array(
			$default => array(
				'name'   => '',
				'locale' => get_locale(),
			),
		);
		if ( self::wpml() ) {
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			foreach ( is_array( $languages ) ? $languages : array() as $code => $lang ) {
				if ( ! is_string( $code ) || ! preg_match( Text::CODE, $code ) ) {
					continue;
				}
				$out[ $code ] = array(
					'name'   => (string) ( $lang['native_name'] ?? $code ),
					'locale' => (string) ( $lang['default_locale'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** The codes that can come as the first directory of a URL (WPML in directories), or none. @return string[] */
	public static function directory_codes(): array {
		if ( ! self::wpml() || 1 !== (int) apply_filters( 'wpml_setting', 0, 'language_negotiation_type' ) ) {
			return array();
		}
		return array_keys( self::all() );
	}

	/** Language of the request: WPML's (from the domain or ?lang=), or the default one. */
	public static function current(): string {
		if ( self::wpml() ) {
			$code = apply_filters( 'wpml_current_language', null );
			if ( is_string( $code ) && '' !== $code && 'all' !== $code ) {
				return $code;
			}
		}
		return self::default();
	}

	/**
	 * Where a language's llms.txt is. WPML converts the language's HOME
	 * (/en/, en.example.com/, /?lang=en), as WPML itself does with
	 * get_home_url(), and llms.txt goes in front of the query. The trap (seen
	 * with real WPML, 31-10-2026: the English link pointed to /llms.txt):
	 * wpml_permalink's third argument, true, resolves the URL as a POST's
	 * (WPML_API_Hook_Permalink), and a URL that is no post comes back as it
	 * went. Without it, WPML's URL converter, which converts any URL.
	 */
	public static function url( string $code ): string {
		$home  = self::home( $code );
		$query = (string) wp_parse_url( $home, PHP_URL_QUERY );
		$base  = '' !== $query ? substr( $home, 0, (int) strpos( $home, '?' ) ) : $home;
		return trailingslashit( $base ) . 'llms.txt' . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * A language's home page. Asked to WPML, not to home_url(): in wp-admin,
	 * with WPML switched to «en», home_url() still gives the default
	 * language's (seen 31-10-2026 in the English draft).
	 */
	public static function home( string $code ): string {
		$home = trailingslashit( (string) get_option( 'home' ) );
		if ( self::wpml() && self::default() !== $code ) {
			$home = (string) apply_filters( 'wpml_permalink', $home, $code );
		}
		return $home;
	}

	/**
	 * Runs $fn with WPML and gettext switched to a language (for the draft:
	 * its pages and the headings in that language), and switches back.
	 */
	public static function with( string $code, callable $fn ): mixed {
		$previous = null;
		if ( self::wpml() ) {
			$previous = apply_filters( 'wpml_current_language', null );
			do_action( 'wpml_switch_language', $code );
		}
		$locale   = self::all()[ $code ]['locale'] ?? '';
		$switched = '' !== $locale && switch_to_locale( $locale );
		try {
			return $fn();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
			if ( self::wpml() && null !== $previous ) {
				do_action( 'wpml_switch_language', $previous );
			}
		}
	}

	/** A Company details value as String Translation has it for a language (the value itself without WPML). */
	public static function company( string $key, string $value, string $code ): string {
		if ( ! self::wpml() || '' === $value ) {
			return $value;
		}
		return (string) apply_filters( 'wpml_translate_single_string', $value, 'admin_texts_estrategia_web_site', '[estrategia_web_site]' . $key, $code );
	}
}
