<?php
/**
 * Los nombres de tipo y taxonomía SIN PREFIJO de antes de 1.2.0 («client»,
 * «client_sector»…), como ALIAS de los de ahora («eweb_client»; ver
 * Type_Names). Hasta la 2.0.0.
 *
 * Qué cubre, y por qué cada cosa:
 * - CONSULTAS (core/query) guardadas con "postType":"client" o con
 *   "taxQuery":{"client_sector":[…]}: al pintar (render_block_data) se les
 *   cambia el nombre, y sale lo mismo que con el nuevo. Tiene que ser ahí y
 *   no en query_loop_block_query_vars: el núcleo DESCARTA antes una taxonomía
 *   que no existe (is_taxonomy_viewable), y el filtro ya no la ve.
 * - TÉRMINOS (core/post-terms) con "term":"client_sector": igual.
 * - RUTAS REST /wp/v2/client, /wp/v2/client/12, /wp/v2/client_sector…: se
 *   contestan como /wp/v2/eweb_client… (rest_pre_dispatch), SOLO si nadie
 *   más ha registrado esa ruta vieja (un plugin con su propio «client» manda).
 *   Para scripts de carga que no se hayan puesto al día; los nuestros
 *   (cargar.mjs, bajar-contenido) ya usan los nombres nuevos.
 *
 * Qué NO cubre, a propósito:
 * - El EDITOR. Una Consulta vieja abierta en el editor pide los registros de
 *   «client», que no existe, y sale vacía; al guardar se queda vieja. El
 *   migrador (includes/class-prefix-migration.php) reescribe el contenido,
 *   así que esto solo pasa con marcado que él no vio: una revisión
 *   restaurada, un patrón pegado de otra web.
 * - get_posts( array( 'post_type' => 'client' ) ) desde el código de un tema:
 *   devuelve vacío. Un tema que pida nuestros tipos usa
 *   estrategia_web_type_name( 'client' ) (modules/site/includes/functions.php).
 * - ?post_type=client en una URL: no se usa en estas webs.
 *
 * Cómo se mantiene:
 * - Quién: quien toque el plugin. CUÁNDO SE QUITA: en la 2.0.0, con
 *   Legacy_Names, cuando todas las webs hayan pasado el migrador de la 1.2.0
 *   (15-10-2026: solo Lavandería Mallorca).
 * - Un tipo nuevo NO entra aquí: nace con prefijo (Type_Names).
 * - Caso frontera, con nombre: una Consulta con "postType":"post" o "page"
 *   no se toca (no es una clave nuestra); una con "eweb_client" tampoco (ya
 *   es nueva). Solo las ocho claves de Type_Names::KEYS.
 * - La trampa: el alias REST reenvía con rest_do_request(), que vuelve a
 *   pasar por rest_pre_dispatch; por eso solo actúa si la ruta es vieja, y la
 *   nueva no lo es: no hay bucle.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Unprefixed_Names {

	public static function boot(): void {
		add_filter( 'render_block_data', array( self::class, 'block_data' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest' ), 10, 3 );
	}

	/**
	 * Los atributos de una Consulta o de unos términos con los nombres nuevos.
	 *
	 * @param array $block Bloque analizado (blockName, attrs, innerBlocks…).
	 */
	public static function block_data( $block ): array {
		$block = (array) $block;
		$name  = $block['blockName'] ?? '';
		if ( 'core/query' === $name && isset( $block['attrs']['query'] ) && is_array( $block['attrs']['query'] ) ) {
			$block['attrs']['query'] = self::query( $block['attrs']['query'] );
		} elseif ( 'core/post-terms' === $name && isset( $block['attrs']['term'] ) ) {
			$block['attrs']['term'] = self::renamed( (string) $block['attrs']['term'] );
		}
		return $block;
	}

	/** El atributo «query» de una Consulta con los nombres nuevos (postType y las claves de taxQuery). */
	public static function query( array $query ): array {
		if ( isset( $query['postType'] ) && is_string( $query['postType'] ) ) {
			$query['postType'] = self::renamed( $query['postType'] );
		}
		if ( isset( $query['taxQuery'] ) && is_array( $query['taxQuery'] ) ) {
			$query['taxQuery'] = self::tax_query( $query['taxQuery'] );
		}
		return $query;
	}

	/** taxQuery: {"client_sector":[3]} o, desde WordPress 6.8, {"include":{"client_sector":[3]}}. */
	private static function tax_query( array $tax ): array {
		$out = array();
		foreach ( $tax as $key => $value ) {
			if ( is_array( $value ) && in_array( $key, array( 'include', 'exclude' ), true ) ) {
				$out[ $key ] = self::tax_query( $value );
				continue;
			}
			$out[ is_string( $key ) ? self::renamed( $key ) : $key ] = $value;
		}
		return $out;
	}

	/** Un nombre viejo («client») → el nuevo; cualquier otro, igual. */
	public static function renamed( string $name ): string {
		return Type_Names::renamed()[ $name ] ?? $name;
	}

	/**
	 * /wp/v2/client… contestado como /wp/v2/eweb_client…
	 *
	 * @param mixed            $result  Null si nadie ha contestado aún.
	 * @param \WP_REST_Server  $server  El servidor.
	 * @param \WP_REST_Request $request La petición.
	 * @return mixed
	 */
	public static function rest( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		$route = $request->get_route();
		if ( ! preg_match( '#^/wp/v2/([a-z_]+)(/.*)?$#', $route, $m ) || ! isset( Type_Names::renamed()[ $m[1] ] ) ) {
			return $result;
		}
		$routes = $server->get_routes();
		if ( isset( $routes[ '/wp/v2/' . $m[1] ] ) ) {
			return $result; // Alguien más tiene ese nombre registrado: es suyo.
		}
		$new = new \WP_REST_Request( $request->get_method(), '/wp/v2/' . Type_Names::renamed()[ $m[1] ] . ( $m[2] ?? '' ) );
		$new->set_query_params( (array) $request->get_query_params() );
		$new->set_body_params( (array) $request->get_body_params() );
		$new->set_body( (string) $request->get_body() );
		$new->set_file_params( (array) $request->get_file_params() );
		$new->set_headers( (array) $request->get_headers() );
		return rest_do_request( $new );
	}
}
