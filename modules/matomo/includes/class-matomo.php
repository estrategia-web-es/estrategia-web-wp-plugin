<?php
namespace EstrategiaWeb\Matomo;

defined( 'ABSPATH' ) || exit;

/**
 * Matomo (Armazón, row 16): the tracking code in the site's <head>, with the
 * URL, site id and mode from the `estrategia_web_site` option (`matomo_url`,
 * `matomo_site_id`, `matomo_mode`; see Settings).
 *
 * The two modes:
 * - `cookieless` (the default, row 16's): `disableCookies`. Matomo sets no
 *   cookie at all and there is nothing to ask: the cookie notice
 *   (includes/class-consent.php) does not even show if there is no other
 *   category.
 * - `hybrid`: tracking starts WITHOUT cookies (`requireCookieConsent`: Matomo
 *   sends the visits but writes no cookies) and, when the visitor accepts
 *   «statistics» in the cookie notice, `rememberCookieConsentGiven` is called
 *   (Matomo stores its own mtm_cookie_consent cookie and from then on uses
 *   _pk_id/_pk_ses); if it is withdrawn, `forgetCookieConsentGiven` (deletes
 *   its consent and its cookies).
 *   Reference: https://developer.matomo.org/guides/tracking-consent
 *   NOTE: the legality of hybrid (measuring without cookies BEFORE asking) is
 *   not verified; the CNIL's criterion for the consent exemption is narrower.
 *   That is why it is not the default mode.
 *
 * How it learns about consent (the notice's contract, which since 0.5.0
 * belongs to this same plugin: includes/class-consent.php and
 * blocks/consent/view.js; it used to live in the parent theme): the
 * «estrategia-web:consent» event on document (detail.accepted = accepted
 * categories), fired on startup if there was already a choice and on every
 * change, and window.estrategiaWebConsent.get() (null = no choice yet). BOTH
 * are listened to: the notice module may arrive before or after this script.
 * It talks to it ONLY through that contract, not by calling Consent: that way
 * it keeps working with an old parent theme that still ships its own notice
 * (Consent::active() = false).
 * - In hybrid mode the plugin DECLARES the «statistics» category with the
 *   `estrategia_web_consent_categories` filter (priority 99), so the notice
 *   shows without the theme having to remember.
 * - Since 0.5.0 the notice does not depend on the theme: with any theme there
 *   is a notice and an event. (Up to 0.4.0, without the parent theme hybrid
 *   stayed cookieless forever.)
 *
 * Who is NOT measured: the dashboard, the editor, previews and logged-in users
 * who can edit (`edit_posts`: authors and up). This is the usual practice (the
 * official Matomo plugin for WordPress does not measure administrators by
 * default either) and keeps the site's own people from inflating the figures.
 * A site that wants something else: the `estrategia_web_site_matomo_track`
 * filter.
 *
 * How to maintain:
 * - Who and when: whoever touches the plugin; the tests are
 *   modules/matomo/tests/matomo.test.mjs (level 2) and matomo-browser.test.mjs
 *   (level 3, with the real matomo.js from tests/fixtures/ served from a fake
 *   route).
 * - 1.1.0: it is its own module (modules/matomo), but the OPTION is still the
 *   site module's (estrategia_web_site, matomo_* keys), sanitized in Settings:
 *   there is no data migration. See modules/matomo/module.php.
 * - A new mode: Settings::MATOMO_MODES + script() + the screen + README.
 *   Removing one is a major version (see the pitfall in Settings).
 * - Edge case: the category is withdrawn in the notice on one page and the
 *   visitor keeps browsing. The notice only RELOADS if there was an already
 *   executed <script type="text/plain" data-estrategia-web-consent>; this code
 *   is not one, so there is no reload: forgetCookieConsentGiven is called
 *   right away and that is enough.
 * - Pitfall 1: mtm_cookie_consent lasts 30 years and the notice's choice 12
 *   months. When the notice's choice expires (or its revision changes), get()
 *   returns null and NO event arrives: so, on page load, if the notice is
 *   there and says «no choice yet», forgetCookieConsentGiven is called.
 *   Without that Matomo would keep using cookies on a consent that no longer
 *   exists.
 *   «On load» is NOT window.load: the notice hydrates when it can, sometimes
 *   AFTER load (measured on 2026-10-05 testing with the real notice: 1 in 3
 *   loads; the simulated 0.3.0 test could not see it). That is why
 *   «estrategia-web:consent-ready» is also listened to; the notice fires it
 *   once on startup, with or without a choice (0.5.0).
 * - Pitfall 2: a child theme that declares categories with
 *   `fn() => array( 'maps' )` (ignoring what it receives) OVERWRITES this
 *   plugin's if it runs later. That is why this filter runs at priority 99.
 *   If a child needs to run even later, it should append instead of replace.
 * - Pitfall 3: the code is inline in the <head> and identical for every
 *   visitor, so a page cache breaks nothing: the cookie decision is made in
 *   the browser. The who-is-measured filter does depend on the session, but
 *   logged-in users are not served from cache.
 * - «No full IP» (row 16) does NOT belong here: it is configured on the
 *   Matomo server (Anonymize IP), not in the tracking code.
 */

