<?php
/**
 * Called by tests/graph.test.mjs with the machine's PHP (no WordPress): runs
 * the pure parts of the module (Graph, Types) on what comes on stdin.
 *
 *     php graph.php    stdin: [{fn: "organization"|"local_business"|"merge"|"standalone"|"types", args: […]}, …]  → [result, …]
 *
 * How to maintain: a new public function of Graph or Types gets its case here.
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../../content-types/includes/class-hours.php';
require __DIR__ . '/../../includes/class-types.php';
require __DIR__ . '/../../includes/class-graph.php';

use EstrategiaWeb\LocalBusiness\Graph;
use EstrategiaWeb\LocalBusiness\Types;

$cases = json_decode( (string) stream_get_contents( STDIN ), true );
$out   = array();
foreach ( $cases as $case ) {
	$a     = $case['args'] ?? array();
	$out[] = match ( $case['fn'] ) {
		'organization'   => Graph::organization( $a[0], $a[1] ?? array() ),
		'local_business' => Graph::local_business( $a[0] ),
		// The host comes as a JSON object (keyed, Rank Math) or a list (Yoast); {} stays an object on the way back.
		'merge'          => (object) array( 'graph' => Graph::merge( $a[0], $a[1], $a[2] ) ),
		'standalone'     => Graph::standalone( $a[0], $a[1] ),
		'types'          => Types::ALL,
	};
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
