<?php
/**
 * The few WordPress functions the site-health module calls outside gather(),
 * for the level 1 tests (judge.php, registry.php). No WordPress here: the
 * translation functions return the English text, the URL ones a fixed base,
 * and the escaping ones do what WordPress' do for the characters the tests
 * use (& < > " ').
 *
 * How to maintain: a check's judge() that calls a new WordPress function
 * needs its stub here — or, better, plain PHP in judge() (it is meant to be
 * pure; see includes/class-registry.php).
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['stub'] = array(
	'locale'     => 'es_ES',
	'post_types' => array(),
	'filters'    => array(),
);

function __( $text, $domain = null ) {
	return $text;
}
function _n( $single, $plural, $n, $domain = null ) {
	return 1 === (int) $n ? $single : $plural;
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function admin_url( $path = '' ) {
	return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' );
}
function rest_url( $path = '' ) {
	return 'https://example.com/wp-json/' . ltrim( (string) $path, '/' );
}
function get_locale() {
	return $GLOBALS['stub']['locale'];
}
function post_type_exists( $type ) {
	return in_array( $type, $GLOBALS['stub']['post_types'], true );
}
function add_filter( $hook, $cb, $prio = 10 ) {
	$GLOBALS['stub']['filters'][ $hook ] = $cb;
}
function add_action( $hook, $cb, $prio = 10 ) {
	add_filter( $hook, $cb, $prio );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-registry.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-probe.php';
