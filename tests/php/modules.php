<?php
/**
 * Called by tests/modules.test.mjs with the machine's PHP (no WordPress).
 * Loads the module loader with a get_file_data() copied from core, reads
 * the REAL headers of modules/ and runs wanted() and resolve() with fixed
 * cases. Writes the result as JSON; the assertions are in the .mjs.
 *
 * How to maintain: a new case is a new key here and its assert there.
 * If WordPress changes get_file_data() (wp-includes/functions.php), copy it
 * again: the header test is only valid if it reads the way WordPress reads.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_DIR', dirname( __DIR__, 2 ) . '/' );

/** Copy of get_file_data() from WordPress 7.1 (without the extra_*_headers filter). */
function get_file_data( $file, $default_headers ) {
	$file_data = file_get_contents( $file, false, null, 0, 8 * 1024 );
	if ( false === $file_data ) {
		$file_data = '';
	}
	$file_data = str_replace( "\r", "\n", $file_data );
	$all       = array();
	foreach ( $default_headers as $field => $regex ) {
		if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
			$all[ $field ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
		} else {
			$all[ $field ] = '';
		}
	}
	return $all;
}

require ESTRATEGIA_WEB_DIR . 'includes/class-modules.php';

use EstrategiaWeb\Modules;

$available = Modules::available();
$folders   = array_map( 'basename', glob( ESTRATEGIA_WEB_DIR . 'modules/*', GLOB_ONLYDIR ) ?: array() );

// A made-up catalog for the cases: a and b on by default, c not.
$fake = array(
	'a' => array( 'default' => true, 'requires' => array() ),
	'b' => array( 'default' => true, 'requires' => array( 'a' ) ),
	'c' => array( 'default' => false, 'requires' => array() ),
	'd' => array( 'default' => true, 'requires' => array( 'c' ) ),
);
$wanted = static fn( $saved, $known ) => Modules::wanted( $fake, $saved, $known );

$cycle = array(
	'x' => array( 'default' => true, 'requires' => array( 'y' ) ),
	'y' => array( 'default' => true, 'requires' => array( 'x' ) ),
);
$chain = array(
	'z' => array( 'default' => true, 'requires' => array( 'm' ) ),
	'm' => array( 'default' => true, 'requires' => array( 'k' ) ),
	'k' => array( 'default' => false, 'requires' => array() ),
);

// 1.7.0: a core module (site) that the saved list may lack.
$core        = array(
	'site'   => array( 'default' => true, 'core' => true, 'requires' => array() ),
	'forms'  => array( 'default' => true, 'requires' => array() ),
	'social' => array( 'default' => true, 'requires' => array( 'site' ) ),
);
$core_wanted = static fn( $saved, $known ) => Modules::wanted( $core, $saved, $known );

// Grouping: names out of order, an unknown category, a core one, an empty category.
$grouping = array(
	'zeta'  => array( 'name' => 'Zeta', 'category' => 'Content' ),
	'alpha' => array( 'name' => 'alpha', 'category' => 'Content' ),
	'beta'  => array( 'name' => 'Beta', 'category' => 'SEO' ),
	'odd'   => array( 'name' => 'Odd', 'category' => 'Contnet' ),
	'none'  => array( 'name' => 'None' ),
	'core'  => array( 'name' => 'Core', 'category' => 'Content', 'core' => true ),
	'lang'  => array( 'name' => 'Lang', 'category' => 'Languages' ),
);

echo json_encode(
	array(
		'folders'          => $folders,
		'available'        => array_map( static fn( $m ) => array_diff_key( $m, array( 'file' => 1 ) ), $available ),
		'real_default'     => Modules::resolve( $available, Modules::wanted( $available, null, null ) ),
		'no_option'        => $wanted( null, null ),
		'saved_respected'  => $wanted( array( 'a' ), array( 'a', 'b', 'c', 'd' ) ),
		'new_default_on'   => $wanted( array( 'a' ), array( 'a', 'c' ) ),
		'unknown_dropped'  => $wanted( array( 'a', 'nope', 7 ), array( 'a', 'b', 'c', 'd' ) ),
		'no_known'         => $wanted( array( 'c' ), null ),
		'resolve_defaults' => Modules::resolve( $fake, $wanted( null, null ) ),
		'resolve_order'    => Modules::resolve(
			array(
				'alpha' => array( 'default' => true, 'requires' => array( 'zeta' ) ),
				'zeta'  => array( 'default' => true, 'requires' => array() ),
			),
			array( 'alpha', 'zeta' )
		),
		'resolve_chain'    => Modules::resolve( $chain, array( 'z', 'm' ) ),
		'resolve_cycle'    => Modules::resolve( $cycle, array( 'y', 'x' ) ),
		// 1.7.0: core modules and categories.
		'core_saved_without' => $core_wanted( array( 'forms' ), array( 'site', 'forms', 'social' ) ),
		'core_saved_empty'   => $core_wanted( array(), array( 'site', 'forms', 'social' ) ),
		'core_no_known'      => $core_wanted( array( 'social' ), null ),
		'core_resolved'      => Modules::resolve( $core, $core_wanted( array( 'social' ), array( 'site', 'forms', 'social' ) ) ),
		'real_without_site'  => Modules::resolve( $available, Modules::wanted( $available, array( 'social', 'matomo' ), array_keys( $available ) ) )[0],
		// 1.7.0: wpml-front-page was removed; a site's saved list and _known still name it.
		'removed_module'     => Modules::resolve(
			$available,
			Modules::wanted( $available, array( 'forms', 'wpml-front-page', 'language-switcher' ), array_merge( array_keys( $available ), array( 'wpml-front-page' ) ) )
		),
		'grouped_fake'       => Modules::grouped( $grouping ),
		'grouped_real'       => Modules::grouped( $available ),
		'categories'         => Modules::CATEGORIES,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
