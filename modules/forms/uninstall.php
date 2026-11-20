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
// 2.11.0: the files of the File field go with the log. The folder is the one Files::dir() uses; the
// module is not loaded here, so it is worked out the same way (wp-config's constant or its defaults).
$estrategia_web_dirs = defined( 'ESTRATEGIA_WEB_FORMS_PRIVATE_DIR' ) && '' !== (string) ESTRATEGIA_WEB_FORMS_PRIVATE_DIR
	? array( untrailingslashit( (string) ESTRATEGIA_WEB_FORMS_PRIVATE_DIR ) )
	: array( dirname( untrailingslashit( ABSPATH ) ) . '/estrategia-web-forms-private', untrailingslashit( wp_upload_dir( null, false )['basedir'] ) . '/estrategia-web-forms-private' );
foreach ( $estrategia_web_dirs as $estrategia_web_dir ) {
	if ( is_dir( $estrategia_web_dir ) ) {
		$estrategia_web_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $estrategia_web_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $estrategia_web_items as $estrategia_web_item ) {
			$estrategia_web_item->isDir() ? rmdir( $estrategia_web_item->getPathname() ) : wp_delete_file( $estrategia_web_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		rmdir( $estrategia_web_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_forms_submissions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_forms_definitions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
foreach ( array( 'estrategia_web_forms_settings', 'estrategia_web_forms_db_version', 'estrategia_web_forms_migrations_log', 'estrategia_web_forms_last_purge' ) as $estrategia_web_option ) {
	delete_option( $estrategia_web_option );
}
