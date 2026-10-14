<?php
/**
 * Module Name: External links in a new tab
 * Description: Links to another domain open in a new tab, with rel="noopener" and a hidden «(opens in a new tab)» for screen readers.
 * Default: on
 * Requires:
 *
 * Qué hace y cómo se mantiene, en includes/class-external-links.php. El
 * filtro estrategia_web_site_external_links conserva su nombre de la 1.0.0.
 * Prueba: modules/external-links/tests/ y el caso puro en
 * modules/site/tests/php/pure.php.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-external-links.php';

External_Links::boot();
