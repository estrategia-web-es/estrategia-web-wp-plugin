<?php
/**
 * Al BORRAR el plugin desde Plugins (no al desactivarlo).
 *
 * Por defecto NO se borra el registro de envíos: borrar un plugin para
 * reinstalarlo es habitual, y llevarse por delante los datos de los clientes
 * por eso no tiene vuelta atrás. Solo se borra todo si la web lo pide en
 * wp-config.php:
 *
 *     define( 'ESTRATEGIA_WEB_FORMS_REMOVE_DATA', true );
 *
 * Las opciones de programación (cron) sí se quitan siempre.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'estrategia_web_forms_purge' );

if ( ! defined( 'ESTRATEGIA_WEB_FORMS_REMOVE_DATA' ) || true !== ESTRATEGIA_WEB_FORMS_REMOVE_DATA ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_forms_submissions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_forms_definitions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
foreach ( array( 'estrategia_web_forms_settings', 'estrategia_web_forms_db_version', 'estrategia_web_forms_migrations_log', 'estrategia_web_forms_last_purge' ) as $option ) {
	delete_option( $option );
}
