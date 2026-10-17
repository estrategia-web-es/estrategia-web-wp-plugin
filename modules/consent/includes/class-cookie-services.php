<?php
/**
 * THIRD-PARTY SERVICES the plugin itself loads, and the cookies each one is
 * known to set. They reach the cookie registry (class-cookie-registry.php)
 * WITHOUT anyone typing them, as «pending» proposals on the
 * «Estrategia Web → Cookies» screen; confirmed, they go into the policy table.
 *
 * Why: the detector only sees document.cookie on the site's own domain. The
 * cookies Google sets on google.com from the map iframe, or on
 * doubleclick.net from the Ads tag, never reach it, and before this every
 * site had to add them by hand (Lavandería kept hand-written policy tables
 * for that reason).
 *
 * How it works:
 * 1. catalogue(), below: per service, its cookies with provider, category,
 *    purpose and duration, taken from the vendor's OWN documentation (the
 *    source and the date it was read are in a comment next to each one).
 *    A cookie with no fields takes them from Cookie_Registry::suggest()
 *    (base()): the Matomo ones are already there and are not written twice.
 * 2. The modules DECLARE which services they load with the
 *    estrategia_web_cookie_services filter (an array of catalogue ids). They
 *    depend only on their CONFIGURATION or on what they rendered, never on
 *    who visits. They do not call these classes (the same contract as with
 *    the notice: a filter, so a module works with this one switched off):
 *      google-tags → google-analytics (with a G- id), google-ads (with AW-)
 *      matomo      → matomo (hybrid mode only: cookieless sets none)
 *      map         → google-maps (once a map with its Google embed has been
 *                    rendered: option estrategia_web_map_google_seen)
 * 3. Cookie_Registry::merge_declared() writes them in when the Cookies screen
 *    (or the admin REST list) is opened: pending, source «module», with the
 *    service id. It never touches a row that already exists (detected, typed
 *    or edited: the administrator's word wins), and it removes the PENDING
 *    module rows of a service nobody declares any more. Confirmed ones stay;
 *    the screen says their module no longer declares them.
 *
 * How to maintain:
 * - Who and when: whoever makes a module load a third-party service (a new
 *   embed, a chat, a pixel), in the same commit as that module.
 * - How a module's new service is added:
 *   1. Its entry in catalogue(): id, label and cookies, with the URL of the
 *      vendor's cookie documentation and the date you read it, in a comment.
 *      Durations in the «N unit» form (90 days, 13 months, session), so the
 *      table translates them. Provider with the domain when it is a
 *      third-party one («Google (doubleclick.net)»): that is what the
 *      visitor needs to know.
 *   2. In the module, add_filter( 'estrategia_web_cookie_services', … ) that
 *      appends the id ONLY when the module really loads that service.
 *   3. The new texts into the .po (node tools/i18n.mjs) and a case in
 *      modules/consent/tests/php/pure.php.
 * - Criterion: ONLY cookies the vendor documents for what OUR code loads.
 *   Not everything the vendor has: Google's list has YouTube, Search and
 *   sign-in cookies that a map embed does not set. When in doubt, it stays
 *   out: a proposal is checked by the administrator, but a wrong one in the
 *   policy is worse than a missing one the detector or a hand adds.
 * - Named edge case: Tag Manager declares NOTHING. GTM itself sets no
 *   cookies; what its tags set depends on what is inside, which this plugin
 *   does not know. The first-party ones are caught by the detector; the
 *   third-party ones are added by hand.
 * - Named edge case 2: Matomo on ANOTHER domain (Matomo Cloud or a separate
 *   server) still sets its cookies on the SITE's domain (first-party: the
 *   detector sees them). Its only third-party cookie, _pk_uid, is off by
 *   default (Matomo FAQ 146) and is not declared.
 * - The trap: the Google Maps embed, loaded from Spain without a Google
 *   session, set NO cookies at all in a measurement on 17-10-2026
 *   (Chromium via Playwright, iframe and top level, es-ES and en-US). What
 *   Google lists are the cookies it MAY set (with a session, other regions,
 *   other consent states). That is why they come in as PROPOSALS and the
 *   texts say «may». Do not «verify» a catalogue entry by loading the embed
 *   once: absence in one browser proves nothing.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Cookie_Services {

	/** The filter the modules use to declare the services they load. */
	public const FILTER = 'estrategia_web_cookie_services';

	/**
	 * The known services. See the header for the criterion and the sources.
	 *
	 * @return array<string, array{label: string, cookies: array<string, array>}>
	 */
	public static function catalogue(): array {
		$maps = __( 'Google (google.com), only if the map is shown', 'estrategia-web' );
		return array(
			// Source: «How Google uses cookies», https://policies.google.com/technologies/cookies
			// (read on 17-10-2026). Only the functionality/security ones a
			// signed-out visitor may get from an embed; not YouTube, Search or sign-in.
			'google-maps'      => array(
				'label'   => __( 'Google Maps (map block)', 'estrategia-web' ),
				'cookies' => array(
					'NID'           => array(
						'provider' => $maps,
						'category' => 'maps',
						'purpose'  => __( 'Google may set it when the map loads: it remembers preferences such as the language.', 'estrategia-web' ),
						'duration' => '6 months',
					),
					'__Secure-ENID' => array(
						'provider' => $maps,
						'category' => 'maps',
						'purpose'  => __( 'Google may set it when the map loads: it remembers preferences such as the language.', 'estrategia-web' ),
						'duration' => '13 months',
					),
					'AEC'           => array(
						'provider' => $maps,
						'category' => 'maps',
						'purpose'  => __( 'Google may set it when the map loads: it detects spam, fraud and abuse.', 'estrategia-web' ),
						'duration' => '6 months',
					),
					'SOCS'          => array(
						'provider' => $maps,
						'category' => 'maps',
						'purpose'  => __( 'Google may set it when the map loads: it stores the choice made about Google’s own cookies.', 'estrategia-web' ),
						'duration' => '13 months',
					),
				),
			),
			// Source: «[GA4] Google Analytics cookie usage on websites»,
			// https://support.google.com/analytics/answer/11397207, and
			// «Cookie information for Google's ad products»,
			// https://business.safety.google/adscookies/ (both read on 17-10-2026).
			'google-analytics' => array(
				'label'   => __( 'Google Analytics (Google tags module)', 'estrategia-web' ),
				'cookies' => array(
					'_ga'   => array(
						'provider' => 'Google Analytics',
						'category' => 'statistics',
						'purpose'  => __( 'Statistics: distinguishes one visitor from another. Only after accepting analytics.', 'estrategia-web' ),
						'duration' => '2 years',
					),
					'_ga_*' => array(
						'provider' => 'Google Analytics',
						'category' => 'statistics',
						'purpose'  => __( 'Statistics: keeps the state of the visit. Only after accepting analytics.', 'estrategia-web' ),
						'duration' => '2 years',
					),
				),
			),
			// Source: «Cookie information for Google's ad products»,
			// https://business.safety.google/adscookies/ (read on 17-10-2026).
			// IDE: 13 months is the EEA/UK figure (24 elsewhere).
			'google-ads'       => array(
				'label'   => __( 'Google Ads (Google tags module)', 'estrategia-web' ),
				'cookies' => array(
					'_gcl_au'     => array(
						'provider' => 'Google Ads',
						'category' => 'marketing',
						'purpose'  => __( 'Advertising: measures the conversions that come from ads. Only after accepting advertising.', 'estrategia-web' ),
						'duration' => '90 days',
					),
					'_gcl_aw'     => array(
						'provider' => 'Google Ads',
						'category' => 'marketing',
						'purpose'  => __( 'Advertising: remembers the ad click that brought the visitor, to measure the conversion. Only after accepting advertising.', 'estrategia-web' ),
						'duration' => '90 days',
					),
					'IDE'         => array(
						'provider' => 'Google (doubleclick.net)',
						'category' => 'marketing',
						'purpose'  => __( 'Advertising: Google uses it to show and measure ads on other websites. Only after accepting advertising.', 'estrategia-web' ),
						'duration' => '13 months',
					),
					'test_cookie' => array(
						'provider' => 'Google (doubleclick.net)',
						'category' => 'marketing',
						'purpose'  => __( 'Advertising: Google checks whether the browser accepts cookies. Only after accepting advertising.', 'estrategia-web' ),
						'duration' => '15 minutes',
					),
				),
			),
			// Source: «What are the cookies created by Matomo?»,
			// https://matomo.org/faq/general/faq_146/ (read on 17-10-2026). The
			// texts are base()'s (no fields here): one place for them.
			'matomo'           => array(
				'label'   => __( 'Matomo, hybrid mode (Matomo module)', 'estrategia-web' ),
				'cookies' => array(
					'_pk_id.*'           => array(),
					'_pk_ses.*'          => array(),
					'_pk_ref.*'          => array(),
					'mtm_cookie_consent' => array(),
				),
			),
		);
	}

	/** @return string[] The catalogue ids the active modules declare (unknown ones are ignored). */
	public static function declared(): array {
		$ids = array_map( 'strval', (array) apply_filters( self::FILTER, array() ) );
		return array_values( array_intersect( array_keys( self::catalogue() ), $ids ) );
	}

	/**
	 * The rows the declared services bring: key => provider, category,
	 * purpose, duration and service. Empty fields from the suggestion.
	 *
	 * @param string[]|null $ids Services (default: declared()).
	 * @return array<string, array>
	 */
	public static function rows( ?array $ids = null ): array {
		$catalogue = self::catalogue();
		$out       = array();
		foreach ( $ids ?? self::declared() as $id ) {
			foreach ( $catalogue[ $id ]['cookies'] ?? array() as $key => $data ) {
				$suggestion = Cookie_Registry::suggest( Cookie_Registry::is_pattern( $key ) ? substr( $key, 0, -1 ) : $key );
				$row        = array( 'service' => $id );
				foreach ( array( 'provider', 'category', 'purpose', 'duration' ) as $field ) {
					$row[ $field ] = (string) ( $data[ $field ] ?? $suggestion[ $field ] ?? '' );
				}
				$out[ $key ] = $row;
			}
		}
		return $out;
	}

	/** The service's label for the screen ('' if it is no longer in the catalogue). */
	public static function label( string $id ): string {
		return (string) ( self::catalogue()[ $id ]['label'] ?? '' );
	}

	/** Writes the declared services into the registry. See the header, point 3. */
	public static function sync(): array {
		return Cookie_Registry::merge_declared( self::rows(), self::declared() );
	}
}
