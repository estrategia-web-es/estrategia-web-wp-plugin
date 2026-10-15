<?php
/**
 * REST routes of the cookie registry (estrategia-web/v1 namespace).
 *
 * PUBLIC — POST /cookies/seen  {"names": ["_ga", "_ga_ABC123", …]}
 *   Called by the browser of a visitor who ACCEPTED some category
 *   (blocks/consent/view.js), with the NAMES of the cookies it sees through
 *   document.cookie. Never values. Replies 202 without revealing anything
 *   about the list.
 *
 *   Why WITHOUT a nonce: the page may come from a cache (SiteGround, etc.)
 *   and the cached nonce expires after 12-24 h; with a nonce the detector
 *   would switch itself off on sites with a cache, which is nearly all of
 *   them. Instead:
 *   - Per-client rate: one request every REPORT_INTERVAL seconds per IP.
 *     The IP is NOT stored: only a hash salted with the day, in a transient
 *     that expires with the interval. (It is the REMOTE_ADDR IP: behind a
 *     proxy everyone shares one, and that only makes the limit stricter.)
 *   - Global rate: HOURLY_CAP requests per hour for the whole site.
 *   - Size: MAX_NAMES names per request, each with RFC 6265 characters and
 *     ≤ 128; the registry does not go beyond Cookie_Registry::MAX_ENTRIES.
 *   - Origin: if an Origin header arrives, it must be the site's.
 *   - With the detector off (Cookies screen) it replies 403 and records nothing.
 *   The worst an attacker can do: fill the pending list with made-up names
 *   (at most MAX_ENTRIES), which do not show in the policy until someone
 *   confirms them, and can be deleted on the screen.
 *
 * ADMIN (manage_options) — GET/POST/DELETE /cookies
 *   The same as the screen, for scripts and tests: GET lists everything (with
 *   the version and the database attribution), POST {name, provider,
 *   category, purpose, duration, status} creates or changes, DELETE ?name=
 *   deletes.
 *
 * How to maintain:
 * - Who and when: whoever touches the detector. Tests:
 *   modules/consent/tests/cookies.test.mjs (level 2: limits, origin, values
 *   that are not stored) and cookies-browser.test.mjs (level 3: what a real
 *   browser sends on accepting).
 * - The limits are tuned with the filters estrategia_web_cookies_report_interval
 *   and estrategia_web_cookies_hourly_cap (seconds and requests/hour).
 * - Edge case: two visitors behind the same IP (an office). The second one
 *   gets 429 during the interval; nothing is lost: the first one already sent
 *   the same, and the browser retries when the list changes or when the
 *   period ends.
 * - The trap: the rate transient is an option in the database if there is no
 *   object cache. That is why the key carries a short hash and expires by
 *   itself, and the tests delete estrategia_web_cs_* (the reset route of
 *   their mu-plugin).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Cookie_Rest {

	public const NAMESPACE = 'estrategia-web/v1';

	/** Maximum names per request. */
	public const MAX_NAMES = 60;

	/** Prefix of the rate transients (the tests delete them). */
	public const RATE_PREFIX = 'estrategia_web_cs_';

	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/cookies/seen',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'seen' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'names' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
						'maxItems' => self::MAX_NAMES,
					),
				),
			)
		);
		$admin = static fn() => current_user_can( 'manage_options' );
		register_rest_route(
			self::NAMESPACE,
			'/cookies',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'index' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => $admin,
					'args'                => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'delete' ),
					'permission_callback' => $admin,
					'args'                => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/** Seconds between two reports from the same client. */
	public static function interval(): int {
		return max( 0, (int) apply_filters( 'estrategia_web_cookies_report_interval', 10 * MINUTE_IN_SECONDS ) );
	}

	/** Reports per hour for the whole site. */
	public static function hourly_cap(): int {
		return max( 0, (int) apply_filters( 'estrategia_web_cookies_hourly_cap', 100 ) );
	}

	/** The detector's report. See the header. */
	public static function seen( \WP_REST_Request $request ) {
		if ( ! Consent::active() || ! Cookie_Registry::settings()['detector'] ) {
			return new \WP_Error( 'estrategia_web_detector_off', 'Detector off.', array( 'status' => 403 ) );
		}
		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new \WP_Error( 'estrategia_web_detector_origin', 'Wrong origin.', array( 'status' => 403 ) );
		}

		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$client = self::RATE_PREFIX . substr( md5( $ip . '|' . gmdate( 'Ymd' ) . '|' . wp_salt( 'nonce' ) ), 0, 16 );
		$hour   = self::RATE_PREFIX . 'hour_' . gmdate( 'YmdH' );
		$count  = (int) get_transient( $hour );
		if ( ( self::interval() > 0 && get_transient( $client ) ) || $count >= self::hourly_cap() ) {
			return new \WP_Error( 'estrategia_web_detector_rate', 'Too many reports.', array( 'status' => 429 ) );
		}
		if ( self::interval() > 0 ) {
			set_transient( $client, 1, self::interval() );
		}
		set_transient( $hour, $count + 1, HOUR_IN_SECONDS );

		$names = array_slice( array_filter( (array) $request->get_param( 'names' ), 'is_string' ), 0, self::MAX_NAMES );
		Cookie_Registry::record_seen( $names );
		return new \WP_REST_Response( array( 'ok' => true ), 202 );
	}

	public static function index(): array {
		$ocd = Cookie_Registry::ocd();
		return array(
			'version'     => Cookie_Registry::version(),
			'settings'    => Cookie_Registry::settings(),
			'cookies'     => (object) Cookie_Registry::all(),
			'attribution' => array(
				'source'      => $ocd['source'] ?? '',
				'license'     => $ocd['license'] ?? '',
				'attribution' => $ocd['attribution'] ?? '',
				'generated'   => $ocd['generated'] ?? '',
			),
		);
	}

	public static function save( \WP_REST_Request $request ) {
		$entry = Cookie_Registry::save( (string) $request->get_param( 'name' ), (array) $request->get_params() );
		return is_wp_error( $entry ) ? $entry : array(
			'name'  => trim( (string) $request->get_param( 'name' ) ),
			'entry' => $entry,
		);
	}

	public static function delete( \WP_REST_Request $request ) {
		return array( 'deleted' => Cookie_Registry::delete( (string) $request->get_param( 'name' ) ) );
	}
}
