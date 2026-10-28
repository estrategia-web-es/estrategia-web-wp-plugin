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
 *   or, for ONE request, ?ew_lb_host=<the same> on the page's URL (28-10-2026):
 *   tests/output.test.mjs reads the same page with each double in parallel
 *   instead of switching the option and reading one after another.
 *
 * And, with REAL WPML only (tests/output-wpml.test.mjs, 28-10-2026):
 *
 *   POST estrategia-web-lb-test/v1/wpml-options {options: {name: value}}
 *   update_option() of each (the company details, the business type): what
 *   /wp/v2/settings would do, without the application password that costs
 *   that test one more request.
 *
 *   POST estrategia-web-lb-test/v1/wpml-seed {type, location: {slug, title, title_en, meta},
 *        page: {slug, slug_en, title, title_en, content}}
 *   in ONE request what the test used to do in eight: deletes the posts with
 *   those slugs in every language, re-reads the wpml-config.xml files (what
 *   a deploy does: until then WPML does not treat the location type as
 *   translatable), creates the location and the page in Spanish and their
 *   English translations linked to them (estrategia_web_test_wpml_link(),
 *   tests/wp/estrategia-web-wpml-test.php) and returns the four URLs.
 *   The location type must be enabled in a request BEFORE this one (the
 *   definition is registered from the next request).
 *
 * How to maintain: if a real plugin changes its filter's signature or shape,
 * the double changes with it, and the module's Output after it. The seed
 * creates content as WPML's own translation would leave it; if a test needs
 * more (a second location, a term), it goes in its params, not in a new route.
 * The trap: a Spanish post created here must get its WPML language row from
 * WPML's own save_post hook; the route checks it and answers 500 if not
 * (a missing row looks like «WPML does nothing», never like an error).
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

		if ( ! function_exists( 'estrategia_web_test_wpml_link' ) ) {
			return;
		}
		// {options: {name: value}}: update_option() of each, without a user (the WPML test runs
		// no REST call that needs one: an application password is one request less per run).
		register_rest_route(
			'estrategia-web-lb-test/v1',
			'/wpml-options',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					foreach ( (array) $r->get_param( 'options' ) as $name => $value ) {
						update_option( (string) $name, $value );
					}
					return array( 'ok' => true );
				},
			)
		);
		register_rest_route(
			'estrategia-web-lb-test/v1',
			'/wpml-seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					global $wpdb;
					wp_set_current_user( 1 );
					kses_remove_filters();
					try {
						$type = (string) $r->get_param( 'type' );
						$loc  = (array) $r->get_param( 'location' );
						$page = (array) $r->get_param( 'page' );
						foreach ( array( $loc['slug'], $loc['slug'] . '-en', $page['slug'], $page['slug_en'] ) as $slug ) {
							foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s OR post_name LIKE %s", $slug, $wpdb->esc_like( $slug ) . '-%' ) ) as $id ) { // phpcs:ignore
								wp_delete_post( (int) $id, true );
							}
						}
						WPML_Config::load_config_run();
						$insert = static function ( string $post_type, string $title, string $slug, string $content, array $meta ): int {
							$id = wp_insert_post( wp_slash( array( 'post_type' => $post_type, 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content, 'meta_input' => $meta ) ), true );
							if ( is_wp_error( $id ) ) {
								throw new RuntimeException( $id->get_error_message() );
							}
							return (int) $id;
						};
						$original = static function ( string $post_type, int $id ): void {
							$row = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $id, 'element_type' => $post_type ) );
							if ( ! $row || 'es' !== ( $row->language_code ?? '' ) ) {
								throw new RuntimeException( "WPML gave $post_type $id no Spanish row: " . wp_json_encode( $row ) );
							}
						};
						$out = array();
						foreach ( array( array( $type, $loc['title'], $loc['title_en'], $loc['slug'], $loc['slug'] . '-en', '', 'own' ), array( 'page', $page['title'], $page['title_en'], $page['slug'], $page['slug_en'], (string) $page['content'], 'tied' ) ) as list( $post_type, $title, $title_en, $slug, $slug_en, $content, $key ) ) {
							$meta = 'page' === $post_type ? array() : (array) $loc['meta'];
							$es   = $insert( $post_type, $title, $slug, $content, $meta );
							$original( $post_type, $es );
							$en = $insert( $post_type, $title_en, $slug_en, $content, $meta );
							estrategia_web_test_wpml_link( 'post_' . $post_type, $en, 'en', $es );
							clean_post_cache( $en );
							$out[ $key ]        = get_permalink( $es );
							$out[ $key . 'En' ] = apply_filters( 'wpml_permalink', get_permalink( $en ), 'en' );
						}
						return $out;
					} catch ( Throwable $e ) {
						return new WP_Error( 'lb_wpml_seed', $e->getMessage(), array( 'status' => 500 ) );
					}
				},
			)
		);
	}
);

add_action(
	'wp_head',
	static function () {
		$host = isset( $_GET['ew_lb_host'] ) ? sanitize_key( wp_unslash( $_GET['ew_lb_host'] ) ) : (string) get_option( 'estrategia_web_lb_test_host', '' ); // phpcs:ignore WordPress.Security.NonceVerification
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
