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
// 2.11.0: what a file IS (by its first bytes), the names shown, $_FILES as PHP builds it, and the
// dossier's rules (who receives the link, what a save needs, what is missing, what changed).
require __DIR__ . '/../../includes/class-files.php';
require __DIR__ . '/../../includes/class-dossier.php';
use EstrategiaWeb\Forms\Dossier;
use EstrategiaWeb\Forms\Files;

$files_cases = array(
	'sniff_pdf'       => Files::sniff( "%PDF-1.7\n%\xE2\xE3" ),
	'sniff_jpg'       => Files::sniff( "\xFF\xD8\xFF\xE0\x00\x10JFIF" ),
	'sniff_png'       => Files::sniff( "\x89PNG\r\n\x1A\n\x00\x00" ),
	'sniff_html'      => Files::sniff( '<!doctype html><script>' ),
	'sniff_pdf_late'  => Files::sniff( ' %PDF-1.7' ),
	'accepted'        => Files::accepted( ' .PDF; jpeg,gif ,png,pdf' ),
	'accepted_none'   => Files::accepted( 'exe' ),
	'accept_attr'     => Files::accept_attribute( array( 'pdf', 'jpg' ) ),
	'name_path'       => Files::display_name( 'C:\\fakepath\\..\\escrituras "2026".exe', 'pdf' ),
	'name_empty'      => Files::display_name( '.pdf', 'pdf' ),
	'name_keeps_utf8' => Files::display_name( 'Modelo 036 — Stanila.PDF', 'pdf' ),
	'normalize'       => Files::normalize(
		array(
			'name'     => array( 'docs' => array( 'a.pdf', '' ), 'foto' => array( 'b.jpg' ) ),
			'tmp_name' => array( 'docs' => array( '/tmp/a', '' ), 'foto' => array( '/tmp/b' ) ),
			'size'     => array( 'docs' => array( 10, 0 ), 'foto' => array( 20 ) ),
			'error'    => array( 'docs' => array( 0, UPLOAD_ERR_NO_FILE ), 'foto' => array( UPLOAD_ERR_INI_SIZE ) ),
		)
	),
);

$dossier_def = array(
	'dossier' => true,
	'fields'  => array(
		array( 'type' => 'text', 'name' => 'empresa', 'label' => 'Empresa', 'required' => true, 'required_to_save' => true, 'consent' => false ),
		array( 'type' => 'email', 'name' => 'email_copia', 'label' => 'Copia', 'required' => false, 'required_to_save' => false, 'consent' => false ),
		array( 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true, 'required_to_save' => true, 'consent' => false ),
		array( 'type' => 'text', 'name' => 'iae', 'label' => 'IAE', 'required' => true, 'required_to_save' => false, 'consent' => false ),
		array( 'type' => 'file', 'name' => 'escrituras', 'label' => 'Escrituras', 'required' => true, 'required_to_save' => false, 'consent' => false ),
		array( 'type' => 'file', 'name' => 'otros', 'label' => '', 'required' => false, 'required_to_save' => false, 'consent' => false ),
		array( 'type' => 'checkbox', 'name' => 'privacidad', 'label' => 'Privacidad', 'required' => true, 'required_to_save' => false, 'consent' => true ),
	),
);
$no_marked = $dossier_def;
$no_marked['fields'][2]['required_to_save'] = false;
$data_before = array(
	array( 'name' => 'empresa', 'value' => 'Stanila' ),
	array( 'name' => 'iae', 'value' => '' ),
	array( 'name' => 'escrituras', 'value' => '', 'files' => array() ),
);
$data_after = array(
	array( 'name' => 'empresa', 'value' => 'Stanila' ),
	array( 'name' => 'iae', 'value' => '501.3' ),
	array( 'name' => 'escrituras', 'value' => 'e.pdf', 'files' => array( array( 'id' => 'f1' ) ) ),
);
// What happens to each upload, and to the files a dossier already had (2.11.0, moved down from level 2).
$pdf_head = "%PDF-1.4\n";
$files_cases['problem'] = array(
	'ok'          => Files::problem( UPLOAD_ERR_OK, 100, true, $pdf_head, array( 'pdf' ), 1000 ),
	'too_big'     => Files::problem( UPLOAD_ERR_OK, 1001, true, $pdf_head, array( 'pdf' ), 1000 ),
	'php_limit'   => Files::problem( UPLOAD_ERR_INI_SIZE, 0, false, '', array( 'pdf' ), 1000 ),
	'not_upload'  => Files::problem( UPLOAD_ERR_OK, 100, false, $pdf_head, array( 'pdf' ), 1000 ),
	'partial'     => Files::problem( UPLOAD_ERR_PARTIAL, 100, true, $pdf_head, array( 'pdf' ), 1000 ),
	'html_as_pdf' => Files::problem( UPLOAD_ERR_OK, 100, true, '<!doctype html>', array( 'pdf' ), 1000 ),
	'png_not_ok'  => Files::problem( UPLOAD_ERR_OK, 100, true, "\x89PNG\r\n\x1A\n", array( 'pdf' ), 1000 ),
);
$f = static fn( $id ) => array( 'id' => $id, 'path' => '2026/11/' . str_repeat( $id, 32 ) );
$files_cases['merge'] = array(
	'replace_one' => Files::merge( array( 'e' => array( $f( 'a' ) ) ), array(), array( 'e' => array( $f( 'b' ) ) ), array( 'e' => false ) ),
	'add_several' => Files::merge( array( 'o' => array( $f( 'a' ) ) ), array(), array( 'o' => array( $f( 'b' ) ) ), array( 'o' => true ) ),
	'remove'      => Files::merge( array( 'o' => array( $f( 'a' ), $f( 'c' ) ) ), array( 'c' ), array(), array( 'o' => true ) ),
	'untouched'   => Files::merge( array( 'e' => array( $f( 'a' ) ) ), array(), array(), array( 'e' => false ) ),
	'first_time'  => Files::merge( array(), array(), array( 'e' => array( $f( 'a' ) ) ), array( 'e' => false ) ),
);

