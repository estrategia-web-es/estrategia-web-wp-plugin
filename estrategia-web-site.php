<?php
/**
 * Plugin Name:       Estrategia Web Site
 * Plugin URI:        https://estrategiaweb.es/
 * Description:       Datos de la empresa (con Block Bindings), tipos de contenido habituales de una web corporativa (clientes, opiniones, sedes, proyectos, portfolio) activables, subida de SVG saneada, el código de Matomo (sin cookies o híbrido), el aviso de cookies y los enlaces externos en pestaña nueva.
 * Version:           1.0.0
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Author:            Estrategia Web
 * Author URI:        https://estrategiaweb.es/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       estrategia-web-site
 * Domain Path:       /languages
 *
 * Punto de entrada. Solo define constantes, carga las clases y arranca
 * Plugin::boot(); el cableado de ganchos vive en includes/class-plugin.php.
 *
 * Cómo se mantiene: la versión de aquí, la de ESTRATEGIA_WEB_SITE_VERSION y la
 * entrada nueva de CHANGELOG.md se cambian en el MISMO commit.
 */

defined( 'ABSPATH' ) || exit;

const ESTRATEGIA_WEB_SITE_VERSION = '1.0.0';
const ESTRATEGIA_WEB_SITE_FILE    = __FILE__;
define( 'ESTRATEGIA_WEB_SITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTRATEGIA_WEB_SITE_URL', plugin_dir_url( __FILE__ ) );

require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-links.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-svg-sanitizer.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-settings.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-company.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-bindings.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-location.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-shortcode.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-content-types.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-svg.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-matomo.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-consent.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-webp.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-external-links.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-wpml-front-page.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-legacy-names.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-armazon-migration.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/class-plugin.php';
require_once ESTRATEGIA_WEB_SITE_DIR . 'includes/admin/class-admin.php';

register_activation_hook( __FILE__, array( EstrategiaWeb\Site\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( EstrategiaWeb\Site\Plugin::class, 'deactivate' ) );

EstrategiaWeb\Site\Plugin::boot();
