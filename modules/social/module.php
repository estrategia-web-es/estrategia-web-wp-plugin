<?php
/**
 * Module Name: Social networks
 * Description: Free, ordered list of social network links (name, link, icon from an included collection or your own SVG) and a block that shows them.
 * Default: on
 * Requires: site
 *
 * Three pieces:
 * - The list: option estrategia_web_social_links (includes/class-links.php,
 *   which holds its shape, the sanitizing, the migration for sites that
 *   already had networks in the fixed fields of “Company details”, and the
 *   `social.<network>` alias of Company::value() until 2.0.0).
 * - The “Estrategia Web → Social networks” screen (includes/class-admin.php
 *   and assets/admin.js): one row per network, reorderable with buttons.
 * - The estrategia-web/social-links block (blocks/social-links/render.php):
 *   icons only or icon and name, three sizes, text colour.
 * Icons: 17 from the collection (icons/, credits in icons/CREDITS.txt:
 * Simple Icons, CC0, and Bootstrap Icons, MIT) or an SVG from the media
 * library, sanitized again when rendered (includes/class-icons.php).
 *
 * Why Default: on: it changes nothing visible by itself. The block is on no
 * page until someone adds it, and the screen is one more menu entry. When
 * deployed to an existing site (Lavandería) it switches itself on (see the
 * edge case in includes/class-modules.php) and goes unnoticed until used.
 *
 * Why Requires: site: SVG sanitizing (Svg_Sanitizer) and the company name
 * for “Instagram of <Company>” belong to that module.
 *
 * With WPML: each network's name and link are translated as admin texts
 * (wpml-config.xml, <admin-texts>). Not tested with WPML.
 *
 * How to maintain:
 * - Who and when: whoever adds a network to the collection (icons/ + CREDITS +
 *   Icons::labels() and HOSTS) or a per-network field (Links + screen + XML).
 * - Criterion for a network to join the collection: a real site asks for it
 *   and there is an icon with a GPL-compatible licence (CC0, MIT, GPL). A
 *   network only one client uses goes in as an uploaded SVG, not here.
 * - Named edge case: Google. “Google Maps” (the map listing) and
 *   “Google (Business Profile)” (the reviews) are two icons; a
 *   google.com/maps… link guesses the maps one, any other google.* the other.
 * - The trap when copying new icons: Simple Icons SVGs come with <title>
 *   and role="img"; inside a link whose name is in hidden text, a screen
 *   reader may read the name twice. They are stripped when copied (only
 *   viewBox and <path>) and the <svg> is output with aria-hidden.
 *
 * Tests: modules/social/tests/ (social: level 2; social-admin: level 3).
 */

namespace EstrategiaWeb\Social;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-icons.php';
require_once __DIR__ . '/includes/class-links.php';

add_action( 'init', array( Links::class, 'register' ) );
// After register: the six fixed fields of “Company details” → the list, once (see Links).
add_action( 'init', array( Links::class, 'migrate' ), 11 );
add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/social-links' ) );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-admin.php';
	Admin::boot();
}
