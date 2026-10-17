<?php
/**
 * Called by tests/constructor-pure.test.mjs with the machine's PHP (no
 * WordPress): the pure parts of the constructor. Input JSON on stdin, output
 * JSON on stdout; the assertions are in the .mjs.
 *
 *     php constructor.php validate    stdin: {def}                      → errors (with the template def.template names, from templates/)
 *     php constructor.php templates   stdin: {locale}                   → {key: {errors, slug, taxonomy_slugs}} every template, materialized
 *     php constructor.php changes     stdin: {old|null, new, counts}    → Changes::check() on the NORMALIZED pair
 *     php constructor.php redirects   stdin: {paths, from, to}          → Changes::redirect_paths()
 *     php constructor.php lists       (no stdin)                        → Schema lists for the drift check
 *
 * How to maintain: a new mode is a new `case`. Only __() is shimmed: if a
 * class here ever calls more of WordPress, shim it here (and keep it pure).
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_CONTENT_TYPES_DIR', dirname( __DIR__, 2 ) . '/' );

function __( $text, $domain = 'default' ) {
	return $text;
}

require __DIR__ . '/../../includes/class-schema.php';
require __DIR__ . '/../../includes/class-templates.php';
require __DIR__ . '/../../includes/class-changes.php';

use EstrategiaWeb\ContentTypes\Changes;
use EstrategiaWeb\ContentTypes\Schema;
use EstrategiaWeb\ContentTypes\Templates;

$in = json_decode( (string) stream_get_contents( STDIN ), true );

switch ( $argv[1] ?? '' ) {
	case 'validate':
		// The template the definition names, as Loader and the REST route look it up.
		$out = Schema::validate( $in['def'], Templates::for_definition( $in['def'] ) );
		break;
	case 'templates':
		$out = array();
		foreach ( Templates::all() as $key => $raw ) {
			$def         = Templates::materialize( $raw, $in['locale'] );
			$out[ $key ] = array(
				'errors'         => Schema::validate( $def, $def ),
				'name'           => $def['name'],
				'slug'           => $def['slug'],
				'taxonomy_slugs' => array_map( static fn( $t ) => is_array( $t ) ? $t['slug'] : $t, $def['taxonomies'] ?? array() ),
				'normalized'     => Schema::validate( $def, $def ) ? null : Schema::normalize( $def ),
			);
		}
		$out = (object) $out;
		break;
	case 'changes':
		$out = Changes::check( $in['old'] ? Schema::normalize( $in['old'] ) : null, Schema::normalize( $in['new'] ), $in['counts'] ?? array() );
		break;
	case 'redirects':
		$out = (object) Changes::redirect_paths( $in['paths'], $in['from'], $in['to'] );
		break;
	case 'lists':
		$out = array( 'label_keys' => Schema::LABEL_KEYS, 'type_label_keys' => Schema::TYPE_LABEL_KEYS, 'field_types' => Schema::FIELD_TYPES );
		break;
	default:
		$out = array( 'error' => 'unknown mode' );
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
