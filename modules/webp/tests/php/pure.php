<?php
/**
 * Called by modules/webp/tests/webp-pure.test.mjs with the machine's PHP (no
 * WordPress): the decisions of includes/class-webp.php, with the two
 * WordPress functions it calls played here. A new case is a new key here and
 * its assert there.
 *
 * The trap: Webp::supported() caches its answer in a static; a case that
 * plays «this PHP cannot write WebP» empties it first (reflection), or it
 * would see the previous case's answer.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['webp_filter']    = true; // What a theme returns from estrategia_web_site_webp.
$GLOBALS['webp_quality']   = null; // What a theme returns from estrategia_web_site_webp_quality (null: the default).
$GLOBALS['webp_supported'] = true; // What wp_image_editor_supports() answers for image/webp.

function apply_filters( $hook, $value ) {
	if ( 'estrategia_web_site_webp' === $hook ) {
		return $GLOBALS['webp_filter'];
	}
	if ( 'estrategia_web_site_webp_quality' === $hook ) {
		return $GLOBALS['webp_quality'] ?? $value;
	}
	return $value;
}
function wp_image_editor_supports( $args ) {
	return $GLOBALS['webp_supported'];
}

require __DIR__ . '/../../includes/class-webp.php';

use EstrategiaWeb\Site\Webp;

$forget = static function () {
	( new ReflectionProperty( Webp::class, 'supported' ) )->setValue( null, null );
};
$out = array();

$out['on']    = Webp::output_format( array(), 'x.jpg', 'image/jpeg' );
$out['keeps'] = Webp::output_format( array( 'image/gif' => 'image/png' ), 'x.gif', 'image/gif' );

$GLOBALS['webp_filter'] = false;
$out['filter_off']      = Webp::output_format( array(), 'x.jpg', 'image/jpeg' );
$GLOBALS['webp_filter'] = true;

$forget();
$GLOBALS['webp_supported'] = false;
$out['unsupported']        = Webp::output_format( array(), 'x.jpg', 'image/jpeg' );
$GLOBALS['webp_supported'] = true;
$forget();

$out['quality_webp'] = Webp::quality( 82, 'image/webp' );
$out['quality_jpeg'] = Webp::quality( 82, 'image/jpeg' );
$GLOBALS['webp_quality'] = 0;
$out['quality_zero'] = Webp::quality( 82, 'image/webp' );
$GLOBALS['webp_quality'] = 140;
$out['quality_high'] = Webp::quality( 82, 'image/webp' );

echo json_encode( $out );
