<?php
/**
 * Fuente de Block Bindings `armazon-site/location`: un bloque enlazado a un
 * dato de UNA sede (tipo `location`). Entró en 0.2.0.
 *
 * Dos formas de decir qué sede, por los args del enlace:
 *
 *   {"slug": "maioris", "key": "location_phone"}   esa sede, desde cualquier página
 *   {"key": "location_address"}                    la sede del CONTEXTO: dentro de
 *                                                  una Consulta de sedes, la de cada fila
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"armazon-site/location","args":{"slug":"maioris","key":"location_address"}}}}} -->
 *   <p></p>
 *   <!-- /wp:paragraph -->
 *
 * Claves: `title`, los campos location_* del tipo (Content_Types) y las
 * derivadas `location_phone_href` (tel:) y `location_email_href` (mailto:).
 * El texto y las URL se pintan igual que en la fuente de la empresa
 * (Bindings::format): saltos a <br> en texto, esc_url_raw con tel/mailto/http(s)
 * en url/href.
 *
 * Por qué existe: una página enseña el teléfono y el mapa de UNA sede concreta
 * (la de Maioris en la página de Autoservicio). Antes solo se podía con una
 * Consulta buscando por título, que se rompe al renombrar la sede.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien añada un campo a `location` en Content_Types, en el
 *   mismo commit. Los campos meta entran SOLOS (salen de
 *   Content_Types::meta_fields()); una derivada nueva va en DERIVED, value() y
 *   labels(), con su fila en el README y su caso en tests/location.test.mjs.
 * - El identificador estable es el SLUG de la entrada (post_name), no el
 *   título ni el id: el título cambia y el id cambia al migrar de servidor.
 *   Caso frontera: renombrar la sede en el editor NO cambia el slug, pero
 *   editar a mano su «enlace permanente» sí, y los bloques que la nombran se
 *   quedan vacíos. Es lo que hay que mirar si una página pierde el teléfono.
 * - Solo sedes PUBLICADAS. Una en borrador, privada o en la papelera devuelve
 *   '' (en la web no se ve nada; en el editor sale el nombre del dato entre
 *   corchetes). Y si el tipo `location` no está activado, también ''.
 * - La trampa: el contexto postId existe SIEMPRE, también fuera de una
 *   Consulta (es la página que se pinta). Por eso sin slug se comprueba que
 *   ese post sea de verdad una `location`: si no, un párrafo suelto con
 *   {"key":"title"} enseñaría el título de la página.
 * - Y la otra: uses_context (postId, postType) tiene que estar en el registro
 *   de PHP Y en el de assets/editor.js; si falta en el de JS, el núcleo lo
 *   deja en [null] al fusionar y en el editor no llega el contexto.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Location {

	public const SOURCE = 'armazon-site/location';

	public const TYPE = 'location';

	public const DERIVED = array( 'location_phone_href', 'location_email_href' );

	public static function register(): void {
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Location details', 'armazon-site' ),
				'get_value_callback' => array( self::class, 'get_value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * @param array     $args           {"key": "…"} o {"slug": "…", "key": "…"}.
	 * @param \WP_Block $block_instance El bloque que se pinta (de aquí sale el contexto).
	 * @param string    $attribute_name El atributo enlazado (content, url…).
	 */
	public static function get_value( array $args, $block_instance, string $attribute_name ): ?string {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';
		if ( '' === $key ) {
			return null;
		}
		$slug    = isset( $args['slug'] ) ? (string) $args['slug'] : '';
		$context = ( is_object( $block_instance ) && isset( $block_instance->context ) ) ? (array) $block_instance->context : array();
		$post    = '' !== $slug ? self::by_slug( $slug ) : self::by_id( (int) ( $context['postId'] ?? 0 ) );
		return Bindings::format( $post ? self::value( $post, $key ) : '', $attribute_name );
	}

	/** La sede publicada con ese slug, o null. */
	public static function by_slug( string $slug ): ?\WP_Post {
		if ( ! self::enabled() ) {
			return null;
		}
		$found = get_posts(
			array(
				'post_type'        => self::TYPE,
				'name'             => sanitize_title( $slug ),
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return $found ? $found[0] : null;
	}

	/** La sede con ese id si es una `location` publicada; si no (una página, un borrador), null. */
	public static function by_id( int $id ): ?\WP_Post {
		if ( $id <= 0 || ! self::enabled() ) {
			return null;
		}
		$post = get_post( $id );
		return ( $post && self::TYPE === $post->post_type && 'publish' === $post->post_status ) ? $post : null;
	}

	/** El valor en texto plano (sin escapar). '' si la clave no existe o está vacía. */
	public static function value( \WP_Post $post, string $key ): string {
		$meta = static fn( string $k ): string => (string) get_post_meta( $post->ID, $k, true );
		switch ( $key ) {
			case 'title':
				return (string) $post->post_title;
			case 'location_phone_href':
				return Links::tel( $meta( 'location_phone' ), Company::country_code() );
			case 'location_email_href':
				return Links::mailto( $meta( 'location_email' ) );
		}
		return in_array( $key, Content_Types::meta_keys( self::TYPE ), true ) ? $meta( $key ) : '';
	}

	/** @return array<string,string> Nombre legible de cada clave, en orden: el editor hace la lista con esto. */
	public static function labels(): array {
		return array( 'title' => __( 'Location name', 'armazon-site' ) )
			+ Content_Types::meta_fields( self::TYPE )
			+ array(
				'location_phone_href' => __( 'Phone (link)', 'armazon-site' ),
				'location_email_href' => __( 'Email (link)', 'armazon-site' ),
			);
	}

	/**
	 * Lo que recibe assets/editor.js: las sedes PUBLICADAS con sus valores, por
	 * id (para el contexto) y con su slug (para los args). Una web tiene pocas
	 * sedes; si alguna vez fueran cientos, esto se pasaría a pedirlas por REST.
	 */
	public static function editor_data(): array {
		$locations = array();
		if ( self::enabled() ) {
			$posts = get_posts(
				array(
					'post_type'        => self::TYPE,
					'post_status'      => 'publish',
					'numberposts'      => 200,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => true,
				)
			);
			foreach ( $posts as $post ) {
				$values = array();
				foreach ( array_keys( self::labels() ) as $key ) {
					$values[ $key ] = self::value( $post, $key );
				}
				$locations[] = array(
					'id'     => $post->ID,
					'slug'   => $post->post_name,
					'values' => $values,
				);
			}
		}
		return array(
			'source'    => self::SOURCE,
			'labels'    => self::labels(),
			'locations' => $locations,
		);
	}

	private static function enabled(): bool {
		return in_array( self::TYPE, Settings::enabled_types(), true );
	}
}
