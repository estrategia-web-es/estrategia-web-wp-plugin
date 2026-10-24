<?php
/**
 * Module Name: Translated front page (WPML)
 * Description: With WPML, /en/ shows the translated front page instead of the latest posts. Without WPML it does nothing.
 * Default: on
 * Requires:
 * Category: Languages
 *
 * What it does and why, in includes/class-wpml-front-page.php. The filter
 * estrategia_web_site_wpml_front_page keeps its name from 1.0.0.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-wpml-front-page.php';

Wpml_Front_Page::boot();
