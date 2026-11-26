<?php
/**
 * Called by modules/site-health/tests/judge.test.mjs with the machine's PHP.
 * Reads from stdin a JSON list of {check, data}, runs that check's judge()
 * with that data and writes the verdicts (as judge() returns them, not yet
 * formatted) as a JSON list in the same order. The cases and the assertions
 * are in the .mjs.
 */

require __DIR__ . '/stubs.php';

use EstrategiaWeb\SiteHealth\Registry;

$checks = Registry::checks();
$out    = array();
foreach ( (array) json_decode( (string) stream_get_contents( STDIN ), true ) as $case ) {
	$check = $checks[ $case['check'] ] ?? null;
	$out[] = $check ? ( $check['judge'] )( (array) $case['data'] ) : array( 'error' => 'no check ' . $case['check'] );
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE );
