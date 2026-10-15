<?php
/**
 * When the plugin is DELETED (called by the root uninstall.php): the Google
 * ids belong to the client, like the company details; they are only deleted
 * with ESTRATEGIA_WEB_SITE_REMOVE_DATA in wp-config.php.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_google_tags' );
}
