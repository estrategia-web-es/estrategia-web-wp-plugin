<?php
/**
 * Only for the test WordPress (tests/blueprint.json loads it as a
 * mu-plugin). Routes for the cookie registry that are NOT what is under test:
 *
 * - POST /estrategia-web-cookies-test/v1/reset — deletes the registry, its
 *   settings, the rate-limit transients and whatever setup stored.
 * - POST /estrategia-web-cookies-test/v1/setup {"interval": 0, "cap": 5} —
 *   plays the role of wp-config/theme for the detector limits (the filters
 *   estrategia_web_cookies_report_interval and _hourly_cap). Without setup the
 *   real ones apply (10 min and 100/hour), which is what the «429» tests.
 * - GET /estrategia-web-cookies-test/v1/raw — the option as it is in the
 *   database (to check that no cookie VALUE was stored).
 *
 * How to maintain: a new option of the registry gets its delete_option in reset.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'estrategia_web_cookies_report_interval',
	static function ( $seconds ) {
		$setup = (array) get_option( 'estrategia_web_cookies_test', array() );
		return array_key_exists( 'interval', $setup ) ? (int) $setup['interval'] : $seconds;
	}
);
add_filter(
	'estrategia_web_cookies_hourly_cap',
	static function ( $cap ) {
		$setup = (array) get_option( 'estrategia_web_cookies_test', array() );
		return array_key_exists( 'cap', $setup ) ? (int) $setup['cap'] : $cap;
	}
);
add_filter(
	'estrategia_web_cookies_report_days',
	static function ( $days ) {
		$setup = (array) get_option( 'estrategia_web_cookies_test', array() );
		return array_key_exists( 'days', $setup ) ? (int) $setup['days'] : $days;
	}
);

add_action(
	'rest_api_init',
	static function () {
		$ns    = 'estrategia-web-cookies-test/v1';
		$route = static function ( string $path, string $methods, callable $cb ) use ( $ns ) {
			register_rest_route( $ns, $path, array( 'methods' => $methods, 'callback' => $cb, 'permission_callback' => '__return_true' ) );
		};
		$route(
			'/reset',
			'POST',
			static function () {
				global $wpdb;
				delete_option( 'estrategia_web_cookies' );
				delete_option( 'estrategia_web_cookies_settings' );
				delete_option( 'estrategia_web_cookies_test' );
				delete_option( 'estrategia_web_google_tags' ); // modules/google-tags: its tests use this same reset.
				delete_option( 'estrategia_web_map_google_seen' ); // modules/map: declares google-maps once a map with an embed was rendered.
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%estrategia\\_web\\_cs\\_%'" ); // phpcs:ignore
				return array( 'ok' => true );
			}
		);
		$route(
			'/setup',
			'POST',
			static function ( WP_REST_Request $r ) {
				update_option( 'estrategia_web_cookies_test', (array) $r->get_json_params() );
				return array( 'ok' => true );
			}
		);
		$route( '/raw', 'GET', static fn() => array( 'raw' => get_option( 'estrategia_web_cookies', null ) ) );
	}
);
