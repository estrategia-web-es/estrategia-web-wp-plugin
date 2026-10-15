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
 *   and deletes the «about» and «nosotros» pages the tests create.
 * - import {csv, dry_run}, rule {source, target, type, active}, rules,
 *   settings {log_404, log_days}, log, log-backdate, purge.
 * - last: what happened on the last PUBLIC request (not REST or admin): how
 *   many queries touched the module's tables and how many there were in
 *   total. Counted with the «query» filter and recorded on shutdown.
 * - fake-rank-math {on}: plays Rank Math with its redirects module (defines
 *   RANK_MATH_VERSION here, in a mu-plugin, before the plugins).
 * - bench {n, rules}: seeds filler rules up to «rules» and measures n
 *   Store::match lookups (ms per lookup).
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_REDIRECTS_TESTING' ) || true !== ESTRATEGIA_WEB_REDIRECTS_TESTING ) {
	return;
}

if ( get_option( 'estrategia_web_redirects_test_rank_math' ) ) {
	define( 'RANK_MATH_VERSION', '1.0.0-falso' );
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
		update_option(
			'estrategia_web_redirects_test_last',
			array(
				'uri'     => $_SERVER['REQUEST_URI'] ?? '', // phpcs:ignore
				'queries' => $GLOBALS['estrategia_web_redirects_test_queries'],
				'total'   => get_num_queries(),
			),
			false
		);
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
			static function () {
				global $wpdb;
				foreach ( array( 'estrategia_web_redirects', 'estrategia_web_redirects_404' ) as $t ) {
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) ) ) {
						$wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" ); // phpcs:ignore
					}
				}
				delete_option( 'estrategia_web_redirects_settings' );
				delete_option( 'estrategia_web_redirects_test_rank_math' );
				delete_option( 'rank_math_modules' );
				delete_option( 'estrategia_web_redirects_test_last' );
				// The pages these tests create with a CSV source as slug: if
				// live, they would hide the rule in the next run.
				foreach ( array( 'about', 'nosotros' ) as $slug ) {
					$page = get_page_by_path( $slug );
					if ( $page ) {
						wp_delete_post( $page->ID, true );
					}
				}
				return array( 'ok' => true );
			}
		);
		$route(
			'/import',
			'POST',
			static function ( WP_REST_Request $r ) use ( $loaded ) {
				if ( ! $loaded() ) {
					return new WP_Error( 'off', 'module off', array( 'status' => 409 ) );
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
			static function () {
				global $wpdb;
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
			static function () {
				\EstrategiaWeb\Redirects\Settings::purge();
				return array( 'ok' => true, 'scheduled' => (bool) wp_next_scheduled( \EstrategiaWeb\Redirects\Settings::CRON ) );
			}
		);
		$route( '/last', 'GET', static fn() => get_option( 'estrategia_web_redirects_test_last', null ) );
		$route(
			'/fake-rank-math',
			'POST',
			static function ( WP_REST_Request $r ) {
				if ( $r->get_param( 'on' ) ) {
					update_option( 'estrategia_web_redirects_test_rank_math', 1 );
					update_option( 'rank_math_modules', (array) ( $r->get_param( 'modules' ) ?? array( 'redirections', 'sitemap' ) ) );
				} else {
					delete_option( 'estrategia_web_redirects_test_rank_math' );
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
				for ( $i = $have; $i < $want; $i++ ) {
					$src = '/relleno-' . $i . '/';
					$wpdb->insert( $t, array( 'source' => $src, 'source_hash' => md5( \EstrategiaWeb\Redirects\Rules::key( $src ) ), 'target' => '/', 'type' => 301, 'active' => 1, 'created' => '2026-10-14 00:00:00', 'updated' => '2026-10-14 00:00:00' ) ); // phpcs:ignore
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
