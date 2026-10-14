<?php
/**
 * Ajustes globales del plugin (una sola opción, 'estrategia_web_forms_settings').
 *
 * Aquí va SOLO lo que es igual para todos los formularios de la web: la
 * retención del registro, el antispam y los textos por defecto. Lo de cada
 * formulario (campos, avisos, mensajes propios) vive en sus bloques.
 *
 * Cómo se mantiene:
 * - Un ajuste nuevo entra en DEFAULTS, en sanitize() y en la pantalla
 *   (Admin::render_settings), en el mismo commit. Si es un texto que lee el
 *   visitante, entra además en TRANSLATABLE para que WPML lo vea.
 * - Caso frontera: un texto que solo un formulario necesita distinto NO es un
 *   ajuste: es un atributo del bloque del formulario.
 * - La trampa: un texto vacío significa «el de por defecto, traducido con
 *   gettext». Si se rellena, deja de traducirse con el .po y pasa a
 *   traducirse con WPML (Cadenas). Las dos vías no se mezclan.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'estrategia_web_forms_settings';

	/** Segundos que vale un token: más que cualquier caché de página razonable. */
	public const TOKEN_MAX_AGE = 7 * DAY_IN_SECONDS;

	public const DEFAULTS = array(
		'retention_days'  => 365,
		'min_seconds'     => 3,
		'rate_limit'      => 5,
		'rate_window'     => 10,
		'success_message' => '',
		'error_message'   => '',
		'email_footer'    => '',
	);

	/** Nombre de la cadena en WPML => clave del ajuste. */
	public const TRANSLATABLE = array(
		'Default success message' => 'success_message',
		'Default error message'   => 'error_message',
		'Email footer'            => 'email_footer',
	);

	/** @return array<string, mixed> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$all    = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
		/**
		 * Permite a una web (o a las pruebas) fijar ajustes por código.
		 *
		 * @param array $all Ajustes ya mezclados con los valores por defecto.
		 */
		return (array) apply_filters( 'estrategia_web_forms_settings', $all );
	}

	public static function get( string $key ): mixed {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** @param array<string, mixed> $input */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = self::DEFAULTS;
		foreach ( array( 'retention_days', 'min_seconds', 'rate_limit', 'rate_window' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}
		foreach ( array( 'success_message', 'error_message', 'email_footer' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $input[ $key ] );
			}
		}
		return $clean;
	}

	/** Registra en WPML los textos rellenados. Se llama al guardar los ajustes. */
	public static function register_strings(): void {
		$all = self::all();
		foreach ( self::TRANSLATABLE as $name => $key ) {
			if ( '' !== (string) $all[ $key ] ) {
				Language::register_string( $name, (string) $all[ $key ] );
			}
		}
	}

	/**
	 * Un texto global en el idioma $lang. Hay que llamarlo con el locale ya
	 * cambiado (Language::switch_to) para que el de por defecto salga traducido.
	 */
	public static function text( string $key, string $lang ): string {
		$value = (string) self::get( $key );
		$name  = (string) array_search( $key, self::TRANSLATABLE, true );
		if ( '' !== $value ) {
			return Language::translate_string( $name, $value, $lang );
		}
		return match ( $key ) {
			'success_message' => __( 'Thank you. Your message has been sent.', 'estrategia-web' ),
			'error_message'   => __( 'The message could not be sent. Please check the form and try again.', 'estrategia-web' ),
			default           => '',
		};
	}
}
