<?php
/**
 * Deleting the plugin: this module's options. The posts, terms and meta of
 * the declared types are the site's CONTENT and stay (like modules/site), and
 * so do WPML's slug translations («URL slug: …» strings): they are WPML's data.
 * Called by the root uninstall.php, whether the module is on or not.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'estrategia_web_content_types_hash' );
delete_option( 'estrategia_web_content_types_wpml_pending' );
delete_option( 'estrategia_web_content_types_wpml_slugs' );
