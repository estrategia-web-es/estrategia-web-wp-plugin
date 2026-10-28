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
 * - 2.0.0, the location data migration (tests/location-migration.test.mjs):
 *   /location-legacy (POST) puts back the location definition a 1.7.0 site
 *   stored (tests/fixtures/legacy/location-1.7.0.json, the 1.7.0 template,
 *   keeping the current row's slug), /location-migrate (POST {dry}) runs
 *   Location_Migration::run() — what WP-CLI would — and /location-meta
 *   (GET ?id=) gives every meta of a post, hidden ones included.
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
			'/location-legacy',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$file = WP_CONTENT_DIR . '/plugins/estrategia-web/modules/content-types/tests/fixtures/legacy/location-1.7.0.json';
					$def  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					unset( $def['$schema'] );
					$row = \EstrategiaWeb\ContentTypes\Store::get( 'eweb_location' );
					if ( $row && is_array( $row['definition'] ) ) {
						$def['slug'] = $row['definition']['slug'];
					}
					$id = \EstrategiaWeb\ContentTypes\Store::save( $def, true, $row ? (int) $row['id'] : 0 );
					// Client and testimonial as 1.7.0 stored them: no fields_box, and the client without «editor».
					foreach ( array( 'eweb_client', 'eweb_testimonial' ) as $name ) {
						$other = \EstrategiaWeb\ContentTypes\Store::get( $name );
						if ( $other && is_array( $other['definition'] ) ) {
							$old = $other['definition'];
							unset( $old['fields_box'] );
							if ( 'eweb_client' === $name ) {
								$old['supports'] = array_values( array_diff( (array) $old['supports'], array( 'editor' ) ) );
							}
							\EstrategiaWeb\ContentTypes\Store::save( $old, $other['active'], (int) $other['id'] );
						}
					}
					delete_option( \EstrategiaWeb\ContentTypes\Location_Migration::DONE );
					delete_option( \EstrategiaWeb\ContentTypes\Location_Migration::BACKUP );
					return array( 'id' => $id );
				},
			)
		);
		register_rest_route(
			$ns,
			'/location-migrate',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static fn( WP_REST_Request $r ) => \EstrategiaWeb\ContentTypes\Location_Migration::run( (bool) $r['dry'] ),
			)
		);
		register_rest_route(
			$ns,
			'/location-meta',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static fn( WP_REST_Request $r ) => (object) array_map( static fn( $v ) => $v[0], get_post_meta( (int) $r['id'] ) ),
			)
		);
		// SEVERAL REST CALLS IN ONE REQUEST (28-10-2026, round 2; tests/batch.mjs is its client). A request to
		// the test WordPress costs ~1,5 s whatever it does — it is WordPress booting — and the work inside is
		// nothing, so the tests send the calls that do NOT need a new boot between them in one POST
		// {requests: [{method, path, body?, as?}]}: each is dispatched with rest_do_request(), in order, as
		// the user of that role («administrator» by default; null = logged out), and the answer is
		// [{status, json}] with json as the HTTP answer would carry it (an error is {code, message, data}).
		// It goes through the same routes, permission callbacks, schemas and sanitizers as HTTP; what it
		// skips is core's: the HTTP authentication (application passwords, cookie + nonce).
		// THE TRAP: everything WordPress does once per request — registering the types and their meta
		// (init), loading the modules (the saved list) — happened BEFORE the first call. A definition
		// saved by one call is registered from the NEXT request, never by a later call of the same batch:
		// split the batch there, exactly where the tests used to say «registered from the next request».
		register_rest_route(
			$ns,
			'/batch',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					$server = rest_get_server();
					$out    = array();
					foreach ( (array) $r['requests'] as $q ) {
						$role = array_key_exists( 'as', $q ) ? $q['as'] : 'administrator';
						$user = $role ? get_users( array( 'role' => (string) $role, 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) ) : array();
						wp_set_current_user( $role ? (int) ( $user[0] ?? 0 ) : 0 );
						$parts = explode( '?', (string) $q['path'], 2 );
						$req   = new WP_REST_Request( (string) ( $q['method'] ?? 'GET' ), $parts[0] );
						if ( isset( $parts[1] ) ) {
							parse_str( $parts[1], $query );
							$req->set_query_params( $query );
						}
						if ( isset( $q['body'] ) ) {
							$req->set_header( 'Content-Type', 'application/json' );
							$req->set_body( (string) wp_json_encode( $q['body'] ) );
						}
						$res   = rest_do_request( $req );
						$out[] = array( 'status' => $res->get_status(), 'json' => $server->response_to_data( $res, false ) );
					}
					wp_set_current_user( 0 );
					return $out;
				},
			)
		);
		// location-migration.test.mjs (28-10-2026, round 2): a 1.7.0 site in ONE request. Puts back the 1.7.0
		// location definition (as /location-legacy), adds the image {image: {name, base64}} to the media
		// library, and inserts each of {locations: [{slug, title, meta}]} as a published eweb_location with its
		// meta AS GIVEN (what a 1.7.0 site has in its database; no sanitizer of today runs). In a meta value,
		// "@image.url" and "@image.id" become the image's URL and ID. Returns {image: {id, source_url}, ids: {slug: id}}.
		register_rest_route(
			$ns,
			'/location-seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					$legacy = rest_do_request( new WP_REST_Request( 'POST', '/estrategia-web-ct-test/v1/location-legacy' ) );
					if ( $legacy->is_error() ) {
						return $legacy->as_error();
					}
					$image = (array) $r['image'];
					$bits  = wp_upload_bits( sanitize_file_name( (string) $image['name'] ), null, base64_decode( (string) $image['base64'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					if ( ! empty( $bits['error'] ) ) {
						return new WP_Error( 'seed', (string) $bits['error'], array( 'status' => 500 ) );
					}
					$att = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => pathinfo( $bits['file'], PATHINFO_FILENAME ), 'post_status' => 'inherit' ), $bits['file'] );
					require_once ABSPATH . 'wp-admin/includes/image.php';
					wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $bits['file'] ) );
					$url = (string) wp_get_attachment_url( $att );
					$ids = array();
					foreach ( (array) $r['locations'] as $loc ) {
						$meta = array_map( static fn( $v ) => '@image.url' === $v ? $url : ( '@image.id' === $v ? $att : $v ), (array) $loc['meta'] );
						$ids[ $loc['slug'] ] = wp_insert_post( wp_slash( array( 'post_type' => 'eweb_location', 'post_status' => 'publish', 'post_title' => (string) $loc['title'], 'post_name' => (string) $loc['slug'], 'meta_input' => $meta ) ) );
					}
					return array( 'image' => array( 'id' => $att, 'source_url' => $url ), 'ids' => $ids );
				},
			)
		);
		// slugs-wpml.test.mjs (28-10-2026): its whole seed in ONE request (a request to the WPML WordPress costs
		// ~4-5 s). Deletes the gment_ posts and terms a run that died left (their slugs would be taken), then
		// creates — as the administrator, through the same REST routes the test used — the restaurant {es},
		// the cuisine term {term}, and their English translations through the WPML test routes
		// ({en}, {term_en}). Returns {es, term, en, term_en}. Needs the example switched on in an EARLIER
		// request (the types are registered at init) and WPML's config re-read (the test opens Plugins first).
		register_rest_route(
			$ns,
			'/wpml-seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					global $wpdb;
					foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type LIKE 'gment\\_%'" ) as $id ) { // phpcs:ignore
						wp_delete_post( (int) $id, true );
					}
					// By SQL, as /setup off does: WPML's get_terms() would only see the current language's.
					$tt = $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE 'gment\\_%'" ); // phpcs:ignore
					if ( $tt ) {
						$in = implode( ',', array_map( 'intval', $tt ) );
						$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($in)" ); // phpcs:ignore
						$wpdb->query( "DELETE t FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} x ON x.term_id = t.term_id WHERE x.term_taxonomy_id IN ($in)" ); // phpcs:ignore
						$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($in)" ); // phpcs:ignore
						clean_term_cache( array(), 'gment_cuisine' );
					}
					wp_set_current_user( 1 );
					$call = static function ( string $path, array $body ) {
						$req = new WP_REST_Request( 'POST', $path );
						$req->set_header( 'Content-Type', 'application/json' );
						$req->set_body( (string) wp_json_encode( $body ) );
						$res = rest_do_request( $req );
						if ( $res->is_error() || $res->get_status() >= 300 ) {
							return new WP_Error( 'seed', $path . ' -> ' . $res->get_status() . ' ' . wp_json_encode( $res->get_data() ), array( 'status' => 500 ) );
						}
						return $res->get_data();
					};
					$es = $call( '/wp/v2/gment_restaurant', (array) $r['es'] );
					if ( is_wp_error( $es ) ) {
						return $es;
					}
					$term = $call( '/wp/v2/gment_cuisine', (array) $r['term'] );
					if ( is_wp_error( $term ) ) {
						return $term;
					}
					$en = $call( '/estrategia-web-wpml-test/v1/translate', array( 'kind' => 'post', 'type' => 'gment_restaurant', 'original' => (int) $es['id'] ) + (array) $r['en'] );
					$term_en = $call( '/estrategia-web-wpml-test/v1/translate', array( 'kind' => 'term', 'type' => 'gment_cuisine', 'original' => (int) $term['id'] ) + (array) $r['term_en'] );
					foreach ( array( $en, $term_en ) as $done ) {
						if ( is_wp_error( $done ) ) {
							return $done;
						}
					}
					return array( 'es' => array( 'id' => (int) $es['id'] ), 'term' => array( 'id' => (int) $term['id'] ), 'en' => $en, 'term_en' => $term_en );
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
