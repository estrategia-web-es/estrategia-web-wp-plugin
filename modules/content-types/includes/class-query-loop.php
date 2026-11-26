<?php
namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

/**
 * A QUERY LOOP OF A NON-PUBLIC TYPE OF THIS MODULE lists that type (2.18.0).
 *
 * The trap (measured 26-11-2026, WP 7.1.4): core's
 * build_query_vars_from_query_block() only copies the block's postType
 * into the query when is_post_type_viewable() says yes. A type declared
 * «public»: false (Llar d'Estels' locations: no /seus/<x>/ pages) is not
 * viewable, so the Query Loop silently lists POSTS instead — no error, the
 * editor (REST) shows the right items and the site the wrong ones.
 *
 * What this does, on query_loop_block_query_vars: when the block asked for a
 * post type that WordPress dropped, it puts it back, and touches nothing else
 * (order, orderBy — menu_order included —, perPage, exclusions, taxonomies:
 * all as core built them). ONLY if the type is one this module registered in
 * this request (Registrar::registered()) AND it has show_in_rest (the block
 * editor can pick it and preview it). Never an arbitrary private type of core
 * or of another plugin.
 *
 * Why HERE (content-types) and not in modules/site, where the location binding
 * lives: this module declares the types, so it is the one that knows which
 * private types are ours; and it is not about locations — testimonials or
 * clients declared non-public need it just the same.
 *
 * How to maintain:
 * - Who and when: whoever touches how types are registered (Registrar) checks
 *   that registered() still lists them. Test: modules/site/tests/location.test.mjs
 *   (a Query of the non-public location type, ordered by menu_order).
 * - Edge case, with a name: core's own private `wp_block` (synced patterns)
 *   is non-public AND show_in_rest, and a Query Loop asking for it must keep
 *   listing posts as core decides — it is not ours. The same test checks it.
 *   A type of ours declared «show_in_rest»: false is not restored either.
 * - Priority 5, before the default 10: a site's own filter on the same hook
 *   sees the type the block asked for, not «post».
 * - The check is «core left 'post' and the block asked for something else»:
 *   if another filter already chose a type, it is left alone.
 */
final class Query_Loop {

	public static function boot(): void {
		add_filter( 'query_loop_block_query_vars', array( self::class, 'restore_type' ), 5, 2 );
	}

	/**
	 * @param array     $query The WP_Query args core built.
	 * @param \WP_Block $block The core/post-template being rendered (its context carries the query).
	 */
	public static function restore_type( $query, $block ): array {
		$query = (array) $query;
		$asked = ( is_object( $block ) && isset( $block->context['query']['postType'] ) ) ? $block->context['query']['postType'] : '';
		if ( ! is_string( $asked ) || '' === $asked || 'post' !== ( $query['post_type'] ?? 'post' ) || $asked === ( $query['post_type'] ?? '' ) ) {
			return $query;
		}
		if ( is_post_type_viewable( $asked ) || ! in_array( $asked, Registrar::registered(), true ) ) {
			return $query;
		}
		$object = get_post_type_object( $asked );
		if ( ! $object || empty( $object->show_in_rest ) ) {
			return $query;
		}
		$query['post_type'] = $asked;
		return $query;
	}
}