use EstrategiaWeb\Site\Settings;

final class Matomo {

	/** Cookie notice category that grants consent in hybrid mode. */
	public const CONSENT_CATEGORY = 'statistics';

	public static function boot(): void {
		add_action( 'wp_head', array( self::class, 'print' ), 20 );
		add_filter( 'estrategia_web_consent_categories', array( self::class, 'consent_categories' ), 99 );
		add_filter( 'estrategia_web_cookie_services', array( self::class, 'cookie_services' ), 99 );
	}

	/**
	 * For the cookie registry: hybrid mode sets Matomo's cookies after
	 * consent, so they are proposed before any visitor accepts. Cookieless
	 * sets none and declares nothing. Which cookies, and why Matomo on another
	 * domain changes nothing: modules/consent/includes/class-cookie-services.php.
	 */
	public static function cookie_services( mixed $services ): array {
		$services = (array) $services;
		$config   = self::config();
		if ( $config && 'hybrid' === $config['mode'] && ! in_array( 'matomo', $services, true ) ) {
			$services[] = 'matomo';
		}
		return $services;
	}

	/**
	 * The configuration ready to use, or null if the URL or the id is missing.
	 *
	 * @return array{url: string, site_id: int, mode: string}|null
	 */
	public static function config(): ?array {
		$url     = (string) Settings::get( 'matomo_url' );
		$site_id = absint( Settings::get( 'matomo_site_id' ) );
		if ( '' === $url || 0 === $site_id ) {
			return null;
		}
		$mode = (string) Settings::get( 'matomo_mode' );
		return array(
			'url'     => trailingslashit( $url ),
			'site_id' => $site_id,
			'mode'    => in_array( $mode, Settings::MATOMO_MODES, true ) ? $mode : Settings::MATOMO_MODES[0],
		);
	}

	/** Whether this request is measured. */
	public static function should_track(): bool {
		$track = ! is_admin() && ! is_preview() && ! is_customize_preview() && ! current_user_can( 'edit_posts' );
		/**
		 * Decides whether this request is measured (by default: not in the
		 * dashboard, not in previews, not for users who can edit).
		 *
		 * @param bool $track What the plugin decided.
		 */
		return (bool) apply_filters( 'estrategia_web_site_matomo_track', $track );
	}

	/** In hybrid mode, the notice has to ask about statistics. */
	public static function consent_categories( mixed $categories ): array {
		$categories = (array) $categories;
		$config     = self::config();
		if ( $config && 'hybrid' === $config['mode'] && ! in_array( self::CONSENT_CATEGORY, $categories, true ) ) {
			$categories[] = self::CONSENT_CATEGORY;
		}
		return $categories;
	}

	public static function print(): void {
		$config = self::config();
		if ( ! $config || ! self::should_track() ) {
			return;
		}
		wp_print_inline_script_tag( self::script( $config ), array( 'id' => 'estrategia-web-site-matomo' ) );
	}

	/** The tracking JS. No on-screen text: nothing to translate. */
	public static function script( array $config ): string {
		$url      = wp_json_encode( $config['url'] );
		$site_id  = wp_json_encode( (string) $config['site_id'] );
		$category = wp_json_encode( self::CONSENT_CATEGORY );
		$hybrid   = 'hybrid' === $config['mode'];
		$first    = $hybrid ? "_paq.push(['requireCookieConsent']);" : "_paq.push(['disableCookies']);";

		$js = <<<JS
var _paq = window._paq = window._paq || [];
{$first}
_paq.push(['trackPageView']);
_paq.push(['enableLinkTracking']);
(function () {
	var u = {$url};
	_paq.push(['setTrackerUrl', u + 'matomo.php']);
	_paq.push(['setSiteId', {$site_id}]);
	var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
	g.async = true; g.src = u + 'matomo.js'; s.parentNode.insertBefore(g, s);
})();
JS;
		if ( ! $hybrid ) {
			return $js;
		}
		// Hybrid: follows the cookie notice (through its JS contract). See the header (the two channels and pitfall 1).
		$js .= <<<JS

(function () {
	var given = null;
	function sync(accepted) {
		var yes = Array.isArray(accepted) && accepted.indexOf({$category}) !== -1;
		if (yes === given) { return; }
		given = yes;
		window._paq.push([yes ? 'rememberCookieConsentGiven' : 'forgetCookieConsentGiven']);
	}
	document.addEventListener('estrategia-web:consent', function (e) { sync(e.detail && e.detail.accepted); });
	function current() {
		if (given === null && window.estrategiaWebConsent && typeof window.estrategiaWebConsent.get === 'function') {
			var choice = window.estrategiaWebConsent.get();
			sync(choice || []);
		}
	}
	current();
	window.addEventListener('load', current);
	document.addEventListener('estrategia-web:consent-ready', current);
})();
JS;
		return $js;
	}
}
