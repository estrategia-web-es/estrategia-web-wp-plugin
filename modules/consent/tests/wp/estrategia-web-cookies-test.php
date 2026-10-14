<?php
/**
 * Solo para el WordPress de pruebas (tests/blueprint.json lo carga como
 * mu-plugin). Rutas del registro de cookies que NO son lo probado:
 *
 * - POST /estrategia-web-cookies-test/v1/reset — borra el registro, sus
 *   ajustes, los transitorios de ritmo y lo que haya puesto setup.
 * - POST /estrategia-web-cookies-test/v1/setup {"interval": 0, "cap": 5} —
 *   hace de wp-config/tema para los límites del detector (los filtros
 *   estrategia_web_cookies_report_interval y _hourly_cap). Sin setup valen
 *   los de verdad (10 min y 100/hora), que es lo que prueba el «429».
 * - GET /estrategia-web-cookies-test/v1/raw — la opción tal cual está en la
 *   base de datos (para ver que no se guardó ningún VALOR de cookie).
 *
 * Cómo se mantiene: una opción nueva del registro, su delete_option en reset.
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
				delete_option( 'estrategia_web_google_tags' ); // modules/google-tags: sus pruebas usan este mismo reset.
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
