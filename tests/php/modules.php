<?php
/**
 * Lo llama tests/modules.test.mjs con el PHP de la máquina (sin WordPress).
 * Carga el cargador de módulos con un get_file_data() copiado del núcleo,
 * lee las cabeceras de VERDAD de modules/ y ejecuta wanted() y resolve()
 * con casos fijos. Escribe el resultado en JSON; las aserciones están en el
 * .mjs.
 *
 * Cómo se mantiene: un caso nuevo es una clave nueva aquí y su assert allí.
 * Si WordPress cambia get_file_data() (wp-includes/functions.php), se copia
 * otra vez: la prueba de cabeceras solo vale si lee como lee WordPress.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_DIR', dirname( __DIR__, 2 ) . '/' );

/** Copia de get_file_data() de WordPress 7.1 (sin el filtro extra_*_headers). */
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

// Un catálogo inventado para los casos: a y b encendidos por defecto, c no.
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
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
