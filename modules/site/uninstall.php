<?php
defined( 'ABSPATH' ) || exit;

/**
 * On plugin DELETION (not on deactivation).
 *
 * NOTHING is deleted unless the site asks for it by defining in wp-config.php
 *   define( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA', true );
 * and even then only the `estrategia_web_site` option (the company data) is deleted.
 *
 * The CONTENT (clients, testimonials, locations, projects, portfolio) is never
 * deleted from here: it is the site's content, not the plugin's, and
 * reinstalling the plugin brings it back as it was. If a site wants it gone,
 * it is done by hand, knowing what is being done.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA' ) && true === ESTRATEGIA_WEB_SITE_REMOVE_DATA ) {
	delete_option( 'estrategia_web_site' );
}
