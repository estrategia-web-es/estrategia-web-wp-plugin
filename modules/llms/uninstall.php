<?php
defined( 'ABSPATH' ) || exit;

/**
 * When the plugin is DELETED (called by the root uninstall.php): the llms.txt
 * texts are the client's writing, like the company details; they are only
 * deleted with ESTRATEGIA_WEB_SITE_REMOVE_DATA in wp-config.php.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_llms' );
}
