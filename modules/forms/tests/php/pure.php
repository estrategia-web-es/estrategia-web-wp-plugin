<?php
/**
 * Helper for tests/pure.test.mjs (level 1): loads the plugin's PURE classes
 * —the ones that do not call WordPress— with the machine's PHP, runs
 * the cases and writes the results as JSON. The Node test is the one that
 * checks; this only computes.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/class-markers.php';
require __DIR__ . '/../../includes/class-ip.php';
require __DIR__ . '/../../includes/class-token.php';
require __DIR__ . '/../../includes/class-preselect.php';

use EstrategiaWeb\Forms\Ip;
use EstrategiaWeb\Forms\Markers;
use EstrategiaWeb\Forms\Preselect;
use EstrategiaWeb\Forms\Token;

// A pocket sanitize_title(): in the plugin WordPress's own is passed.
$slug    = static fn( $s ) => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( strtr( (string) $s, array( 'í' => 'i', 'á' => 'a', 'é' => 'e', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n' ) ) ) ), '-' );
$options = array( 'Lavandería para hoteles', 'Lavandería para restaurantes', 'Lavandería para barcos y yates' );

$values = array(
	'nombre'           => 'Ana <b>',
	'telefono'         => '',
	'todos_los_campos' => '<table>',
);
$esc    = static fn( $v ) => htmlspecialchars( $v, ENT_QUOTES );
$secret = 'test-secret';
$now    = 1790000000;
$token  = Token::create( 'abc', $now, $secret );

echo json_encode(
	array(
		'known'          => Markers::replace( 'Hola {{nombre}}', $values ),
		'spaces_case'    => Markers::replace( 'Hola {{ NOMBRE }}', $values ),
		'empty_known'    => Markers::replace( 'Tel: {{telefono}}.', $values ),
		'unknown_kept'   => Markers::replace( 'Mal: {{emial}}', $values ),
		'escaped'        => Markers::replace( '{{nombre}}', $values, $esc ),
		'raw_not_escaped' => Markers::replace( '{{todos_los_campos}}', $values, $esc, array( 'todos_los_campos' ) ),
		'single_line'    => Markers::single_line( "Asunto\r\nBcc: x@y.z" ),
		'ipv4'           => Ip::truncate( '203.0.113.77' ),
		'ipv6'           => Ip::truncate( '2001:db8:85a3:8d3:1319:8a2e:370:7348' ),
		'ip_bad'         => Ip::truncate( 'not-an-ip' ),
		'token_ok'       => Token::verify( $token, 'abc', $now + 10, $secret, 3, 3600 ),
		'token_fast'     => Token::verify( $token, 'abc', $now + 1, $secret, 3, 3600 ),
		'token_expired'  => Token::verify( $token, 'abc', $now + 7200, $secret, 3, 3600 ),
		'token_other_def' => Token::verify( $token, 'xyz', $now + 10, $secret, 3, 3600 ),
		'token_tampered' => Token::verify( ( $now - 100 ) . substr( $token, 10 ), 'abc', $now + 10, $secret, 3, 3600 ),
		'token_garbage'  => Token::verify( 'hello', 'abc', $now, $secret, 3, 3600 ),
		'pre_position'      => Preselect::pick( $options, array( 3 ), $slug ),
		'pre_position_str'  => Preselect::pick( $options, array( '3' ), $slug ),
		'pre_text'          => Preselect::pick( $options, array( 'Lavandería para restaurantes' ), $slug ),
		'pre_slug'          => Preselect::pick( $options, array( 'lavanderia-para-restaurantes' ), $slug ),
		'pre_out_of_range'  => Preselect::pick( $options, array( 4 ), $slug ),
		'pre_zero'          => Preselect::pick( $options, array( 0 ), $slug ),
		'pre_unknown'       => Preselect::pick( $options, array( '"><script>x</script>' ), $slug ),
		'pre_empty_slug'    => Preselect::pick( array_merge( $options, array( '¿?' ) ), array( '!!' ), $slug ),
		'pre_negative'      => Preselect::pick( $options, array( -1 ), $slug ),
		'pre_first_wins'    => Preselect::pick( $options, array( null, '1', 3 ), $slug ),
		'pre_falls_through' => Preselect::pick( $options, array( 'Tintorería', 3 ), $slug ),
		'pre_none'          => Preselect::pick( $options, array( null, '', 0 ), $slug ),
	),
	JSON_UNESCAPED_UNICODE
);
