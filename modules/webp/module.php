<?php
/**
 * Module Name: WebP images
 * Description: Image sizes generated from JPEG and PNG uploads are saved as WebP (quality 75).
 * Default: on
 * Requires:
 *
 * El ejemplo mínimo de módulo: cabecera, un require y un arranque. Qué hace
 * y la trampa de lo ya subido, en includes/class-webp.php. Los filtros
 * estrategia_web_site_webp y estrategia_web_site_webp_quality conservan su
 * nombre de la 1.0.0. Prueba: modules/webp/tests/ y el caso puro en
 * modules/site/tests/php/pure.php.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-webp.php';

Webp::boot();
