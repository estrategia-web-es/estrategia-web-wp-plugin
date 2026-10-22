<?php
/**
 * TESTS ONLY. Loaded by the mu-plugin that tests/blueprint.json writes in the
 * Playground WordPress; it does nothing without
 * ESTRATEGIA_WEB_LOCAL_BUSINESS_TESTING, which only that loader defines (the
 * same double lock as the other test mu-plugins: the route is open to
 * anyone).
 *
 * DOUBLES OF THE SEO PLUGINS, so tests/output.test.mjs can check the merge
 * without installing Rank Math or Yoast (both are hundreds of files and
 * neither is in the test site). Each one does, at wp_head priority 1, what
 * the real one does with its graph: builds its own nodes, passes them
 * through ITS PUBLIC FILTER and prints the result.
 *
 *   rankmath        rank_math/json_ld, keyed: publisher (["Person","Organization"],
 *                   @id …/#person, as Lavandería's Rank Math publishes it on
 *                   22-10-2026), WebSite, WebPage; printed as array_values().
 *   rankmath-local  the same, with its Local SEO on: publisher is a
 *                   DryCleaningOrLaundry with an address.
 *   yoast           wpseo_schema_graph, a list: Organization …/#organization, WebPage.
 *   (none)          nothing: the module prints its own script.
 *
 *   POST estrategia-web-lb-test/v1/host {"host": "rankmath" | "rankmath-local" | "yoast" | ""}
 *
 * How to maintain: if a real plugin changes its filter's signature or shape,
 * the double changes with it, and the module's Output after it.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_LOCAL_BUSINESS_TESTING' ) || true !== ESTRATEGIA_WEB_LOCAL_BUSINESS_TESTING ) {
	return;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'estrategia-web-lb-test/v1',
			'/host',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					update_option( 'estrategia_web_lb_test_host', (string) $r->get_param( 'host' ) );
					return array( 'host' => get_option( 'estrategia_web_lb_test_host' ) );
				},
			)
		);
	}
);

add_action(
	'wp_head',
	static function () {
		$host = (string) get_option( 'estrategia_web_lb_test_host', '' );
		$home = trailingslashit( home_url() );
		if ( str_starts_with( $host, 'rankmath' ) ) {
			$publisher = 'rankmath-local' === $host
				? array( '@type' => array( 'DryCleaningOrLaundry', 'Organization' ), '@id' => $home . '#organization', 'name' => 'RM Local', 'address' => array( '@type' => 'PostalAddress', 'streetAddress' => 'RM street', 'addressLocality' => 'Palma' ) )
				: array( '@type' => array( 'Person', 'Organization' ), '@id' => $home . '#person', 'name' => 'RM name', 'logo' => array( '@type' => 'ImageObject', 'url' => $home . 'rm-logo.svg' ) );
			$data = array(
				'publisher' => $publisher,
				'WebSite'   => array( '@type' => 'WebSite', '@id' => $home . '#website', 'publisher' => array( '@id' => $publisher['@id'] ) ),
				'WebPage'   => array( '@type' => 'WebPage', '@id' => $home . '#webpage' ),
			);
			$data = apply_filters( 'rank_math/json_ld', $data, null );
			echo '<script type="application/ld+json" class="rank-math-schema">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => array_values( $data ) ) ) . "</script>\n"; // phpcs:ignore
		} elseif ( 'yoast' === $host ) {
			$graph = array(
				array( '@type' => 'Organization', '@id' => $home . '#organization', 'name' => 'Yoast name' ),
				array( '@type' => 'WebPage', '@id' => $home . '#webpage' ),
			);
			$graph = apply_filters( 'wpseo_schema_graph', $graph, null );
			echo '<script type="application/ld+json" class="yoast-schema-graph">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ) ) . "</script>\n"; // phpcs:ignore
		}
	},
	1
);
