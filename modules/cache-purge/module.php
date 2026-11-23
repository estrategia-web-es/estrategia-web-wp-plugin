<?php
namespace EstrategiaWeb\CachePurge;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Cache purge on field changes
 * Description: When a published post's fields change without going through the editor (a gallery, a date, a photo set from a script or WP-CLI), empties that post's page in the cache plugin the site uses: SiteGround, WP Rocket, LiteSpeed, W3 Total Cache or WP Super Cache.
 * Default: on
 * Requires:
 * Category: Media & performance
 *
 * Why (23-11-2026, Gastronòmicament): the photo gallery of post 119974 was
 * written with update_post_meta() from WP-CLI and David did not see it. Cache
 * plugins empty a post's page when it is SAVED (save_post); a field written on
 * its own saves nothing, so the cached page stayed old. Everything happens in
 * includes/class-purger.php; this file only hooks it.
 *
 * Why Default: on: it changes nothing a visitor sees except that a change
 * shows sooner, and it writes nothing. A site with no supported cache plugin
 * runs no adapter (only the estrategia_web_purge_post action, with nobody
 * listening).
 *
 * How to maintain:
 * - Who: whoever touches the plugin. When: a site uses a cache plugin that is
 *   not in Purger::ADAPTERS. Add it there (one line: the function or constant
 *   that says it is installed, and the call that empties ONE post), or hook
 *   the estrategia_web_purge_post action from that site without touching this.
 * - Criterion: an adapter empties ONE post, never the whole cache. If a cache
 *   plugin only knows how to empty everything, it does not go in: one gallery
 *   edit must not leave the whole site cold.
 * - Edge case, by name: a VISITOR's request never purges, even if it writes a
 *   field (a view counter in post meta would purge on every visit). Only
 *   someone who can edit the post, WP-CLI or cron. See Purger::who_counts().
 * - The pitfall that bit (23-11-2026): SiteGround's sg_cachepress_purge_cache()
 *   with the URL plus a trailing slash says «purged» and purges NOTHING,
 *   because the site's URLs have no trailing slash. Always the URL exactly as
 *   get_permalink() returns it.
 * - Only SiteGround has been tried on a real site (Gastronòmicament). The other
 *   four adapters follow each plugin's public documentation: the first site
 *   that uses one, checks it with a curl without parameters after a change,
 *   and writes the date here.
 * - Test: modules/cache-purge/tests/pure.test.mjs (level 1).
 */

require_once __DIR__ . '/includes/class-purger.php';

Purger::hook();
