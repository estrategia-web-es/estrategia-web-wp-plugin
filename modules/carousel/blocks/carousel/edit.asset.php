<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dependencies of edit.js (classic script), WRITTEN BY HAND. Every wp.* global
 * that edit.js uses must be listed here, or the editor shows "wp.x is undefined".
 */
return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
	'version'      => (string) filemtime( __DIR__ . '/edit.js' ),
);
