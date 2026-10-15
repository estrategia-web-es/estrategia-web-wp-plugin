<?php
/**
 * Module Name: Redirects
 * Description: Editable 301, 302 and 410 redirects with hit counters, CSV import and export, and an optional 404 log.
 * Default: off
 * Requires:
 *
 * Editable redirects under «Estrategia Web → Redirects»: source, target,
 * type (301, 302, 410), active, hit counter and last hit. CSV import and
 * export (the format of Lavandería's,
 * docs/redirecciones/redirecciones-2026-10-07.csv in that repo), optional
 * 404 log with «Create redirect», and WP-CLI for deployment:
 *
 *     wp estrategia-web redirects import file.csv [--dry-run]
 *     wp estrategia-web redirects list | export
 *
 * WHY IT SHIPS DISABLED (Default: off): it changes what the visitor sees, and
 * on Lavandería, the only site today, redirects are handled by Rank Math
 * because the SEO agency works with it. Enabling it there would mean two
 * systems at once; the screen warns if that happens
 * (includes/class-conflicts.php).
 *
 * Where everything lives:
 *   includes/class-rules.php     the pure part: normalise, match, read the
 *                                CSV, validate (loops, duplicates). THE
 *                                MATCHING CRITERION is in its header.
 *   includes/class-runtime.php   when the table is consulted (only on 404s)
 *                                and what is counted.
 *   includes/class-store.php     the two tables.
 *   includes/class-importer.php  save one or many; importing is idempotent.
 *   includes/class-cli.php       WP-CLI.
 *   includes/admin/              the screen.
 *
 * HOW TO MAINTAIN
 * - Who and when: whoever migrates a site with old URLs. The rules CSV lives
 *   in the SITE's repo (not here) and is imported on deploy with WP-CLI or
 *   from the screen; importing twice doesn't duplicate. Whatever is edited in
 *   the panel afterwards is pulled down with «Export» (or `wp … export`) into
 *   the site's repo.
 * - Criterion for what becomes a rule: a URL that existed and that someone
 *   may have linked or that Google may have. 301 if there is an equivalent
 *   page; 410 if the content is deliberately gone (business sold, language
 *   removed); 302 only for something temporary. A typo that was never
 *   published is not a rule.
 * - Named edge case: /?p=24298 → / on Lavandería. It works because on the new
 *   site post 24298 does NOT exist and the request goes to 404. If some day a
 *   new post had that ID, WordPress would show it and the rule would not
 *   fire: rules only act on 404s (class-runtime.php). That is on purpose: an
 *   old rule never hides a live page.
 * - The trap that bit while building it: old URLs exported to static carry
 *   the «?» encoded inside the path (/index.html%3Fp=6981.html). Splitting on
 *   «?» and THEN decoding leaves them as a path; decoding and then splitting,
 *   as a query. It splits twice so both forms give the same key (header of
 *   class-rules.php). And it is deceptive: with /index.html%3Fp=6981.html the
 *   unsplit key happens to match the split one, so the obvious test passes
 *   even when the code is wrong; /en/%3Fp=1 (slash before the ?) and
 *   %3Fpage_id= give it away. Level 1 tests it, with the real CSV.
 * - A table changes shape: Store::DB_VERSION (header of class-store.php).
 * - Another redirects plugin to detect: class-conflicts.php.
 *
 * Personal data: none. The 404 log stores the path and a counter; no IP, no
 * browser, no query (except p=, which is an ID).
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-rules.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-conflicts.php';
require_once __DIR__ . '/includes/class-runtime.php';

// Idempotent on every load: enabling the module does not go through activation.
add_action( 'plugins_loaded', array( Store::class, 'maybe_install' ) );
add_action( 'init', array( Settings::class, 'schedule' ) );
add_action( Settings::CRON, array( Settings::class, 'purge' ) );
add_action( 'estrategia_web_deactivate', array( Settings::class, 'unschedule' ) );

Runtime::boot();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-tables.php';
	require_once __DIR__ . '/includes/admin/class-page.php';
	Admin\Page::boot();
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	require_once __DIR__ . '/includes/class-cli.php';
	\WP_CLI::add_command( 'estrategia-web redirects', Cli::class );
}
