<?php
namespace EstrategiaWeb\LocalBusiness;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Local business data
 * Description: Tells search engines about the company and its locations with schema.org (Organization and LocalBusiness: address, hours, coordinates), inside Rank Math's or Yoast's graph when they are there.
 * Default: on
 * Requires: site, content-types
 * Category: SEO
 *
 * WHAT IT DOES (22-10-2026, asked by David for every site): JSON-LD
 * structured data, so Google can show the business — address, phone,
 * opening hours, map — from the site itself.
 *   - Front page: the Organization (Company details + the social module's
 *     links), with each location as a department.
 *   - A location's page, and every page TIED to one (it binds a block to it
 *     by slug: Lavandería's /autoservicio/ → maioris): that location as a
 *     LocalBusiness, pointing to the Organization.
 *   - In each language (WPML): the translated name and the page's URL.
 *   Which page is about which location, and what is read from where:
 *   includes/class-data.php. The nodes and the merge rules:
 *   includes/class-graph.php. Where it is printed (Rank Math, Yoast or our
 *   own script): includes/class-output.php. The company's type of business:
 *   includes/class-settings.php and includes/class-types.php.
 *
 * WHERE THE DATA LIVES: in the location template's fields of the
 * content-types module (address by parts, structured hours — the «hours»
 * field type —, latitude/longitude, type of business); since 2.0.0 the
 * same fields make the texts the page shows (content-types'
 * class-location-data.php), so what Google reads and what people read can
 * no longer differ. A site WITHOUT locations gives its Company details
 * hours to the Organization (Graph::organization()). A site that copied the template before 22-10-2026
 * is OFFERED those fields in «Content types» (Templates::missing_fields());
 * nothing is added to its definition by itself.
 *
 * WHY A MODULE OF ITS OWN and not part of «site»: it can be switched off
 * alone (a site whose SEO agency publishes its own LocalBusiness), it hooks
 * into third-party plugins, and «site» stays the company data. Requires
 * site (Company, the location binding source) and content-types (the
 * location type and the Hours class).
 *
 * DEFAULT: ON, knowingly (class-modules.php, the edge case: it switches
 * itself on when deployed to a site that already saved its module list).
 * It changes nothing a visitor sees, and a location without the new fields
 * filled in publishes nothing for itself (Graph: no node rather than half
 * a one); what reaches every site on deploy is the Organization's data
 * added to the SEO plugin's own Organization. If the SEO plugin already
 * publishes a LocalBusiness on a page, this module steps aside on that page.
 *
 * Out, on purpose: aggregateRating / reviews (Google ignores a business's
 * ratings of itself), events, offers.
 *
 * Tests: tests/graph.test.mjs (level 1: the nodes, Google's rules — shared
 * in tests/google-rules.mjs —, the merge, the type list against the
 * template); tests/output.test.mjs (level 2: the page's JSON-LD alone, with
 * a Rank Math double and with a Yoast double; tests/wp/ has the doubles).
 *
 * =========================================================================
 * HOW TO MAINTAIN
 * =========================================================================
 *
 * - Who and when: whoever adds a property (Graph + Data + its test line),
 *   a type of business (Types::ALL and the location template's select, in
 *   the same commit: the level 1 test compares them) or another SEO plugin
 *   (Output: its filter).
 * - Criterion for a property: it is in Google's LocalBusiness or
 *   Organization documentation and the site has the data as a FIELD (not
 *   parsed from a free text: «De 8:00 a 23:00» is for people).
 * - Boundary case, with a name: Lavandería's Rank Math publishes
 *   ["Person","Organization"] as «publisher» with @id …/#person. That node
 *   IS the Organization for the merge (it has «Organization» in its type):
 *   ours adds telephone, email, sameAs and departments to it, and the
 *   locations point to …/#person. Not pretty, but one entity; changing it
 *   is Rank Math's setting (Titles & Meta › Local SEO › Person or company),
 *   which this module never touches.
 * - The traps: an empty property is reported invalid (Graph::compact()); a
 *   location type without public pages has no permalink for its @id
 *   (Data::location_id()); and WPML's home_url() has the language in it, so
 *   the Organization's @id is built from the «home» option.
 */

require_once __DIR__ . '/includes/class-types.php';
require_once __DIR__ . '/includes/class-graph.php';
require_once __DIR__ . '/includes/class-data.php';
require_once __DIR__ . '/includes/class-output.php';
require_once __DIR__ . '/includes/class-settings.php';

Settings::boot();
Output::boot();
