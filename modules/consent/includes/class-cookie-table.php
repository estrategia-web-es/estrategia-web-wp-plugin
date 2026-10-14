<?php
/**
 * La TABLA DE COOKIES para la página de Política de cookies: las cookies
 * CONFIRMADAS en «Estrategia Web → Cookies», agrupadas por categoría, con
 * nombre, proveedor, finalidad y duración.
 *
 * Dónde se usa: el bloque estrategia-web/cookie-table (blocks/cookie-table,
 * sin editor propio: autoRegister) y el shortcode [estrategia_web_cookie_table
 * category="statistics" heading_level="3"], para quien edite la política sin
 * bloques. Los dos pintan lo mismo (render()).
 *
 * Traducción, en tres capas:
 * - Lo FIJO (cabeceras de la tabla, nombre y descripción de cada categoría):
 *   gettext, dominio estrategia-web (.po del plugin).
 * - La DURACIÓN, si es de la forma «2 years», «30 minutes», «session»
 *   (como viene de la base externa y de base()): se escribe con _n() en el
 *   idioma de la página. Si el administrador escribió otra cosa («Hasta
 *   cerrar el navegador»), se trata como texto libre (siguiente punto).
 * - Lo que ESCRIBE el administrador (proveedor, finalidad, duración libre):
 *   WPML String Translation, contexto «estrategia-web-cookies», nombres
 *   «purpose <cookie>», «provider <cookie>», «duration <cookie>». Se
 *   registran al guardar (register_strings) y se traducen al pintar con
 *   wpml_translate_single_string. Sin WPML, se pinta tal cual.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque el aviso o la tabla. Prueba:
 *   modules/consent/tests/cookies.test.mjs (nivel 2).
 * - Una unidad de duración nueva: a duration() con su _n().
 * - Caso frontera: ninguna cookie confirmada. En la web no se pinta NADA (una
 *   tabla vacía en la política diría «no usamos cookies», y es falso: como
 *   mínimo está la del aviso). En el editor sale una nota que dice por qué.
 * - La trampa: las cadenas de WPML se registran AL GUARDAR, no al pintar. Una
 *   fila que el detector creó y nadie ha guardado en la pantalla no está en
 *   String Translation; tampoco sale en la tabla (no está confirmada), así
 *   que no se nota. Confirmar = guardar = registrar.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Cookie_Table {

	public const BLOCK         = 'estrategia-web/cookie-table';
	public const WPML_CONTEXT  = 'estrategia-web-cookies';
	public const SHORTCODE     = 'estrategia_web_cookie_table';

	/** Nombre y descripción de cada categoría, para la tabla y la pantalla. */
	public static function category_labels(): array {
		$known  = Consent::known_categories();
		$labels = array(
			'necessary' => array(
				'label'       => __( 'Necessary', 'estrategia-web' ),
				'description' => __( 'Required for the site to work, including remembering this choice. Always active.', 'estrategia-web' ),
			),
		);
		foreach ( Cookie_Registry::CATEGORIES as $slug ) {
			if ( isset( $known[ $slug ] ) ) {
				$labels[ $slug ] = $known[ $slug ];
			}
		}
		return $labels;
	}

	/** Registra en WPML los textos libres de una fila (al guardarla). */
	public static function register_strings( string $key, array $entry ): void {
		foreach ( array( 'provider', 'purpose', 'duration' ) as $field ) {
			if ( '' !== (string) $entry[ $field ] && ( 'duration' !== $field || null === self::duration_parts( (string) $entry[ $field ] ) ) ) {
				do_action( 'wpml_register_single_string', self::WPML_CONTEXT, $field . ' ' . $key, (string) $entry[ $field ] );
			}
		}
	}

	private static function translated( string $field, string $key, string $value ): string {
		return '' === $value ? '' : (string) apply_filters( 'wpml_translate_single_string', $value, self::WPML_CONTEXT, $field . ' ' . $key );
	}

	/**
	 * «2 years» → array( 2, 'year' ); «session» → array( 0, 'session' ); otra cosa → null.
	 *
	 * @return array{0: int, 1: string}|null
	 */
	public static function duration_parts( string $value ): ?array {
		$value = strtolower( trim( $value ) );
		if ( 'session' === $value ) {
			return array( 0, 'session' );
		}
		if ( preg_match( '/^(\d+)\s*(second|minute|hour|day|week|month|year)s?$/', $value, $m ) ) {
			return array( (int) $m[1], $m[2] );
		}
		return null;
	}

	/** La duración en el idioma de la página (ver la cabecera). */
	public static function duration( string $key, string $value ): string {
		$parts = self::duration_parts( $value );
		if ( null === $parts ) {
			return self::translated( 'duration', $key, $value );
		}
		list( $n, $unit ) = $parts;
		switch ( $unit ) {
			case 'session':
				return __( 'Session', 'estrategia-web' );
			case 'second':
				/* translators: %d: number of seconds. */
				return sprintf( _n( '%d second', '%d seconds', $n, 'estrategia-web' ), $n );
			case 'minute':
				/* translators: %d: number of minutes. */
				return sprintf( _n( '%d minute', '%d minutes', $n, 'estrategia-web' ), $n );
			case 'hour':
				/* translators: %d: number of hours. */
				return sprintf( _n( '%d hour', '%d hours', $n, 'estrategia-web' ), $n );
			case 'day':
				/* translators: %d: number of days. */
				return sprintf( _n( '%d day', '%d days', $n, 'estrategia-web' ), $n );
			case 'week':
				/* translators: %d: number of weeks. */
				return sprintf( _n( '%d week', '%d weeks', $n, 'estrategia-web' ), $n );
			case 'month':
				/* translators: %d: number of months. */
				return sprintf( _n( '%d month', '%d months', $n, 'estrategia-web' ), $n );
			default:
				/* translators: %d: number of years. */
				return sprintf( _n( '%d year', '%d years', $n, 'estrategia-web' ), $n );
		}
	}

	/**
	 * El HTML de la tabla.
	 *
	 * @param array $attributes category ('' = todas), headingLevel (2-6).
	 */
	public static function render( array $attributes ): string {
		$only    = (string) ( $attributes['category'] ?? '' );
		$level   = min( 6, max( 2, (int) ( $attributes['headingLevel'] ?? 3 ) ) );
		$groups  = Cookie_Registry::confirmed_by_category();
		$labels  = self::category_labels();
		if ( '' !== $only ) {
			$groups = array_intersect_key( $groups, array( $only => true ) );
		}
		if ( ! $groups ) {
			if ( wp_is_serving_rest_request() ) {
				return sprintf(
					'<p %s>%s</p>',
					get_block_wrapper_attributes(),
					esc_html__( 'Cookie table: there are no confirmed cookies yet. Confirm them in Estrategia Web → Cookies and they will appear here.', 'estrategia-web' )
				);
			}
			return '';
		}

		$html = '';
		foreach ( $groups as $category => $entries ) {
			$label = $labels[ $category ] ?? array(
				'label'       => $category,
				'description' => '',
			);
			$html .= sprintf( '<h%1$d class="estrategia-web-cookie-table__title">%2$s</h%1$d>', $level, esc_html( $label['label'] ) );
			if ( '' !== $label['description'] ) {
				$html .= '<p class="estrategia-web-cookie-table__description">' . esc_html( $label['description'] ) . '</p>';
			}
			$html .= '<figure class="wp-block-table estrategia-web-cookie-table__figure"><table class="estrategia-web-cookie-table__table"><thead><tr>';
			$html .= '<th scope="col">' . esc_html__( 'Cookie', 'estrategia-web' ) . '</th>';
			$html .= '<th scope="col">' . esc_html__( 'Provider', 'estrategia-web' ) . '</th>';
			$html .= '<th scope="col">' . esc_html__( 'Purpose', 'estrategia-web' ) . '</th>';
			$html .= '<th scope="col">' . esc_html__( 'Duration', 'estrategia-web' ) . '</th>';
			$html .= '</tr></thead><tbody>';
			foreach ( $entries as $key => $entry ) {
				$html .= sprintf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( (string) $key ),
					esc_html( self::translated( 'provider', (string) $key, (string) $entry['provider'] ) ),
					esc_html( self::translated( 'purpose', (string) $key, (string) $entry['purpose'] ) ),
					esc_html( self::duration( (string) $key, (string) $entry['duration'] ) )
				);
			}
			$html .= '</tbody></table></figure>';
		}
		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => 'estrategia-web-cookie-table' ) ), $html );
	}

	/**
	 * [estrategia_web_cookie_table category="…" heading_level="3"].
	 *
	 * @param array|string $atts Atributos del shortcode.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'category'      => '',
				'heading_level' => 3,
			),
			$atts,
			self::SHORTCODE
		);
		return render_block(
			array(
				'blockName'    => self::BLOCK,
				'attrs'        => array(
					'category'     => (string) $atts['category'],
					'headingLevel' => (int) $atts['heading_level'],
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
}
