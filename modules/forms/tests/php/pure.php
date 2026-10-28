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
	'all_fields'       => '<table>',
);
// What Notifier::marker_values() hands over: the fields first, then the fixed
// markers. "fecha" is a FIELD here, named like an old (pre-1.4.0) marker.
$fixed  = array(
	'fecha'                => 'el 3 de mayo',
	Markers::ALL_FIELDS    => 'Nombre: Ana',
	Markers::DATE          => '15/10/2026 10:00',
	Markers::PAGE_URL      => 'https://example.test/contacto/',
	Markers::SITE_NAME     => 'Lavandería',
);
$fixed_template = '{{all_fields}}|{{submission_date}}|{{page_url}}|{{site_name}}';
$old_template   = '{{todos_los_campos}}|{{fecha}}|{{url_pagina}}|{{ Nombre_Sitio }}';

// 2.0.1: the markers migrator's pure part. A page with an inline form: the
// notifications change, the rest of the content (a paragraph that MENTIONS
// {{fecha}}, a field named «fecha») is byte for byte the same.
require __DIR__ . '/../../includes/class-marker-migration.php';
$page = "<!-- wp:paragraph -->\n<p>Escribe {{fecha}} en tu plantilla</p>\n<!-- /wp:paragraph -->\n"
	. '<!-- wp:estrategia-web-forms/form {"formId":"contacto","title":"Contacto"} -->' . "\n"
	. '<!-- wp:estrategia-web-forms/text {"label":"Fecha","name":"fecha"} /-->' . "\n"
	. '<!-- wp:estrategia-web-forms/notification {"label":"Aviso al equipo","to":"equipo@ejemplo.test","subject":"Nuevo {{nombre}} el {{ Fecha }}","body":"Desde {{url_pagina}} en {{nombre_sitio}}:\n\n{{todos_los_campos}}"} /-->' . "\n"
	. '<!-- wp:estrategia-web-forms/notification {"label":"Ya nuevo","to":"{{email}}","body":"{{all_fields}}"} /-->' . "\n"
	. "<!-- /wp:estrategia-web-forms/form -->\n"
	. '<!-- wp:estrategia-web-forms/form {"formId":"presupuesto"} -->' . "\n"
	. '<!-- wp:estrategia-web-forms/notification {"label":"Copia","to":"x@ejemplo.test","body":"{{fecha}}"} /-->' . "\n"
	. "<!-- /wp:estrategia-web-forms/form -->\n";
$migrated = \EstrategiaWeb\Forms\Marker_Migration::rename_markup( $page, $marker_changes );
$again    = \EstrategiaWeb\Forms\Marker_Migration::rename_markup( $migrated, $marker_changes_again );
$snapshot = array(
	'form_id'       => 'contacto',
	'fields'        => array( array( 'name' => 'fecha' ) ),
	'notifications' => array(
		array( 'label' => 'A', 'to' => 'a@b.c', 'reply_to' => '', 'subject' => '{{fecha}}', 'body' => '{{todos_los_campos}}' ),
		array( 'label' => 'B', 'to' => 'a@b.c', 'reply_to' => '', 'subject' => 'x', 'body' => '{{all_fields}}' ),
	),
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
		'raw_not_escaped' => Markers::replace( '{{all_fields}}', $values, $esc, array( Markers::ALL_FIELDS ) ),
		'single_line'    => Markers::single_line( "Asunto\r\nBcc: x@y.z" ),
		'fixed_current'  => Markers::replace( $fixed_template, $fixed ),
		'fixed_old'      => Markers::replace( $old_template, $fixed ),
		'mig_text'       => \EstrategiaWeb\Forms\Marker_Migration::rename_text( $old_template . '|{{nombre}}|{{fechas}}' ),
		'mig_page'       => $migrated,
		'mig_changes'    => $marker_changes,
		'mig_again'      => array( $again === $migrated, $marker_changes_again ),
		'mig_snapshot'   => \EstrategiaWeb\Forms\Marker_Migration::rename_definition( $snapshot ),
		'mig_snapshot_new' => \EstrategiaWeb\Forms\Marker_Migration::rename_definition( array( 'notifications' => array( $snapshot['notifications'][1] ) ) ),
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
