<?php
/**
 * Idioma y WPML: el ÚNICO sitio del plugin que sabe que WPML existe.
 *
 * Sin WPML todo funciona igual: el «idioma» es el locale de WordPress
 * (es_ES) y los textos salen tal cual. Con WPML, el idioma es su código
 * (es, en, ca) y se usan sus ganchos públicos:
 *   - wpml_current_language, wpml_active_languages, wpml_switch_language
 *   - wpml_register_single_string / wpml_translate_single_string (ajustes)
 *   - wpml_object_id (la página de privacidad traducida)
 *
 * Los textos de CADA formulario no pasan por aquí: son atributos de bloque y
 * WPML los traduce con la página, según wpml-config.xml.
 *
 * Cómo se mantiene: si una versión de WPML cambia un gancho, se cambia aquí y
 * en ningún otro sitio. La trampa: WPML no está en el entorno de pruebas (es
 * de pago), así que esta clase se prueba a mano en una web con WPML antes de
 * cada versión que la toque — anotado en el README, «Versiones».
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Language {

	public const STRING_CONTEXT = 'estrategia-web-forms';

	public static function wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** Idioma de lo que se está pintando ahora: código WPML o locale de WP. */
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
	 * Cambia WPML y gettext al idioma del envío mientras se montan los
	 * correos y los mensajes. Devuelve lo necesario para volver.
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

	/** El id de la traducción de un objeto (página, entrada) en $lang, o el mismo id. */
	public static function object_id( int $id, string $type, string $lang ): int {
		if ( $id && self::wpml_active() ) {
			return (int) apply_filters( 'wpml_object_id', $id, $type, true, $lang );
		}
		return $id;
	}
}
