<?php
/**
 * Deleting the plugin: this module's two options. The posts, terms and meta
 * of the declared types are the site's CONTENT and stay (like modules/site).
 * Called by the root uninstall.php, whether the module is on or not.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'estrategia_web_content_types_hash' );
delete_option( 'estrategia_web_content_types_wpml_pending' );
