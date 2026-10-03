<?php
/**
 * Shortcode `[armazon_company key="…"]` (0.4.0): un dato de la empresa DENTRO
 * de una frase. Es lo que necesita el aviso legal (LSSI, art. 10):
 *
 *   <!-- wp:paragraph -->
 *   <p>Titular: [armazon_company key="legal_name"], NIF [armazon_company key="tax_id"],
 *   con domicilio en [armazon_company key="address"]. Teléfono [armazon_company key="phone"],
 *   correo [armazon_company key="email"]. Inscrita en el [armazon_company key="registry"].</p>
 *   <!-- /wp:paragraph -->
 *
 * Por qué un shortcode y no un binding ni un bloque: un binding de WP 7.1
 * sustituye el contenido ENTERO del párrafo (no hay bindings en línea), así que
 * una frase con seis datos serían seis párrafos. Un bloque propio en línea
 * (format type) necesita JS compilado y guarda marcado que el editor tiene que
 * conocer; el shortcode es una línea de PHP, se escribe a mano o en la carga
 * de contenido, y funciona dentro de cualquier bloque de texto.
 *
 * Cómo pinta: el mismo valor que Company::value() (todas sus claves, también
 * `social.<red>` y las derivadas), escapado con esc_html y en UNA línea: las
 * claves de varias líneas (dirección, horario) salen unidas con «, », porque
 * van dentro de una frase. Una clave que no existe o vacía no pinta nada.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: nadie, al añadir una clave: sale sola de Company::value().
 *   Si cambia cómo se escapa un valor en Bindings::format, mirar si aquí
 *   también toca (aquí no hay <br> a propósito).
 * - Caso frontera: en el EDITOR el shortcode se ve tal cual, entre corchetes;
 *   el valor solo sale en la web. Es lo que se acepta a cambio de no compilar
 *   nada. Para un dato SOLO en su párrafo, mejor el binding, que sí se ve.
 * - La trampa: do_shortcode corre en `the_content` (prioridad 11) y en las
 *   plantillas de bloques, pero NO en un atributo de bloque ni en el título.
 *   Y nada de shortcode dentro de un enlace: la url no se pinta escapada para
 *   atributo; para `href`, el binding de botón o enlace.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const TAG = 'armazon_company';

	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/** @param array|string $atts {key: "legal_name"} */
	public static function render( $atts ): string {
		$key = is_array( $atts ) && isset( $atts['key'] ) ? (string) $atts['key'] : '';
		if ( '' === $key ) {
			return '';
		}
		return esc_html( self::inline( Company::value( $key ) ) );
	}

	/** Varias líneas a una: «Calle Mayor 1\n07001 Palma» → «Calle Mayor 1, 07001 Palma». */
	public static function inline( string $value ): string {
		// Sin la coma final que alguien ya escribió («Calle Mayor 1,»): no sale «,,».
		$lines = array_filter( array_map( static fn( $l ) => rtrim( trim( $l ), ',' ), preg_split( '/\R/', $value ) ), 'strlen' );
		return implode( ', ', $lines );
	}
}
