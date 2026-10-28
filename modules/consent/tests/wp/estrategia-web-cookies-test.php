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
 * - POST /estrategia-web-cookies-test/v1/rows {"rows": [{"name": …, "status": …}]}
 *   (28-10-2026) — several registry rows in one request, through
 *   Cookie_Registry::save() (what the admin route calls).
 * - ?ew-consent-setup=<json> on a front request (28-10-2026) — what
 *   /consent-setup would have stored, for that request only (one request
 *   instead of two). Edge case: it is only for reading pages; a test that
 *   POSTs to the detector needs the stored /consent-setup, because the
 *   detector request carries no such parameter.
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

// 28-10-2026: ?ew-consent-setup=<json> on a FRONT request is the body
// /consent-setup (tests/wp/estrategia-web-site-test.php) would have stored,
// for THAT request only: the same filters read it (pre_option), so a test that
// changes what «the theme» declares and reads the page makes one request, not
// two. Nothing is stored. Used by consent.test.mjs.
add_filter(
	'pre_option_estrategia_web_site_test_consent',
	static function ( $pre ) {
		if ( ! isset( $_GET['ew-consent-setup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $pre;
		}
		$setup = json_decode( wp_unslash( (string) $_GET['ew-consent-setup'] ), true ); // phpcs:ignore WordPress.Security
		return is_array( $setup ) ? $setup : $pre;
	}
);

// 28-10-2026 (round 2): ?ew-options=<json {"option": value}> on a GET request
// (a page, or a read-only REST route) is those options' value for THAT
// request only, for the options listed here. The plugin reads them as
// always (merging defaults and cleaning), so what is tested is what it does
// with that value; HOW it is saved (REST validation) keeps its own cases.
// Used by google-tags, matomo, cookie-services, consent and cookie-registry
// (level 2) and google-tags-browser and matomo-browser (level 3, on the page
// URL: reloads keep it) to read several configurations at once instead of
// save + read each. A new option is added to the list below only if a test
// needs it; reading pages must not depend on it being there.
// Edge case: never on a save request — sanitize() merges with get_option(),
// and the override would leak into what is stored.
foreach ( array( 'estrategia_web_google_tags', 'estrategia_web_site', 'estrategia_web_site_test_consent', 'estrategia_web_cookies_settings' ) as $estrategia_web_test_option ) {
	add_filter(
		'pre_option_' . $estrategia_web_test_option,
		static function ( $pre, $option ) {
			if ( ! isset( $_GET['ew-options'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return $pre;
			}
			$all = json_decode( wp_unslash( (string) $_GET['ew-options'] ), true ); // phpcs:ignore WordPress.Security
			return is_array( $all ) && array_key_exists( $option, $all ) ? $all[ $option ] : $pre;
		},
		10,
		2
	);
}

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
		// 28-10-2026: several registry rows in ONE request, through the same
		// Cookie_Registry::save() the admin route calls (the admin route itself
		// is tested on its own in cookie-registry.test.mjs).
		$route(
			'/rows',
			'POST',
			static function ( WP_REST_Request $r ) {
				$out = array();
				foreach ( (array) $r->get_param( 'rows' ) as $row ) {
					$entry = \EstrategiaWeb\Site\Cookie_Registry::save( (string) $row['name'], (array) $row );
					if ( is_wp_error( $entry ) ) {
						return $entry;
					}
					$out[ $row['name'] ] = $entry;
				}
				return $out;
			}
		);
	}
);
