<?php
/**
 * Lo llama modules/consent/tests/cookies-pure.test.mjs con el PHP de la
 * máquina, SIN WordPress: el registro de cookies (propuestas, comodines,
 * límites) y los alias de las categorías. Lo mínimo de WordPress va
 * simulado aquí abajo (opciones en memoria, gettext que devuelve el inglés).
 * Escribe el resultado en JSON; las aserciones están en el .mjs.
 *
 * Cómo se mantiene: un caso nuevo es una clave nueva aquí y su assert allí.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_VERSION', '0.0.0-test' );
define( 'ESTRATEGIA_WEB_CONSENT_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'MINUTE_IN_SECONDS', 60 );

/** Lo justo de WP_Error para save(). */
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

// El detector: dos medidas de GA en un solo patrón, un valor colado, un nombre largo.
R::record_seen( array( '_ga', '_ga_ABC123', 'wp-wpml_current_language', 'x=secreto', str_repeat( 'b', 200 ) ), 1000 );
R::record_seen( array( '_ga_ZZZ999', '_ga' ), 2000 );
$out['recorded']          = R::all();

// Tope de filas.
$GLOBALS['pure_options'] = array();
$many                    = array();
for ( $i = 0; $i < R::MAX_ENTRIES + 25; $i++ ) {
	$many[] = 'inventada_' . $i;
}
$out['full']              = R::record_seen( $many, 3000 );
$out['full_count']        = count( R::all() );

// Guardar a mano: lo vacío se rellena con la propuesta; un nombre malo no.
$GLOBALS['pure_options'] = array();
$out['save_manual']       = R::save( 'NID', array( 'category' => 'maps', 'provider' => '', 'purpose' => 'Mapas de Google', 'duration' => '' ) );
$out['save_bad']          = R::save( 'mal nombre', array() ) instanceof WP_Error;

$out['duration']          = array(
	'years'   => Cookie_Table::duration_parts( '2 years' ),
	'one'     => Cookie_Table::duration_parts( '1 Day' ),
	'session' => Cookie_Table::duration_parts( 'Session' ),
	'free'    => Cookie_Table::duration_parts( 'Hasta cerrar el navegador' ),
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
