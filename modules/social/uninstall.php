<?php
/**
 * On plugin DELETION (not deactivation). Same criterion as the site module:
 * nothing is deleted unless the site asks for it in wp-config.php with
 *   define( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA', true );
 * and then only the list of networks. Uploaded SVGs belong to the media
 * library: they are not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && true === ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_social_links' );
}
