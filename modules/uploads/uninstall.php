<?php
/**
 * When the plugin is DELETED: the uploads option is deleted (three
 * configuration numbers, nothing the client wrote). Uploaded photos and
 * their sizes are not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'estrategia_web_uploads' );
