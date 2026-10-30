<?php
defined( 'ABSPATH' ) || exit;

/**
 * On plugin DELETION (called by the root uninstall.php).
 *
 * - Always: the detector's rate-limit transients (estrategia_web_cs_*),
 *   which belong to no one.
 * - Only with ESTRATEGIA_WEB_SITE_REMOVE_DATA in wp-config.php: the cookie
 *   registry and its settings. The purposes were written by the client for
 *   their policy: they are THEIR data, like the company data, and are kept by
 *   default.
 *
 * How to maintain: a new option of the notice gets its delete_option here and
 * in the reset route of modules/consent/tests/wp/.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_estrategia\\_web\\_cs\\_%' OR option_name LIKE '\\_transient\\_timeout\\_estrategia\\_web\\_cs\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_cookies' );
	delete_option( 'estrategia_web_cookies_settings' );
}
