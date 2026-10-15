<?php
/**
 * TESTS ONLY. Loaded by a mu-plugin that tests/blueprint.json writes into the
 * Playground WordPress; on a real site nobody loads it.
 *
 * And even then it does nothing unless ESTRATEGIA_WEB_SITE_TESTING is
 * defined, which only that loader defines. Double lock on purpose: these REST
 * routes open the database to anyone (permission_callback __return_true) and
 * create users with application passwords. When deploying to a site, the
 * tests/ folder is NOT uploaded (README, «Instalar en una web»).
 *
 * What it provides:
 * - The site language set to es_ES (locale filter): public slugs and labels
 *   are tested in Spanish, which is how they appear on the sites.
 * - Application passwords without HTTPS (Playground runs over http): that
 *   way the tests talk to the real REST API as admin and as author.
 * - Routes estrategia-web-site-test/v1/*: ping, reset, users, page, raw-post,
 *   consent-categories (0.3.0, only reads a filter), consent-setup (0.5.0),
 *   modules (1.1.0: GET which modules the request loaded; POST saves the list
 *   like the screen does, or deletes it with «active»: null), legacy-types
 *   and prefix-migration (1.2.0: seeds via SQL a site with unprefixed post
 *   types and runs the migrator; modules/site/tests/prefix-migration.test.mjs).
 * - Since 1.1.0 it is the test mu-plugin of the CORE and of the modules
 *   that come from Estrategia Web Site; forms brings its own
 *   (modules/forms/tests/wp/). Both are loaded by tests/blueprint.json.
 * - consent-setup (0.5.0) acts as the THEME for the cookie notice: it stores
 *   in the estrategia_web_site_test_consent option which categories «the
 *   site» declares, what the «Powered by» filter returns and whether there is
 *   a <script type="text/plain" data-estrategia-web-consent="statistics"> in
 *   the footer. On a site that is done by the theme's functions.php; here
 *   there is no theme of our own (Playground uses the default one). reset
 *   deletes it.
 * - WebP (0.5.1): webp-support, jpeg (a JPEG made with GD), webp-setup (acts
 *   as the theme with the estrategia_web_site_webp filter; reset deletes it)
 *   and regenerate, which imitates `wp media regenerate` (WP-CLI is not
 *   available in Playground).
 * - External links (0.5.2): external-links-setup acts as the theme with the
 *   estrategia_web_site_external_links filter (reset deletes it).
 *
 * What it does NOT do: save settings or create content through the back
 * door. That goes through /wp/v2/* with a user, which is what is under test.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_SITE_TESTING' ) || true !== ESTRATEGIA_WEB_SITE_TESTING ) {
	return;
}

// WPLANG does not work: sanitize_option() rejects it if the core language pack is not downloaded.
add_filter( 'locale', static fn() => 'es_ES' );
add_filter( 'wp_is_application_passwords_available', '__return_true' );

// 0.5.0: what the theme would do with the cookie notice (see the header, consent-setup).
add_filter(
	'estrategia_web_consent_categories',
	static function ( $categories ) {
		$setup = (array) get_option( 'estrategia_web_site_test_consent', array() );
		return array_values( array_unique( array_merge( (array) $categories, (array) ( $setup['categories'] ?? array() ) ) ) );
	}
);
add_filter(
	'estrategia_web_site_consent_powered_by',
	static function ( $powered ) {
		$setup = (array) get_option( 'estrategia_web_site_test_consent', array() );
		if ( ! array_key_exists( 'powered_by', $setup ) ) {
			return $powered;
		}
		return false === $setup['powered_by'] ? false : array_merge( (array) $powered, (array) $setup['powered_by'] );
	}
);
// 0.5.1: CSS the theme would add (consent-setup «theme_css»). For the notice's
// spacing: Lavandería emits from its theme.json «:root :where(p) { margin: 0 }»,
// which ties in specificity with .estrategia-web-consent__text and, loaded later, wins.
add_action(
	'wp_head',
	static function () {
		$setup = (array) get_option( 'estrategia_web_site_test_consent', array() );
		if ( ! empty( $setup['theme_css'] ) ) {
			echo '<style id="estrategia-web-test-theme-css">' . wp_strip_all_tags( (string) $setup['theme_css'] ) . '</style>';
		}
	},
	100
);
// 0.5.1: what a theme that turns WebP off would do (option set by /webp-setup).
add_filter(
	'estrategia_web_site_webp',
	static function ( $enabled ) {
		$setup = get_option( 'estrategia_web_site_test_webp', null );
		return null === $setup ? $enabled : (bool) $setup;
	}
);
// 0.5.2: what a theme that turns off external links in a new tab would do
// (option set by /external-links-setup; reset deletes it).
add_filter(
	'estrategia_web_site_external_links',
	static function ( $enabled ) {
		$setup = get_option( 'estrategia_web_site_test_external_links', null );
		return null === $setup ? $enabled : (bool) $setup;
	}
);
add_action(
	'wp_footer',
	static function () {
		$setup = (array) get_option( 'estrategia_web_site_test_consent', array() );
		if ( ! empty( $setup['script'] ) ) {
			echo '<script type="text/plain" data-estrategia-web-consent="statistics">window.estrategiaWebTestStatistics = true;</script>';
		}
	}
);

add_action(
	'rest_api_init',
	static function () {
		$ns    = 'estrategia-web-site-test/v1';
		$route = static function ( string $path, string $methods, callable $cb ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => '__return_true' ) );
		};

		$route( '/ping', 'GET', static fn() => array( 'ok' => true ) );

		// 1.1.0, modules: what was loaded in THIS request and what is saved.
		// POST saves the list with update_option (like the screen), so it goes
		// through the hook that writes _known; the next request already loads
		// the new ones. With «null», it deletes both options (defaults).
		$route(
			'/modules',
			'GET',
			static fn() => array(
				'loaded'  => \EstrategiaWeb\Modules::loaded(),
				'blocked' => (object) \EstrategiaWeb\Modules::blocked(),
				'saved'   => get_option( \EstrategiaWeb\Modules::OPTION, null ),
				'known'   => get_option( \EstrategiaWeb\Modules::KNOWN_OPTION, null ),
				// The ones on disk, on or not (Default: off ones do not show up in «loaded»).
				'available' => array_keys( \EstrategiaWeb\Modules::available() ),
			)
		);
		$route(
			'/modules',
			'POST',
			static function ( WP_REST_Request $r ) {
				$active = $r->get_param( 'active' );
				if ( null === $active ) {
					delete_option( \EstrategiaWeb\Modules::OPTION );
					delete_option( \EstrategiaWeb\Modules::KNOWN_OPTION );
				} else {
					update_option( \EstrategiaWeb\Modules::OPTION, \EstrategiaWeb\Modules::sanitize( (array) $active ) );
				}
				return array( 'ok' => true );
			}
		);

		// 0.5.1. WebP: whether this PHP's image editor can write WebP (if not,
		// the test would test nothing), and a real JPEG made with GD (no binary
		// files in the repo), in base64.
		$route( '/webp-support', 'GET', static fn() => array( 'webp' => (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) );
		$route(
			'/jpeg',
			'GET',
			static function ( WP_REST_Request $r ) {
				$w     = max( 16, (int) ( $r['w'] ?? 1600 ) );
				$h     = max( 16, (int) ( $r['h'] ?? 1000 ) );
				$image = imagecreatetruecolor( $w, $h );
				for ( $x = 0; $x < $w; $x += 20 ) {
					imagefilledrectangle( $image, $x, 0, $x + 19, $h - 1, imagecolorallocate( $image, ( $x / 7 ) % 256, 120, 200 ) );
				}
				ob_start();
				imagejpeg( $image, null, 80 );
				return array( 'base64' => base64_encode( (string) ob_get_clean() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		);
		// 0.5.1. Acts as the theme for WebP: {"enabled": false} turns it off with the filter, null leaves the default.
		$route(
			'/webp-setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				$enabled = $r->get_json_params()['enabled'] ?? null;
				null === $enabled ? delete_option( 'estrategia_web_site_test_webp' ) : update_option( 'estrategia_web_site_test_webp', $enabled ? 1 : 0 );
				return array( 'ok' => true );
			}
		);
		// 0.5.2. Acts as the theme for external links: {"enabled": false} turns them off, null leaves the default.
		$route(
			'/external-links-setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				$enabled = $r->get_json_params()['enabled'] ?? null;
				null === $enabled ? delete_option( 'estrategia_web_site_test_external_links' ) : update_option( 'estrategia_web_site_test_external_links', $enabled ? 1 : 0 );
				return array( 'ok' => true );
			}
		);
		// 0.5.1. What `wp media regenerate` does (WP-CLI, Media_Command):
		// deletes the intermediate sizes listed in the meta and regenerates the
		// meta from the ORIGINAL. WP-CLI is not available in Playground: this
		// imitates it to see what happens to the URLs the content already has
		// stored. With skip_delete: true, like --skip-delete (old ones are kept).
		$route(
			'/regenerate/(?P<id>\d+)',
			'POST',
			static function ( WP_REST_Request $r ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$id   = (int) $r['id'];
				$meta = wp_get_attachment_metadata( $id );
				$file = wp_get_original_image_path( $id );
				if ( empty( $r->get_json_params()['skip_delete'] ) ) {
					$dir = dirname( (string) get_attached_file( $id ) );
					foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
						wp_delete_file( $dir . '/' . $size['file'] );
					}
				}
				$new = wp_generate_attachment_metadata( $id, $file );
				wp_update_attachment_metadata( $id, $new );
				return array( 'sizes' => wp_list_pluck( (array) ( $new['sizes'] ?? array() ), 'file' ), 'file' => $new['file'] ?? '' );
			}
		);

		// 0.3.0. What the parent theme's notice would see (it is not here): the
		// categories after the estrategia_web_consent_categories filter, starting
		// from those of a child theme that only declares «maps».
		$route( '/consent-categories', 'GET', static fn() => array_values( (array) apply_filters( 'estrategia_web_consent_categories', array( 'maps' ) ) ) );

		// 0.5.0. Acts as the theme for the cookie notice (see the header).
		$route(
			'/consent-setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				update_option( 'estrategia_web_site_test_consent', (array) $r->get_json_params() );
				return array(
					'ok'         => true,
					'active'     => \EstrategiaWeb\Site\Consent::active(),
					'categories' => \EstrategiaWeb\Site\Consent::categories(),
					'revision'   => \EstrategiaWeb\Site\Consent::revision(),
				);
			}
		);

		// Leaves the site as freshly installed for this plugin: no data, no content of its post types.
		$route(
			'/reset',
			'POST',
			static function () {
				global $wpdb;
				delete_option( 'estrategia_web_site' );
				delete_option( 'estrategia_web_site_test_consent' );
				delete_option( 'estrategia_web_site_test_webp' );
				delete_option( 'estrategia_web_site_test_external_links' );
				delete_option( 'estrategia_web_test_suite' ); // The forms tests' marker (es_ES comes back).
				delete_option( 'estrategia_web_social_links' ); // No list: the migration of the fixed fields runs again.
				delete_option( 'estrategia_web_modules' ); // 1.1.0: all modules, as freshly installed.
				delete_option( 'estrategia_web_modules_known' );
				delete_option( 'rewrite_rules' );
				delete_option( 'icl_sitepress_settings#custom_posts_sync_option' ); // Those from legacy-types (1.2.0).
				delete_option( 'WPML(setup/translate-everything-taxonomies)' );
				delete_option( 'rank_math_known_post_types' );
				// With and without prefix (1.2.0): the migrator test seeds rows with the old names.
				$renamed = \EstrategiaWeb\Site\Type_Names::renamed();
				$names   = array_merge( array_keys( $renamed ), array_values( $renamed ) );
				$types   = "'" . implode( "','", array_diff( $names, \EstrategiaWeb\Site\Type_Names::TAXONOMIES, array_map( array( \EstrategiaWeb\Site\Type_Names::class, 'name' ), \EstrategiaWeb\Site\Type_Names::TAXONOMIES ) ) ) . "'";
				$taxes   = "'" . implode( "','", array_merge( \EstrategiaWeb\Site\Type_Names::TAXONOMIES, array_map( array( \EstrategiaWeb\Site\Type_Names::class, 'name' ), \EstrategiaWeb\Site\Type_Names::TAXONOMIES ) ) ) . "'";
				$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) OR (post_type = 'attachment' AND post_title LIKE 'prueba-%')" ); // phpcs:ignore
				foreach ( $ids as $id ) {
					wp_delete_post( (int) $id, true );
				}
				// The taxonomies may not be registered: deleted by hand.
				$tt = $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($taxes)" ); // phpcs:ignore
				if ( $tt ) {
					$in = implode( ',', array_map( 'intval', $tt ) );
					$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($in)" ); // phpcs:ignore
					$wpdb->query( "DELETE t FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} x ON x.term_id = t.term_id WHERE x.term_taxonomy_id IN ($in)" ); // phpcs:ignore
					$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($in)" ); // phpcs:ignore
				}
				return array( 'ok' => true );
			}
		);

		// An administrator and an author, each with a new application password.
		$route(
			'/users',
			'POST',
			static function () {
				$out = array();
				foreach ( array( 'admin' => 'administrator', 'autor-prueba' => 'author' ) as $login => $role ) {
					$user = get_user_by( 'login', $login );
					$id   = $user ? $user->ID : wp_insert_user(
						array(
							'user_login' => $login,
							'user_pass'  => wp_generate_password(),
							'user_email' => $login . '@ejemplo.test',
							'role'       => $role,
						)
					);
					\WP_Application_Passwords::delete_all_application_passwords( $id );
					[ $password ] = \WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'pruebas' ) );
					$out[ $role ] = array( 'user' => $login, 'password' => $password );
				}
				return $out;
			}
		);

		$route(
			'/page',
			'POST',
			static function ( WP_REST_Request $r ) {
				kses_remove_filters();
				$p    = $r->get_json_params();
				$slug = sanitize_title( $p['slug'] ?? 'prueba' );
				$old  = get_page_by_path( $slug );
				$id   = wp_insert_post(
					wp_slash(
						array(
							'ID'           => $old ? $old->ID : 0,
							'post_type'    => 'page',
							'post_status'  => 'publish',
							'post_title'   => $p['title'] ?? 'Prueba',
							'post_name'    => $slug,
							'post_content' => (string) $p['content'],
						)
					),
					true
				);
				return is_wp_error( $id ) ? $id : array( 'id' => $id, 'url' => get_permalink( $id ) );
			}
		);

		// The row exactly as it is in the database, whether its post type is registered or not.
		$route(
			'/raw-post/(?P<id>\d+)',
			'GET',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE ID = %d", (int) $r['id'] ), ARRAY_A ); // phpcs:ignore
				return $row ? $row : new WP_Error( 'not_found', 'does not exist', array( 'status' => 404 ) );
			}
		);

		// 1.2.0: a site from BEFORE the prefix, seeded via SQL (with the new
		// plugin a «client» cannot be created through REST): a client with its
		// sector, a location with a phone number, and a page with the old Query.
		$route(
			'/legacy-types',
			'POST',
			static function () {
				global $wpdb;
				$insert = static function ( string $type, string $title, string $slug, string $content = '' ) use ( $wpdb ): int {
					$wpdb->insert( $wpdb->posts, array( 'post_type' => $type, 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish', 'post_content' => $content, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ), 'post_author' => 1 ) ); // phpcs:ignore
					return (int) $wpdb->insert_id;
				};
				$client = $insert( 'client', 'Hotel de antes', 'hotel-de-antes' );
				$wpdb->insert( $wpdb->terms, array( 'name' => 'Hoteles de antes', 'slug' => 'hoteles-de-antes' ) ); // phpcs:ignore
				$term = (int) $wpdb->insert_id;
				$wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $term, 'taxonomy' => 'client_sector', 'description' => '', 'count' => 1 ) ); // phpcs:ignore
				$tt = (int) $wpdb->insert_id;
				$wpdb->insert( $wpdb->term_relationships, array( 'object_id' => $client, 'term_taxonomy_id' => $tt ) ); // phpcs:ignore
				$location = $insert( 'location', 'Sede de antes', 'sede-de-antes' );
				add_post_meta( $location, 'estrategia_web_location_phone', '971 11 22 33' );
				$query = '<!-- wp:query {"queryId":5,"query":{"perPage":10,"pages":0,"offset":0,"postType":"client","order":"asc","orderBy":"title","inherit":false,"taxQuery":{"client_sector":[' . $term . ']}}} -->'
					. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- wp:post-terms {"term":"client_sector"} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
				$page = $insert( 'page', 'Clientes de antes', 'clientes-de-antes', $query );
				// The way WPML 5.1 and Rank Math store them on Lavandería (without those plugins here: just the rows).
				update_option( 'icl_sitepress_settings#custom_posts_sync_option', array( 'v' => array( 'page' => 1, 'client' => 2, 'location' => 2 ) ) );
				update_option( 'WPML(setup/translate-everything-taxonomies)', array( 'v' => array( 'client_sector' => array() ) ) );
				update_option( 'rank_math_known_post_types', array( 'post' => 'post', 'client' => 'client' ) );
				wp_cache_flush();
				return array( 'client' => $client, 'term' => $term, 'location' => $location, 'page' => $page );
			}
		);

		// Some options exactly as they are (read only): what the migrator left.
		$route(
			'/options',
			'POST',
			static fn( WP_REST_Request $r ) => array_combine( (array) $r->get_param( 'names' ), array_map( static fn( $n ) => get_option( (string) $n, null ), (array) $r->get_param( 'names' ) ) )
		);

		// The prefix migrator (includes/class-prefix-migration.php), dry or for real.
		$route(
			'/prefix-migration',
			'POST',
			static fn( WP_REST_Request $r ) => \EstrategiaWeb\Site\Prefix_Migration::run( (bool) $r->get_param( 'dry' ) )
		);
	}
);
