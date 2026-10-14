<?php
/**
 * Al BORRAR el plugin (no al desactivarlo). Mismo criterio que el módulo
 * site: no se borra nada salvo que la web lo pida en wp-config.php con
 *   define( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA', true );
 * y entonces solo la lista de redes. Los SVG subidos son de la mediateca:
 * no se tocan.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && true === ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_social_links' );
}
