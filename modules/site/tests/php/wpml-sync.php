<?php
/**
 * Lo llama tests/wpml-sync.test.mjs con el PHP de la máquina (sin WordPress
 * ni WPML): carga Meta_Panel con un DOBLE de WPML y apunta qué
 * wpml_sync_custom_field se piden al escribir cada meta. Las aserciones están
 * en el .mjs.
 *
 *     php wpml-sync.php wpml       con WPML (ICL_SITEPRESS_VERSION definida)
 *     php wpml-sync.php sin-wpml   sin WPML: no se pide nada
 *
 * El doble hace lo mismo que WPML en lo que importa aquí: su
 * wpml_sync_custom_field ESCRIBE la meta en cada traducción con
 * update_post_meta, que vuelve a disparar updated_post_meta. Así la prueba ve
 * también que la copia no se llama a sí misma.
 *
 * Cómo se mantiene: un caso nuevo es una clave nueva en $out y su assert allí.
 * Las entradas de mentira están en $GLOBALS['posts'] (id => tipo) y
 * $GLOBALS['original'] (traducción => original).
 */

define( 'ABSPATH', __DIR__ . '/' );
if ( 'wpml' === ( $argv[1] ?? '' ) ) {
	define( 'ICL_SITEPRESS_VERSION', 'doble' );
}

$GLOBALS['hooks']    = array();
$GLOBALS['syncs']    = array();
$GLOBALS['meta']     = array();
// Los tipos con su nombre REGISTRADO (con prefijo, 1.2.0), como los ve get_post_type().
require __DIR__ . '/../../includes/class-type-names.php';
$ew_type             = array( \EstrategiaWeb\Site\Type_Names::class, 'name' );
$GLOBALS['posts']    = array( 10 => $ew_type( 'location' ), 11 => $ew_type( 'location' ), 20 => $ew_type( 'client' ), 21 => $ew_type( 'client' ), 30 => $ew_type( 'testimonial' ), 31 => $ew_type( 'testimonial' ), 40 => 'page', 50 => $ew_type( 'location' ) );
$GLOBALS['original'] = array( 11 => 10, 21 => 20, 31 => 30 ); // 50: sede sin traducir.

function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	$GLOBALS['hooks'][ $hook ][] = array( $callback, $args );
}
function add_filter(): void {}
function do_action( string $hook, ...$args ): void {
	foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as list( $callback, $count ) ) {
		$callback( ...array_slice( $args, 0, $count ) );
	}
}
function apply_filters( string $hook, $value, ...$args ) {
	if ( 'wpml_element_language_details' === $hook ) {
		$id = $args[0]['element_id'];
		return isset( $GLOBALS['posts'][ $id ] ) ? (object) array( 'source_language_code' => isset( $GLOBALS['original'][ $id ] ) ? 'es' : null ) : null;
	}
	return $value;
}
function get_post_type( $id ) {
	return $GLOBALS['posts'][ (int) $id ] ?? false;
}
function update_post_meta( int $id, string $key, $value ): void {
	$hook                          = isset( $GLOBALS['meta'][ $id ][ $key ] ) ? 'updated_post_meta' : 'added_post_meta';
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	do_action( $hook, 1, $id, $key, $value );
}
function delete_post_meta( int $id, string $key ): void {
	unset( $GLOBALS['meta'][ $id ][ $key ] );
	do_action( 'deleted_post_meta', array( 1 ), $id, $key, '' );
}

// El doble de WPML: copia la meta del original a sus traducciones.
add_action(
	'wpml_sync_custom_field',
	static function ( $post_id, $key ) {
		$GLOBALS['syncs'][] = "$post_id:$key";
		foreach ( array_keys( $GLOBALS['original'], (int) $post_id, true ) as $translation ) {
			if ( isset( $GLOBALS['meta'][ $post_id ][ $key ] ) ) {
				update_post_meta( $translation, $key, $GLOBALS['meta'][ $post_id ][ $key ] );
			} else {
				delete_post_meta( $translation, $key );
			}
		}
	},
	10,
	2
);

require __DIR__ . '/../../includes/class-meta-panel.php';
\EstrategiaWeb\Site\Meta_Panel::boot();

/** Escribe y devuelve las copias que se pidieron durante esa escritura. */
function step( callable $write ): array {
	$GLOBALS['syncs'] = array();
	$write();
	return $GLOBALS['syncs'];
}

$out = array(
	'phone_new'         => step( static fn() => update_post_meta( 10, 'estrategia_web_location_phone', '+34 600 000 001' ) ),
	'phone_changed'     => step( static fn() => update_post_meta( 10, 'estrategia_web_location_phone', '+34 600 000 002' ) ),
	'phone_in_en'       => $GLOBALS['meta'][11]['estrategia_web_location_phone'] ?? null,
	'map_embed'         => step( static fn() => update_post_meta( 10, 'estrategia_web_location_map_embed', 'https://maps.example/x' ) ),
	'address_translate' => step( static fn() => update_post_meta( 10, 'estrategia_web_location_address', 'Nave 14' ) ),
	'on_translation'    => step( static fn() => update_post_meta( 11, 'estrategia_web_location_phone', '+44 1' ) ),
	'phone_after_trans' => $GLOBALS['meta'][10]['estrategia_web_location_phone'] ?? null,
	'client_url'        => step( static fn() => update_post_meta( 20, 'client_url', 'https://cliente.test/' ) ),
	'client_fit'        => step( static fn() => update_post_meta( 20, 'estrategia_web_client_image_fit', 'contain' ) ),
	'client_business'   => step( static fn() => update_post_meta( 20, 'estrategia_web_client_business_type', 'hotel' ) ),
	'testimonial_stars' => step( static fn() => update_post_meta( 30, 'estrategia_web_testimonial_rating', 5 ) ),
	'testimonial_role'  => step( static fn() => update_post_meta( 30, 'testimonial_role', 'Gerente' ) ),
	'page_same_key'     => step( static fn() => update_post_meta( 40, 'estrategia_web_location_phone', '1' ) ),
	'untranslated'      => step( static fn() => update_post_meta( 50, 'estrategia_web_location_phone', '1' ) ),
	'phone_deleted'     => step( static fn() => delete_post_meta( 10, 'estrategia_web_location_phone' ) ),
	'phone_en_deleted'  => array_key_exists( 'estrategia_web_location_phone', $GLOBALS['meta'][11] ?? array() ),
);

echo json_encode( $out, JSON_UNESCAPED_UNICODE );
