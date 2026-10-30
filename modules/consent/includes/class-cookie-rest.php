<?php
namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

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
 *   - LOGGED-IN USERS (1.7.0, seen in Lavandería production on 24-10-2026:
 *     an administrator who accepted the notice sent wp-settings-1,
 *     wp-settings-time-1 and wp-wpml_current_admin_language_<hash>, cookies
 *     only the dashboard sets, and they landed as «pending»). Stopped at BOTH
 *     ends: the page of a logged-in user carries no detector configuration
 *     (Consent::state(), and view.js also checks the «logged-in» body class),
 *     and this route replies 202 and records nothing when the request
 *     carries a valid logged-in cookie (view.js sends with credentials
 *     «same-origin» since 1.7.0 so that it does; before, «omit»).
 *     THE TRAP: is_user_logged_in() is ALWAYS false here. The report has no
 *     nonce (see above), and without one WordPress's REST cookie check
 *     (rest_cookie_check_errors) sets the current user to 0 even with a
 *     perfectly valid cookie. So the cookie itself is validated
 *     (wp_validate_auth_cookie( '', 'logged_in' )). And if a browser sent no
 *     cookie at all (an old cached view.js with «omit», a privacy extension)
 *     the server cannot know: the page-side stop is the one that holds.
 *     Why the page-side stop is safe with page caches: SiteGround's, WP
 *     Super Cache, W3TC and the like never store or serve a page for a
 *     request with a logged-in cookie, so a cached page was always rendered
 *     for an anonymous visitor and never carries a logged-in user's state.
 *     (A cache that DID store logged-in pages for everyone would leak the
 *     admin bar too: that cache is broken, not this.)
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
		if ( self::from_logged_in_user() ) {
			// 1.7.0: an editor's browser carries dashboard-only cookies (wp-settings-*,
			// wp-wpml_current_admin_language_*). Same 202 as always, nothing recorded,
			// and the rate is not spent. See «Logged-in users» in the header.
			return new \WP_REST_Response( array( 'ok' => true ), 202 );
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

	/**
	 * Whether the report comes from a logged-in user's browser: a VALID
	 * logged-in cookie in the request. Not is_user_logged_in(): see the trap
	 * in the header (REST resets the user to 0 when there is no nonce).
	 */
	public static function from_logged_in_user(): bool {
		return (bool) wp_validate_auth_cookie( '', 'logged_in' );
	}

	public static function index(): array {
		Cookie_Services::sync(); // Like the screen: the services the modules declare, as proposals.
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
