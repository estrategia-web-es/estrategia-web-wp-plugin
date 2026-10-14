<?php
/**
 * Plugin Name:       Estrategia Web
 * Plugin URI:        https://estrategiaweb.es/
 * Description:       Todo lo que no es diseño en una web de Estrategia Web, por módulos que se encienden y se apagan en «Estrategia Web → Módulos»: datos de la empresa y tipos de contenido, aviso de cookies, formularios, carrusel, mapa, WhatsApp, selector de idioma, WebP y enlaces externos.
 * Version:           1.1.0
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Author:            Estrategia Web
 * Author URI:        https://estrategiaweb.es/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       estrategia-web
 * Domain Path:       /languages
 *
 * Punto de entrada. Define las constantes del plugin, carga el NÚCLEO
 * (includes/) y le pasa el mando al cargador de módulos
 * (includes/class-modules.php), que es quien decide qué carpeta de modules/
 * se carga. Aquí no se añade nada de un módulo: un módulo nuevo es una
 * carpeta nueva, ver la cabecera de class-modules.php.
 *
 * Nació el 14-10-2026 juntando tres piezas que hasta la 1.0.0 iban por
 * separado: el plugin «Estrategia Web Site», el plugin «Estrategia Web
 * Forms» y los cuatro bloques que registraba el tema (WordPress.org no deja
 * que un tema registre bloques). Los nombres públicos (bloques, opciones,
 * metas, tablas, rutas REST) son los de la 1.0.0 y NO cambian: lo que cambió
 * es dónde vive el código.
 *
 * Cómo se mantiene: la versión de aquí, la de ESTRATEGIA_WEB_VERSION y la
 * entrada nueva de CHANGELOG.md se cambian en el MISMO commit. Los módulos no
 * llevan versión propia: la del plugin vale para todos.
 */

defined( 'ABSPATH' ) || exit;

const ESTRATEGIA_WEB_VERSION = '1.1.0';
const ESTRATEGIA_WEB_FILE    = __FILE__;
define( 'ESTRATEGIA_WEB_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTRATEGIA_WEB_URL', plugin_dir_url( __FILE__ ) );

require_once ESTRATEGIA_WEB_DIR . 'includes/class-modules.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-plugin.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-blocks.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-armazon-migration.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/admin/class-modules-page.php';

register_activation_hook( __FILE__, array( EstrategiaWeb\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( EstrategiaWeb\Plugin::class, 'deactivate' ) );

EstrategiaWeb\Plugin::boot();
