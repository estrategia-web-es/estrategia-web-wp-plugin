<?php
/**
 * TESTS ONLY. The test WordPress's setup, in ONE request (tests/blueprint.json, 28-10-2026).
 *
 * It was five blueprint steps (activatePlugin, setSiteOptions and three runPHP), and each step is
 * a whole WordPress request (~1,5 s alone, 3-5 s with six WordPresses booting at once). Same
 * effect, one request:
 * - activates the plugin (its activation hook runs here, as activatePlugin did);
 * - the site options the tests expect (pretty permalinks, the site's name, the admin email);
 * - no welcome guide on the admin's FIRST editor load: with shards any editor test can be the
 *   first one (tests/run.mjs, «The trap of the first editor»);
 * - the month's uploads folder, before two workers race to create it (tests/run.mjs, «The trap
 *   of the two workers»);
 * - WPML's first pass (tests/wp/setup-wpml.php: activation; it is configured in the next request).
 *
 * How to maintain: a new boot-time setting goes here, not as a new blueprint step. The trap:
 * whatever needs the plugin LOADED (its classes, its hooks) does not work here, because the
 * plugin is activated in this very request; that goes into the second step (setup-wpml.php's
 * second run is the model).
 *
 * @package estrategia-web
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$ew_activated = activate_plugin( 'estrategia-web/estrategia-web.php' );
if ( is_wp_error( $ew_activated ) ) {
	throw new RuntimeException( 'setup-site: the plugin did not activate: ' . $ew_activated->get_error_message() );
}

update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blogname', 'Sitio de pruebas' );
update_option( 'admin_email', 'admin@ejemplo.test' );

global $wpdb;
update_user_meta(
	1,
	$wpdb->get_blog_prefix() . 'persisted_preferences',
	array(
		'core/edit-post' => array( 'welcomeGuide' => false ),
		'core/edit-site' => array( 'welcomeGuide' => false ),
		'_modified'      => gmdate( 'c' ),
	)
);

wp_upload_dir( null, true );

require __DIR__ . '/setup-wpml.php';
