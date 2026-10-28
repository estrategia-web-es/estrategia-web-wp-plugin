<?php
/**
 * TESTS ONLY. Loaded by the mu-plugin that tests/blueprint.json writes into
 * the Playground WordPress; on a real site nobody loads it, and even so it
 * does nothing without ESTRATEGIA_WEB_REDIRECTS_TESTING (the double key from
 * forms: these routes open the database to anyone).
 *
 * What it provides (estrategia-web-redirects-test/v1):
 * - setup {on}: switches the module on or off (the Default: on ones + redirects).
 * - reset: empties both tables and the settings, removes the fake Rank Math
 *   and deletes the «about» and «nosotros» pages the tests create. With
 *   {core: true} the core reset first, {on: true} switches the module on,
 *   {about: true} seeds a live /about/; it answers whether THIS request
 *   loaded the module («loaded»: a /reset reads the modules as not saved).
 *   The trap: nothing of the module can run here when it is Default: off
 *   (its classes are not loaded in this request); importing is the next one.
 * - import {csv, dry_run} ({dry_first: true}: the dry run, then the real one,
 *   answered as {dry, real}), rule {source, target, type, active}, rules
 *   ({count: 1}: only how many), settings {log_404, log_days}, log,
 *   log-backdate, purge ({backdate: true}: log-backdate first, in the same
 *   request; answers how many log rows are «left»).
 * - last: what happened on the last PUBLIC request (not REST or admin): how
 *   many queries touched the module's tables and how many there were in
 *   total. Counted with the «query» filter and recorded on shutdown.
 * - requests (28-10-2026): the same, one row per public request while the
 *   module is on, in order: several requests' counts with one call, also
 *   for requests made at once. The trap it avoids: a single option holding
 *   a list loses entries when two requests append at the same time.
 * - fake-seo {plugin, modules?} (2.0.1, was fake-rank-math): plays another
 *   plugin by defining, here in a mu-plugin before the plugins, the constant
 *   it defines when loaded: «rank-math» (RANK_MATH_VERSION, with
 *   rank_math_modules = modules, by default redirections + sitemap),
 *   «yoast-premium» (WPSEO_PREMIUM_FILE) or «redirection»
 *   (REDIRECTION_VERSION). Without «plugin», none.
 * - bench {n, rules}: seeds filler rules up to «rules» and measures n
 *   Store::match lookups (ms per lookup).
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_REDIRECTS_TESTING' ) || true !== ESTRATEGIA_WEB_REDIRECTS_TESTING ) {
	return;
}

// The fake SEO / redirect plugin (/fake-seo): the constant the real one defines when loaded.
switch ( get_option( 'estrategia_web_redirects_test_fake' ) ) {
	case 'rank-math':
		define( 'RANK_MATH_VERSION', '1.0.0-falso' );
		break;
	case 'yoast-premium':
		define( 'WPSEO_PREMIUM_FILE', __FILE__ );
		break;
	case 'redirection':
		define( 'REDIRECTION_VERSION', '0.0.0-falso' );
		break;
}

$GLOBALS['estrategia_web_redirects_test_queries'] = 0;
add_filter(
	'query',
	static function ( $sql ) {
		if ( preg_match( '/(FROM|INTO|UPDATE)\s+\S*estrategia_web_redirects/i', (string) $sql ) ) {
			++$GLOBALS['estrategia_web_redirects_test_queries'];
		}
		return $sql;
	}
);
add_action(
	'shutdown',
	static function () {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return;
		}
		$last = array(
			'uri'     => $_SERVER['REQUEST_URI'] ?? '', // phpcs:ignore
			'queries' => $GLOBALS['estrategia_web_redirects_test_queries'],
			'total'   => get_num_queries(),
		);
		update_option( 'estrategia_web_redirects_test_last', $last, false );
		// 28-10-2026: with the module on, also one row per request, so that a
		// test reads several requests' counts with ONE call (/requests), even
		// for requests made at once (a row each: none is lost). The module's
		// reset deletes them.
		if ( class_exists( '\EstrategiaWeb\Redirects\Store' ) ) {
			add_option( 'estrategia_web_redirects_test_req_' . str_replace( '.', '', sprintf( '%.6F', microtime( true ) ) ) . wp_rand( 100, 999 ), $last, '', false );
		}
	},
	0
);

add_action(
	'rest_api_init',
	static function () {
		$ns    = 'estrategia-web-redirects-test/v1';
		$route = static function ( string $path, string $methods, callable $cb ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => '__return_true' ) );
		};
		$loaded = static fn() => class_exists( '\EstrategiaWeb\Redirects\Store' );

		$route(
			'/setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				$available = \EstrategiaWeb\Modules::available();
				$list      = \EstrategiaWeb\Modules::wanted( $available, null, null );
				$list      = array_values( array_diff( $list, array( 'redirects' ) ) );
				if ( $r->get_param( 'on' ) ) {
					$list[] = 'redirects';
				}
				update_option( \EstrategiaWeb\Modules::OPTION, $list );
				return array( 'ok' => true, 'modules' => $list );
			}
		);
		$route(
			'/reset',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				// 28-10-2026, a test file's before() in ONE request: {core: true} runs the core
				// reset first (dispatched here, no HTTP), {on: true} then switches the module on
				// (as /setup), {about: true} seeds a live /about/ page (a CSV source). The answer
				// says whether THIS request loaded the module: it is a /reset, which reads the
				// modules list as not saved (tests/wp/estrategia-web-site-test.php), so that is
				// «a site without the saved option» — redirects is Default: off.
				if ( $r->get_param( 'core' ) ) {
					$res = rest_do_request( new WP_REST_Request( 'POST', '/estrategia-web-site-test/v1/reset' ) );
					if ( $res->is_error() ) {
						return new WP_Error( 'core_reset', wp_json_encode( $res->get_data() ), array( 'status' => 500 ) );
					}
				}
				foreach ( array( 'estrategia_web_redirects', 'estrategia_web_redirects_404' ) as $t ) {
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) ) ) {
						$wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" ); // phpcs:ignore
					}
				}
				delete_option( 'estrategia_web_redirects_settings' );
				delete_option( 'estrategia_web_redirects_test_fake' );
				delete_option( 'rank_math_modules' );
				delete_option( 'estrategia_web_redirects_test_last' );
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'estrategia_web_redirects_test_req_%'" ); // phpcs:ignore
				// The pages these tests create with a CSV source as slug: if
				// live, they would hide the rule in the next run.
				foreach ( array( 'about', 'nosotros' ) as $slug ) {
					$page = get_page_by_path( $slug );
					if ( $page ) {
						wp_delete_post( $page->ID, true );
					}
				}
				$out = array(
					'ok'               => true,
					'loaded'           => class_exists( '\EstrategiaWeb\Redirects\Store' ),
					'available'        => array_keys( \EstrategiaWeb\Modules::available() ),
				);
				if ( $r->get_param( 'on' ) ) {
					$list   = array_values( array_diff( \EstrategiaWeb\Modules::wanted( \EstrategiaWeb\Modules::available(), null, null ), array( 'redirects' ) ) );
					$list[] = 'redirects';
					// The trap: this /reset request reads the option through a pre_option filter that
					// answers null, and with it update_option() takes the option as existing and
					// updates 0 rows (seen 28-10-2026: the module stayed off). Out of the way first.
					remove_all_filters( 'pre_option_' . \EstrategiaWeb\Modules::OPTION );
					update_option( \EstrategiaWeb\Modules::OPTION, $list );
				}
				if ( $r->get_param( 'about' ) ) {
					kses_remove_filters();
					$out['about'] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_name' => 'about', 'post_content' => '<!-- wp:paragraph --><p>Viva</p><!-- /wp:paragraph -->' ) );
				}
				return $out;
			}
		);
		$route(
			'/import',
			'POST',
			static function ( WP_REST_Request $r ) use ( $loaded ) {
				if ( ! $loaded() ) {
					return new WP_Error( 'off', 'module off', array( 'status' => 409 ) );
				}
				if ( $r->get_param( 'dry_first' ) ) { // 28-10-2026: the dry run and then the real import, in one request.
					$dry = \EstrategiaWeb\Redirects\Importer::import( (string) $r->get_param( 'csv' ), true );
					return array( 'dry' => $dry, 'real' => \EstrategiaWeb\Redirects\Importer::import( (string) $r->get_param( 'csv' ), false ) );
				}
				return \EstrategiaWeb\Redirects\Importer::import( (string) $r->get_param( 'csv' ), (bool) $r->get_param( 'dry_run' ) );
			}
		);
		$route(
			'/export',
			'GET',
			static fn() => array( 'csv' => \EstrategiaWeb\Redirects\Importer::export() )
		);
		$route(
			'/rule',
			'POST',
			static function ( WP_REST_Request $r ) {
				list( $id, $code ) = \EstrategiaWeb\Redirects\Importer::save_one(
					array(
						'source' => (string) $r->get_param( 'source' ),
						'target' => (string) $r->get_param( 'target' ),
						'type'   => $r->get_param( 'type' ) ?? 301,
						'active' => null === $r->get_param( 'active' ) ? true : (bool) $r->get_param( 'active' ),
					),
					(int) $r->get_param( 'id' )
				);
				return array( 'id' => $id, 'error' => $code );
			}
		);
		$route(
			'/rules',
			'GET',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				if ( $r->get_param( 'count' ) ) { // 28-10-2026: only how many (after the bench there are 5000).
					return array( 'count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}estrategia_web_redirects" ) ); // phpcs:ignore
				}
				return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}estrategia_web_redirects ORDER BY id", ARRAY_A ); // phpcs:ignore
			}
		);
		$route(
			'/settings',
			'POST',
			static function ( WP_REST_Request $r ) {
				\EstrategiaWeb\Redirects\Settings::save( (bool) $r->get_param( 'log_404' ), (int) ( $r->get_param( 'log_days' ) ?? 90 ) );
				return \EstrategiaWeb\Redirects\Settings::get();
			}
		);
		$route(
			'/log',
			'GET',
			static function () {
				global $wpdb;
				return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}estrategia_web_redirects_404 ORDER BY id", ARRAY_A ); // phpcs:ignore
			}
		);
		$route(
			'/log-backdate',
			'POST',
			static function () {
				global $wpdb;
				$wpdb->query( "UPDATE {$wpdb->prefix}estrategia_web_redirects_404 SET last_seen = '2000-01-01 00:00:00'" ); // phpcs:ignore
				return array( 'ok' => true );
			}
		);
		$route(
			'/purge',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				if ( $r->get_param( 'backdate' ) ) { // What /log-backdate does, in the same request (28-10-2026).
					$wpdb->query( "UPDATE {$wpdb->prefix}estrategia_web_redirects_404 SET last_seen = '2000-01-01 00:00:00'" ); // phpcs:ignore
				}
				\EstrategiaWeb\Redirects\Settings::purge();
				return array(
					'ok'        => true,
					'scheduled' => (bool) wp_next_scheduled( \EstrategiaWeb\Redirects\Settings::CRON ),
					'left'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}estrategia_web_redirects_404" ), // phpcs:ignore
				);
			}
		);
		$route( '/last', 'GET', static fn() => get_option( 'estrategia_web_redirects_test_last', null ) );
		$route(
			'/requests',
			'GET',
			static function () {
				global $wpdb;
				$rows = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'estrategia_web_redirects_test_req_%' ORDER BY option_id" ); // phpcs:ignore
				return array_map( 'maybe_unserialize', $rows );
			}
		);
		$route(
			'/fake-seo',
			'POST',
			static function ( WP_REST_Request $r ) {
				$plugin = (string) $r->get_param( 'plugin' );
				if ( '' !== $plugin ) {
					update_option( 'estrategia_web_redirects_test_fake', $plugin );
					update_option( 'rank_math_modules', (array) ( $r->get_param( 'modules' ) ?? array( 'redirections', 'sitemap' ) ) );
				} else {
					delete_option( 'estrategia_web_redirects_test_fake' );
					delete_option( 'rank_math_modules' );
				}
				return array( 'ok' => true );
			}
		);
		$route(
			'/bench',
			'POST',
			static function ( WP_REST_Request $r ) {
				global $wpdb;
				$t    = $wpdb->prefix . 'estrategia_web_redirects';
				$want = (int) $r->get_param( 'rules' );
				$have = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore
				// 500 rows per INSERT (28-10-2026): one by one, the 5000 took ~30 s of the test's time.
				for ( $i = $have; $i < $want; $i += 500 ) {
					$rows = array();
					for ( $j = $i; $j < min( $want, $i + 500 ); $j++ ) {
						$src    = '/relleno-' . $j . '/';
						$rows[] = $wpdb->prepare( "(%s, %s, '/', 301, 1, '2026-10-14 00:00:00', '2026-10-14 00:00:00')", $src, md5( \EstrategiaWeb\Redirects\Rules::key( $src ) ) );
					}
					$wpdb->query( "INSERT INTO $t (source, source_hash, target, type, active, created, updated) VALUES " . implode( ',', $rows ) ); // phpcs:ignore
				}
				$n     = max( 1, (int) $r->get_param( 'n' ) );
				$uris  = array( '/no-existe-nada/?utm_source=x', '/index.html%3Fp=6981.html', '/?p=30859&lang=es' );
				$start = microtime( true );
				for ( $i = 0; $i < $n; $i++ ) {
					\EstrategiaWeb\Redirects\Store::match( $uris[ $i % 3 ] );
				}
				return array(
					'rules'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), // phpcs:ignore
					'ms_each'   => round( ( microtime( true ) - $start ) * 1000 / $n, 4 ),
					'db_engine' => defined( 'DB_ENGINE' ) ? DB_ENGINE : ( class_exists( 'WP_SQLite_DB' ) ? 'sqlite' : 'mysql' ),
				);
			}
		);
	}
);
