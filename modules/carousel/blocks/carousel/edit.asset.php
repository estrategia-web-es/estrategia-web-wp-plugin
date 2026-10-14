<?php
/**
 * Dependencias de edit.js (script clásico), ESCRITAS A MANO. Cada global wp.*
 * que use edit.js tiene que estar aquí, o en el editor sale «wp.x is undefined».
 */
return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
	'version'      => (string) filemtime( __DIR__ . '/edit.js' ),
);
