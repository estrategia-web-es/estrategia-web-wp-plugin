<?php
/**
 * Al BORRAR el plugin desde «Plugins» (no al desactivarlo).
 *
 * Llama al modules/<slug>/uninstall.php de CADA módulo que lo tenga, esté
 * encendido o no: un módulo apagado hoy pudo guardar datos ayer. Cada uno
 * decide qué borra; la regla de todos es la misma: por defecto NO se borran
 * los datos del cliente (envíos de formularios, datos de la empresa), solo si
 * la web lo pide con su constante en wp-config.php
 * (ESTRATEGIA_WEB_SITE_REMOVE_DATA, ESTRATEGIA_WEB_FORMS_REMOVE_DATA).
 *
 * Lo del núcleo sí se borra siempre: la lista de módulos encendidos.
 *
 * Cómo se mantiene: un módulo que guarda algo que no sea contenido de la web
 * pone su uninstall.php en su carpeta, con la guarda
 * defined( 'WP_UNINSTALL_PLUGIN' ) || exit; arriba. Aquí no se toca nada.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( glob( __DIR__ . '/modules/*/uninstall.php' ) ?: array() as $estrategia_web_uninstall ) {
	( static function ( string $file ): void {
		require $file; // En su propio ámbito: un «return» suyo no corta a los demás.
	} )( $estrategia_web_uninstall );
}

delete_option( 'estrategia_web_modules' );
delete_option( 'estrategia_web_modules_known' );
