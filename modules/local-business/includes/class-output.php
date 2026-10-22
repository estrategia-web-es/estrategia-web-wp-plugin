<?php
/**
 * WHERE THE GRAPH GOES: into the SEO plugin's graph if there is one, or our
 * own <script type="application/ld+json"> if not. One graph per page, never
 * two scripts describing the same business.
 *
 *   Rank Math   filter rank_math/json_ld ($data keyed: publisher, WebSite…),
 *               at 999, after its own pieces and its Local SEO.
 *   Yoast       filter wpseo_schema_graph ($graph, a list), at 999. Chosen
 *               over wpseo_schema_graph_pieces: the pieces API wants a class
 *               per node with Yoast's own context, and the result is the same
 *               list; this way the merge is one function (Graph::merge()).
 *   neither     wp_head at 99: our own script.
 *
 * HOW «is there an SEO plugin» IS DECIDED: not by asking whether it is
 * active, but by whether its filter RAN on this page. Both print their
 * JSON-LD early in wp_head (priority 1); if one of our filters ran by
 * priority 99, ours went inside theirs and the script is not printed. That
 * covers the cases detection would get wrong: Rank Math active with its
 * schema module off, Yoast with its schema switched off by a filter, a page
 * type they skip — in all of them their filter never runs and ours prints
 * alone, so the page is never left without it.
 *
 * Their settings are not touched (asked: «no toques sus ajustes»): only the
 * nodes of the graph they are about to print.
 *
 * How to maintain:
 * - Who and when: whoever adds another SEO plugin. One more filter that
 *   marks $delivered and calls Graph::merge() with its graph's shape; its
 *   double in tests/wp/ and its case in tests/output.test.mjs.
 * - The site's last word: the filter estrategia_web_local_business_graph
 *   gets [Organization|null, LocalBusiness[]] before anything is printed or
 *   merged (return [null, []] to say nothing on a page).
 * - The trap: Rank Math calls rank_math/json_ld ALSO outside the page head
 *   (its schema preview in the editor, over REST). There is no queried
 *   object there and Data::current() returns nothing: never build the page
 *   context from the global $post.
 */

namespace EstrategiaWeb\LocalBusiness;

defined( 'ABSPATH' ) || exit;

final class Output {

	/** Whether an SEO plugin's filter already took our nodes on this page. */
	private static bool $delivered = false;

	/** @var array{0: ?array, 1: array[]}|null */
	private static ?array $page = null;

	public static function boot(): void {
		add_filter( 'rank_math/json_ld', array( self::class, 'into_host' ), 999 );
		add_filter( 'wpseo_schema_graph', array( self::class, 'into_host' ), 999 );
		add_action( 'wp_head', array( self::class, 'standalone' ), 99 );
	}

	/** @return array{0: ?array, 1: array[]} */
	public static function page(): array {
		if ( null === self::$page ) {
			$page       = (array) apply_filters( 'estrategia_web_local_business_graph', Data::current() );
			self::$page = array( isset( $page[0] ) && is_array( $page[0] ) ? $page[0] : null, array_values( array_filter( (array) ( $page[1] ?? array() ), 'is_array' ) ) );
		}
		return self::$page;
	}

	/**
	 * Rank Math's and Yoast's filter.
	 *
	 * @param mixed $graph Their graph (keyed or a list).
	 * @return mixed
	 */
	public static function into_host( $graph ) {
		if ( ! is_array( $graph ) || is_admin() || wp_is_json_request() ) {
			return $graph;
		}
		self::$delivered = true;
		list( $org, $locations ) = self::page();
		return ( null === $org && ! $locations ) ? $graph : Graph::merge( $graph, $org, $locations );
	}

	/** Our own script, when no SEO plugin took the nodes. */
	public static function standalone(): void {
		if ( self::$delivered ) {
			return;
		}
		list( $org, $locations ) = self::page();
		$graph = Graph::standalone( $org, $locations );
		if ( ! $graph ) {
			return;
		}
		// JSON_HEX_TAG: a «</script>» in a name cannot close the tag.
		echo '<script type="application/ld+json" class="estrategia-web-local-business">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON, tags hex-escaped.
	}

	/** For the tests: forget what this request decided. */
	public static function reset(): void {
		self::$delivered = false;
		self::$page      = null;
	}
}
