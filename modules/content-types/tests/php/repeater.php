<?php
/**
 * Called by tests/repeater.test.mjs with the machine's PHP (no WordPress):
 * runs the pure functions of includes/class-repeater.php. Input JSON on
 * stdin, output JSON on stdout.
 *
 *     php repeater.php    stdin: [{fn, args: […]}, …]  → [result, …]
 *
 *   fn: sanitize (value, field) · rows (value, field) · incomplete (value, field)
 *       merge (original, translation, field) · time (value) · format (format, values)
 *       schema (field) · mixed (field)
 *   «field» is a RAW field of a definition: it goes through
 *   Schema::normalize_field() first, as WordPress gets it.
 *
 * The WordPress sanitizers sub_value() calls are shimmed below, as simply as
 * the cases need: sanitize_text_field strips tags and folds whitespace,
 * sanitize_textarea_field strips tags, esc_url_raw only checks the scheme,
 * is_email wants an «@» and a dot after it.
 *
 * How to maintain: a new public function of Repeater gets its case here.
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = 'default' ) {
	return $text;
}
function sanitize_text_field( $s ) {
	return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_textarea_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function esc_url_raw( $url, $protocols = null ) {
	$protocols ??= array( 'http', 'https' );
	$scheme      = strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, $protocols, true ) ? str_replace( ' ', '%20', (string) $url ) : '';
}
function is_email( $email ) {
	return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email ) ? $email : false;
}
function sanitize_email( $email ) {
	return strtolower( trim( (string) $email ) );
}

require __DIR__ . '/../../includes/class-schema.php';
require __DIR__ . '/../../includes/class-suggestions.php';

use EstrategiaWeb\ContentTypes\Repeater;
use EstrategiaWeb\ContentTypes\Schema;
use EstrategiaWeb\ContentTypes\Suggestions;

$cases = json_decode( (string) stream_get_contents( STDIN ), true );
$out   = array();
foreach ( $cases as $case ) {
	$a     = $case['args'];
	$f     = static fn( $raw ) => Schema::normalize_field( $raw );
	$out[] = match ( $case['fn'] ) {
		'sanitize'   => Repeater::sanitize( $a[0], $f( $a[1] ) ),
		'rows'       => Repeater::rows( $a[0], $f( $a[1] ) ),
		'incomplete' => Repeater::incomplete( $a[0], $f( $a[1] ) ),
		'merge'      => Repeater::merge_translation( $a[0], $a[1], $f( $a[2] ) ),
		'time'       => Repeater::time( $a[0] ),
		'format'     => Repeater::apply_format( $a[0], $a[1] ),
		'schema'     => Repeater::rest_schema( $f( $a[0] ) ),
		'mixed'      => Repeater::is_mixed( $f( $a[0] ) ),
		'rank'       => Suggestions::rank( $a[0] ), // 2.15.0: the suggestions, most used first.
		'suggest'    => Schema::normalize_field( $a[0] )['fields'][0]['suggest'] ?? null,
	};
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
