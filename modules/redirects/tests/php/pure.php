<?php
/**
 * Called by modules/redirects/tests/pure.test.mjs with the machine's PHP
 * (no WordPress). Runs includes/class-rules.php with fixed cases and with
 * Lavandería's real CSV (tests/fixtures/), and writes the result as JSON;
 * the assertions are in the .mjs.
 *
 * How to maintain: a new case is a new key here and its assert there. The
 * CSV in fixtures/ is a COPY of Lavandería's from 07-10-2026: if it changes
 * there and you want to test the new one, copy it again (and change the 83).
 */

define( 'ABSPATH', __DIR__ . '/' );
// WordPress's wrapper (Plugin Check asks for it); on PHP 8 it is parse_url().
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
require dirname( __DIR__, 2 ) . '/includes/class-rules.php';

use EstrategiaWeb\Redirects\Rules;

$out = array();

// Normalise.
foreach ( array(
	'/Contacto/',
	'/contacto',
	'https://www.ejemplo.com/contacto/',
	'/contacto/#form',
	'/index.html%3Fp=6981.html',
	'/index.html?p=6981.html',
	'/?page_id=5',
	'/?p=5',
	'/a//b/',
	'/caf%C3%A9/',
	'/en/?p=30671',
	'/en/%3Fp=30671',
	'/index.html%3Fpage_id=6981',
	'/index.html?p=6981',
	'',
) as $u ) {
	$out['key'][ $u ] = Rules::key( $u );
}
$out['key_base'] = Rules::key( '/blog/viejo/', '/blog' );

// Candidate keys for a request.
$out['cand_utm']    = Rules::candidates( '/contacto/?utm_source=x&utm_medium=y' );
$out['cand_p_lang'] = Rules::candidates( '/?p=6981&lang=es' );
$out['cand_3f']     = Rules::candidates( '/index.html%3Fp=6981.html' );
$out['cand_long']   = count( Rules::candidates( '/x?' . implode( '&', array_map( static fn( $i ) => "a$i=$i", range( 1, 200 ) ) ) ) );

