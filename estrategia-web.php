<?php
/**
 * Plugin Name:       EstrategiaWeb Toolkit
 * Plugin URI:        https://github.com/estrategia-web-es/estrategia-web-wp-plugin
 * Description:       Site essentials as modules you switch on and off in "Estrategia Web → Modules": cookie consent, block forms, content types, company details, redirects, schema.org, map, carousel, WhatsApp, analytics behind consent, llms.txt, WebP and more.
 * Version:           2.4.1
 * Requires at least: 7.1
 * Requires PHP:      8.1
 * Author:            Estrategia Web
 * Author URI:        https://estrategiaweb.es/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       estrategiaweb-toolkit
 * Domain Path:       /languages
 *
 * Entry point. Defines the plugin constants, loads the CORE (includes/) and
 * hands control to the module loader (includes/class-modules.php), which
 * decides which folder of modules/ gets loaded. Nothing module-specific is
 * added here: a new module is a new folder, see the header of
 * class-modules.php.
 *
 * THE NAME (2.4.1, WordPress.org review of 02-11-2026: «Estrategia Web» is
 * too generic a phrase for the directory). On WordPress.org it is
 * «EstrategiaWeb Toolkit», slug and text domain «estrategiaweb-toolkit»
 * (Plugin::DOMAIN), and the folder on a site is that slug. NOT renamed, on
 * purpose: this file, the PHP namespace, the options, metas, tables, block
 * names, REST routes, hooks and CSS classes (all «estrategia_web» /
 * «estrategia-web»): they are the plugin's public names, a unique prefix is
 * all the directory asks of them, and changing them would mean migrating
 * every site's data. The admin menu still says «Estrategia Web».
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

const ESTRATEGIA_WEB_VERSION = '2.4.1';
const ESTRATEGIA_WEB_FILE    = __FILE__;
define( 'ESTRATEGIA_WEB_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTRATEGIA_WEB_URL', plugin_dir_url( __FILE__ ) );

require_once ESTRATEGIA_WEB_DIR . 'includes/class-modules.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-plugin.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/class-blocks.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/admin/class-admin-ui.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/admin/class-menu.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/admin/class-settings-page.php';
require_once ESTRATEGIA_WEB_DIR . 'includes/admin/class-modules-page.php';

register_activation_hook( __FILE__, array( EstrategiaWeb\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( EstrategiaWeb\Plugin::class, 'deactivate' ) );

EstrategiaWeb\Plugin::boot();
