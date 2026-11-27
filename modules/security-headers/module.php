<?php
namespace EstrategiaWeb\SecurityHeaders;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Security headers
 * Description: Adds the browser security headers a site is missing (HSTS over https, framing only from the site itself, a referrer policy and no camera, microphone or location) and hides the PHP version. It never repeats a header another plugin already sends.
 * Default: off
 * Requires:
 * Category: Media & performance
 *
 * Born on 26-11-2026 with the Llar d'Estels audit: its future hosting (OVH,
 * Apache) sends none of these headers and says «X-Powered-By: PHP/8.5». The
 * rest of a site's hardening is NOT here on purpose: SiteGround Security does
 * it and works outside SiteGround too (login attempts, XML-RPC, nosniff,
 * system folders, WP version), and page and browser caching is Speed
 * Optimizer's (outside SiteGround, its file-based cache and its browser
 * caching rules in .htaccess). This module adds only what those leave out.
 *
 * How it works: one filter on wp_headers, at priority 100, so it runs after
 * everyone else's (SiteGround Security adds its headers at 10). For each
 * header in HEADERS it looks at the headers WordPress is about to send and at
 * the ones PHP has already queued (headers_list()), case-insensitively, and
 * adds it only if nobody has. It writes nothing: not to the database and not
 * to .htaccess. Switching it off is the end of it.
 *
 * Where it applies: the pages WordPress builds (front end, feeds, REST does
 * NOT go through wp_headers). Not static files: HSTS on the HTML is enough
 * for the browser to use https for the whole host, and wp-admin and wp-login
 * already send X-Frame-Options themselves (core's frame_options).
 *
 * How to maintain:
 * - Who: whoever touches the plugin. When: when a site needs a header that
 *   is the same for every site; a header one site needs and another does not
 *   is that site's, through the estrategia_web_security_headers filter.
 * - Criterion: a header goes in if it is safe on ANY site with its default
 *   value. That is why Content-Security-Policy is out: with Google tags,
 *   maps in two clicks and an SEO plugin, a CSP that is wrong breaks the site
 *   without an error anyone sees. And why HSTS has no «preload»: preload is
 *   very hard to undo, and that is the site owner's decision, not a default.
 * - Edge case, by name: a header the SERVER adds (Apache's Header set in
 *   .htaccess, the hosting panel) is invisible to PHP, so it would go out
 *   twice. Header always set from SiteGround Security's .htaccess replaces
 *   ours, so its nosniff is not duplicated; anything else seen twice on a
 *   real site is fixed with the filter (return the header without that key).
 * - WPML's version (2.18.0): WPML prints <meta name="generator" content="WPML ver:x.y.z …"> from
 *   SitePress::meta_generator_tag on wp_head. It is unhooked on «wp» (after
 *   WPML has hooked it, before wp_head runs). It only touches that WPML
 *   method; WordPress' own generator is SiteGround Security's.
 * - The pitfall: X-Powered-By is not in wp_headers; PHP adds it before
 *   WordPress starts, so it is removed with header_remove(), which only works
 *   if the hosting has not added it AFTER PHP (some do, from the web server):
 *   check on the real site, the first day.
 * - Test: modules/security-headers/tests/security-headers.test.mjs (level 2).
 */

/**
 * The headers, with their values. HSTS only over https (see apply()).
 * nosniff is here so a site without SiteGround Security also has it; with it,
 * SiteGround's is already there and this one is not added.
 */
const HEADERS = array(
	'Strict-Transport-Security' => 'max-age=31536000',
	'X-Frame-Options'           => 'SAMEORIGIN',
	'X-Content-Type-Options'    => 'nosniff',
	'Referrer-Policy'           => 'strict-origin-when-cross-origin',
	'Permissions-Policy'        => 'camera=(), microphone=(), geolocation=()',
);

/**
 * The headers to send: what is missing from $headers and from what PHP has
 * already queued. Pure function: tests/php can call it without WordPress
 * having sent anything.
 *
 * @param array<string,string> $headers Headers WordPress is about to send.
 * @param string[]             $queued  Lines from headers_list().
 * @param bool                 $https   Whether the request came over https.
 * @return array<string,string>
 */
function missing( array $headers, array $queued, bool $https ): array {
	$have = array();
	foreach ( array_keys( $headers ) as $key ) {
		$have[ strtolower( (string) $key ) ] = true;
	}
	foreach ( $queued as $line ) {
		$have[ strtolower( trim( strstr( (string) $line, ':', true ) ) ) ] = true;
	}
	/**
	 * The security headers this site adds when missing. Return the array
	 * without a key to leave that header out, or with another value.
	 *
	 * @param array<string,string> $wanted Header => value.
	 */
	$wanted = (array) apply_filters( 'estrategia_web_security_headers', HEADERS );
	if ( ! $https ) {
		unset( $wanted['Strict-Transport-Security'] );
	}
	$add = array();
	foreach ( $wanted as $key => $value ) {
		if ( '' !== (string) $value && empty( $have[ strtolower( (string) $key ) ] ) ) {
			$add[ (string) $key ] = (string) $value;
		}
	}
	return $add;
}

add_filter(
	'wp_headers',
	static function ( $headers ) {
		$headers = (array) $headers;
		return array_merge( $headers, missing( $headers, headers_list(), is_ssl() ) );
	},
	100
);

add_action(
	'send_headers',
	static function (): void {
		if ( ! headers_sent() ) {
			header_remove( 'X-Powered-By' );
		}
	}
);

/** WPML publishes its version in a generator meta tag: take it off the public HTML. */
add_action(
	'wp',
	static function (): void {
		global $sitepress;
		if ( is_object( $sitepress ) && method_exists( $sitepress, 'meta_generator_tag' ) ) {
			remove_action( 'wp_head', array( $sitepress, 'meta_generator_tag' ) );
		}
	}
);
