<?php
/**
 * Al BORRAR el plugin (lo llama el uninstall.php de la raíz): los ids de
 * Google son del cliente, como los datos de la empresa; solo se borran con
 * ESTRATEGIA_WEB_SITE_REMOVE_DATA en wp-config.php.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_google_tags' );
}
