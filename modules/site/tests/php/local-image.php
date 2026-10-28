<?php
/**
 * Called by tests/local-image.test.mjs with the machine's PHP (no WordPress).
 * Runs Sanitize::local_image() (modules/content-types) over the cases of
 * argv[1] (a JSON list of strings) and prints the results as a JSON list.
 *
 * Only three WordPress functions are stood in for, and only as far as the
 * rule needs them: wp_parse_url() is PHP's parse_url() (what core's does for
 * a full URL), home_url() is a fixed site, and esc_url_raw() returns the URL
 * (the cases that reach it are already clean http(s) URLs of the site).
 *
 * How to maintain: if local_image() starts using another WordPress function,
 * this file fails with «Call to undefined function»: add it here, as small
 * as the rule needs. A new case goes in the .mjs, not here.
 */

define( 'ABSPATH', __DIR__ . '/' );
const ESTRATEGIA_WEB_TEST_HOME = 'http://127.0.0.1:9404';
function wp_parse_url( $url ) {
	return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}
function home_url() {
	return ESTRATEGIA_WEB_TEST_HOME;
}
function esc_url_raw( $url ) {
	return $url;
}
require __DIR__ . '/../../../content-types/includes/class-sanitize.php';

echo json_encode( array_map( array( \EstrategiaWeb\ContentTypes\Sanitize::class, 'local_image' ), json_decode( $argv[1], true ) ) );
