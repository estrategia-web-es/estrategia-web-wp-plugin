<?php
/**
 * Al BORRAR el plugin (no al desactivarlo).
 *
 * No se borra NADA salvo que la web lo pida definiendo en wp-config.php
 *   define( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA', true );
 * y aun así solo se borra la opción `estrategia_web_site` (los datos de la empresa).
 *
 * El CONTENIDO (clientes, opiniones, sedes, proyectos, portfolio) no se borra
 * nunca desde aquí: es contenido de la web, no del plugin, y volver a
 * instalar el plugin lo recupera tal cual. Si una web quiere borrarlo, se
 * hace a mano y sabiendo lo que se hace.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && true === ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_site' );
}
