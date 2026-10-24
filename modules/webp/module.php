<?php
/**
 * Module Name: WebP images
 * Description: Image sizes generated from JPEG and PNG uploads are saved as WebP (quality 75).
 * Default: on
 * Requires:
 * Category: Media & performance
 *
 * The minimal module example: header, one require and one boot. What it
 * does and the trap with already uploaded images, in includes/class-webp.php.
 * The filters estrategia_web_site_webp and estrategia_web_site_webp_quality
 * keep their names from 1.0.0. Tests: modules/webp/tests/ and the pure case
 * in modules/site/tests/php/pure.php.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-webp.php';

Webp::boot();
