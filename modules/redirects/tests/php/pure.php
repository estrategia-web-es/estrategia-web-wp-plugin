<?php
/**
 * Lo llama modules/redirects/tests/pure.test.mjs con el PHP de la máquina
 * (sin WordPress). Ejecuta includes/class-rules.php con casos fijos y con el
 * CSV de verdad de Lavandería (tests/fixtures/), y escribe el resultado en
 * JSON; las aserciones están en el .mjs.
 *
 * Cómo se mantiene: un caso nuevo es una clave nueva aquí y su assert allí.
 * El CSV de fixtures/ es una COPIA del de Lavandería del 07-10-2026: si allí
 * cambia y se quiere probar el nuevo, se copia otra vez (y se cambia el 83).
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/includes/class-rules.php';

use EstrategiaWeb\Redirects\Rules;

$out = array();

// Normalizar.
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

// Candidatas de una petición.
$out['cand_utm']    = Rules::candidates( '/contacto/?utm_source=x&utm_medium=y' );
$out['cand_p_lang'] = Rules::candidates( '/?p=6981&lang=es' );
$out['cand_3f']     = Rules::candidates( '/index.html%3Fp=6981.html' );
$out['cand_long']   = count( Rules::candidates( '/x?' . implode( '&', array_map( static fn( $i ) => "a$i=$i", range( 1, 200 ) ) ) ) );

// El CSV real: se lee entero y se valida fila a fila contra lo anterior (como el importador).
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
	// La petición que pide ese origen tal cual (en el navegador) casa con su regla.
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
	'quoted'     => $csv['rows'][16]['note'], // La de las comillas con comas dentro.
	'empty_410'  => $all['/lavanderia-santa-ponsa']['target'] ?? 'falta',
);

// Validar.
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

// CSV con punto y coma, BOM, «revisar» y celda protegida.
$semi              = Rules::parse_csv( "\xEF\xBB\xBForigen;destino;tipo;motivo;revisar\n/a/;/b/;302;'=SUMA(1);dudoso\n\n/c/;;410;;\n" );
$out['semi']       = $semi['rows'];
$out['noheader']   = Rules::parse_csv( "/a/,/b/,301,nota\n" )['rows'];
$out['cell']       = array( Rules::cell( '=1+1' ), Rules::cell( '/a/' ), Rules::cell( '-x' ) );

echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
