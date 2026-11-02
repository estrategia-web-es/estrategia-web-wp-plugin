<?php
// Before the header on purpose: Plugin Check only looks for the ABSPATH guard near the top of the file, and
// this header is long (missing_direct_file_access_protection, an error, 02-11-2026).
namespace EstrategiaWeb\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Newsletter
 * Description: Choose the week's posts, preview the newsletter and copy its code or create the draft in Mailchimp. It never sends.
 * Default: off
 * Requires:
 * Category: Content
 *
 * Gastronòmicament's Thursday butlletí (2026): three posts, each with link,
 * photo, headline, a 16-word lead and its label («Actualitat – Llegeix-ne
 * més»), sent with Mailchimp to the «Consentiment = Butlletí» segment. Until
 * now someone copied an HTML by hand every week.
 *
 *   Posts → Newsletter          the screen: propose (featured category + latest, or a
 *                               search), choose, order, preview, «Copy the code»,
 *                               «Create the draft in Mailchimp»
 *   Estrategia Web › Settings › Newsletter
 *                               Mailchimp key, audience, featured category, templates
 *
 * Where everything lives:
 *   includes/class-rules.php      the pure part (lead, block, email, photo, datacenter, campaign body)
 *   includes/class-settings.php   the option and its tab; WHY TEMPLATES and not the last campaign
 *   includes/class-builder.php    posts → blocks → email (one build() for preview, copy and draft)
 *   includes/class-mailchimp.php  the draft: model, new campaign WITHOUT template, content, check
 *   includes/class-rest.php       GET posts, POST preview, POST draft
 *   includes/class-screen.php     the screen; assets/newsletter.js and .css
 *   tests/fixtures/               Gastronòmicament's two templates, to paste in its settings
 *
 * IT NEVER SENDS. It creates the campaign as a DRAFT; the test is sent and
 * OPENED from Mailchimp, and only then is it sent. Rule born on 04-09-2026:
 * a campaign replicated from one with the drag-and-drop template «Butlletí
 * 2016» accepted the new HTML through the API and sent last week's news to
 * 348 people; the test had been sent and nobody opened it. That is also why
 * the draft is a NEW campaign without template (class-mailchimp.php).
 *
 * WHY Default: off: it adds a screen and talks to Mailchimp; only a site
 * with a newsletter wants it.
 *
 * HOW TO MAINTAIN
 * - Who and when: whoever changes a site's newsletter (its design → the
 *   templates in its settings, not code) or how the draft is made (→
 *   class-mailchimp.php and its mock in tests/wp/, same commit).
 * - Criterion: what changes per site is a setting; what changes per week is
 *   the screen; nothing per site is written in code.
 * - Edge case, by name: a site that is not Gastronòmicament and has no
 *   template: the defaults (Settings::default_email/default_block) make a
 *   plain, complete email with Mailchimp's compulsory footer.
 * - The trap: what reaches Mailchimp is built on the SERVER from the post
 *   ids (Rest::draft), never taken from the browser. Change build() and the
 *   preview, the copied code and the draft change together; build HTML
 *   anywhere else and they drift apart.
 *
 * Personal data: none stored. The Mailchimp key is a secret: option
 * estrategia_web_newsletter, not in REST, never printed back; uninstall.php
 * deletes it always.
 */

require_once __DIR__ . '/includes/class-rules.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-builder.php';
require_once __DIR__ . '/includes/class-mailchimp.php';
require_once __DIR__ . '/includes/class-rest.php';

Settings::boot();
Rest::boot();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-screen.php';
	Screen::boot();
}
