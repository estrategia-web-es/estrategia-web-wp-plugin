<?php
/**
 * Module Name: Banners
 * Description: Image or code banners by zone and dates, with daily views and clicks, and AdRotate migration.
 * Default: off
 * Requires:
 * Category: Content
 *
 * Banners for a medium that lives on advertising (Gastronòmicament, 2026).
 * A banner (post type eweb_banner, «Banners» menu) is an image with a link
 * or an adserver's code, belongs to one or more zones (eweb_banner_zone) and
 * has dates and a weight. The theme places a zone with the block
 * estrategia-web/banner-zone {"zone":"lateral"}.
 *
 * Statistics: views and clicks per banner and day, in
 * {prefix}estrategia_web_banners_stats, counted FROM THE BROWSER (why: the
 * header of includes/class-rest.php). They show on the list and on each
 * banner's screen.
 *
 * Where everything lives:
 *   includes/class-rules.php      the pure part: state, weighted pick, AdRotate → banner
 *   includes/class-store.php      the statistics table
 *   includes/class-post-type.php  type, zones, fields, screen, columns
 *   includes/class-rest.php       POST estrategia-web/v1/banners/hits
 *   includes/class-cli.php        wp estrategia-web banners migrate-adrotate
 *   blocks/banner-zone/           the block (render.php: what it prints and why)
 *
 * WHY Default: off: it adds a menu and a table, and only a site with
 * advertisers wants them. Switched on per site.
 *
 * HOW TO MAINTAIN
 * - Who and when: whoever adds a site with advertising, or changes how the
 *   weekly banners are loaded. The zones are the SITE's (terms in its
 *   database, created by the migration or by hand): which zones a theme
 *   places is written in that theme, not here.
 * - Criterion for a new zone: a place in the layout where an advertiser can
 *   buy a slot with its own size. Two places with the same size and the
 *   same advertisers are ONE zone shown twice, not two zones.
 * - Edge case: Gastronòmicament's «Recipes Sidebar» is its own zone
 *   (receptes) because its advertisers are not the sidebar's, even though
 *   the size is the same. That is the criterion above, not an exception.
 * - The trap: a code banner (adserver) in a zone that shows fewer than it
 *   has still runs its script when hidden (render.php). Code banners go in
 *   zones with count 0.
 *
 * Personal data: none (a counter per banner and day).
 */

namespace EstrategiaWeb\Banners;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-rules.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-post-type.php';
require_once __DIR__ . '/includes/class-rest.php';

// Idempotent on every load: enabling the module does not go through activation.
add_action( 'plugins_loaded', array( Store::class, 'maybe_install' ) );
Post_Type::boot();
Rest::boot();
add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/banner-zone' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			if ( class_exists( '\WP_CLI' ) ) {
				require_once __DIR__ . '/includes/class-cli.php';
				\WP_CLI::add_command( 'estrategia-web banners', Cli::class );
			}
		}
	);
}
