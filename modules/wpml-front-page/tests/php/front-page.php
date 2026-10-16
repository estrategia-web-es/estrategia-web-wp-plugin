<?php
/**
 * Called by modules/wpml-front-page/tests/front-page-pure.test.mjs with the
 * machine's PHP (no WordPress). It stubs the few WordPress functions the
 * module uses and records the filters boot() adds, then runs them.
 *
 * The case it pins (Lavandería, 15-10-2026): boot() runs at plugins_loaded,
 * BEFORE WordPress knows the request is REST (REST_REQUEST is defined later,
 * at parse_request). So a REST request got the filter, and
 * get_option( 'page_on_front' ) came back as an int there instead of the
 * string stored in the database. A new case is a new key here and its
 * assert there.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['stub'] = array(
		'filters' => array(),
		'admin'   => false,
		'ajax'    => false,
		'rest'    => false,
	);

	function add_filter( string $hook, $cb ) {
		$GLOBALS['stub']['filters'][ $hook ][] = $cb;
		return true;
	}
	function has_filter( string $hook ) {
		return 'wpml_object_id' === $hook || ! empty( $GLOBALS['stub']['filters'][ $hook ] );
	}
	function apply_filters( string $hook, $value, ...$args ) {
		if ( 'wpml_object_id' === $hook ) {
			return 109 === (int) $value ? '120' : $value; // English front page: 120.
		}
		if ( 'estrategia_web_site_wpml_front_page' === $hook ) {
			return $value;
		}
		foreach ( $GLOBALS['stub']['filters'][ $hook ] ?? array() as $cb ) {
			$value = $cb( $value );
		}
		return $value;
	}
	function is_admin() {
		return $GLOBALS['stub']['admin'];
	}
	function wp_doing_ajax() {
		return $GLOBALS['stub']['ajax'];
	}
	function wp_is_serving_rest_request() {
		return $GLOBALS['stub']['rest'];
	}

	require __DIR__ . '/../../includes/class-wpml-front-page.php';

	// boot() as at plugins_loaded: nothing is known about the request yet.
	\EstrategiaWeb\Site\Wpml_Front_Page::boot();
	$public = apply_filters( 'option_page_on_front', '109' );

	// Then WordPress finds out it is a REST request (parse_request).
	$GLOBALS['stub']['rest'] = true;
	$rest = apply_filters( 'option_page_on_front', '109' );

	// A page with no front page set: untouched, whatever its type.
	$GLOBALS['stub']['rest'] = false;
	$none = apply_filters( 'option_page_on_front', '0' );

	echo json_encode(
		array(
			'public' => $public,
			'rest'   => $rest,
			'none'   => $none,
		)
	);
}
