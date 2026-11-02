<?php
defined( 'ABSPATH' ) || exit;

/**
 * When the plugin is DELETED (not deactivated). Like forms: the statistics
 * are an advertiser's history and are NOT deleted unless the site asks for
 * it in wp-config.php:
 *
 *     define( 'ESTRATEGIA_WEB_BANNERS_REMOVE_DATA', true );
 *
 * The banners themselves are posts: they stay, as any content does.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The state's WP-Cron events (includes/class-state.php) go always: without the plugin nobody handles them.
wp_unschedule_hook( 'estrategia_web_banner_state' );
wp_unschedule_hook( 'estrategia_web_banners_states' );
delete_option( 'estrategia_web_banners_state_version' );

if ( ! defined( 'ESTRATEGIA_WEB_BANNERS_REMOVE_DATA' ) || true !== ESTRATEGIA_WEB_BANNERS_REMOVE_DATA ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}estrategia_web_banners_stats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'estrategia_web_banners_db_version' );
