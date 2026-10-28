<?php
/**
 * Helper for tests/form-migration-plan.test.mjs (level 1): the PURE part of
 * the forms migrator (Form_Migration::find_trees, plan, replace) with the
 * machine's PHP. It only computes and prints JSON; the Node test checks.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/class-form-post-type.php';
require __DIR__ . '/../../includes/class-form-migration.php';

use EstrategiaWeb\Forms\Form_Migration;

$form = static fn( string $id, string $title, string $label = 'Nombre', string $sep = "\n" ) =>
	'<!-- wp:estrategia-web-forms/form {"formId":"' . $id . '","title":"' . $title . '"} -->' . $sep
	. '<!-- wp:estrategia-web-forms/text {"label":"' . $label . '","name":"nombre"} /-->' . $sep
	. '<!-- wp:estrategia-web-forms/notification {"to":"equipo@ejemplo.test"} /-->' . $sep
	. '<!-- /wp:estrategia-web-forms/form -->';

$out = array();

// --- find_trees
$page = "<!-- wp:paragraph --><p>Hola</p><!-- /wp:paragraph -->\n"
	. '<!-- wp:group --><div class="wp-block-group">' . $form( 'contacto', 'Contacto } raro' ) . "</div><!-- /wp:group -->\n"
	. "<!-- wp:estrategia-web-forms/form-ref {\"ref\":12} /-->\n"
	. "<!-- wp:estrategia-web-forms/form {\"formId\":\"vacio\"} /-->\n"
	. "<!-- wp:armazon-forms/form {\"formId\":\"viejo\"} --><!-- wp:armazon-forms/submit /--><!-- /wp:armazon-forms/form -->";
$trees = Form_Migration::find_trees( $page, $errors );
$out['find']        = array_map( static fn( $t ) => array( $t['form_id'], $t['attrs']['title'] ?? null, substr( $page, $t['start'], $t['length'] ) === $t['markup'] ), $trees );
$out['find_errors'] = $errors;
$out['legacy']      = $trees[2]['markup'] ?? null;
Form_Migration::find_trees( '<!-- wp:estrategia-web-forms/form {"formId":"x"} --><!-- wp:estrategia-web-forms/submit /-->', $broken );
$out['unclosed'] = $broken;

// --- plan (WPML: es default, en)
$posts = array(
	array( 'id' => 20, 'type' => 'page', 'title' => 'Contact', 'lang' => 'en', 'content' => '<p>x</p>' . $form( 'contacto', 'Contact', 'Name' ) ),
	array( 'id' => 10, 'type' => 'page', 'title' => 'Contacto', 'lang' => 'es', 'content' => $form( 'contacto', 'Contacto' ) ),
	array( 'id' => 30, 'type' => 'wp_block', 'title' => 'Formulario de presupuesto', 'lang' => 'es', 'content' => $form( 'presupuesto', 'Presupuesto' ) ),
	array( 'id' => 40, 'type' => 'wp_block', 'title' => 'Quote request form', 'lang' => 'en', 'content' => $form( 'presupuesto', 'Quote', 'Name' ) ),
	// The same Contacto copied into another Spanish page, only the whitespace changes: identical.
	array( 'id' => 50, 'type' => 'page', 'title' => 'Copia', 'lang' => 'es', 'content' => $form( 'contacto', 'Contacto', 'Nombre', "\n\n" ) ),
	// Two forms with no identifier in the same page, and two DIFFERENT identifiers with the same fields.
	array( 'id' => 70, 'type' => 'page', 'title' => 'Sin id', 'lang' => 'es', 'content' => $form( '', '' ) . $form( '', 'Otro' ) ),
	array( 'id' => 80, 'type' => 'page', 'title' => 'A y B', 'lang' => 'es', 'content' => $form( 'a', 'Igual' ) . $form( 'b', 'Igual' ) ),
	array( 'id' => 90, 'type' => 'page', 'title' => 'Roto', 'lang' => 'es', 'content' => '<!-- wp:estrategia-web-forms/form {"formId":"roto"} -->' ),
);
$existing = array( array( 'id' => 99, 'form_id' => 'presupuesto', 'lang' => 'es' ) );
$plan     = Form_Migration::plan( $posts, $existing, 'es' );
$out['keys']  = array_keys( $plan['forms'] );
$out['forms'] = array_map( static fn( $f ) => array( 'source' => $f['source'], 'copies' => $f['copies'], 'identical' => $f['identical'], 'existing' => $f['existing'], 'original' => $f['original'], 'title' => $f['title'] ), $plan['forms'] );
$out['posts'] = array_map( static fn( $p ) => array_map( static fn( $t ) => $t['key'], $p['trees'] ), $plan['posts'] );
$out['errors'] = $plan['errors'];

// A different copy of contacto (es): flagged.
$diff                = Form_Migration::plan( array( $posts[1], array( 'id' => 60, 'type' => 'page', 'title' => 'Otra', 'lang' => 'es', 'content' => $form( 'contacto', 'Contacto', 'Tu nombre' ) ) ), array(), 'es' );
$out['diff']         = array( $diff['forms']['contacto|es']['copies'], $diff['forms']['contacto|es']['identical'] );

// Without WPML: one bucket, no translations.
$mono                = Form_Migration::plan( array( array( 'id' => 1, 'type' => 'page', 'title' => 'C', 'lang' => '', 'content' => $form( 'contacto', 'Contacto' ) ) ), array(), '' );
$out['mono']         = array( array_keys( $mono['forms'] ), $mono['forms']['contacto|']['original'] );

// --- replace: byte for byte around, two trees, offsets right.
$content             = "<!-- wp:paragraph --><p>Á</p><!-- /wp:paragraph -->\n" . $form( 'a', 'A' ) . "\n<p>medio</p>\n" . $form( 'b', 'B' ) . "\n<p>fin</p>";
$p                   = Form_Migration::plan( array( array( 'id' => 5, 'type' => 'page', 'title' => 'x', 'lang' => '', 'content' => $content ) ), array(), '' );
$out['replaced']     = Form_Migration::replace( $content, $p['posts'][5]['trees'], array( 'a|' => 101, 'b|' => 102 ) );

echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
