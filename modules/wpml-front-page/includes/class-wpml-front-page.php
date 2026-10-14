<?php
/**
 * La portada traducida con WPML: /en/ enseña la portada en inglés (0.5.3).
 *
 * Qué hace: en la parte pública, `page_on_front` (y `page_for_posts`) se
 * traducen al idioma de la petición con `wpml_object_id`. Es lo que WPML
 * debería hacer solo, y en el WordPress de pruebas lo hace; en el servidor de
 * Lavandería Mallorca (WPML 5.0.2, 06-10-2026) NO lo hacía: con el inglés
 * activo y la portada inglesa bien enlazada (mismo trid en icl_translations),
 * WordPress seguía viendo la castellana en /en/, `is_front_page()` daba falso
 * y `redirect_canonical` mandaba /en/ → /en/home/ (sin la redirección, 404).
 * Se midió con una cabecera de diagnóstico: page_on_front=6 con lang=en y
 * ningún filtro sobre option_page_on_front. No se averiguó por qué WPML no lo
 * engancha allí; este filtro lo cubre en cualquier caso.
 *
 * Cómo se mantiene:
 * - Si WPML ya ha traducido el valor, `wpml_object_id` devuelve el mismo id:
 *   el filtro no estorba cuando WPML sí funciona (por eso no se mira antes).
 * - Sin WPML no hace nada: el filtro `wpml_object_id` no existe y se devuelve
 *   el valor tal cual.
 * - Solo en la parte pública: en el escritorio, la opción tiene que ser la del
 *   idioma por defecto, que es la que se guarda.
 * - El caso frontera: una portada SIN traducción al idioma pedido. Se devuelve
 *   la original (return_original = true), que es lo que enseña WPML con
 *   «mostrar como traducido».
 * - La trampa: comprobarlo por WP-CLI no sirve, porque WPML no carga su parte
 *   pública allí; hay que pedir la página (curl a /en/ debe dar 200, no 301).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Wpml_Front_Page {

	public static function boot(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! apply_filters( 'estrategia_web_site_wpml_front_page', true ) ) {
			return;
		}
		add_filter( 'option_page_on_front', array( self::class, 'translate' ), 20 );
		add_filter( 'option_page_for_posts', array( self::class, 'translate' ), 20 );
	}

	/** @param mixed $id */
	public static function translate( $id ) {
		$id = (int) $id;
		if ( ! $id || ! has_filter( 'wpml_object_id' ) ) {
			return $id;
		}
		return (int) apply_filters( 'wpml_object_id', $id, 'page', true );
	}
}
