<?php
/**
 * Called by tests/admin-pure.test.mjs with the machine's PHP (no WordPress):
 * the pure functions of the 1.7.0 admin frame — Settings_Page::layout() (the
 * tab registry), Menu::order() (the submenu) and Menu::legacy_target() (old
 * URLs). Writes JSON; the assertions are in the .mjs.
 *
 * How to maintain: a new case is a new key here and its assert there. These
 * files must stay loadable without WordPress: only class definitions at the
 * top level, WordPress calls inside methods.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_DIR', dirname( __DIR__, 2 ) . '/' );

require ESTRATEGIA_WEB_DIR . 'includes/class-modules.php';
require ESTRATEGIA_WEB_DIR . 'includes/admin/class-menu.php';
require ESTRATEGIA_WEB_DIR . 'includes/admin/class-settings-page.php';
require ESTRATEGIA_WEB_DIR . 'includes/admin/class-modules-page.php';

use EstrategiaWeb\Admin\Menu;
use EstrategiaWeb\Admin\Settings_Page;

$render = 'strlen'; // Any callable: layout() only checks that it is one.
$tabs   = array(
	'media'     => array( 'label' => 'Media', 'order' => 30 ),
	'cookies'   => array( 'label' => 'Cookies', 'order' => 10 ),
	'analytics' => array( 'label' => 'Analytics', 'order' => 20 ),
	'empty'     => array( 'label' => 'Empty', 'order' => 5 ),
);
$sections = array(
	array( 'tab' => 'media', 'id' => 'uploads', 'priority' => 20, 'options' => array( 'estrategia_web_uploads' ), 'render' => $render ),
	array( 'tab' => 'media', 'id' => 'svg', 'priority' => 10, 'options' => 'estrategia_web_site', 'render' => $render ),
	array( 'tab' => 'analytics', 'id' => 'matomo', 'priority' => 20, 'options' => array( 'estrategia_web_site' ), 'render' => $render ),
	array( 'tab' => 'analytics', 'id' => 'google-tags', 'priority' => 10, 'options' => array( 'estrategia_web_google_tags' ), 'render' => $render ),
	array( 'tab' => 'analytics', 'id' => 'other', 'priority' => 30, 'options' => array( 'estrategia_web_site' ), 'render' => $render ),
	array( 'tab' => 'cookies', 'id' => 'cookies', 'render' => $render ),
	array( 'tab' => 'nowhere', 'id' => 'orphan', 'render' => $render ),
	array( 'tab' => 'media', 'id' => 'broken', 'render' => 'no_such_function_here' ),
);
$summary = static fn( array $layout ) => array_map(
	static fn( $t ) => array(
		'label'    => $t['label'],
		'sections' => array_column( $t['sections'], 'id' ),
		'options'  => $t['options'],
	),
	$layout
);

echo json_encode(
	array(
		'layout'           => $summary( Settings_Page::layout( $tabs, $sections ) ),
		'layout_order'     => array_keys( Settings_Page::layout( $tabs, $sections ) ),
		// Only matomo on (google-tags off): Analytics still exists, with one section.
		'only_matomo'      => $summary( Settings_Page::layout( $tabs, array( $sections[2] ) ) ),
		'nothing'          => Settings_Page::layout( $tabs, array() ),
		'group'            => Settings_Page::group( 'media' ),
		'order_full'       => Menu::order( array( 'estrategia-web-site', 'estrategia-web', 'estrategia-web-social', 'estrategia-web-redirects', 'estrategia-web-settings', 'estrategia-web-content-types' ) ),
		'order_unknown'    => Menu::order( array( 'estrategia-web-site', 'estrategia-web', 'child-theme-page', 'estrategia-web-social', 'another-page' ) ),
		'order_some'       => Menu::order( array( 'estrategia-web', 'estrategia-web-site', 'estrategia-web-redirects' ) ),
		'legacy_cookies'   => Menu::legacy_target( 'estrategia-web-cookies', array( 'cookies', 'analytics', 'media' ) ),
		'legacy_google'    => Menu::legacy_target( 'estrategia-web-google', array( 'cookies', 'analytics', 'media' ) ),
		'legacy_google_no' => Menu::legacy_target( 'estrategia-web-google', array( 'cookies', 'media' ) ),
		'legacy_other'     => Menu::legacy_target( 'estrategia-web-site', array( 'cookies' ) ),
		'parent'           => Menu::PARENT,
		'modules_menu'     => EstrategiaWeb\Admin\Modules_Page::MENU,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
