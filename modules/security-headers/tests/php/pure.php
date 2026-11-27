<?php
/**
 * Called by modules/security-headers/tests/pure.test.mjs with the machine's
 * PHP (no WordPress). Loads module.php against stubs, keeps the callbacks it
 * hooks and writes what they return as JSON; the assertions are in the .mjs.
 *
 * The stubs: add_filter/add_action record the callback by hook,
 * apply_filters runs $GLOBALS['filter'] when a case sets one, and is_ssl()
 * returns $GLOBALS['https']. headers_list() is PHP's own, empty in the CLI.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['hooks']  = array();
$GLOBALS['filter'] = null;
$GLOBALS['https']  = true;

function add_filter( $hook, $cb, $prio = 10 ) {
	$GLOBALS['hooks'][ $hook ] = array( $cb, $prio );
}
function add_action( $hook, $cb, $prio = 10 ) {
	add_filter( $hook, $cb, $prio );
}
function apply_filters( $hook, $value ) {
	return $GLOBALS['filter'] ? ( $GLOBALS['filter'] )( $value ) : $value;
}
function remove_action( $hook, $cb ) {
	$GLOBALS['removed'][] = array( $hook, $cb );
}
function is_ssl() {
	return $GLOBALS['https'];
}

require dirname( __DIR__, 2 ) . '/module.php';

use function EstrategiaWeb\SecurityHeaders\missing;

$wp_headers = $GLOBALS['hooks']['wp_headers'][0];
$out        = array(
	'priority'   => $GLOBALS['hooks']['wp_headers'][1],
	'send_hook'  => isset( $GLOBALS['hooks']['send_headers'] ),
	// Nothing sent by anyone, over https: all five.
	'empty'      => $wp_headers( array( 'Content-Type' => 'text/html' ) ),
	// Another plugin (SiteGround Security) already put nosniff, in another case
	// (all capitals: lower case would match a lowercased lookup by chance).
	'sg'         => $wp_headers( array( 'X-CONTENT-TYPE-OPTIONS' => 'nosniff', 'X-XSS-Protection' => '1; mode=block' ) ),
	// Already queued by PHP with header() (headers_list() lines).
	'queued'     => missing( array(), array( 'X-Frame-Options: DENY', 'Referrer-Policy:no-referrer' ), true ),
);
$GLOBALS['https'] = false;
$out['http']      = $wp_headers( array() );
$GLOBALS['https'] = true;
// A site leaves one header out and changes another.
$GLOBALS['filter'] = static function ( $h ) {
	unset( $h['Permissions-Policy'] );
	$h['X-Frame-Options'] = 'DENY';
	return $h;
};
$out['filtered'] = missing( array(), array(), true );

// WPML: its generator tag is unhooked on «wp».
class FakeSitePress {
	public function meta_generator_tag() {}
}
$sitepress = new FakeSitePress();
$GLOBALS['hooks']['wp'][0]();
$out['wpml'] = array_map( static fn( $r ) => array( $r[0], $r[1][1] ), $GLOBALS['removed'] ?? array() );

echo json_encode( $out );
