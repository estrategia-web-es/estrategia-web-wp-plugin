<?php
defined( 'ABSPATH' ) || exit;

/**
 * On DELETING the plugin (not on deactivating it or switching the module off).
 *
 * The rules are the client's work (or their SEO's): by default they are NOT
 * deleted, like forms submissions. Only if the site asks for it in
 * wp-config.php:
 *
 *     define( 'ESTRATEGIA_WEB_REDIRECTS_REMOVE_DATA', true );
 *
 * The scheduled purge is always removed.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'estrategia_web_redirects_purge' );

if ( ! defined( 'ESTRATEGIA_WEB_REDIRECTS_REMOVE_DATA' ) || true !== ESTRATEGIA_WEB_REDIRECTS_REMOVE_DATA ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_redirects" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_redirects_404" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
foreach ( array( 'estrategia_web_redirects_settings', 'estrategia_web_redirects_db_version' ) as $estrategia_web_option ) {
	delete_option( $estrategia_web_option );
}
