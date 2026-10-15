<?php
/**
 * Called by tests/schema.test.mjs with the machine's PHP (no WordPress): loads
 * Schema and answers one question per run. Input JSON on stdin, output JSON
 * on stdout. The assertions are in the .mjs.
 *
 *     php schema.php validate     stdin: a definition        → its errors
 *     php schema.php check-all    stdin: {file: definition}  → cross-file errors
 *     php schema.php normalize    stdin: a definition        → normalized
 *     php schema.php text         stdin: {text, locale}      → the picked label
 *     php schema.php lists        (no stdin)                 → the PHP lists, for the drift check
 *
 * How to maintain: a new mode is a new `case`; Schema needs nothing from
 * WordPress but __(), shimmed below. If it ever needs more, shim it here.
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = 'default' ) {
	return $text;
}

require __DIR__ . '/../../includes/class-schema.php';

use EstrategiaWeb\ContentTypes\Schema;

$in = json_decode( (string) stream_get_contents( STDIN ), true );

$out = match ( $argv[1] ?? '' ) {
	'validate'  => Schema::validate( $in ),
	'check-all' => (object) Schema::check_all( $in ),
	'normalize' => Schema::normalize( $in ),
	'text'      => Schema::text( $in['text'], $in['locale'] ),
	'lists'     => array(
		'type_keys'     => Schema::TYPE_KEYS,
		'taxonomy_keys' => Schema::TAXONOMY_KEYS,
		'field_keys'    => Schema::FIELD_KEYS,
		'label_keys'    => Schema::LABEL_KEYS,
		'field_types'   => Schema::FIELD_TYPES,
		'supports'      => Schema::SUPPORTS,
		'field_wpml'    => Schema::FIELD_WPML,
		'type_wpml'     => Schema::TYPE_WPML,
		'reserved'      => Schema::RESERVED_PREFIXES,
		'max_type'      => Schema::MAX_TYPE,
		'max_taxonomy'  => Schema::MAX_TAXONOMY,
		'max_field'     => Schema::MAX_FIELD,
	),
	default     => array( 'error' => 'unknown mode' ),
};
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
