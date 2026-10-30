<?php
namespace EstrategiaWeb\GoogleTags;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Google tags
 * Description: Google Analytics 4, Google Ads (with an optional conversion for form submissions) and Google Tag Manager behind the cookie notice, with Consent Mode v2. An empty field loads nothing.
 * Default: off
 * Requires: consent
 * Category: Privacy & analytics
 *
 * What it does, in full, in the header of includes/class-google-tags.php. In
 * short: three fields (G-…, AW-…, GTM-…) under «Estrategia Web › Settings ›
 * Analytics» (1.7.0; before, «Estrategia Web → Google»); an
 * empty field = none of that is loaded. In the <head>, BEFORE anything else,
 * Consent Mode v2 with everything «denied»; and Google's scripts are NOT
 * requested until the visitor accepts Analytics or Advertising in the notice
 * (basic mode, the default).
 *
 * Why Default: off: it changes what the site requests (Google) and declares
 * categories in the notice, which makes it show again to everyone. That is
 * decided site by site by switching it on, never on deploy (edge case in
 * includes/class-modules.php).
 *
 * Why Requires: consent: without the notice there is no consent and, in basic
 * mode, nothing would ever load; with the module on and the notice off the
 * Modules screen says what is missing instead of pretending to measure. It
 * talks to the notice ONLY through its JS API (window.estrategiaWebConsent and
 * the events), like Matomo, not by calling its classes.
 *
 * Option: estrategia_web_google_tags (also via /wp/v2/settings).
 * Screen: includes/admin/class-google-tags-page.php.
 * Tests: modules/google-tags/tests/ (level 2 what is printed; level 3 what the
 * browser requests before and after accepting, with Google intercepted).
 *
 * How to maintain:
 * - Who and when: whoever touches analytics. A new Google tag (e.g.
 *   Floodlight) is one more field with its validation in Google_Tags::FIELDS
 *   and its line in script(); another vendor (Meta, LinkedIn) is ANOTHER
 *   module following the same pattern.
 * - Category criterion: analytics_storage ← Analytics (statistics);
 *   ad_storage, ad_user_data and ad_personalization ← Advertising (marketing);
 *   functionality_storage and personalization_storage ← Preferences;
 *   security_storage, always granted.
 * - Edge case: Tag Manager only. GTM can carry anything inside, so it declares
 *   Analytics AND Advertising, and loads with either of the two; what each tag
 *   inside GTM does is decided by Consent Mode.
 * - The pitfall: «loading Google with everything denied» (advanced mode) also
 *   sends data to Google before consent (cookieless pings, with the IP). That
 *   is why it is not the default. See the class header.
 */

require_once __DIR__ . '/includes/class-google-tags.php';

Google_Tags::boot();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-google-tags-page.php';
	Google_Tags_Page::boot();
}
