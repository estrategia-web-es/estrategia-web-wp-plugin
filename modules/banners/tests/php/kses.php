<?php
/**
 * Called by modules/banners/tests/code.test.mjs with the machine's PHP (no
 * WordPress). Writes Kses::banner() as JSON: tag => list of attributes. The
 * assertions are in the .mjs.
 *
 * wp_kses_allowed_html( 'post' ) is WordPress's: here it is a stub with only
 * an empty <img> (Kses::allowed() extends the post list's <img>, so it must
 * exist), so what comes out is what OUR lists add (Kses::FORM_TAGS, BANNER
 * and the global attributes). That is what this level checks; what wp_kses()
 * itself does with the list is WordPress's, not ours.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function wp_kses_allowed_html( $context = '' ) {
		return array( 'img' => array() );
	}
}

namespace {
	require dirname( __DIR__, 4 ) . '/includes/class-kses.php';
	$out = array();
	foreach ( \EstrategiaWeb\Kses::banner() as $tag => $attributes ) {
		$out[ $tag ] = array_keys( array_filter( (array) $attributes ) );
	}
	echo json_encode( $out );
}
