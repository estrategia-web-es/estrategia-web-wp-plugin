<?php
/**
 * TESTS ONLY. The test mu-plugin of REAL WPML (25-10-2026). Loaded by the
 * mu-plugin that tests/blueprint.json writes; does nothing without
 * ESTRATEGIA_WEB_SITE_TESTING (the same double lock as the others: these
 * routes are open to anyone) nor without WPML mounted and active (see
 * tests/run.mjs, «WPML», and tests/wp/setup-wpml.php).
 *
 * WHAT IT FIXES (Playground runs on SQLite; WPML is written for MySQL). Each
 * one failed WITHOUT an error, which is why they are fixed here once and not
 * worked around in each test:
 * - SELECT GET_LOCK(…) returns «1=1» from the SQLite driver, not «1»: WPML's
 *   advisory locks are never acquired, and WPML gives up allocating a trid
 *   when a post is saved (and re-reading its config). → answered «1».
 * - «ON DUPLICATE KEY UPDATE translation_id = LAST_INSERT_ID(translation_id)»
 *   (the INSERT of every language row) fails in the SQLite translator. →
 *   rewritten to «= translation_id», which is the same for a new row.
 *   Measured 25-10-2026 with both: a page created through /wp/v2/pages got
 *   no language row and served 404; with the two fixes, 200 and its row.
 *   (Lavandería's test WordPress worked around the same thing per call: its
 *   loader writes icl_translations by hand.)
 * - String Translation asks information_schema for the collation of
 *   icl_strings.context, which SQLite does not have; the cached answer
 *   («COLLATE utf8mb4_bin») breaks its domain query and NO .mo is built:
 *   nothing translated in String Translation shows on the site (measured in
 *   Lavandería, 16-10-2026). → no collation.
 * On MySQL (a real site) this file is never loaded.
 *
 * ROUTES, estrategia-web-wpml-test/v1/* — what a translator or WPML itself
 * would do, and that a test cannot do over REST:
 *   GET  /state                 {wpml, st, default, active, setup_complete, verified}
 *   POST /config                re-reads the wpml-config.xml files (WPML_Config::load_config_run,
 *                               what WPML does when one of its screens or Plugins is opened) and
 *                               returns what it declared: types, taxonomies, the locked ones
 *   POST /string                {context, name, value | delete, lang=en}: translates (or removes the
 *                               translation of) a String Translation string, after /config
 *   POST /translate             {kind: post|term, type, original, title|name, slug, content?, meta?, lang=en}:
 *                               creates the translation and links it to the original
 *   POST /blocks                {action: package|translate|delete, post_id, …}: a page through
 *                               WPML's own translation flow (see the route)
 *
 * How to maintain: a route only for what no REST call can do; content goes
 * through /wp/v2 like everything else. If a WPML update changes one of the
 * internal calls used here, the route answers 500 with the PHP error: the
 * test fails saying so, it does not pass blind. The trap: a new SQLite gap
 * shows as «WPML does nothing» (a row missing, a 404), never as an error;
 * look at $wpdb->last_error right after WPML's query (that is how the two
 * above were found).
 *
 * @package estrategia-web
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_SITE_TESTING' ) || true !== ESTRATEGIA_WEB_SITE_TESTING || ! function_exists( 'estrategia_web_test_real_wpml' ) || ! estrategia_web_test_real_wpml() ) {
	return;
}

add_filter(
	'query',
	static function ( $sql ) {
		if ( preg_match( '/^\s*SELECT\s+(GET_LOCK|RELEASE_LOCK)\s*\(/i', (string) $sql ) ) {
			return 'SELECT 1';
		}
		return preg_replace( '/=\s*LAST_INSERT_ID\(\s*(\w+)\s*\)/i', '= $1', (string) $sql );
	}
);
add_filter( 'pre_transient_wpml_st_context_collation', static fn() => '' );

/**
 * Gives element $id of $etype (post_page, tax_<taxonomy>…) the language $lang
 * in the translation group of $original (0: a group of its own). Returns the
 * trid; throws if WPML did not write the row (never a silent half-link).
 */
