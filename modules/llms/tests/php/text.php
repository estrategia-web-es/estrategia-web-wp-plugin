<?php
/**
 * Called by tests/text.test.mjs with the machine's PHP (no WordPress): runs
 * Text (the pure part of llms.txt) on what comes on stdin.
 *
 *     php text.php    stdin: [{fn: "language_of"|"body"|"draft"|"clean"|"merge", args: […]}, …]  → [result, …]
 *
 * How to maintain: a new public function of Text gets its case here.
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../includes/class-text.php';

use EstrategiaWeb\Llms\Text;

$cases = json_decode( (string) stream_get_contents( STDIN ), true );
$out   = array();
foreach ( $cases as $case ) {
	$a     = $case['args'] ?? array();
	$out[] = match ( $case['fn'] ) {
		'language_of' => Text::language_of( $a[0], $a[1], $a[2] ),
		'body'        => Text::body( $a[0], $a[1], $a[2] ),
		'draft'       => Text::draft( $a[0] ),
		'clean'       => Text::clean( $a[0] ),
		// {} stays an object on the way back.
		'merge'       => (object) Text::merge( $a[0], $a[1] ),
	};
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
