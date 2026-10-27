<?php
/**
 * Plugin Name:       Estrategia Web
 * Plugin URI:        https://estrategiaweb.es/
 * Description:       Everything but the design in an Estrategia Web site, as modules you switch on and off in "Estrategia Web → Modules": company details and content types, cookie notice, forms, carousel, map, WhatsApp, language switcher, WebP and external links.
 * Version:           1.8.0
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Author:            Estrategia Web
 * Author URI:        https://estrategiaweb.es/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       estrategia-web
 * Domain Path:       /languages
 *
 * Entry point. Defines the plugin constants, loads the CORE (includes/) and
 * hands control to the module loader (includes/class-modules.php), which
 * decides which folder of modules/ gets loaded. Nothing module-specific is
 * added here: a new module is a new folder, see the header of
 * class-modules.php.
 *
 * Born on 14-10-2026 by merging three pieces that were separate up to 1.0.0:
 * the «Estrategia Web Site» plugin, the «Estrategia Web Forms» plugin and the
 * four blocks the theme used to register (WordPress.org does not allow a
 * theme to register blocks). The public names (blocks, options, metas,
 * tables, REST routes) are those of 1.0.0 and do NOT change: what changed is
 * where the code lives.
 *
 * How to maintain: the version here, the one in ESTRATEGIA_WEB_VERSION and the
 * new CHANGELOG.md entry change in the SAME commit. Modules have no version of
 * their own: the plugin's version applies to all of them.
 */

defined( 'ABSPATH' ) || exit;

const ESTRATEGIA_WEB_VERSION = '1.8.0';
const ESTRATEGIA_WEB_FILE    = __FILE__;
define( 'ESTRATEGIA_WEB_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTRATEGIA_WEB_URL', plugin_dir_url( __FILE__ ) );

require_once ESTRATEGIA_WEB_DIR . 'includes/class-modules.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-plugin.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-blocks.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-armazon-migration.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-prefix-migration.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-meta-prefix-migration.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/admin/class-menu.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/admin/class-settings-page.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/admin/class-modules-page.php';

register_activation_hook( __FILE__, array( EstrategiaWeb\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( EstrategiaWeb\Plugin::class, 'deactivate' ) );

EstrategiaWeb\Plugin::boot();
