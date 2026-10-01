<?php
/**
 * Plugin Name:       Armazón Site
 * Description:       Datos de la empresa (con Block Bindings), tipos de contenido habituales de una web corporativa (clientes, opiniones, sedes, proyectos, portfolio) activables, y subida de SVG saneada.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Author:            David Pérez
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       armazon-site
 * Domain Path:       /languages
 *
 * Punto de entrada. Solo define constantes, carga las clases y arranca
 * Plugin::boot(); el cableado de ganchos vive en includes/class-plugin.php.
 *
 * Cómo se mantiene: la versión de aquí, la de ARMAZON_SITE_VERSION y la
 * entrada nueva de CHANGELOG.md se cambian en el MISMO commit.
 */

defined( 'ABSPATH' ) || exit;

const ARMAZON_SITE_VERSION = '0.1.0';
const ARMAZON_SITE_FILE    = __FILE__;
define( 'ARMAZON_SITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARMAZON_SITE_URL', plugin_dir_url( __FILE__ ) );

require_once ARMAZON_SITE_DIR . 'includes/class-links.php';
require_once ARMAZON_SITE_DIR . 'includes/class-svg-sanitizer.php';
require_once ARMAZON_SITE_DIR . 'includes/class-settings.php';
require_once ARMAZON_SITE_DIR . 'includes/class-company.php';
require_once ARMAZON_SITE_DIR . 'includes/class-bindings.php';
require_once ARMAZON_SITE_DIR . 'includes/class-content-types.php';
require_once ARMAZON_SITE_DIR . 'includes/class-svg.php';
require_once ARMAZON_SITE_DIR . 'includes/class-plugin.php';
require_once ARMAZON_SITE_DIR . 'includes/admin/class-admin.php';

register_activation_hook( __FILE__, array( Armazon\Site\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Armazon\Site\Plugin::class, 'deactivate' ) );

Armazon\Site\Plugin::boot();
