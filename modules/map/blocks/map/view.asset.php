<?php
/**
 * Dependencias de view.js, ESCRITAS A MANO (no hay build que las genere).
 * Si view.js importa un módulo nuevo, se añade aquí. La versión es la fecha
 * del fichero: cambia sola al editarlo y rompe la caché del navegador.
 */
return array(
	'dependencies' => array( '@wordpress/interactivity' ),
	'version'      => (string) filemtime( __DIR__ . '/view.js' ),
);
