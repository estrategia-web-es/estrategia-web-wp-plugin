<?php
defined( 'ABSPATH' ) || exit;

/**
 * When the plugin is DELETED from Plugins (not when it is deactivated).
 *
 * By default the submissions log is NOT deleted: deleting a plugin to
 * reinstall it is common, and wiping out the clients' data because of that
 * cannot be undone. Everything is deleted only if the site asks for it in
 * wp-config.php:
 *
 *     define( 'ESTRATEGIA_WEB_FORMS_REMOVE_DATA', true );
 *
 * The scheduling (cron) options are always removed.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'estrategia_web_forms_purge' );

if ( ! defined( 'ESTRATEGIA_WEB_FORMS_REMOVE_DATA' ) || true !== ESTRATEGIA_WEB_FORMS_REMOVE_DATA ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_forms_submissions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_forms_definitions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
foreach ( array( 'estrategia_web_forms_settings', 'estrategia_web_forms_db_version', 'estrategia_web_forms_migrations_log', 'estrategia_web_forms_last_purge' ) as $estrategia_web_option ) {
	delete_option( $estrategia_web_option );
}
