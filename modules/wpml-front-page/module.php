<?php
/**
 * Module Name: Translated front page (WPML)
 * Description: With WPML, /en/ shows the translated front page instead of the latest posts. Without WPML it does nothing.
 * Default: on
 * Requires:
 *
 * Qué hace y por qué, en includes/class-wpml-front-page.php. El filtro
 * estrategia_web_site_wpml_front_page conserva su nombre de la 1.0.0.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-wpml-front-page.php';

Wpml_Front_Page::boot();
