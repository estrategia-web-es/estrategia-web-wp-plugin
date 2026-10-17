<?php
/**
 * Module Name: Cookie notice
 * Description: Own cookie consent notice (Accept / Reject / Settings with declarable categories), the «Cookie settings» link block and the consent API for PHP and JavaScript, plus a cookie list fed by a detector and a cookie table block for the cookie policy.
 * Default: on
 * Requires:
 *
 * It was part of «Estrategia Web Site» (since its 0.5.0; before that, of the
 * theme). Everything it does and how to maintain it, in the header of
 * includes/class-consent.php. Block: estrategia-web-site/consent (the 1.0.0
 * name, which does not change). Tests: modules/consent/tests/.
 *
 * Who depends on it without declaring it: Matomo in hybrid mode (site module)
 * listens to the «estrategia-web:consent» event in the browser. With this
 * module off, that event never arrives and Matomo stays without cookies,
 * which is the safe side. And the PHP API (estrategia_web_consent_has())
 * stops existing: callers must check function_exists() first.
 *
 * 1.1.0: besides the notice, the cookie REGISTRY with its DETECTOR (browsers
 * that accept send the names of the cookies they see), the
 * «Estrategia Web → Cookies» screen and the TABLE for the cookie policy
 * (estrategia-web/cookie-table block and shortcode). They stay here and not
 * in a separate module because they are the same notice: the categories it
 * asks about, and what the policy has to tell, come from the same source.
 * The Google tags (Consent Mode v2) are a separate module (google-tags), like
 * Matomo: they learn about consent through the JS API, without calling these
 * classes.
 *
 * Third-party data: data/open-cookie-database.json, an extract of the Open
 * Cookie Database (Apache 2.0; attribution in the JSON and in data/NOTICE),
 * regenerated with tools/open-cookie-database.mjs.
 *
 * How to maintain: anything about WHICH cookies exist and WHAT is asked goes
 * here; a tag or a service that WAITS for consent (Matomo, Google, a chat) is
 * its own module and talks through the JS API. Which cookies that service
 * sets is declared from its module with the estrategia_web_cookie_services
 * filter, against the catalogue in includes/class-cookie-services.php (the
 * «How to maintain» for a new one is there). Each piece documents its own
 * part in its header (includes/class-*.php).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_CONSENT_DIR', __DIR__ . '/' );

require_once __DIR__ . '/includes/class-consent.php';
require_once __DIR__ . '/includes/class-cookie-registry.php';
require_once __DIR__ . '/includes/class-cookie-services.php';
require_once __DIR__ . '/includes/class-cookie-table.php';
require_once __DIR__ . '/includes/class-cookie-rest.php';

Consent::boot(); // Boots on after_setup_theme: see its header («Coexistence»).
