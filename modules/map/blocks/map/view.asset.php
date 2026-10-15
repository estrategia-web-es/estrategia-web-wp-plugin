<?php
/**
 * Dependencies of view.js, WRITTEN BY HAND (there is no build to generate
 * them). If view.js imports a new module, add it here. The version is the
 * file date: it changes by itself on edit and busts the browser cache.
 */
return array(
	'dependencies' => array( '@wordpress/interactivity' ),
	'version'      => (string) filemtime( __DIR__ . '/view.js' ),
);
