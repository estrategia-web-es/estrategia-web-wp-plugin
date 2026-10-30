<?php
namespace EstrategiaWeb\Llms;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: llms.txt
 * Description: Serves /llms.txt, the text AI assistants read to describe the site, written in «Estrategia Web › Settings › llms.txt», one per language with WPML.
 * Default: off
 * Requires:
 * Category: SEO
 *
 * WHAT IT DOES (31-10-2026, David: «what applies to every site goes in the
 * plugin», and /llms.txt is the example). The site's /llms.txt
 * (https://llmstxt.org/: Markdown that tells an AI what the site is and which
 * pages matter), written by hand in a Settings tab and served with
 * «text/plain; charset=utf-8» (a loose file on nginx goes out without the
 * charset and the accents can come out wrong). A «Generate draft» button
 * fills it from Company details and the published pages with their SEO
 * description; the text worth having is the one a person writes on top.
 *
 * ONE PER LANGUAGE WITH WPML (David, 31-10-2026). llmstxt.org says nothing
 * about languages; it only says a file can live at a subpath and covers the
 * URLs under it, the most specific one winning. So: /llms.txt in the default
 * language, /en/llms.txt etc. when WPML uses directories (with a domain per
 * language, each domain's /llms.txt; with ?lang=, /llms.txt?lang=en). The
 * file of the DEFAULT language ends with «Other languages» linking the
 * others, because a bot asks for /llms.txt and does not negotiate
 * languages. That section is added here, never written by hand.
 *
 * WHY Default: off: it is a text someone has to write; an empty one serves
 * nothing (/llms.txt keeps answering 404).
 *
 * Where everything lives:
 *   includes/class-text.php       the pure part: which request is a llms.txt
 *                                 and of which language, the body served,
 *                                 the draft, the sanitising.
 *   includes/class-languages.php  the ONLY place of this module that knows
 *                                 WPML exists.
 *   includes/class-runtime.php    answering the request.
 *   includes/class-conflicts.php  Rank Math's own llms.txt, a loose file.
 *   includes/admin/               the Settings tab and its button.
 *
 * Option: estrategia_web_llms, {language code: text} (also via
 * /wp/v2/settings, for a site's loader). Without WPML the code is the site
 * locale's language («es» for es_ES), the same as WPML's default code, so
 * installing WPML later keeps the text where it was.
 *
 * HOW TO MAINTAIN
 * - Who and when: whoever sets up a site's SEO writes its text here, and
 *   rewrites it when prices, services or the company's description change
 *   (the AI repeats what it reads: a stale price here is a stale price in its
 *   answer).
 * - What goes in the text: what an AI needs to describe the business in one
 *   paragraph (what, where, for whom, with figures that are on the site) and
 *   the pages that answer the usual questions. Not every page: the draft lists
 *   them all so that someone REMOVES, which is easier than remembering.
 * - Edge case, by name: estrategiaweb.es, whose /llms.txt is today a loose
 *   file in public_html (from content/semilla/llms.txt of its repo). The web
 *   server serves the file before WordPress runs, so with this module on that
 *   file has to be deleted; the tab warns while it exists.
 * - The trap: this answers on parse_request, BEFORE WordPress decides the
 *   request is a 404. Moved later (template_redirect, as Rank Math does),
 *   the redirects module would log /llms.txt in its 404 log first, and
 *   a rewrite rule would need flushing every time the module is switched on.
 *   Rank Math's own llms.txt answers on template_redirect, so with both on
 *   ours is the one served (the tab says so).
 *
 * Tests: modules/llms/tests/ (level 1 the pure part; level 2 the request, the
 * tab and its warnings, and /en/llms.txt with REAL WPML).
 */

require_once __DIR__ . '/includes/class-text.php';
require_once __DIR__ . '/includes/class-languages.php';
require_once __DIR__ . '/includes/class-conflicts.php';
require_once __DIR__ . '/includes/class-runtime.php';

Runtime::boot();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-tab.php';
	Tab::boot();
}