// The real CSV: read in full and validated row by row against the previous ones (like the importer).
$csv    = Rules::parse_csv( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/lavanderia-2026-10-07.csv' ) );
$all    = array();
$errors = array();
$types  = array();
$self   = 0;
foreach ( $csv['rows'] as $row ) {
	list( $rule, $code ) = Rules::validate( $row, $all, 'https://lavanderiamallorca.com' );
	if ( ! $rule ) {
		$errors[] = $row['line'] . ' ' . $row['source'] . ': ' . $code;
		continue;
	}
	$all[ $rule['key'] ] = $rule;
	$types[ $rule['type'] ] = ( $types[ $rule['type'] ] ?? 0 ) + 1;
	// A request for that source as is (in the browser) matches its rule.
	if ( in_array( $rule['key'], Rules::candidates( $row['source'] ), true ) ) {
		++$self;
	}
}
ksort( $types );
$out['csv'] = array(
	'rows'       => count( $csv['rows'] ),
	'valid'      => count( $all ),
	'errors'     => $errors,
	'types'      => $types,
	'self_match' => $self,
	'inactive'   => count( array_filter( $all, static fn( $r ) => ! $r['active'] ) ),
	'first'      => $csv['rows'][0],
	'quoted'     => $csv['rows'][16]['note'], // The one with quotes containing commas.
	'empty_410'  => $all['/lavanderia-santa-ponsa']['target'] ?? 'falta',
);

// Validate.
$r = static function ( string $s, string $t, $type = 301, bool $active = true ): array {
	return array( 'source' => $s, 'target' => $t, 'type' => $type, 'active' => $active );
};
$k = static function ( array ...$rules ): array {
	$map = array();
	foreach ( $rules as $rule ) {
		$map[ Rules::key( $rule['source'] ) ] = $rule;
	}
	return $map;
};
$home           = 'https://ejemplo.com';
$out['validate'] = array(
	'loop2'          => Rules::validate( $r( '/b/', '/a/' ), $k( $r( '/a/', '/b/' ) ), $home )[1],
	'loop3'          => Rules::validate( $r( '/c/', '/a' ), $k( $r( '/a/', '/b/' ), $r( '/b/', '/c/' ) ), $home )[1],
	'loop_abs'       => Rules::validate( $r( '/b/', 'https://www.ejemplo.com/a/' ), $k( $r( '/a/', '/b/' ) ), $home )[1],
	'loop_inactive'  => Rules::validate( $r( '/b/', '/a/' ), $k( $r( '/a/', '/b/', 301, false ) ), $home )[1],
	'chain_ok'       => Rules::validate( $r( '/c/', '/a/' ), $k( $r( '/a/', '/b/' ) ), $home )[1],
	'same'           => Rules::validate( $r( '/a/', '/A' ), array(), $home )[1],
	'same_abs'       => Rules::validate( $r( '/a/', 'https://ejemplo.com/a/' ), array(), $home )[1],
	'external_ok'    => Rules::validate( $r( '/a/', 'https://otra.com/a/' ), array(), $home )[1],
	'duplicate'      => Rules::validate( $r( '/A/', '/x/' ), $k( $r( '/a', '/y/' ) ), $home )[1],
	'dup_3f'         => Rules::validate( $r( '/index.html?p=1.html', '/x/' ), $k( $r( '/index.html%3Fp=1.html', '/y/' ) ), $home )[1],
	'empty_target'   => Rules::validate( $r( '/a/', '' ), array(), $home )[1],
	'bad_target'     => Rules::validate( $r( '/a/', 'nueva-pagina' ), array(), $home )[1],
	'bad_target_js'  => Rules::validate( $r( '/a/', 'javascript:alert(1)' ), array(), $home )[1],
	'bad_type'       => Rules::validate( $r( '/a/', '/b/', 307 ), array(), $home )[1],
	'default_type'   => Rules::validate( $r( '/a/', '/b/', '' ), array(), $home )[0]['type'],
	'home'           => Rules::validate( $r( '/', '/b/' ), array(), $home )[1],
	'home_p_ok'      => Rules::validate( $r( '/?p=24298', '/' ), array(), $home )[1],
	'foreign_source' => Rules::validate( $r( 'https://otra.com/a/', '/b/' ), array(), $home )[1],
	'gone_target'    => Rules::validate( $r( '/a/', '/b/', 410 ), array(), $home )[0]['target'],
);

// CSV with semicolons, BOM, «revisar» and a protected cell.
$semi              = Rules::parse_csv( "\xEF\xBB\xBForigen;destino;tipo;motivo;revisar\n/a/;/b/;302;'=SUMA(1);dudoso\n\n/c/;;410;;\n" );
$out['semi']       = $semi['rows'];
$out['noheader']   = Rules::parse_csv( "/a/,/b/,301,nota\n" )['rows'];
$out['cell']       = array( Rules::cell( '=1+1' ), Rules::cell( '/a/' ), Rules::cell( '-x' ) );

// Conflicts::detect() (28-10-2026; it was checked by loading the screen three
// times in a browser). get_option and apply_filters are played here; the
// trap: RANK_MATH_VERSION cannot be undefined, so the case without Rank Math
// goes first.
$GLOBALS['rank_math_modules'] = array();
function get_option( $name, $fallback = false ) {
	return 'rank_math_modules' === $name ? $GLOBALS['rank_math_modules'] : $fallback;
}
function apply_filters( $hook, $value ) {
	return $value;
}
require dirname( __DIR__, 2 ) . '/includes/class-conflicts.php';
use EstrategiaWeb\Redirects\Conflicts;
// 2.0.1: owner() — who manages the redirects instead of this module. Each
// pair is [owner(), detect()].
$both                          = static fn() => array( Conflicts::owner(), Conflicts::detect() );
$out['owner_none']             = $both();
$GLOBALS['rank_math_modules']  = array( 'redirections' );
$out['owner_rank_math_absent'] = $both(); // The option left behind by an uninstalled Rank Math.
define( 'RANK_MATH_VERSION', '1.0.0-falso' );
$GLOBALS['rank_math_modules']  = array( 'sitemap' );
$out['owner_rank_math_off']    = $both();
$GLOBALS['rank_math_modules']  = array( 'redirections', 'sitemap' );
$out['owner_rank_math']        = $both();
define( 'REDIRECTION_VERSION', '5.0-falso' );
$out['owner_rank_math_and_redirection'] = $both();
$GLOBALS['rank_math_modules']  = array();
define( 'WPSEO_PREMIUM_FILE', __FILE__ );
$out['owner_yoast_premium']    = $both();

echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
