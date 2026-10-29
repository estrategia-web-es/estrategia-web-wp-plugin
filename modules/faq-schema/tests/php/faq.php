<?php
/**
 * Called by tests/faq.test.mjs with the machine's PHP (no WordPress): runs
 * Faq (the pure part) on what comes on stdin.
 *
 *     php faq.php    stdin: [{fn: "questions"|"node"|"merge", args: […]}, …]  → [result, …]
 *
 * «questions» takes [blocks, patterns]: patterns is {ref: blocks}, the synced
 * patterns the callable would load. How to maintain: a new public function
 * of Faq gets its case here.
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../includes/class-faq.php';

use EstrategiaWeb\FaqSchema\Faq;

$cases = json_decode( (string) stream_get_contents( STDIN ), true );
$out   = array();
foreach ( $cases as $case ) {
	$a     = $case['args'] ?? array();
	$out[] = match ( $case['fn'] ) {
		'questions' => Faq::questions( $a[0], static fn( int $ref ): ?array => $a[1][ (string) $ref ] ?? null ),
		'node'      => Faq::node( $a[0], $a[1] ),
		// The host comes as a JSON object (keyed, Rank Math) or a list (Yoast); {} stays an object on the way back.
		'merge'     => (object) array( 'graph' => Faq::merge( $a[0], $a[1] ) ),
	};
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
