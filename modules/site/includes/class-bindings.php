<?php
/**
 * Fuente de Block Bindings `estrategia-web-site/company`: un párrafo, un encabezado,
 * un botón o una imagen enlazados a un dato de la empresa.
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"phone"}}}}} -->
 *   <p></p>
 *   <!-- /wp:paragraph -->
 *
 * Dos mitades, y hacen falta las dos:
 * - AQUÍ (PHP) se pinta en la web: get_value() devuelve el valor para el
 *   atributo enlazado.
 * - assets/editor.js (JS, sin compilar) hace que el EDITOR enseñe el valor y
 *   que la fuente salga en el panel «Atributos» de los bloques. Sin ella, el
 *   editor enseña el texto de relleno del bloque y no deja elegir la fuente.
 *   Los valores le llegan por wp_add_inline_script (enqueue_editor), no por
 *   REST: /wp/v2/settings solo lo puede leer un administrador, y un editor
 *   también tiene que ver el teléfono que está enlazando.
 *
 * Cómo se mantiene:
 * - Una clave nueva se añade en Company (no aquí): esta clase no tiene lista.
 * - Desde 0.2.0 hay una segunda fuente, `estrategia-web-site/location` (los datos de
 *   UNA sede), en includes/class-location.php. Comparte format() con esta y
 *   el mismo script de editor; sus datos le llegan en window.estrategiaWebSiteLocation.
 *   Si cambias cómo se pinta un texto o una URL, cambia en las dos: es lo que
 *   se quiere.
 * - Caso frontera: dirección y horario tienen varias líneas. En atributos de
 *   TEXTO (content, text, alt…) los saltos salen como <br>; en atributos de
 *   URL (url, href) el valor va tal cual. Por eso se mira $attribute_name.
 * - La trampa: el núcleo escapa por su cuenta lo que se pone en un atributo
 *   HTML (url), pero en el contenido enriquecido solo pasa wp_kses_post. Si
 *   aquí no se escapara, un «<» en el nombre de la empresa sería HTML.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Bindings {

	public const SOURCE = 'estrategia-web-site/company';

	/** Atributos de bloque que llevan una URL (el resto se trata como texto). */
	private const URL_ATTRIBUTES = array( 'url', 'href' );

	public static function register(): void {
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Company details', 'estrategia-web' ),
				'get_value_callback' => array( self::class, 'get_value' ),
			)
		);
	}

	/**
	 * @param array     $args           args del enlace: {"key": "phone"}.
	 * @param \WP_Block $block_instance El bloque que se pinta.
	 * @param string    $attribute_name El atributo enlazado (content, url…).
	 */
	public static function get_value( array $args, $block_instance, string $attribute_name ): ?string {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';
		if ( '' === $key ) {
			return null;
		}
		return self::format( Company::value( $key ), $attribute_name );
	}

	/**
	 * Un valor en texto plano, listo para el atributo enlazado. Lo comparten
	 * esta fuente y `estrategia-web-site/location` (Location): el mismo tratamiento
	 * en las dos, a propósito.
	 */
	public static function format( string $value, string $attribute_name ): string {
		if ( in_array( $attribute_name, self::URL_ATTRIBUTES, true ) ) {
			return '' === $value ? '' : esc_url_raw( $value, array( 'http', 'https', 'tel', 'mailto' ) );
		}
		return nl2br( esc_html( $value ), false );
	}

	/** Valores y nombres para el editor (assets/editor.js). */
	public static function enqueue_editor(): void {
		wp_enqueue_script(
			'estrategia-web-site-editor',
			ESTRATEGIA_WEB_SITE_URL . 'assets/editor.js',
			array( 'wp-blocks', 'wp-data', 'wp-block-editor' ),
			ESTRATEGIA_WEB_SITE_VERSION,
			false
		);
		$data = array(
			'source' => self::SOURCE,
			'values' => Company::values(),
			'labels' => Company::labels(),
		);
		wp_add_inline_script( 'estrategia-web-site-editor', 'window.estrategiaWebSiteCompany = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_add_inline_script( 'estrategia-web-site-editor', 'window.estrategiaWebSiteLocation = ' . wp_json_encode( Location::editor_data() ) . ';', 'before' );
	}
}
