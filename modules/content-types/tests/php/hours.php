<?php
/**
 * Called by tests/hours.test.mjs with the machine's PHP (no WordPress): runs
 * the pure functions of includes/class-hours.php. Input JSON on stdin,
 * output JSON on stdout.
 *
 *     php hours.php    stdin: [{fn: "sanitize"|"specification"|"summary"|"error"|"to_line"|"from_line", args: […]}, …]  → [result, …]
 *
 * summary() gets the day names Mo Tu We Th Fr Sa Su and a time formatter
 * that drops the hour's leading zero («08:00» → «8:00»), like a site with
 * time_format «G:i».
 *
 * How to maintain: a new public function of Hours gets its case here.
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../includes/class-hours.php';

use EstrategiaWeb\ContentTypes\Hours;

$cases = json_decode( (string) stream_get_contents( STDIN ), true );
$out   = array();
foreach ( $cases as $case ) {
	$args  = $case['args'];
	$out[] = match ( $case['fn'] ) {
		'sanitize'      => Hours::sanitize( $args[0] ),
		'specification' => Hours::specification( $args[0] ),
		'summary'       => Hours::summary( $args[0], array( 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' ), static fn( $t ) => (int) substr( $t, 0, 2 ) . substr( $t, 2 ), $args[1] ?? ', ' ),
		'error'         => Hours::error( $args[0] ),
		'to_line'       => Hours::to_line( $args[0], $args[1] ),
		'from_line'     => Hours::from_line( $args[0] ),
	};
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