require __DIR__ . '/../../includes/class-definitions.php';
$notes = array(
	array( 'label' => 'team', 'when' => 'always' ),
	array( 'label' => 'ack', 'when' => 'created' ),
	array( 'label' => 'changes', 'when' => 'updated' ),
	array( 'label' => 'old snapshot' ),
);
$labels_of = static fn( $list ) => array_column( $list, 'label' );

$now  = 1790000000;
$open = array( 'form_id' => 'ayuda', 'access_hash' => str_repeat( 'a', 64 ), 'access_expires' => gmdate( 'Y-m-d H:i:s', $now + 60 ) );

$a_token       = Dossier::new_token();
$dossier_cases = array(
	'owner_marked'   => Dossier::owner_field( $dossier_def ),
	'owner_first'    => Dossier::owner_field( $no_marked ),
	'needed'         => Dossier::needed_to_save( $dossier_def ),
	'missing_before' => Dossier::missing( $dossier_def, $data_before ),
	'missing_after'  => Dossier::missing( $dossier_def, $data_after ),
	'labels'         => Dossier::labels( $dossier_def, array( 'iae', 'escrituras', 'otros' ) ),
	'changed'        => Dossier::changed( $data_before, $data_after ),
	'token_shape'    => Dossier::is_token( $a_token ),
	'token_unique'   => $a_token !== Dossier::new_token(),
	'token_bad'      => array( Dossier::is_token( '' ), Dossier::is_token( str_repeat( 'a', 42 ) ), Dossier::is_token( str_repeat( 'a', 42 ) . '/' ) ),
	'hash_is_sha256' => Dossier::hash( 'x' ) === hash( 'sha256', 'x' ),
	'on_created'     => $labels_of( \EstrategiaWeb\Forms\Definitions::for_event( $notes, 'created' ) ),
	'on_updated'     => $labels_of( \EstrategiaWeb\Forms\Definitions::for_event( $notes, 'updated' ) ),
	'open'           => array(
		'valid'        => Dossier::is_open( $open, $now, 'ayuda' ),
		'any_form'     => Dossier::is_open( $open, $now ),
		'other_form'   => Dossier::is_open( $open, $now, 'contacto' ),
		'expired'      => Dossier::is_open( $open, $now + 61, 'ayuda' ),
		'revoked'      => Dossier::is_open( array( 'access_hash' => '', 'access_expires' => null ) + $open, $now, 'ayuda' ),
		'no_expiry'    => Dossier::is_open( array( 'access_expires' => '' ) + $open, $now, 'ayuda' ),
	),
);

$esc    = static fn( $v ) => htmlspecialchars( $v, ENT_QUOTES );
$secret = 'test-secret';
$now    = 1790000000;
$token  = Token::create( 'abc', $now, $secret );

