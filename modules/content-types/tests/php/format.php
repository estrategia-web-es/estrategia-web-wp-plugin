<?php
/**
 * Called by tests/format.test.mjs with the machine's PHP (no WordPress):
 * runs Field_Format::format() with FAKE lookups, so the .mjs checks the rules
 * of each field type without a site. Input JSON on stdin, output JSON on
 * stdout.
 *
 *     php format.php    stdin: [{field, raw, attribute, args?, locale?, html?}, …]  → [string, …]
 *
 * The fakes (what the assertions in the .mjs rely on):
 *   date    "D:<ymd>|<format>"            number  "N:<n>|<decimals>"
 *   post    7 → {id: 70, title "Ca n'Andreu & Co", url https://x.test/ca/restaurants/ca-nandreu/}
 *           (70 = «the translation»); 8 → null (a draft); with types ≠ gment_restaurant, null
 *   image   5 → {id: 5, url https://x.test/photo-<size>.jpg, alt 'Façana "nova"', title "Foto"}; else null
 *   yes / no "Sí" / "No"
 *
 * How to maintain: a new env key in Bindings::env() gets its fake here; the
 * WordPress functions Field_Format calls are shimmed below (as simply as the
 * test needs: esc_url_raw only checks the scheme).
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = 'default' ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url_raw( $url, $protocols = null ) {
	$protocols ??= array( 'http', 'https', 'mailto', 'tel' );
	$scheme      = strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, $protocols, true ) ? str_replace( ' ', '%20', (string) $url ) : '';
}

require __DIR__ . '/../../includes/class-schema.php';
require __DIR__ . '/../../includes/class-field-format.php';

use EstrategiaWeb\ContentTypes\Field_Format;
use EstrategiaWeb\ContentTypes\Schema;

$cases = json_decode( (string) stream_get_contents( STDIN ), true );
$out   = array();
foreach ( $cases as $case ) {
	$env   = array(
		'locale' => $case['locale'] ?? 'ca',
		'yes'    => 'Sí',
		'no'     => 'No',
		'date'   => static fn( $ymd, $format ) => "D:$ymd|$format",
		'number' => static fn( $n, $decimals ) => "N:$n|$decimals",
		'post'   => static fn( $id, $types ) => 7 === $id && in_array( 'gment_restaurant', $types, true ) ? array( 'id' => 70, 'title' => "Ca n'Andreu & Co", 'url' => 'https://x.test/ca/restaurants/ca-nandreu/' ) : null,
		'image'  => static fn( $id, $size ) => 5 === $id ? array( 'id' => 5, 'url' => "https://x.test/photo-$size.jpg", 'alt' => 'Façana "nova"', 'title' => 'Foto' ) : null,
	);
	$out[] = Field_Format::format( Schema::normalize_field( $case['field'] ), $case['raw'], $case['attribute'] ?? 'content', $case['args'] ?? array(), $env, $case['html'] ?? true );
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
