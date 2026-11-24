<?php
/**
 * Called by tests/meta-box.test.mjs with the machine's PHP (no WordPress):
 * runs Fields_Panel::save_meta_box() — the classic meta box's save, the same
 * for the classic screen and the main-area box — with the real Loader
 * reading examples/ (gment_restaurant: a textarea, a tel, a select and a url;
 * llest_esquela: the repeater). Output JSON on stdout: what was stored.
 *
 *     php meta-box.php    stdin: {type, post: {field: value, …}}  → {field: stored, …}
 *
 * «post» is what the browser sends; it reaches $_POST SLASHED, as
 * WordPress's wp_magic_quotes() leaves it. The shims, where it matters:
 * update_post_meta() UNSLASHES what it is given, as WordPress does (the trap
 * of class-repeater.php); the nonce, the capability and the autosave checks
 * say yes; the sanitizers are as simple as the cases need.
 *
 * How to maintain: a case is a new «post» in the .mjs; a field type that
 * calls a WordPress function not shimmed here fails with its name — add the
 * shim, as simply as that case needs.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_CONTENT_TYPES_DIR', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['meta'] = array();

// phpcs:disable -- test shims.
class WP_Post {
	public $ID;
	public $post_type;
	public function __construct( int $id, string $type ) {
		$this->ID        = $id;
		$this->post_type = $type;
	}
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function determine_locale() {
	return 'ca';
}
function get_stylesheet_directory() {
	return __DIR__ . '/no-theme';
}
function get_template_directory() {
	return __DIR__ . '/no-theme';
}
function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}
function add_action( ...$args ): void {
}
function add_filter( ...$args ): void {
}
function do_action( ...$args ): void {
}
function apply_filters( string $hook, $value, ...$args ) {
	return 'estrategia_web_content_types_dirs' === $hook ? array( dirname( __DIR__, 2 ) . '/examples' ) : $value;
}
function get_posts( $args = array() ) {
	return array(); // No definitions in the database (class-store.php).
}
function wp_verify_nonce( $nonce, $action ) {
	return 'nonce' === $nonce ? 1 : false;
}
function wp_is_post_autosave( $id ) {
	return false;
}
function wp_is_post_revision( $id ) {
	return false;
}
function use_block_editor_for_post_type( $type ) {
	return false; // The classic screen.
}
function current_user_can( ...$args ) {
	return true;
}
function wp_slash( $value ) {
	return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
}
function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}
function map_deep( $value, $callback ) {
	return is_array( $value ) ? array_map( static fn( $v ) => map_deep( $v, $callback ), $value ) : $callback( $value );
}
function sanitize_text_field( $s ) {
	return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_textarea_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function esc_url_raw( $url, $protocols = null ) {
	$scheme = strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, $protocols ?? array( 'http', 'https' ), true ) ? (string) $url : '';
}
function is_email( $email ) {
	return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email ) ? $email : false;
}
function sanitize_email( $email ) {
	return strtolower( trim( (string) $email ) );
}
function get_post_meta( int $id, string $key, bool $single = false ) {
	return $GLOBALS['meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( int $id, string $key, $value ): void {
	// As WordPress does: update_post_meta() UNSLASHES (wp_unslash on the value).
	$GLOBALS['meta'][ $id ][ $key ] = wp_unslash( $value );
}
// phpcs:enable

require __DIR__ . '/../../includes/class-schema.php';
require __DIR__ . '/../../includes/class-store.php';
require __DIR__ . '/../../includes/class-templates.php';
require __DIR__ . '/../../includes/class-loader.php';
require __DIR__ . '/../../includes/class-sanitize.php';
require __DIR__ . '/../../includes/class-registrar.php';
require __DIR__ . '/../../includes/class-wpml.php';
require __DIR__ . '/../../includes/class-fields-panel.php';

use EstrategiaWeb\ContentTypes\Fields_Panel;
use EstrategiaWeb\ContentTypes\Registrar;

$in = json_decode( (string) stream_get_contents( STDIN ), true );

// The type as registered (Registrar::register() fills this on «init»).
$registered = new ReflectionProperty( Registrar::class, 'registered' );
$registered->setAccessible( true );
$registered->setValue( null, array( $in['type'] ) );

$_POST = wp_slash(
	array(
		'estrategia_web_ct_nonce' => 'nonce',
		'estrategia_web_ct'       => $in['post'],
	)
);
Fields_Panel::save_meta_box( 10, new WP_Post( 10, $in['type'] ) );
echo json_encode( (object) ( $GLOBALS['meta'][10] ?? array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