function estrategia_web_test_wpml_link( string $etype, int $id, string $lang, int $original = 0 ): int {
	global $sitepress, $wpdb;
	$trid   = $original ? (int) $sitepress->get_element_trid( $original, $etype ) : 0;
	$source = $original ? $sitepress->get_default_language() : null;
	do_action(
		'wpml_set_element_language_details',
		array(
			'element_id'           => $id,
			'element_type'         => $etype,
			'trid'                 => $trid ? $trid : false,
			'language_code'        => $lang,
			'source_language_code' => $source,
		)
	);
	wp_cache_flush();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT trid, language_code FROM {$wpdb->prefix}icl_translations WHERE element_type = %s AND element_id = %d", $etype, $id ) );
	if ( ! $row || $row->language_code !== $lang || ( $trid && (int) $row->trid !== $trid ) ) {
		throw new RuntimeException( "WPML did not link $etype $id to $lang (trid $trid): " . wp_json_encode( $row ) . ' ' . $wpdb->last_error );
	}
	return (int) $row->trid;
}

add_action(
	'rest_api_init',
	static function () {
		$ns    = 'estrategia-web-wpml-test/v1';
		$route = static function ( string $path, string $methods, callable $cb ) use ( $ns ) {
			register_rest_route(
				$ns,
				$path,
				array(
					'methods'             => $methods,
					'permission_callback' => '__return_true',
					'callback'            => static function ( WP_REST_Request $r ) use ( $cb ) {
						// As the administrator, the one who translates: anonymous, WPML's rebuild of a
						// translation goes through kses and every «&» of a block attribute is stored as
						// «&amp;» (seen 25-10-2026: the map's translated URLs came out broken).
						wp_set_current_user( 1 );
						try {
							return $cb( $r );
						} catch ( Throwable $e ) {
							return new WP_Error( 'wpml_test', $e->getMessage(), array( 'status' => 500 ) );
						}
					},
				)
			);
		};

		$route(
			'/state',
			'GET',
			static function () {
				global $sitepress;
				return array(
					'wpml'           => true,
					'st'             => function_exists( 'icl_add_string_translation' ),
					'default'        => $sitepress->get_default_language(),
					'active'         => array_keys( $sitepress->get_active_languages( true ) ),
					'setup_complete' => (bool) $sitepress->get_setting( 'setup_complete' ),
					'verified'       => (bool) $sitepress->get_setting( 'existing_content_language_verified' ),
				);
			}
		);

		$config = static function () {
			global $sitepress, $iclTranslationManagement;
			WPML_Config::load_config_run();
			$tm = is_object( $iclTranslationManagement ) ? $iclTranslationManagement->settings : array();
			return array(
				'types'      => (array) $sitepress->get_setting( 'custom_posts_sync_option', array() ),
				'taxonomies' => (array) $sitepress->get_setting( 'taxonomies_sync_option', array() ),
				// Sic: WPML 5 stores the types one with a hyphen (WPML_TM_Settings_Update: «custom-types» . '_readonly_config').
				'locked'     => array(
					'types'      => $tm['custom-types_readonly_config'] ?? ( $tm['custom_types_readonly_config'] ?? null ),
					'taxonomies' => $tm['taxonomies_readonly_config'] ?? null,
				),
			);
		};
		$route( '/config', 'POST', $config );

		// {type, like}: what WPML keeps of the slug translation (modules/content-types/tests/slugs-wpml.test.mjs):
		// the «URL slug: …» strings whose name matches «like», with their translations, and the switches.
		$route(
			'/slugs',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $sitepress, $wpdb;
				$type = (string) $r->get_param( 'type' );
				$like = '%' . $wpdb->esc_like( (string) $r->get_param( 'like' ) ) . '%';
				return array(
					'registered' => post_type_exists( $type ) ? get_post_type_object( $type )->rewrite['slug'] : null,
					'translated' => (bool) $sitepress->is_translated_post_type( $type ),
					'strings'    => $wpdb->get_results( $wpdb->prepare( "SELECT s.name, s.value, s.language, t.language AS lang, t.value AS translation, t.status FROM {$wpdb->prefix}icl_strings s LEFT JOIN {$wpdb->prefix}icl_string_translations t ON t.string_id = s.id WHERE s.name LIKE 'URL%%' AND s.name LIKE %s", $like ), ARRAY_A ), // phpcs:ignore
					'global'     => get_option( 'wpml_base_slug_translation' ),
					'types'      => $sitepress->get_setting( 'posts_slug_translation' )['types'] ?? null,
					'taxonomies' => get_option( 'wpml_tax_slug_translation_settings' ),
				);
			}
		);

		// {slugs: [...]}: deletes every post with one of those slugs, of any type and IN ANY LANGUAGE
		// (/wp/v2 only lists the current language's, so a test's English pages would pile up).
		$route(
			'/purge',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				$deleted = array();
				foreach ( (array) $r->get_param( 'slugs' ) as $slug ) {
					foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s OR post_name LIKE %s", $slug, $wpdb->esc_like( $slug ) . '-%' ) ) as $id ) { // phpcs:ignore
						$packages = (array) apply_filters( 'wpml_st_get_post_string_packages', array(), (int) $id );
						foreach ( $packages as $package ) {
							do_action( 'wpml_delete_package', $package->name, $package->kind );
						}
						wp_delete_post( (int) $id, true );
						$deleted[] = (int) $id;
					}
				}
				return array( 'deleted' => $deleted );
			}
		);

		$route(
			'/string',
			'POST',
			static function ( WP_REST_Request $r ) use ( $config ) {
				global $wpdb;
				if ( ! function_exists( 'icl_add_string_translation' ) ) {
					throw new RuntimeException( 'WPML String Translation is not active' );
				}
				$out     = $config();
				$context = (string) $r->get_param( 'context' );
				$name    = (string) $r->get_param( 'name' );
				$lang    = (string) ( $r->get_param( 'lang' ) ?: 'en' );
				$id      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}icl_strings WHERE context = %s AND name = %s", $context, $name ) );
				if ( $id && $r->get_param( 'delete' ) ) {
					$wpdb->delete( $wpdb->prefix . 'icl_string_translations', array( 'string_id' => $id, 'language' => $lang ) );
					icl_update_string_status( $id );
					// Deleting the row does not rebuild WPML's .mo (which is what PHP reads): it is asked to.
					do_action( 'wpml_st_refresh_domain', $context );
				} elseif ( $id && null !== $r->get_param( 'value' ) ) {
					icl_add_string_translation( $id, $lang, (string) $r->get_param( 'value' ), ICL_TM_COMPLETE );
				}
				return $out + array(
					'id'       => $id,
					// The ORIGINAL language of the string: if it is not the site's, WPML does not translate it into English.
					'language' => $id ? $wpdb->get_var( $wpdb->prepare( "SELECT language FROM {$wpdb->prefix}icl_strings WHERE id = %d", $id ) ) : null,
					'strings'  => $wpdb->get_col( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}icl_strings WHERE context = %s ORDER BY name", $context ) ),
				);
			}
		);

		$route(
			'/translate',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $sitepress;
				$type     = (string) $r->get_param( 'type' );
				$lang     = (string) ( $r->get_param( 'lang' ) ?: 'en' );
				$original = (int) $r->get_param( 'original' );
				if ( 'term' === $r->get_param( 'kind' ) ) {
					$orig = get_term( $original, $type );
					if ( ! $orig instanceof WP_Term ) {
						throw new RuntimeException( "no term $original in $type" );
					}
					// WPML gives a new term the CURRENT language when it is saved: it is switched first.
					do_action( 'wpml_switch_language', $lang );
					$term = wp_insert_term( (string) $r->get_param( 'name' ), $type, array( 'slug' => (string) $r->get_param( 'slug' ) ) );
					do_action( 'wpml_switch_language', null );
					if ( is_wp_error( $term ) ) {
						throw new RuntimeException( $term->get_error_message() );
					}
					estrategia_web_test_wpml_link( 'tax_' . $type, (int) $term['term_taxonomy_id'], $lang, (int) $orig->term_taxonomy_id );
					return array( 'term_id' => (int) $term['term_id'] );
				}
				kses_remove_filters();
				$id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => $type,
							'post_status'  => 'publish',
							'post_title'   => (string) $r->get_param( 'title' ),
							'post_name'    => (string) $r->get_param( 'slug' ),
							'post_content' => (string) $r->get_param( 'content' ),
							'meta_input'   => (array) $r->get_param( 'meta' ),
						)
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					throw new RuntimeException( $id->get_error_message() );
				}
				estrategia_web_test_wpml_link( 'post_' . $type, (int) $id, $lang, $original );
				clean_post_cache( $id );
				return array( 'id' => (int) $id, 'url' => apply_filters( 'wpml_permalink', get_permalink( $id ), $lang ) );
			}
		);

		/*
		 * A page through WPML's own translation flow, without its editor (the
		 * advanced one lives in WPML's cloud; the classic one is a screen). What
		 * WPML does when a page is sent to translation and the job is completed,
		 * step by step and with its own hooks (tests/wpml-blocks-translated.test.mjs):
		 *   package:   re-reads the wpml-config.xml files and registers the page's
		 *              strings (wpml_pb_register_all_strings_for_translation: what
		 *              WPML does when it creates the job). Returns the package's
		 *              strings (what the translator would see), the block config
		 *              WPML took from the XML, and whether the plugin's XML passes
		 *              WPML's XSD (if not, WPML drops it WHOLE, silently).
		 *   translate: {post_id, translations: {original: translation}, slug, title}
		 *              creates the empty English page (if missing), saves each
		 *              translated string (wpml_add_string_translation) and says the
		 *              job is done (wpml_pb_finished_adding_string_translations):
		 *              what WPML_TM_Page_Builders::pro_translation_completed_action
		 *              does. WPML then REBUILDS the English page from the original.
		 *   delete:    removes both pages and the package.
		 * The trap (17-10-2026, in Lavandería): the packages table
		 * (icl_string_packages) is created by String Translation on the first
		 * wp-admin request (WPML_Package_Translation_Schema, only with
		 * is_admin()). In a fresh Playground it does not exist until then: the
		 * test opens wp-admin first.
		 */
		$route(
			'/blocks',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $sitepress, $wpdb;
				if ( ! has_action( 'wpml_pb_register_all_strings_for_translation' ) ) {
					throw new RuntimeException( 'WPML String Translation (page builders) is not active' );
				}
				$post_id = (int) $r->get_param( 'post_id' );
				if ( ! get_post( $post_id ) instanceof WP_Post ) {
					throw new RuntimeException( "no page $post_id" );
				}
				$packages    = static fn() => (array) apply_filters( 'wpml_st_get_post_string_packages', array(), $post_id );
				$strings     = static function () use ( $packages ) {
					$out = array();
					foreach ( $packages() as $package ) {
						foreach ( (array) $package->get_package_strings() as $s ) {
							$out[] = array( 'id' => (int) $s->id, 'value' => (string) $s->value );
						}
					}
					return $out;
				};
				$trid        = static fn() => (int) $sitepress->get_element_trid( $post_id, 'post_page' );
				$translation = static function () use ( $sitepress, $trid ) {
					$tr = $trid() ? $sitepress->get_element_translations( $trid(), 'post_page', false, true ) : array();
					return isset( $tr['en'] ) ? (int) $tr['en']->element_id : 0;
				};
				$action = (string) $r->get_param( 'action' );

				if ( 'package' === $action ) {
					WPML_Config::load_config_run();
					do_action( 'wpml_pb_register_all_strings_for_translation', get_post( $post_id ) );
					$errors = array();
					$dom    = new DOMDocument();
					libxml_use_internal_errors( true );
					$dom->load( WP_PLUGIN_DIR . '/estrategia-web/wpml-config.xml' );
					$valid = $dom->schemaValidate( WP_PLUGIN_DIR . '/sitepress-multilingual-cms/res/xsd/wpml-config.xsd' );
					foreach ( libxml_get_errors() as $e ) {
						$errors[] = trim( $e->message ) . ' (line ' . $e->line . ')';
					}
					libxml_clear_errors();
					return array(
						'strings' => $strings(),
						'config'  => array_filter( (array) get_option( 'wpml-gutenberg-config', array() ), static fn( $k ) => str_starts_with( (string) $k, 'estrategia-web' ), ARRAY_FILTER_USE_KEY ),
						'xsd'     => array( 'valid' => $valid, 'errors' => $errors ),
					);
				}
				if ( 'translate' === $action ) {
					$en = $translation();
					if ( ! $en ) {
						$en = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => (string) $r->get_param( 'title' ), 'post_name' => (string) $r->get_param( 'slug' ), 'post_content' => '' ) );
						estrategia_web_test_wpml_link( 'post_page', $en, 'en', $post_id );
					}
					$done = 0;
					$map  = (array) $r->get_param( 'translations' );
					foreach ( $strings() as $s ) {
						if ( isset( $map[ $s['value'] ] ) ) {
							do_action( 'wpml_add_string_translation', $s['id'], 'en', (string) $map[ $s['value'] ], 10 );
							++$done;
						}
					}
					do_action( 'wpml_pb_finished_adding_string_translations', $en, $post_id, array() );
					clean_post_cache( $en );
					return array(
						'id'         => $en,
						'translated' => $done,
						'url'        => apply_filters( 'wpml_permalink', get_permalink( $en ), 'en' ),
						'content'    => (string) get_post_field( 'post_content', $en ),
					);
				}
				if ( 'delete' === $action ) {
					foreach ( $packages() as $package ) {
						do_action( 'wpml_delete_package', $package->name, $package->kind );
					}
					$en = $translation();
					if ( $en ) {
						wp_delete_post( $en, true );
					}
					wp_delete_post( $post_id, true );
					return array( 'deleted' => array_values( array_filter( array( $post_id, $en ) ) ) );
				}
				throw new RuntimeException( "unknown action: $action" );
			}
		);
	}
);
