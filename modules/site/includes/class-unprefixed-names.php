<?php
/**
 * The UNPREFIXED type and taxonomy names from before 1.2.0 («client»,
 * «client_sector»…), as ALIASES of the current ones («eweb_client»; see
 * Type_Names). Until 2.0.0.
 *
 * What it covers, and why each thing:
 * - QUERIES (core/query) saved with "postType":"client" or with
 *   "taxQuery":{"client_sector":[…]}: at render time (render_block_data) the
 *   name is swapped, and the output is the same as with the new one. It has
 *   to be there and not in query_loop_block_query_vars: core DISCARDS a
 *   taxonomy that does not exist earlier (is_taxonomy_viewable), and the
 *   filter no longer sees it.
 * - TERMS (core/post-terms) with "term":"client_sector": the same.
 * - REST ROUTES /wp/v2/client, /wp/v2/client/12, /wp/v2/client_sector…: they
 *   are answered as /wp/v2/eweb_client… (rest_pre_dispatch), ONLY if nobody
 *   else has registered that old route (a plugin with its own «client» wins).
 *   For load scripts that have not been brought up to date; ours (cargar.mjs,
 *   bajar-contenido) already use the new names.
 *
 * What it does NOT cover, on purpose:
 * - The EDITOR. An old Query opened in the editor asks for «client» records,
 *   which does not exist, and comes out empty; on save it stays old. The
 *   migrator (includes/class-prefix-migration.php) rewrites the content, so
 *   this only happens with markup it did not see: a restored revision, a
 *   pattern pasted from another site.
 * - get_posts( array( 'post_type' => 'client' ) ) from a theme's code: returns
 *   empty. A theme that asks for our types uses
 *   estrategia_web_type_name( 'client' ) (modules/site/includes/functions.php).
 * - ?post_type=client in a URL: not used on these sites.
 *
 * How to maintain:
 * - Who: whoever touches the plugin. WHEN IT GOES: in 2.0.0, together with
 *   Legacy_Names, once every site has run the 1.2.0 migrator (15-10-2026: only
 *   Lavandería Mallorca).
 * - A new type does NOT go in here: it is born prefixed (Type_Names).
 * - Edge case, by name: a Query with "postType":"post" or "page" is not
 *   touched (not one of our keys); one with "eweb_client" neither (it is
 *   already new). Only the eight keys of Type_Names::KEYS.
 * - The trap: the REST alias forwards with rest_do_request(), which goes
 *   through rest_pre_dispatch again; that is why it only acts if the route is
 *   old, and the new one is not: there is no loop.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Unprefixed_Names {

	public static function boot(): void {
		add_filter( 'render_block_data', array( self::class, 'block_data' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest' ), 10, 3 );
	}

	/**
	 * The attributes of a Query or of a terms block with the new names.
	 *
	 * @param array $block Parsed block (blockName, attrs, innerBlocks…).
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

	/** A Query's «query» attribute with the new names (postType and the taxQuery keys). */
	public static function query( array $query ): array {
		if ( isset( $query['postType'] ) && is_string( $query['postType'] ) ) {
			$query['postType'] = self::renamed( $query['postType'] );
		}
		if ( isset( $query['taxQuery'] ) && is_array( $query['taxQuery'] ) ) {
			$query['taxQuery'] = self::tax_query( $query['taxQuery'] );
		}
		return $query;
	}

	/** taxQuery: {"client_sector":[3]} or, since WordPress 6.8, {"include":{"client_sector":[3]}}. */
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

	/** An old name («client») → the new one; anything else, unchanged. */
	public static function renamed( string $name ): string {
		return Type_Names::renamed()[ $name ] ?? $name;
	}

	/**
	 * /wp/v2/client… answered as /wp/v2/eweb_client…
	 *
	 * @param mixed            $result  Null if nobody has answered yet.
	 * @param \WP_REST_Server  $server  The server.
	 * @param \WP_REST_Request $request The request.
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
			return $result; // Someone else has that name registered: it is theirs.
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
