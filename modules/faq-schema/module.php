<?php
/**
 * Module Name: FAQ structured data
 * Description: Publishes the page's FAQ accordions as schema.org FAQPage, inside Rank Math's or Yoast's graph when they are there.
 * Default: off
 * Category: SEO
 *
 * WHAT IT DOES (29-10-2026, David's design criterion: «FAQ con schema
 * FAQPage»): on a single page or post, every core/accordion with a FAQ style
 * (class is-style-faq or is-style-faq-list, the parent theme's 1.2.2) becomes
 * a Question with its acceptedAnswer, all of the page's in ONE FAQPage node.
 * Inner blocks and synced patterns are read too.
 *
 * WHAT IT IS WORTH, said plainly: since 2023 Google only shows the FAQ rich
 * result for government and health sites. The markup is still valid and
 * other engines and AI assistants read it; that is why it exists, and why it
 * is off by default — a site decides.
 *
 * WHERE IT GOES: the same three ways as local-business (its class-output.php
 * explains the decision): Rank Math's rank_math/json_ld, Yoast's
 * wpseo_schema_graph, or our own script in wp_head if neither filter ran.
 * If the host graph ALREADY has a FAQPage (Rank Math's own FAQ block), this
 * module steps aside on that page: never two.
 *
 * HOW TO MAINTAIN
 * - Who and when: whoever adds a FAQ block style to a theme. Its class goes
 *   in Faq::STYLE (a regular expression on the class name), with its case in
 *   tests/faq.test.mjs.
 * - Criterion: only accordions that LOOK like a FAQ on the page count. A
 *   plain accordion (a menu, a list of services) is not a FAQ, so it is left
 *   out on purpose.
 * - Edge case, with a name: Lavandería's «faq» (cards) counts too. Switching
 *   this module on there publishes its Autoservicio questions: that is
 *   intended, but it is a change to decide per site.
 * - The trap: the answer keeps only the HTML Google accepts in Answer.text
 *   (Faq::ANSWER_TAGS); anything else (spans, divs, buttons) is stripped,
 *   not escaped, or the answer would show tags as text.
 *
 * Tests: tests/faq.test.mjs (level 1, the machine's PHP: which accordions
 * count, nesting and synced patterns, the answer's HTML, the merge into Rank
 * Math's keyed graph and Yoast's list, stepping aside).
 */

namespace EstrategiaWeb\FaqSchema;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-faq.php';
require_once __DIR__ . '/includes/class-output.php';

Output::boot();
