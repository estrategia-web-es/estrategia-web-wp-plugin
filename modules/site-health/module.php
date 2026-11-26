<?php
namespace EstrategiaWeb\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Site Health checks
 * Description: Adds the Estrategia Web checks to Tools › Site Health: search engines, legal pages, cookies, company details, locations, social profiles, security headers, login protection, page cache, DMARC, untranslated SEO texts, llms.txt and FAQ blocks. Each one says why it matters and links to the screen where it is fixed. It only reports: it changes nothing.
 * Default: on
 * Requires:
 * Category: Media & performance
 *
 * Born on 26-11-2026 from the Llar d'Estels audit (25-26/11/2026): twelve
 * findings that any of our sites can have and that nobody sees until an
 * audit looks. WordPress already has the screen where a site's owner looks
 * for this (Tools › Site Health, and its dashboard widget), so the checks go
 * there, grouped under the badge «Estrategia Web», instead of a screen of
 * our own.
 *
 * Why Default: on: it reads, it never writes (not an option, not a header,
 * not a file). The only cost is when the Site Health screen is opened (and
 * WordPress' weekly site-health cron): the direct checks are a few options
 * and capped queries; the two that go out of the request (a loopback GET
 * of the home page, a DNS lookup) are ASYNC, run by the screen's
 * JavaScript through our REST route, so they never slow the page.
 *
 * Why «Media & performance»: it is where security-headers and cache-purge
 * live, the modules about how the site is served and kept in shape. No
 * category is «maintenance»; SEO would fit five checks and not the other
 * seven, and a new category for one module is not worth the .po churn.
 *
 * How it works: includes/class-registry.php loads every file in checks/,
 * hooks site_status_tests and registers ONE REST route for the async ones.
 * Each check is a FILE that returns an array (the contract is in the
 * registry's header): gather() reads the site, judge() decides from that
 * data alone — so level 1 tests judge() with plain arrays and no WordPress.
 *
 * How to maintain:
 * - Who: whoever audits a site and finds something any site could have.
 *   When: the finding is the same on every site and checkable from PHP.
 *   It is a new file in checks/ (copy the closest one); nothing else changes,
 *   except its case in tests/judge.test.mjs, its strings in the .po files,
 *   and this header's Description if the list of topics grows.
 * - Criterion: a check REPORTS; it never fixes, never writes, never makes
 *   more than one outbound request, and anything outbound is async. Critical
 *   is kept for what loses visitors or breaks the law today (hidden from
 *   Google, no legal pages); the rest is recommended.
 * - Edge case, by name: a check whose gather() throws (a plugin's option in
 *   a shape we did not expect) shows as «recommended: could not check», with
 *   the message; it must never break the Site Health screen.
 * - The trap: Site Health caches nothing for direct tests but the dashboard
 *   widget shows the counts of the LAST visit to the screen (option
 *   health-check-site-status-result). After fixing something, open the
 *   screen again before saying it is fixed.
 * - Tests: modules/site-health/tests/judge.test.mjs and registry.test.mjs
 *   (level 1), site-health.test.mjs (level 2).
 */

require_once __DIR__ . '/includes/class-registry.php';
require_once __DIR__ . '/includes/class-probe.php';

Registry::hook();
