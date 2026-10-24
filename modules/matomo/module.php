<?php
/**
 * Module Name: Matomo
 * Description: Matomo tracking code, cookieless or hybrid (no cookies until the visitor accepts analytics in the cookie notice).
 * Default: on
 * Requires: site
 * Category: Privacy & analytics
 *
 * What each mode does and its pitfalls: header of includes/class-matomo.php.
 *
 * Why it has been a module since 1.1.0 (it used to live in «site»): so it can
 * be switched off on its own, and so Google analytics (google-tags) is its
 * peer rather than an add-on. What was NOT moved, on purpose:
 * - The OPTION. It stays inside estrategia_web_site (matomo_url,
 *   matomo_site_id, matomo_mode), with its schema and sanitization in
 *   modules/site/includes/class-settings.php. That way each site's saved
 *   option keeps working WITHOUT MIGRATION, and /wp/v2/settings does not
 *   change. Hence «Requires: site»: Settings belongs to site.
 * - The <script> id (estrategia-web-site-matomo) and the filters
 *   (estrategia_web_site_matomo_track): the sites have tests that check them.
 * The fields are in «Estrategia Web › Settings › Analytics» (1.7.0; up to
 * 1.6.1, on the «Company details» screen): includes/class-admin-fields.php.
 * With this module off they do not show up (and with google-tags off too,
 * the Analytics tab disappears), and what was saved stays.
 *
 * On by default, knowingly: it reaches sites that ALREADY had Matomo inside
 * site (Lavandería: hybrid, id 87). With «on», it switches itself on when
 * deployed (edge case in includes/class-modules.php: slug new to the site) and
 * the site keeps measuring as before. With «off» it would silently stop
 * measuring. Without a URL and id configured it prints nothing, so on a new
 * site it does nothing.
 *
 * How to maintain:
 * - Who and when: whoever touches Matomo; the tests are modules/matomo/tests/
 *   (matomo.test.mjs level 2, matomo-browser.test.mjs level 3).
 * - Criterion: what belongs to Matomo goes here; a new Matomo field is stored
 *   in Settings (site) so the option is not split, and rendered here.
 * - Edge case: site switched off. This module does not load (Requires) and the
 *   site stops measuring; what was saved stays in the option.
 * - The pitfall: hybrid mode LOADS matomo.js before consent (it measures
 *   without cookies, requireCookieConsent). A test demanding «no third-party
 *   request before accepting» will see it as a failure: it has to exclude the
 *   Matomo host in hybrid mode and check the COOKIES (_pk_*, mtm_*), not the
 *   requests. It happened with Lavandería's tests/consent-maps.test.mjs.
 */

namespace EstrategiaWeb\Matomo;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-matomo.php';
require_once __DIR__ . '/includes/class-admin-fields.php';

Matomo::boot();
add_action( \EstrategiaWeb\Admin\Settings_Page::HOOK, array( Admin_Fields::class, 'register' ) ); // 1.7.0: Settings › Analytics.
