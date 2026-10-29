<?php
defined( 'ABSPATH' ) || exit;

/**
<<<<<<< HEAD
 * Deleting the plugin: this module's options. The posts, terms and meta of
 * the declared types are the site's CONTENT and stay (like modules/site), and
 * so do WPML's slug translations («URL slug: …» strings): they are WPML's data.
=======
 * Deleting the plugin: this module's two options. The posts, terms and meta
 * of the declared types are the site's CONTENT and stay (like modules/site),
 * and so do the DEFINITIONS (posts of eweb_content_type, class-store.php):
 * they are content too — without them, the entries could not be shown
 * again by reinstalling.
>>>>>>> 4045159 (content-types: constructor de tipos en el panel (definiciones en la base de datos))
 * Called by the root uninstall.php, whether the module is on or not.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'estrategia_web_content_types_hash' );
delete_option( 'estrategia_web_content_types_wpml_pending' );
delete_option( 'estrategia_web_content_types_wpml_slugs' );
