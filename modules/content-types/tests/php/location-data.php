<?php
/**
 * Called by tests/location-data.test.mjs with the machine's PHP (no
 * WordPress): runs the pure functions of includes/class-location-data.php.
 * Input JSON on stdin, output JSON on stdout.
 *
 *     php location-data.php    stdin: [{fn, args: […]}, …]  → [result, …]
 *
 * A «format» argument is a language code («es», «en», «ca», «fr»): the
 * default format of that language (Location_Data::default_format()).
 *
 * How to maintain: a new public function of Location_Data gets its case here.
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../includes/class-location-data.php';

use EstrategiaWeb\ContentTypes\Location_Data;

$cases = json_decode( (string) stream_get_contents( STDIN ), true );
$out   = array();
foreach ( $cases as $case ) {
	$a     = $case['args'];
	$out[] = match ( $case['fn'] ) {
		'address_text'        => Location_Data::address_text( $a[0], Location_Data::default_format( $a[1] ) ),
		'hours_text'          => Location_Data::hours_text( $a[0], Location_Data::default_format( $a[1] ) ),
		'street_line'         => Location_Data::street_line( $a[0] ),
		'maps_url'            => Location_Data::maps_url( $a[0] ),
		'embed_url'           => Location_Data::embed_url( $a[0] ),
		'query_from_maps_url' => Location_Data::query_from_maps_url( $a[0] ),
		'query_from_embed'    => Location_Data::query_from_embed( $a[0] ),
		'parse_address'       => Location_Data::parse_address( $a[0] ),
		'parse_hours'         => Location_Data::parse_hours( $a[0] ),
		'time'                => Location_Data::time( $a[0], $a[1] ),
	};
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
