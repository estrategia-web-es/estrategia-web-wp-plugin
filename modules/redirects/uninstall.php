<?php
/**
 * Al BORRAR el plugin (no al desactivarlo ni al apagar el módulo).
 *
 * Las reglas son trabajo del cliente (o de su SEO): por defecto NO se
 * borran, como los envíos de forms. Solo si la web lo pide en wp-config.php:
 *
 *     define( 'ESTRATEGIA_WEB_REDIRECTS_REMOVE_DATA', true );
 *
 * La purga programada sí se quita siempre.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'estrategia_web_redirects_purge' );

if ( ! defined( 'ESTRATEGIA_WEB_REDIRECTS_REMOVE_DATA' ) || true !== ESTRATEGIA_WEB_REDIRECTS_REMOVE_DATA ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_redirects" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_redirects_404" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
foreach ( array( 'estrategia_web_redirects_settings', 'estrategia_web_redirects_db_version' ) as $option ) {
	delete_option( $option );
}
