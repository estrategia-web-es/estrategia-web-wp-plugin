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
 *   like the screen does, or deletes it with «active»: null). (The routes of
 *   the 1.2.0 and meta prefix migrators went with them in 2.0.1.)
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

/**
 * Whether REAL WPML runs in this request: tests/run.mjs mounted the local copy,
 * tests/wp/setup-wpml.php activated it, and the request is not one of the
 * ?wpml-double ones (which switch it off, below).
 */
function estrategia_web_test_real_wpml(): bool {
	return ! isset( $_GET['wpml-double'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& is_dir( WP_PLUGIN_DIR . '/sitepress-multilingual-cms' )
		&& in_array( 'sitepress-multilingual-cms/sitepress.php', (array) get_option( 'active_plugins', array() ), true );
}

// For /ping?timing (below): when the mu-plugins load, when the plugins have loaded, at init.
$GLOBALS['estrategia_web_test_t_mu'] = microtime( true );
add_action( 'plugins_loaded', static fn() => $GLOBALS['estrategia_web_test_t_plugins'] = microtime( true ), PHP_INT_MIN );
add_action( 'init', static fn() => $GLOBALS['estrategia_web_test_t_init'] = microtime( true ), PHP_INT_MIN );

// A RESET request loads every module (28-10-2026). Modules are loaded when the plugin loads,
// from the saved list, BEFORE any route runs: a file that left the list without forms made the
// next file's forms /reset die with «Class "EstrategiaWeb\Forms\Migrations" not found» (seen red
// in the first run with shards, consent-links, where the order of the files is no longer fixed).
// Any module's /reset (…-test/v1/reset) reads the list as not saved, i.e. every default module;
// the reset itself deletes the saved list for the requests after it. Seed routes are left alone
// on purpose: the block modules' browser scenarios seed with ONLY those modules on.
// The same for /setup (any module's): it runs /reset first and then seeds through module code
// (consent-setup needs the consent module: «Class "EstrategiaWeb\Site\Consent" not found» when
// map.test.mjs, which left only the block modules on, ran before consent-browser.test.mjs; both
// are browser scenarios since 29-10-2026).
// The filter only lives until plugins_loaded, when the modules are already loaded: after that
// the request reads and WRITES the real list (with it, an update_option of the list inside the
// request updated 0 rows and saved nothing).
if ( preg_match( '#/wp-json/estrategia-web-[a-z-]+-test/v1/(reset|setup)/?(\?|$)#', (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	add_filter( 'pre_option_estrategia_web_modules', '__return_null' );
	add_action( 'plugins_loaded', static fn() => remove_filter( 'pre_option_estrategia_web_modules', '__return_null' ), PHP_INT_MIN );
}

// ?ew-test-modules=a,b,c (28-10-2026): THIS request reads that as the saved module list, through
// get_option() like a saved one, without writing anything. A test that reads several screens, each
// with another list (tests/menu.test.mjs, tests/settings-page.test.mjs), reads them all at once
// instead of «save the list, read, save the next». It does not go through update_option, so it
// does not write _known (what saving does is tests/modules-page.test.mjs). Edge case: the list
// is taken as given, also without core modules («site»), exactly as a saved list would be.
// The trap: the links on the page do not carry the parameter; following one reads the real list.
if ( isset( $_GET['ew-test-modules'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$estrategia_web_test_modules = array_values( array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['ew-test-modules'] ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	add_filter( 'pre_option_estrategia_web_modules', static fn() => $estrategia_web_test_modules );
}

// WPLANG does not work: sanitize_option() rejects it if the core language pack is not downloaded.
// Except with ?wpml-double=<code>: then nothing forces the locale (WordPress
// says en_US, as with WPLANG empty) and a WPML double says the language
// (1.4.0, tests/wplang.test.mjs).
// With REAL WPML (tests/wp/estrategia-web-wpml-test.php) nothing is forced
// either: WPML gives each request its language's locale (es_ES by default,
// en_US in /en/), and forcing es_ES here would leave /en/ in Spanish.
if ( ! isset( $_GET['wpml-double'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! estrategia_web_test_real_wpml() ) {
		add_filter( 'locale', static fn() => 'es_ES' );
	}
} else {
	// The double is the only WPML of this request: a real one, if mounted, is not loaded.
	add_filter( 'option_active_plugins', static fn( $plugins ) => array_values( array_filter( (array) $plugins, static fn( $p ) => ! str_starts_with( (string) $p, 'sitepress-multilingual-cms/' ) && ! str_starts_with( (string) $p, 'wpml-string-translation/' ) ) ) );
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
	// &wpml-double-switcher (28-10-2026): es and en as wpml_active_languages returns them (the
	// current one «active»), so the language switcher block renders on the normal WordPress.
	// For the browser scenario 28-language-switcher (modules/language-switcher/tests/browser/), which tests the
	// block's JavaScript; the URLs here are NOT WPML's (the real-WPML level 2 test checks those).
	// Opt-in because forms and content-types read this filter too, and their tests with the
	// double expect no language list.
	if ( isset( $_GET['wpml-double-switcher'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		add_filter(
			'wpml_active_languages',
			static function () {
				$out = array();
				foreach ( array( 'es' => 'Español', 'en' => 'English' ) as $code => $name ) {
					$out[ $code ] = array(
						'code'        => $code,
						'native_name' => $name,
						'active'      => $code === $GLOBALS['estrategia_web_test_wpml'] ? 1 : 0,
						'url'         => home_url( 'es' === $code ? '/' : '/en/' ),
					);
				}
				return $out;
			}
		);
	}
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

		// ?timing: where a request's time goes, in ms from PHP's start (28-10-2026: every request to
		// Playground costs ~1,1 s whatever it does; this says how much is WordPress before any
		// plugin, how much the plugins, and how much the rest until the route runs).
		$route(
			'/ping',
			'GET',
			static function ( WP_REST_Request $r ) {
				if ( null === $r->get_param( 'timing' ) ) {
					return array( 'ok' => true );
				}
				$start = (float) $_SERVER['REQUEST_TIME_FLOAT']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$ms    = static fn( $t ) => null === $t ? null : (int) round( ( $t - $start ) * 1000 );
				return array(
					'ok'       => true,
					'mu'       => $ms( $GLOBALS['estrategia_web_test_t_mu'] ?? null ),
					'plugins'  => $ms( $GLOBALS['estrategia_web_test_t_plugins'] ?? null ),
					'init'     => $ms( $GLOBALS['estrategia_web_test_t_init'] ?? null ),
					'route'    => $ms( microtime( true ) ),
					'included' => count( get_included_files() ),
					// Measured 28-10-2026: mu ~800 ms (WordPress and the SQLite drop-in, before any
					// plugin; the opcache is Playground's file cache only), plugins +150, route +100.
				);
			}
		);

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
				delete_option( 'estrategia_web_forms_test_locale' ); // And the locale a forms test may force (28-10-2026: it carried into the next file).
				delete_option( 'estrategia_web_social_links' ); // No list: the migration of the fixed fields runs again.
				delete_option( 'estrategia_web_modules' ); // 1.1.0: all modules, as freshly installed.
				delete_option( 'estrategia_web_modules_known' );
				delete_option( 'rewrite_rules' );
				delete_option( 'icl_sitepress_settings#custom_posts_sync_option' ); // Those from legacy-types (1.2.0).
				delete_option( 'WPML(setup/translate-everything-taxonomies)' );
				delete_option( 'rank_math_known_post_types' );
				// 29-10-2026: the content-types example files (modules/content-types/tests/wp). editor-panel.test.mjs
				// switched them off in its after(); its browser scenario (16-ct-editor-panel) has no after(), so
				// the next reset does it. Their gment_ posts are left: unregistered, nobody sees them.
				delete_option( 'estrategia_web_ct_test' );
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

		// 28-10-2026: a test file's whole before() in ONE request (a request
		// costs ~1,3 s, it is WordPress booting; the work inside is nothing).
		// POST {modules?, company?, consent?, options?, pages?}: runs /reset
		// exactly as that route does (dispatched inside this request, no HTTP),
		// then, in this order and only what is given:
		//   modules  "all" (every module on disk, Default: off ones too) or a
		//            list, as POST /modules;
		//   company  saved through /wp/v2/settings as the administrator, as
		//            saveCompany() in tests/lib.mjs (same sanitize);
		//   consent  as POST /consent-setup;
		//   options  {name: value}; null deletes the option;
		//   pages    [{slug, content, title?}], each as POST /page.
		// Returns {available, pages: [{id, url}]}.
		// How to maintain: a new seed is one more key here, dispatching to the
		// route that already does it. What /reset does is NOT changed here
		// (every test file calls it). The trap: a route dispatched inside this
		// request sees this request's modules (the ones loaded BEFORE «modules»
		// was saved), so nothing here may depend on a module just switched on.
		$route(
			'/setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				$call = static function ( string $method, string $path, array $body ) {
					$req = new WP_REST_Request( $method, $path );
					$req->set_header( 'Content-Type', 'application/json' );
					$req->set_body( (string) wp_json_encode( $body ) );
					$res = rest_do_request( $req );
					if ( $res->is_error() || $res->get_status() >= 300 ) {
						throw new RuntimeException( $path . ' -> ' . $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
					}
					return $res->get_data();
				};
				$ns = '/estrategia-web-site-test/v1';
				try {
					$call( 'POST', $ns . '/reset', array() );
					$available = array_keys( \EstrategiaWeb\Modules::available() );
					$modules   = $r->get_param( 'modules' );
					if ( null !== $modules ) {
						$call( 'POST', $ns . '/modules', array( 'active' => 'all' === $modules ? $available : (array) $modules ) );
					}
					if ( null !== $r->get_param( 'company' ) ) {
						wp_set_current_user( 1 );
						$call( 'POST', '/wp/v2/settings', array( 'estrategia_web_site' => (array) $r->get_param( 'company' ) ) );
					}
					if ( null !== $r->get_param( 'consent' ) ) {
						$call( 'POST', $ns . '/consent-setup', (array) $r->get_param( 'consent' ) );
					}
					foreach ( (array) $r->get_param( 'options' ) as $name => $value ) {
						if ( null === $value ) {
							delete_option( (string) $name );
						} else {
							update_option( (string) $name, $value );
						}
					}
					$pages = array();
					foreach ( (array) $r->get_param( 'pages' ) as $p ) {
						$pages[] = $call( 'POST', $ns . '/page', (array) $p );
					}
				} catch ( RuntimeException $e ) {
					return new WP_Error( 'setup', $e->getMessage(), array( 'status' => 500 ) );
				}
				return array(
					'available' => $available,
					'pages'     => $pages,
				);
			}
		);

		// 28-10-2026: GET /modules plus what the loaded modules REGISTERED in
		// this request: the plugin's block names (estrategia-web*, and the old
		// armazon/* aliases) and the REST namespaces. One request instead of
		// one /wp/v2/block-types call per block (tests/modules-page.test.mjs).
		$route(
			'/registered',
			'GET',
			static function () {
				$modules = rest_do_request( new WP_REST_Request( 'GET', '/estrategia-web-site-test/v1/modules' ) )->get_data();
				$blocks  = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
				return $modules + array(
					'blocks'     => array_values( array_filter( $blocks, static fn( $n ) => str_starts_with( $n, 'estrategia-web' ) || str_starts_with( $n, 'armazon/' ) ) ),
					'namespaces' => rest_get_server()->get_namespaces(),
				);
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

		// Some options exactly as they are. 28-10-2026: {delete: [names]}
		// deletes those AFTER reading (a test's last read is also its cleanup of options /reset
		// does not delete: tests/settings-page.test.mjs). Without «delete», read only, as before.
		$route(
			'/options',
			'POST',
			static function ( WP_REST_Request $r ) {
				$out = array_combine( (array) $r->get_param( 'names' ), array_map( static fn( $n ) => get_option( (string) $n, null ), (array) $r->get_param( 'names' ) ) );
				foreach ( (array) $r->get_param( 'delete' ) as $name ) {
					delete_option( (string) $name );
				}
				return $out;
			}
		);
	}
);
