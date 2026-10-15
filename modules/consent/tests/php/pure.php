<?php
/**
 * Called by modules/consent/tests/cookies-pure.test.mjs with the machine's
 * PHP, WITHOUT WordPress: the cookie registry (suggestions, wildcards,
 * limits) and the category aliases. The bare minimum of WordPress is
 * stubbed below (options in memory, gettext that returns the English).
 * Writes the result as JSON; the assertions are in the .mjs.
 *
 * How to maintain: a new case is a new key here and its assert there.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_VERSION', '0.0.0-test' );
define( 'ESTRATEGIA_WEB_CONSENT_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'MINUTE_IN_SECONDS', 60 );

/** Just enough WP_Error for save(). */
class WP_Error {
	public function __construct( public $code = '', public $message = '', public $data = null ) {}
}

$GLOBALS['pure_options'] = array();
function __( $text, $domain = '' ) {
	return $text;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function do_action() {}
function get_option( $name, $default = false ) {
	return $GLOBALS['pure_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['pure_options'][ $name ] = $value;
	return true;
}
function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function sanitize_textarea_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}

require ESTRATEGIA_WEB_CONSENT_DIR . 'includes/class-consent.php';
require ESTRATEGIA_WEB_CONSENT_DIR . 'includes/class-cookie-registry.php';
require ESTRATEGIA_WEB_CONSENT_DIR . 'includes/class-cookie-table.php';

use EstrategiaWeb\Site\Consent;
use EstrategiaWeb\Site\Cookie_Registry as R;
use EstrategiaWeb\Site\Cookie_Table;

$out = array();

$keys                     = array( '_ga', '_ga_*', '_g*', 'wp-settings-*', 'wp-settings-time-*' );
$out['match']             = array(
	'exact'   => R::match_key( $keys, '_ga' ),
	'prefix'  => R::match_key( $keys, '_ga_ABC123' ),
	'longest' => R::match_key( $keys, 'wp-settings-time-1' ),
	'short'   => R::match_key( $keys, '_gid' ),
	'none'    => R::match_key( $keys, 'PHPSESSID' ),
);
$out['suggest']           = array();
foreach ( array( 'wp-wpml_current_language', '_ga', '_ga_ABC123', '_pk_id.87.a1b2', '_gcl_au', 'estrategia_web_consent', 'inventada_xyz' ) as $name ) {
	$out['suggest'][ $name ] = R::suggest( $name );
}
$out['name_ok']           = array(
	'normal'  => R::name_ok( '_ga' ),
	'pattern' => R::name_ok( '_ga_*' ),
	'value'   => R::name_ok( 'session=abc123' ),
	'space'   => R::name_ok( 'a b' ),
	'long'    => R::name_ok( str_repeat( 'a', 129 ) ),
	'html'    => R::name_ok( '<script>' ),
);

// The detector: two GA measurements under a single pattern, a sneaked-in value, a long name.
R::record_seen( array( '_ga', '_ga_ABC123', 'wp-wpml_current_language', 'x=secret', str_repeat( 'b', 200 ) ), 1000 );
R::record_seen( array( '_ga_ZZZ999', '_ga' ), 2000 );
$out['recorded']          = R::all();

// Row cap.
$GLOBALS['pure_options'] = array();
$many                    = array();
for ( $i = 0; $i < R::MAX_ENTRIES + 25; $i++ ) {
	$many[] = 'madeup_' . $i;
}
$out['full']              = R::record_seen( $many, 3000 );
$out['full_count']        = count( R::all() );

// Manual save: empty fields are filled from the suggestion; a bad name is not saved.
$GLOBALS['pure_options'] = array();
$out['save_manual']       = R::save( 'NID', array( 'category' => 'maps', 'provider' => '', 'purpose' => 'Google Maps', 'duration' => '' ) );
$out['save_bad']          = R::save( 'bad name', array() ) instanceof WP_Error;

$out['duration']          = array(
	'years'   => Cookie_Table::duration_parts( '2 years' ),
	'one'     => Cookie_Table::duration_parts( '1 Day' ),
	'session' => Cookie_Table::duration_parts( 'Session' ),
	'free'    => Cookie_Table::duration_parts( 'Until the browser closes' ),
);
$out['normalize']         = array( Consent::normalize( 'analytics' ), Consent::normalize( 'Advertising' ), Consent::normalize( 'ads' ), Consent::normalize( 'maps' ) );
$out['display']           = Consent::display_order( array( 'maps', 'marketing', 'statistics', 'preferences' ) );
$out['known_order']       = array_keys( Consent::known_categories() );
$ocd                      = R::ocd();
$out['ocd']               = array(
	'license'     => $ocd['license'] ?? null,
	'attribution' => $ocd['attribution'] ?? null,
	'count'       => count( $ocd['cookies'] ),
);

echo json_encode( $out );
