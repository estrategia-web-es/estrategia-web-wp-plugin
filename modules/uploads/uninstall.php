<?php
/**
 * Al BORRAR el plugin: se borra la opción de las subidas (tres números de
 * configuración, nada que el cliente haya escrito). Las fotos subidas y sus
 * tamaños no se tocan.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'estrategia_web_uploads' );
