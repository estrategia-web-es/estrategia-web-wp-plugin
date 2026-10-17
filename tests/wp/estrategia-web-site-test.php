<?php
/**
 * TESTS ONLY. Loaded by a mu-plugin that tests/blueprint.json writes into the
 * Playground WordPress; on a real site nobody loads it.
 *
 * And even then it does nothing unless ESTRATEGIA_WEB_SITE_TESTING is
 * defined, which only that loader defines. Double lock on purpose: these REST
 * routes open the database to anyone (permission_callback __return_true) and
 * create users with application passwords. When deploying to a site, the
 * tests/ folder is NOT uploaded (README, «Installing on a site»).
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
// Except with ?wpml-double=<code>: then nothing forces the locale (WordPress
// says en_US, as with WPLANG empty) and a WPML double says the language
// (1.4.0, tests/wplang.test.mjs).
if ( ! isset( $_GET['wpml-double'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	add_filter( 'locale', static fn() => 'es_ES' );
} else {
	/*
	 * A WPML DOUBLE with what Lavandería production did on 15-10-2026: Spanish
	 * by default, English in /en/, WPLANG empty. Only the public surface the
	 * plugin uses: the wpml_current_language and wpml_default_language
	 * filters, $sitepress->get_locale() (language code → locale, as WPML
	 * resolves it), the wpml_switch_language action and the
	 * wpml_language_has_switched one it fires. And the LATE locale fix:
	 * on init 5 it moves the WordPress locale and the translation controller
	 * to the language's locale with no change_locale, which is what WPML
	 * String Translation did there (measured in local Lavandería without
	 * WPML's `locale` filter: between init 2 and init 1000).
	 * What it does NOT imitate: WPML's swap of $l10n on a switch, nor URLs.
	 * The real check is the local Lavandería with real WPML (its deployer
	 * and tests/server.mjs).
	 * It also knows Catalan («ca», WPML's own locale for it), and
	 * &wpml-default=<code> changes the default language: ?wpml-double=ca&wpml-default=ca
	 * is Gastronòmicament (Catalan by default; tests/catalan.test.mjs).
	 */
	$GLOBALS['estrategia_web_test_wpml']         = sanitize_key( wp_unslash( $_GET['wpml-double'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$GLOBALS['estrategia_web_test_wpml_default'] = isset( $_GET['wpml-default'] ) ? sanitize_key( wp_unslash( $_GET['wpml-default'] ) ) : 'es'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$GLOBALS['sitepress']                        = new class() {
		public function get_locale( $code ) {
			return array( 'es' => 'es_ES', 'en' => 'en_US', 'ca' => 'ca' )[ $code ] ?? false;
		}
	};
	add_filter( 'wpml_current_language', static fn() => $GLOBALS['estrategia_web_test_wpml'] );
	add_filter( 'wpml_default_language', static fn() => $GLOBALS['estrategia_web_test_wpml_default'] );
	add_action(
		'wpml_switch_language',
		static function ( $code ) {
			if ( null === $code ) {
				$GLOBALS['estrategia_web_test_wpml'] = array_pop( $GLOBALS['estrategia_web_test_wpml_stack'] );
			} else {
				$GLOBALS['estrategia_web_test_wpml_stack'][] = $GLOBALS['estrategia_web_test_wpml'];
				$GLOBALS['estrategia_web_test_wpml']         = $code;
			}
			do_action( 'wpml_language_has_switched', $code, false, null );
		}
	);
	add_action(
		'init',
		static function () {
			$locale = $GLOBALS['sitepress']->get_locale( $GLOBALS['estrategia_web_test_wpml'] );
			add_filter( 'locale', static fn() => $locale, PHP_INT_MAX );
			WP_Translation_Controller::get_instance()->set_locale( $locale );
		},
		5
	);
}
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

		// 1.4.0, languages (tests/wplang.test.mjs, with ?wpml-double=<code>):
		// a plugin text and a URL slug as THIS request translates them, the
		// slug the location type was registered with, and the same text after
		// a WPML switch to English and after switching back.
		$route(
			'/i18n',
			'GET',
			static function () {
				$now = static fn() => array(
					'text' => __( 'Accept all', 'estrategia-web' ),
					'slug' => _x( 'locations', 'URL slug', 'estrategia-web' ),
				);
				$out = array(
					'determine_locale' => determine_locale(),
					'now'              => $now(),
					'registered_slug'  => get_post_type_object( \EstrategiaWeb\Site\Type_Names::name( 'location' ) )->rewrite['slug'] ?? null,
				);
				do_action( 'wpml_switch_language', 'en' );
				$out['switched_en'] = $now();
				do_action( 'wpml_switch_language', null );
				$out['switched_back'] = $now();
				return $out;
			}
		);

		// Catalan (tests/catalan.test.mjs): every URL slug of the plugin as
		// THIS request translates it (the site language's, see url_slug()).
		$route(
			'/i18n-slugs',
			'GET',
			static function () {
				$out = array();
				foreach ( array( 'clients', 'client-sector', 'testimonials', 'locations', 'projects', 'project-category', 'portfolio', 'portfolio-category' ) as $slug ) {
					$out[ $slug ] = _x( $slug, 'URL slug', 'estrategia-web' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				}
				return $out;
			}
		);

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
				// 17-10-2026: the site's types are definitions of the content-types module. As freshly installed: none, not migrated.
				foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'eweb_content_type'" ) as $def_id ) { // phpcs:ignore
					wp_delete_post( (int) $def_id, true );
				}
				delete_option( 'estrategia_web_content_types_site_types' );
				delete_option( 'estrategia_web_content_types_hash' );
				if ( class_exists( \EstrategiaWeb\ContentTypes\Store::class ) ) {
					\EstrategiaWeb\ContentTypes\Store::forget();
				}
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

		// Unreleased: a site from BEFORE the meta prefix (client_url,
		// testimonial_role, testimonial_client), seeded via SQL (REST no longer
		// accepts the old keys): a client and a testimonial with the old rows, a
		// client with both, a PAGE with its own «client_url» (someone else's),
		// and a page whose Query loops bind the old keys (core/post-meta).
		$route(
			'/legacy-meta',
			'POST',
			static function () {
				global $wpdb;
				$client      = \EstrategiaWeb\Site\Type_Names::name( 'client' );
				$testimonial = \EstrategiaWeb\Site\Type_Names::name( 'testimonial' );
				$insert      = static function ( string $type, string $title, string $slug, string $content = '' ) use ( $wpdb ): int {
					$wpdb->insert( $wpdb->posts, array( 'post_type' => $type, 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish', 'post_content' => $content, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ), 'post_author' => 1, 'menu_order' => 1 ) ); // phpcs:ignore
					return (int) $wpdb->insert_id;
				};
				$meta = static function ( int $id, string $key, $value ) use ( $wpdb ): void {
					$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => $key, 'meta_value' => $value ) ); // phpcs:ignore
				};
				$c = $insert( $client, 'Cliente con web', 'cliente-con-web' );
				$meta( $c, 'client_url', 'https://antes.test/' );
				$both = $insert( $client, 'Cliente con las dos', 'cliente-con-las-dos' );
				$meta( $both, 'client_url', 'https://vieja.test/' );
				$meta( $both, 'estrategia_web_client_url', 'https://nueva.test/' );
				$t = $insert( $testimonial, 'Opinión con cargo', 'opinion-con-cargo', '<!-- wp:paragraph --><p>Muy bien</p><!-- /wp:paragraph -->' );
				$meta( $t, 'testimonial_role', 'Gerente del Hotel Antes' );
				$meta( $t, 'testimonial_client', (string) $c );
				$foreign = $insert( 'page', 'Página ajena', 'pagina-ajena' );
				$meta( $foreign, 'client_url', 'https://ajeno.test/' );
				$loop = static fn( string $type, string $body ) => '<!-- wp:query {"queryId":7,"query":{"perPage":10,"pages":0,"offset":0,"postType":"' . $type . '","order":"asc","orderBy":"title","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template -->' . $body . '<!-- /wp:post-template --></div><!-- /wp:query -->';
				$content = $loop( $client, '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"client_url"}}}}} --><p>sin web</p><!-- /wp:paragraph -->' )
					. $loop( $testimonial, '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"args":{"key":"testimonial_role"},"source":"core/post-meta"}}}} --><p>sin cargo</p><!-- /wp:paragraph -->' );
				$page = $insert( 'page', 'Fichas de antes', 'fichas-de-antes', $content );
				wp_cache_flush();
				return array( 'client' => $c, 'both' => $both, 'testimonial' => $t, 'foreign' => $foreign, 'page' => $page );
			}
		);

		// The meta rows exactly as they are in the database (no alias).
		$route(
			'/raw-meta/(?P<id>\d+)',
			'GET',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT LIKE '\\_%%' ORDER BY meta_key", (int) $r['id'] ), ARRAY_A ); // phpcs:ignore
				return (object) array_column( $rows, 'meta_value', 'meta_key' );
			}
		);

		// get_post_meta() as a theme would call it (goes through the alias).
		$route(
			'/get-meta',
			'POST',
			static fn( WP_REST_Request $r ) => array( 'value' => get_post_meta( (int) $r->get_param( 'id' ), (string) $r->get_param( 'key' ), true ) )
		);

		// The raw content of a post (what the migrator left).
		$route(
			'/raw-content/(?P<id>\d+)',
			'GET',
			static fn( WP_REST_Request $r ) => array( 'content' => (string) get_post_field( 'post_content', (int) $r['id'], 'raw' ) )
		);

		// The meta prefix migrator (includes/class-meta-prefix-migration.php), dry or for real.
		$route(
			'/meta-prefix-migration',
			'POST',
			static fn( WP_REST_Request $r ) => \EstrategiaWeb\Site\Meta_Prefix_Migration::run( (bool) $r->get_param( 'dry' ) )
		);
	}
);
