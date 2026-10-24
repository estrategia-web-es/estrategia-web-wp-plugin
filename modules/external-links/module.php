<?php
/**
 * Module Name: External links in a new tab
 * Description: Links to another domain open in a new tab, with rel="noopener" and a hidden «(opens in a new tab)» for screen readers.
 * Default: on
 * Requires:
 * Category: SEO
 *
 * What it does and how to maintain it: includes/class-external-links.php. The
 * estrategia_web_site_external_links filter keeps its name from 1.0.0.
 * Tests: modules/external-links/tests/ and the pure case in
 * modules/site/tests/php/pure.php.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-external-links.php';

External_Links::boot();
