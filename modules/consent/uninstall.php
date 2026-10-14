<?php
/**
 * Al BORRAR el plugin (lo llama el uninstall.php de la raíz).
 *
 * - Siempre: los transitorios de ritmo del detector (estrategia_web_cs_*),
 *   que no son de nadie.
 * - Solo con ESTRATEGIA_WEB_SITE_REMOVE_DATA en wp-config.php: el registro de
 *   cookies y sus ajustes. Las finalidades las escribió el cliente para su
 *   política: son SUS datos, como los de la empresa, y se quedan por defecto.
 *
 * Cómo se mantiene: una opción nueva del aviso, su delete_option aquí y en la
 * ruta de reset de modules/consent/tests/wp/.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_estrategia\\_web\\_cs\\_%' OR option_name LIKE '\\_transient\\_timeout\\_estrategia\\_web\\_cs\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_cookies' );
	delete_option( 'estrategia_web_cookies_settings' );
}
