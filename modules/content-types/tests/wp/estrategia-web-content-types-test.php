<?php
/**
 * TESTS ONLY. Loaded by the mu-plugin that tests/blueprint.json writes in the
 * Playground WordPress; on a real site nobody loads it. And even then it does
 * nothing without ESTRATEGIA_WEB_CONTENT_TYPES_TESTING, which only that loader
 * defines (the same double lock as tests/wp/estrategia-web-site-test.php:
 * these routes are open to anyone).
 *
 * What it gives:
 * - With the option estrategia_web_ct_test on, the folders of definitions
 *   are modules/content-types/examples/ (the real Gastronòmicament example),
 *   tests/fixtures/valid/ and tests/fixtures/broken/ — what a site does with
 *   the estrategia_web_content_types_dirs filter. Off: no folder, so the
 *   other modules' tests never see these types.
 * - estrategia-web-ct-test/v1/setup (POST {on}) turns it on or off, and off
 *   deletes the gment_ posts and terms.
 * - estrategia-web-ct-test/v1/state (GET): what this request registered,
 *   the errors per file, the hash option, the rewrite rules for the slugs,
 *   and the registered meta of each type.
 *
 * How to maintain: a new fixture folder goes in the filter below; anything
 * that must be measured from inside WordPress goes in /state.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_CONTENT_TYPES_TESTING' ) || true !== ESTRATEGIA_WEB_CONTENT_TYPES_TESTING ) {
	return;
}

add_filter(
	'estrategia_web_content_types_dirs',
	static function ( $dirs ) {
		if ( ! get_option( 'estrategia_web_ct_test' ) ) {
			return array();
		}
		$module = WP_CONTENT_DIR . '/plugins/estrategia-web/modules/content-types';
		return array( $module . '/examples', $module . '/tests/fixtures/valid', $module . '/tests/fixtures/broken' );
	}
);

add_action(
	'rest_api_init',
	static function () {
		$ns = 'estrategia-web-ct-test/v1';
		register_rest_route(
			$ns,
			'/setup',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					if ( $r->get_param( 'on' ) ) {
						update_option( 'estrategia_web_ct_test', 1 );
						return array( 'ok' => true );
					}
					global $wpdb;
					delete_option( 'estrategia_web_ct_test' );
					foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type LIKE 'gment\\_%'" ) as $id ) { // phpcs:ignore
						wp_delete_post( (int) $id, true );
					}
					$tt = $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE 'gment\\_%'" ); // phpcs:ignore
					if ( $tt ) {
						$in = implode( ',', array_map( 'intval', $tt ) );
						$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($in)" ); // phpcs:ignore
						$wpdb->query( "DELETE t FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} x ON x.term_id = t.term_id WHERE x.term_taxonomy_id IN ($in)" ); // phpcs:ignore
						$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($in)" ); // phpcs:ignore
					}
					return array( 'ok' => true );
				},
			)
		);
		// The constructor (17-10-2026): deletes every stored definition (and its revisions) and every gment_ post. Its tests start and end with it.
		register_rest_route(
			$ns,
			'/constructor-reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					global $wpdb;
					$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s OR post_type LIKE 'gment\\_%%'", \EstrategiaWeb\ContentTypes\Store::POST_TYPE ) ); // phpcs:ignore
					foreach ( $ids as $id ) {
						wp_delete_post( (int) $id, true );
					}
					\EstrategiaWeb\ContentTypes\Store::forget();
					return array( 'deleted' => count( $ids ) );
				},
			)
		);
		// A site as 1.4.x left it (17-10-2026, Site_Types): the company option written by SQL — no
		// update_option, so the «enabled_types» switch does not fire —, N testimonials of a type that is
		// not registered, no definitions and the migration not done. The next request migrates.
		register_rest_route(
			$ns,
			'/constructor-legacy',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					global $wpdb;
					foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", \EstrategiaWeb\ContentTypes\Store::POST_TYPE ) ) as $id ) { // phpcs:ignore
						wp_delete_post( (int) $id, true );
					}
					$wpdb->delete( $wpdb->options, array( 'option_name' => 'estrategia_web_site' ) ); // phpcs:ignore
					$wpdb->insert( $wpdb->options, array( 'option_name' => 'estrategia_web_site', 'option_value' => maybe_serialize( (array) $r['option'] ), 'autoload' => 'yes' ) ); // phpcs:ignore
					delete_option( \EstrategiaWeb\ContentTypes\Site_Types::MIGRATED );
					$ids = array();
					for ( $i = 0; $i < (int) $r['testimonials']; $i++ ) {
						$wpdb->insert( $wpdb->posts, array( 'post_type' => 'eweb_testimonial', 'post_status' => 'publish', 'post_title' => 'Old ' . $i, 'post_name' => 'old-' . $i, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '' ) ); // phpcs:ignore
						$ids[] = (int) $wpdb->insert_id;
					}
					wp_cache_flush();
					return array( 'testimonials' => $ids );
				},
			)
		);
		// What the database holds for one definition: its row, its revisions and the meta of one post (to see that a removed field kept its data).
		register_rest_route(
			$ns,
			'/constructor-raw',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					$row  = \EstrategiaWeb\ContentTypes\Store::get( (string) $r['name'] );
					$post = (int) $r['post'];
					return array(
						'row'       => $row,
						'revisions' => $row ? count( wp_get_post_revisions( $row['id'] ) ) : 0,
						'meta'      => $post ? array_map( static fn( $v ) => $v[0], get_post_meta( $post ) ) : null,
						'exists'    => post_type_exists( (string) $r['name'] ),
						'entries'   => \EstrategiaWeb\ContentTypes\Store::entries( (string) $r['name'] ),
						'rules'     => array_values( array_filter( array_keys( (array) get_option( 'rewrite_rules', array() ) ), static fn( $k ) => str_starts_with( $k, (string) $r['slug'] ) ) ),
					);
				},
			)
		);
		register_rest_route(
			$ns,
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					if ( ! class_exists( \EstrategiaWeb\ContentTypes\Loader::class ) ) {
						return array( 'module' => false );
					}
					$loaded = \EstrategiaWeb\ContentTypes\Loader::load();
					$errors = array();
					foreach ( $loaded['errors'] as $file => $list ) {
						$errors[ basename( $file ) ] = $list;
					}
					$rules = (array) get_option( 'rewrite_rules', array() );
					$meta  = array();
					foreach ( array_keys( $loaded['types'] ) as $type ) {
						foreach ( get_registered_meta_keys( 'post', $type ) as $key => $args ) {
							$meta[ $type ][ $key ] = array( 'type' => $args['type'], 'label' => $args['label'] ?? '', 'default' => $args['default'] ?? null, 'rest' => (bool) $args['show_in_rest'] );
						}
					}
					return array(
						'module'     => true,
						'registered' => \EstrategiaWeb\ContentTypes\Registrar::registered(),
						'errors'     => (object) $errors,
						'hash'       => get_option( \EstrategiaWeb\ContentTypes\Registrar::HASH_OPTION ),
						// The slugs of the fixtures, in every language they have (the site speaks es_ES: restaurantes, cocina, bodegas).
						'rules'      => array_values( array_filter( array_keys( $rules ), static fn( $r ) => (bool) preg_match( '#^(restaurant|wines|cuisine|cocina|cuina|bodegas|wineries|cellers)#', $r ) ) ),
						'meta'       => $meta,
						'taxonomy'   => taxonomy_exists( 'gment_cuisine' ) ? get_taxonomy( 'gment_cuisine' )->object_type : null,
					);
				},
			)
		);
	}
);
