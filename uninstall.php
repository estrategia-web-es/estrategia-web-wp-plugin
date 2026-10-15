<?php
/**
 * Runs when the plugin is DELETED from «Plugins» (not when it is deactivated).
 *
 * Calls the modules/<slug>/uninstall.php of EVERY module that has one,
 * whether it is on or not: a module that is off today may have stored data
 * yesterday. Each one decides what it deletes; the rule is the same for all:
 * by default the client's data (form submissions, company details) is NOT
 * deleted, only if the site asks for it with its constant in wp-config.php
 * (ESTRATEGIA_WEB_SITE_REMOVE_DATA, ESTRATEGIA_WEB_FORMS_REMOVE_DATA).
 *
 * The core's own data is always deleted: the list of enabled modules.
 *
 * How to maintain: a module that stores anything other than site content
 * puts its uninstall.php in its folder, with the guard
 * defined( 'WP_UNINSTALL_PLUGIN' ) || exit; at the top. Nothing is touched here.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( glob( __DIR__ . '/modules/*/uninstall.php' ) ?: array() as $estrategia_web_uninstall ) {
	( static function ( string $file ): void {
		require $file; // In its own scope: a «return» inside it does not stop the others.
	} )( $estrategia_web_uninstall );
}

delete_option( 'estrategia_web_modules' );
delete_option( 'estrategia_web_modules_known' );