// 2.14.0 (24-11-2026): the entry the form is shown in — its signed id, the {{post_*}} markers,
// which meta {{post_field:<key>}} may read and the To / Cc / Bcc addresses after the markers.
$ctx         = Token::context( 4242, 'abc', $secret );
$is_email    = static fn( $a ) => false !== filter_var( $a, FILTER_VALIDATE_EMAIL );
$underscore  = static fn( $k ) => str_starts_with( $k, '_' ); // is_protected_meta()'s default rule.
$registered  = array( 'lle_correu_familia', 'lle_data_funeral', 'shared_key' );
$entry_marks = array(
	Markers::POST_TITLE                     => 'Joan Mas Riera',
	Markers::POST_URL                       => 'https://example.test/esqueles/joan-mas/',
	Markers::POST_FIELD . ':correu_familia' => 'familia@example.test',
	Markers::POST_FIELD . ':_privat'        => '',
);
$context_cases = array(
	'ok'            => Token::context_id( $ctx, 'abc', $secret ),
	'shape'         => (bool) preg_match( '/^4242\.[a-f0-9]{40}$/', $ctx ),
	'absent'        => Token::context_id( '', 'abc', $secret ),
	'none_for_0'    => Token::context( 0, 'abc', $secret ),
	'other_def'     => Token::context_id( $ctx, 'xyz', $secret ),
	'other_id'      => Token::context_id( '4243' . substr( $ctx, 4 ), 'abc', $secret ),
	'tampered_sig'  => Token::context_id( substr( $ctx, 0, -1 ) . ( str_ends_with( $ctx, '0' ) ? '1' : '0' ), 'abc', $secret ),
	'other_secret'  => Token::context_id( $ctx, 'abc', 'another-site' ),
	'a_token'       => Token::context_id( $token, 'abc', $secret ),
	'garbage'       => Token::context_id( '4242.<script>', 'abc', $secret ),
	'leading_zero'  => Token::context_id( '0' . $ctx, 'abc', $secret ),
);
$entry_cases = array(
	'subject'      => Markers::replace( 'Condol: {{post_title}} ({{ POST_FIELD:Correu_Familia }}) {{post_url}}', $entry_marks ),
	'protected'    => Markers::replace( '[{{post_field:_privat}}]', $entry_marks ),
	'typo_kept'    => Markers::replace( '{{post_feld:x}}|{{post_field:}}', $entry_marks ),
	'html_escaped' => Markers::replace( '{{post_title}}', array( Markers::POST_TITLE => 'Joan <b>' ), $esc ),
	'keys'         => Markers::post_field_keys( 'Cc {{post_field:correu_familia}}, {{ post_field:Correu_Familia }}', '{{post_field:lle_data_funeral}} {{post_title}} {{email}}', '' ),
	'key'          => array(
		'registered'   => Markers::post_field_key( 'lle_correu_familia', 'lle_esquela', $registered, $underscore ),
		'prefixed'     => Markers::post_field_key( 'correu_familia', 'lle_esquela', $registered, $underscore ),
		'public'       => Markers::post_field_key( 'acf_email', 'lle_esquela', $registered, $underscore ),
		'no_prefix'    => Markers::post_field_key( 'correu_familia', 'post', $registered, $underscore ),
		'exact_first'  => Markers::post_field_key( 'shared_key', 'lle_esquela', array( 'shared_key', 'lle_shared_key' ), $underscore ),
		'underscore'   => Markers::post_field_key( '_edit_lock', 'lle_esquela', $registered, $underscore ),
		'filtered'     => Markers::post_field_key( 'secret', 'lle_esquela', $registered, static fn( $k ) => 'secret' === $k ),
		'bad_chars'    => Markers::post_field_key( 'a b', 'lle_esquela', $registered, $underscore ),
	),
	'addresses'    => array(
		'list'         => Markers::addresses( ' funeraria@example.test, no-es-correu ;familia@example.test,, FAMILIA@example.test ', $is_email ),
		'injection'    => Markers::addresses( "familia@example.test\nBcc: spam@example.test", $is_email ),
		'cr_injection' => Markers::addresses( "familia@example.test\r\nX-Evil: 1, ok@example.test", $is_email ),
		'trailing_nl'  => Markers::addresses( "familia@example.test\n", $is_email ),
		'empty'        => Markers::addresses( '', $is_email ),
		'except_to'    => Markers::addresses( 'Funeraria@example.test, familia@example.test', $is_email, array( 'funeraria@example.test' ) ),
	),
	// The control-character rule on its own, not leaning on the validator (WordPress's is_email() is not filter_var).
	'addresses_trusting' => Markers::addresses( "familia@example.test\nBcc: spam@example.test", static fn() => true ),
);

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
		// 2.11.0: the File field and the dossier's pure parts.
		'files'             => $files_cases,
		'dossier'           => $dossier_cases,
		'context'           => $context_cases,
		'entry'             => $entry_cases,
	),
	JSON_UNESCAPED_UNICODE
);
