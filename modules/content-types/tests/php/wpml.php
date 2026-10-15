<?php
/**
 * Called by tests/wpml.test.mjs with the machine's PHP (no WordPress, no
 * WPML): the definitions are READ FROM DISK by the real Loader (the folder
 * filter points at examples/, so the Gastronòmicament example is what is
 * tested), and WPML is a double. Output JSON; assertions in the .mjs.
 *
 *     php wpml.php config      what goes into WPML's config array
 *     php wpml.php wpml        the copy on save, with WPML
 *     php wpml.php no-wpml     the same, without WPML: nothing is asked
 *
 * The double does what WPML does where it matters: its
 * wpml_sync_custom_field WRITES the meta into each translation with
 * update_post_meta, which fires updated_post_meta again — so the test also
 * sees that the copy does not call itself.
 *
 * How to maintain: a new case is a new key in $out and its assert there.
 * Fake posts: $GLOBALS['posts'] (id => type) and $GLOBALS['original']
 * (translation => original).
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( 'wpml' === ( $argv[1] ?? '' ) ) {
	define( 'ICL_SITEPRESS_VERSION', 'double' );
}

$GLOBALS['hooks']    = array();
$GLOBALS['syncs']    = array();
$GLOBALS['meta']     = array();
$GLOBALS['posts']    = array( 10 => 'gmct_restaurant', 11 => 'gmct_restaurant', 40 => 'page', 50 => 'gmct_restaurant' );
$GLOBALS['original'] = array( 11 => 10 ); // 50: a restaurant not translated yet.

function __( $text, $domain = 'default' ) {
	return $text;
}
function get_stylesheet_directory() {
	return __DIR__ . '/no-theme';
}
function get_template_directory() {
	return __DIR__ . '/no-theme';
}
function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}
function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['hooks'][ $hook ][] = array( $callback, $args );
}
function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	add_action( $hook, $callback, $priority, $args );
}
function do_action( string $hook, ...$args ): void {
	foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as list( $callback, $count ) ) {
		$callback( ...array_slice( $args, 0, $count ) );
	}
}
function apply_filters( string $hook, $value, ...$args ) {
	if ( 'estrategia_web_content_types_dirs' === $hook ) {
		return array( dirname( __DIR__, 2 ) . '/examples' );
	}
	if ( 'wpml_element_language_details' === $hook ) {
		$id = $args[0]['element_id'];
		return isset( $GLOBALS['posts'][ $id ] ) ? (object) array( 'source_language_code' => isset( $GLOBALS['original'][ $id ] ) ? 'ca' : null ) : null;
	}
	return $value;
}
function get_post_type( $id ) {
	return $GLOBALS['posts'][ (int) $id ] ?? false;
}
function update_post_meta( int $id, string $key, $value ): void {
	$hook                           = isset( $GLOBALS['meta'][ $id ][ $key ] ) ? 'updated_post_meta' : 'added_post_meta';
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	do_action( $hook, 1, $id, $key, $value );
}
function delete_post_meta( int $id, string $key ): void {
	unset( $GLOBALS['meta'][ $id ][ $key ] );
	do_action( 'deleted_post_meta', array( 1 ), $id, $key, '' );
}

require __DIR__ . '/../../includes/class-schema.php';
require __DIR__ . '/../../includes/class-loader.php';
require __DIR__ . '/../../includes/class-registrar.php';
require __DIR__ . '/../../includes/class-wpml.php';

use EstrategiaWeb\ContentTypes\Loader;
use EstrategiaWeb\ContentTypes\Wpml;

// The double of WPML's public API: copies the original's value into each translation.
add_action(
	'wpml_sync_custom_field',
	static function ( $post_id, $key ) {
		$GLOBALS['syncs'][] = $post_id . ':' . $key;
		foreach ( $GLOBALS['original'] as $translation => $original ) {
			if ( $original === $post_id ) {
				if ( isset( $GLOBALS['meta'][ $post_id ][ $key ] ) ) {
					update_post_meta( $translation, $key, $GLOBALS['meta'][ $post_id ][ $key ] );
				} else {
					delete_post_meta( $translation, $key );
				}
			}
		}
	},
	10,
	2
);
Wpml::boot();

/** The syncs asked for while running $write. */
function syncs( callable $write ): array {
	$GLOBALS['syncs'] = array();
	$write();
	return $GLOBALS['syncs'];
}

if ( 'config' === ( $argv[1] ?? '' ) ) {
	// What WPML gathered from the XML files: ONE custom field from one element
	// arrives as the entry itself (not a list), the shape WPML_XML2Array gives.
	$from_xml = array(
		'wpml-config' => array(
			'custom-fields'    => array( 'custom-field' => array( 'value' => 'estrategia_web_location_phone', 'attr' => array( 'action' => 'copy' ) ) ),
			'gutenberg-blocks' => array( 'gutenberg-block' => array() ),
		),
	);
	$types              = Loader::types();
	$types['gmct_wine'] = array(
		'name'       => 'gmct_wine',
		'wpml'       => 'display-as-translated',
		'taxonomies' => array( 'gmct_cuisine' ),
		'fields'     => array(
			array( 'name' => 'gmct_winery', 'type' => 'relation', 'wpml' => 'copy', 'post_type' => array( 'gmct_restaurant' ) ),
			array( 'name' => 'gmct_label', 'type' => 'image', 'wpml' => 'copy', 'post_type' => array() ),
			array( 'name' => 'gmct_pairs', 'type' => 'relation', 'wpml' => 'translate', 'post_type' => array( 'post', 'page' ) ),
			array( 'name' => 'gmct_phone', 'type' => 'tel', 'wpml' => 'ignore', 'post_type' => array() ),
		),
	);
	echo json_encode(
		array(
			'errors'   => (object) Loader::load()['errors'],
			'filtered' => Wpml::filter_config( $from_xml, $types ),
			'not_array' => Wpml::filter_config( 'x', $types ),
		),
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	exit;
}

$out                       = array();
$out['phone_new']          = syncs( static fn() => update_post_meta( 10, 'gmct_phone', '971 00 00 01' ) );
$out['phone_changed']      = syncs( static fn() => update_post_meta( 10, 'gmct_phone', '971 00 00 02' ) );
$out['phone_in_ca']        = $GLOBALS['meta'][11]['gmct_phone'] ?? null;
$out['price']              = syncs( static fn() => update_post_meta( 10, 'gmct_price_range', '€€€' ) );
$out['website']            = syncs( static fn() => update_post_meta( 10, 'gmct_website', 'https://example.test' ) );
$out['address_translate']  = syncs( static fn() => update_post_meta( 10, 'gmct_address', 'Carrer Major 1' ) );
$out['on_translation']     = syncs( static fn() => update_post_meta( 11, 'gmct_phone', '000' ) );
$out['phone_after_trans']  = $GLOBALS['meta'][10]['gmct_phone'] ?? null;
$out['page_same_key']      = syncs( static fn() => update_post_meta( 40, 'gmct_phone', '1' ) );
$out['untranslated']       = syncs( static fn() => update_post_meta( 50, 'gmct_phone', '1' ) );
$out['phone_deleted']      = syncs( static fn() => delete_post_meta( 10, 'gmct_phone' ) );
$out['phone_ca_deleted']   = isset( $GLOBALS['meta'][11]['gmct_phone'] );
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
