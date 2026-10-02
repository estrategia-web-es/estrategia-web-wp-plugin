<?php
/**
 * Los tipos de contenido habituales de una web corporativa, cada uno
 * registrado SOLO si está activado en «Datos de la empresa» (enabled_types).
 *
 *   client       Clientes   /clientes/   taxonomía client_sector
 *   testimonial  Opiniones  /opiniones/
 *   location     Sedes      /sedes/
 *   project      Proyectos  /proyectos/  taxonomía project_category
 *   portfolio    Portfolio  /portfolio/  taxonomía portfolio_category
 *
 * Los nombres (tipo, taxonomía, meta) son CONTRATO: el tema y la carga de
 * contenido los usan en bloques Consulta y en Block Bindings (core/post-meta).
 *
 * Desactivar un tipo NO borra nada: sus entradas siguen en wp_posts con su
 * post_type, simplemente WordPress no las conoce mientras no esté registrado
 * (ni en el menú, ni en REST, ni en la web). Al volver a activarlo aparecen
 * tal cual. Ni aquí ni en uninstall.php se borra contenido.
 *
 * Cómo se mantiene:
 * - Un tipo nuevo: TYPES de Settings (es el enum del esquema), definitions()
 *   aquí, sus etiquetas, el README y una prueba. Es versión MENOR (añade).
 *   Quitar o renombrar uno es MAYOR. Él y su taxonomía, también en
 *   Settings::REWRITE_NAMES y DEFAULTS['rewrite_slugs'].
 * - Slugs públicos (0.2.0, slug()): traducido ← `rewrite_slugs` de la opción
 *   ← filtro `armazon_site_rewrite_slug`. Las rutas son del PLUGIN: una web
 *   las fija en la opción (por REST o en la carga de contenido), no en su
 *   tema. El filtro se queda para lo que no cabe ahí. Caso frontera: una web
 *   con una PÁGINA /clientes/ y el tipo `client` activado; el archivo del tipo
 *   la pisa, y se arregla con {"rewrite_slugs":{"client":"cliente"}}.
 * - Un campo nuevo: en META de su tipo, con tipo y saneado. Si no empieza por
 *   el nombre del tipo (`client_…`), no entra: así no choca con el meta de
 *   otro plugin. Un campo nuevo de `location` entra SOLO en la fuente
 *   `armazon-site/location` (Location lee meta_fields()): añade su fila en la
 *   tabla de esa fuente en el README.
 * - Un campo con valor por defecto distinto de ''/0 lleva 'default' en META
 *   (el primero, `client_image_fit` = 'cover', 0.2.0).
 * - Clases de entrada (post_class): el plugin añade clases que DESCRIBEN el
 *   dato (`has-image-fit-contain` en un cliente que es un logo) y nunca CSS.
 *   Son contrato con los temas (README, tabla de clases). Caso frontera: un
 *   cliente sin el campo guardado (de 0.1.0) vale 'cover' y no lleva clase,
 *   así que un tema que no conozca la clase se ve igual que antes.
 * - Caso frontera: «servicios». No está, a propósito: en estas webs un
 *   servicio es una PÁGINA (con su diseño propio), no una ficha repetida. Si
 *   una web necesita fichas de servicio, se valora aquí como tipo nuevo.
 * - La trampa: el slug público se traduce con gettext (msgid en inglés,
 *   «clients» → «clientes» en es_ES). Cambiar el idioma de la web CAMBIA las
 *   URL. En una web ya publicada, fijarlo en `rewrite_slugs` antes de cambiar
 *   de idioma.
 * - Y la otra: tras activar o desactivar un tipo, o cambiar un slug fijado,
 *   hay que regenerar las reglas de URL. Se hace solo (maybe_flush borra
 *   `rewrite_rules` y WordPress las rehace en la siguiente carga), pero una
 *   caché de página puede servir un 404 viejo un rato. Un slug cambiado por
 *   el FILTRO no lo ve maybe_flush: quien cambie el filtro, que guarde los
 *   enlaces permanentes.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Content_Types {

	/** @return array<string, array> Definición de cada tipo, solo lo que cambia entre ellos. */
	private static function definitions(): array {
		return array(
			'client'      => array(
				'icon'     => 'dashicons-groups',
				'slug'     => _x( 'clients', 'URL slug', 'armazon-site' ),
				'supports' => array( 'title', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ),
				'labels'   => array(
					'name'               => __( 'Clients', 'armazon-site' ),
					'singular_name'      => __( 'Client', 'armazon-site' ),
					'add_new_item'       => __( 'Add client', 'armazon-site' ),
					'edit_item'          => __( 'Edit client', 'armazon-site' ),
					'new_item'           => __( 'New client', 'armazon-site' ),
					'view_item'          => __( 'View client', 'armazon-site' ),
					'view_items'         => __( 'View clients', 'armazon-site' ),
					'search_items'       => __( 'Search clients', 'armazon-site' ),
					'not_found'          => __( 'No clients found.', 'armazon-site' ),
					'not_found_in_trash' => __( 'No clients found in Trash.', 'armazon-site' ),
					'all_items'          => __( 'All clients', 'armazon-site' ),
					'featured_image'     => __( 'Logo or photo', 'armazon-site' ),
					'set_featured_image' => __( 'Set logo or photo', 'armazon-site' ),
				),
				'taxonomy' => array(
					'name'   => 'client_sector',
					'slug'   => _x( 'client-sector', 'URL slug', 'armazon-site' ),
					'labels' => array(
						'name'          => __( 'Sectors', 'armazon-site' ),
						'singular_name' => __( 'Sector', 'armazon-site' ),
						'add_new_item'  => __( 'Add sector', 'armazon-site' ),
						'edit_item'     => __( 'Edit sector', 'armazon-site' ),
						'search_items'  => __( 'Search sectors', 'armazon-site' ),
						'all_items'     => __( 'All sectors', 'armazon-site' ),
						'not_found'     => __( 'No sectors found.', 'armazon-site' ),
					),
				),
				'meta'     => array(
					'client_url'       => array( 'type' => 'string', 'sanitize' => 'url', 'label' => __( 'Client website', 'armazon-site' ) ),
					// 0.2.0. 'contain' = la imagen es un LOGO (no se recorta); ver post_class().
					'client_image_fit' => array( 'type' => 'string', 'sanitize' => 'fit', 'default' => 'cover', 'label' => __( 'Image fit (cover for a photo, contain for a logo)', 'armazon-site' ) ),
				),
			),
			'testimonial' => array(
				'icon'     => 'dashicons-format-quote',
				'slug'     => _x( 'testimonials', 'URL slug', 'armazon-site' ),
				'supports' => array( 'title', 'editor', 'thumbnail', 'page-attributes', 'custom-fields' ),
				'labels'   => array(
					'name'               => __( 'Testimonials', 'armazon-site' ),
					'singular_name'      => __( 'Testimonial', 'armazon-site' ),
					'add_new_item'       => __( 'Add testimonial', 'armazon-site' ),
					'edit_item'          => __( 'Edit testimonial', 'armazon-site' ),
					'new_item'           => __( 'New testimonial', 'armazon-site' ),
					'view_item'          => __( 'View testimonial', 'armazon-site' ),
					'view_items'         => __( 'View testimonials', 'armazon-site' ),
					'search_items'       => __( 'Search testimonials', 'armazon-site' ),
					'not_found'          => __( 'No testimonials found.', 'armazon-site' ),
					'not_found_in_trash' => __( 'No testimonials found in Trash.', 'armazon-site' ),
					'all_items'          => __( 'All testimonials', 'armazon-site' ),
					'featured_image'     => __( 'Author photo', 'armazon-site' ),
					'set_featured_image' => __( 'Set author photo', 'armazon-site' ),
				),
				'meta'     => array(
					'testimonial_author' => array( 'type' => 'string', 'sanitize' => 'text', 'label' => __( 'Author', 'armazon-site' ) ),
					'testimonial_role'   => array( 'type' => 'string', 'sanitize' => 'text', 'label' => __( 'Author role or company', 'armazon-site' ) ),
					'testimonial_rating' => array( 'type' => 'integer', 'sanitize' => 'rating', 'label' => __( 'Rating (1 to 5)', 'armazon-site' ) ),
					'testimonial_client' => array( 'type' => 'integer', 'sanitize' => 'client', 'label' => __( 'Related client', 'armazon-site' ) ),
				),
			),
			'location'    => array(
				'icon'     => 'dashicons-location',
				'slug'     => _x( 'locations', 'URL slug', 'armazon-site' ),
				'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ),
				'labels'   => array(
					'name'               => __( 'Locations', 'armazon-site' ),
					'singular_name'      => __( 'Location', 'armazon-site' ),
					'add_new_item'       => __( 'Add location', 'armazon-site' ),
					'edit_item'          => __( 'Edit location', 'armazon-site' ),
					'new_item'           => __( 'New location', 'armazon-site' ),
					'view_item'          => __( 'View location', 'armazon-site' ),
					'view_items'         => __( 'View locations', 'armazon-site' ),
					'search_items'       => __( 'Search locations', 'armazon-site' ),
					'not_found'          => __( 'No locations found.', 'armazon-site' ),
					'not_found_in_trash' => __( 'No locations found in Trash.', 'armazon-site' ),
					'all_items'          => __( 'All locations', 'armazon-site' ),
					'featured_image'     => __( 'Static map image', 'armazon-site' ),
					'set_featured_image' => __( 'Set static map image', 'armazon-site' ),
				),
				'meta'     => array(
					'location_address'   => array( 'type' => 'string', 'sanitize' => 'textarea', 'label' => __( 'Address', 'armazon-site' ) ),
					'location_phone'     => array( 'type' => 'string', 'sanitize' => 'text', 'label' => __( 'Phone', 'armazon-site' ) ),
					'location_email'     => array( 'type' => 'string', 'sanitize' => 'email', 'label' => __( 'Email', 'armazon-site' ) ),
					'location_schedule'  => array( 'type' => 'string', 'sanitize' => 'textarea', 'label' => __( 'Opening hours', 'armazon-site' ) ),
					'location_map_url'   => array( 'type' => 'string', 'sanitize' => 'url', 'label' => __( 'Google Maps link', 'armazon-site' ) ),
					'location_map_embed' => array( 'type' => 'string', 'sanitize' => 'url', 'label' => __( 'Map embed URL', 'armazon-site' ) ),
				),
			),
			'project'     => array(
				'icon'     => 'dashicons-portfolio',
				'slug'     => _x( 'projects', 'URL slug', 'armazon-site' ),
				'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields', 'revisions' ),
				'labels'   => array(
					'name'               => __( 'Projects', 'armazon-site' ),
					'singular_name'      => __( 'Project', 'armazon-site' ),
					'add_new_item'       => __( 'Add project', 'armazon-site' ),
					'edit_item'          => __( 'Edit project', 'armazon-site' ),
					'new_item'           => __( 'New project', 'armazon-site' ),
					'view_item'          => __( 'View project', 'armazon-site' ),
					'view_items'         => __( 'View projects', 'armazon-site' ),
					'search_items'       => __( 'Search projects', 'armazon-site' ),
					'not_found'          => __( 'No projects found.', 'armazon-site' ),
					'not_found_in_trash' => __( 'No projects found in Trash.', 'armazon-site' ),
					'all_items'          => __( 'All projects', 'armazon-site' ),
				),
				'taxonomy' => array(
					'name'   => 'project_category',
					'slug'   => _x( 'project-category', 'URL slug', 'armazon-site' ),
					'labels' => array(
						'name'          => __( 'Project categories', 'armazon-site' ),
						'singular_name' => __( 'Project category', 'armazon-site' ),
						'add_new_item'  => __( 'Add category', 'armazon-site' ),
						'edit_item'     => __( 'Edit category', 'armazon-site' ),
						'search_items'  => __( 'Search categories', 'armazon-site' ),
						'all_items'     => __( 'All categories', 'armazon-site' ),
						'not_found'     => __( 'No categories found.', 'armazon-site' ),
					),
				),
				'meta'     => array(),
			),
			'portfolio'   => array(
				'icon'     => 'dashicons-images-alt2',
				'slug'     => _x( 'portfolio', 'URL slug', 'armazon-site' ),
				'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields', 'revisions' ),
				'labels'   => array(
					'name'               => __( 'Portfolio', 'armazon-site' ),
					'singular_name'      => __( 'Portfolio item', 'armazon-site' ),
					'add_new_item'       => __( 'Add portfolio item', 'armazon-site' ),
					'edit_item'          => __( 'Edit portfolio item', 'armazon-site' ),
					'new_item'           => __( 'New portfolio item', 'armazon-site' ),
					'view_item'          => __( 'View portfolio item', 'armazon-site' ),
					'view_items'         => __( 'View portfolio', 'armazon-site' ),
					'search_items'       => __( 'Search portfolio', 'armazon-site' ),
					'not_found'          => __( 'No portfolio items found.', 'armazon-site' ),
					'not_found_in_trash' => __( 'No portfolio items found in Trash.', 'armazon-site' ),
					'all_items'          => __( 'All portfolio items', 'armazon-site' ),
				),
				'taxonomy' => array(
					'name'   => 'portfolio_category',
					'slug'   => _x( 'portfolio-category', 'URL slug', 'armazon-site' ),
					'labels' => array(
						'name'          => __( 'Portfolio categories', 'armazon-site' ),
						'singular_name' => __( 'Portfolio category', 'armazon-site' ),
						'add_new_item'  => __( 'Add category', 'armazon-site' ),
						'edit_item'     => __( 'Edit category', 'armazon-site' ),
						'search_items'  => __( 'Search categories', 'armazon-site' ),
						'all_items'     => __( 'All categories', 'armazon-site' ),
						'not_found'     => __( 'No categories found.', 'armazon-site' ),
					),
				),
				'meta'     => array(),
			),
		);
	}

	/** Registra los tipos activados, con su taxonomía y sus campos. En init. */
	public static function register(): void {
		$definitions = self::definitions();
		$position    = 21;
		foreach ( Settings::enabled_types() as $type ) {
			$def  = $definitions[ $type ];
			$slug = self::slug( $def['slug'], $type );

			register_post_type(
				$type,
				array(
					'labels'        => $def['labels'] + array( 'menu_name' => $def['labels']['name'] ),
					'public'        => true,
					'show_in_rest'  => true,
					'has_archive'   => $slug,
					'rewrite'       => array( 'slug' => $slug, 'with_front' => false ),
					'menu_icon'     => $def['icon'],
					'menu_position' => $position++,
					'supports'      => $def['supports'],
				)
			);

			if ( isset( $def['taxonomy'] ) ) {
				$tax = $def['taxonomy'];
				register_taxonomy(
					$tax['name'],
					$type,
					array(
						'labels'            => $tax['labels'] + array( 'menu_name' => $tax['labels']['name'] ),
						'hierarchical'      => true,
						'public'            => true,
						'show_in_rest'      => true,
						'show_admin_column' => true,
						'rewrite'           => array( 'slug' => self::slug( $tax['slug'], $tax['name'] ), 'with_front' => false ),
					)
				);
			}

			foreach ( $def['meta'] as $key => $meta ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'              => $meta['type'],
						'label'             => $meta['label'],
						'single'            => true,
						'default'           => $meta['default'] ?? ( 'integer' === $meta['type'] ? 0 : '' ),
						'show_in_rest'      => true,
						'sanitize_callback' => self::sanitizer( $meta['sanitize'] ),
						'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
					)
				);
			}
		}
	}

	/**
	 * El slug público de un tipo o taxonomía, por orden: el traducido
	 * («clientes») ← el fijado en la opción (`rewrite_slugs`, 0.2.0) ← el filtro,
	 * que sigue mandando sobre los dos.
	 */
	private static function slug( string $translated, string $name ): string {
		$fixed = (string) ( ( (array) Settings::get( 'rewrite_slugs' ) )[ $name ] ?? '' );
		/**
		 * Cambia el slug público de un tipo (o de su taxonomía) en una web.
		 *
		 * @param string $slug El slug de `rewrite_slugs` si lo hay; si no, el traducido («clientes»).
		 * @param string $name El tipo o la taxonomía («client», «client_sector»).
		 */
		return (string) apply_filters( 'armazon_site_rewrite_slug', '' !== $fixed ? $fixed : $translated, $name );
	}

	/** @return string[] Las claves de meta de un tipo (para la prueba y el README). */
	public static function meta_keys( string $type ): array {
		return array_keys( self::definitions()[ $type ]['meta'] ?? array() );
	}

	/** @return array<string,string> Clave de meta => nombre legible (la fuente de sedes los enseña en el editor). */
	public static function meta_fields( string $type ): array {
		return array_map( static fn( $meta ) => $meta['label'], self::definitions()[ $type ]['meta'] ?? array() );
	}

	private static function sanitizer( string $kind ): callable {
		return match ( $kind ) {
			'url'      => static fn( $v ) => esc_url_raw( trim( (string) $v ), array( 'http', 'https' ) ),
			'email'    => static fn( $v ) => is_email( trim( (string) $v ) ) ? sanitize_email( (string) $v ) : '',
			'textarea' => static fn( $v ) => sanitize_textarea_field( (string) $v ),
			// 0 = sin valoración; cualquier otra cosa se lleva al intervalo 1-5.
			'rating'   => static fn( $v ) => (int) $v <= 0 ? 0 : min( 5, (int) $v ),
			// Solo un id que sea de verdad un cliente; si no, 0.
			'client'   => static fn( $v ) => ( (int) $v > 0 && 'client' === get_post_type( (int) $v ) ) ? (int) $v : 0,
			// Solo dos valores; cualquier otro (o vacío) es el de por defecto.
			'fit'      => static fn( $v ) => 'contain' === trim( (string) $v ) ? 'contain' : 'cover',
			default    => static fn( $v ) => sanitize_text_field( (string) $v ),
		};
	}

	/**
	 * Filtro post_class: un cliente con client_image_fit = 'contain' lleva la
	 * clase `has-image-fit-contain` (en su <li> de una Consulta, en su ficha).
	 * El plugin NO pone CSS: cómo se pinta un logo es del tema (README).
	 *
	 * La trampa: es PHP, así que solo sale en la web. La vista previa de una
	 * Consulta en el editor la pinta JS y no lleva esta clase: ahí los logos
	 * se ven recortados aunque en la web no (deducido del núcleo, no probado).
	 *
	 * @param string[] $classes
	 */
	public static function post_class( $classes, $css_class = '', $post_id = 0 ): array {
		$classes = (array) $classes;
		$post_id = (int) $post_id;
		if ( $post_id > 0 && 'client' === get_post_type( $post_id ) && 'contain' === get_post_meta( $post_id, 'client_image_fit', true ) ) {
			$classes[] = 'has-image-fit-contain';
		}
		return $classes;
	}

	/**
	 * Si cambian los tipos activados o un slug fijado (rewrite_slugs), se
	 * tiran las reglas de URL para que WordPress las rehaga en la siguiente
	 * carga, ya con lo nuevo. (flush_rewrite_rules() aquí las rehará con lo
	 * VIEJO: esta carga registró los tipos de antes del cambio.)
	 */
	public static function maybe_flush( $old, $new ): void {
		$types = static function ( $o ): array {
			$t = is_array( $o ) ? (array) ( $o['enabled_types'] ?? array() ) : array();
			sort( $t );
			return $t;
		};
		// Sin ninguno fijado da lo mismo array() que todos vacíos (una opción de 0.1.0).
		$slugs = static fn( $o ): array => array_filter( is_array( $o ) ? (array) ( $o['rewrite_slugs'] ?? array() ) : array(), 'strlen' );
		$a     = $slugs( $old );
		$b     = $slugs( $new );
		ksort( $a );
		ksort( $b );
		if ( $types( $old ) !== $types( $new ) || $a !== $b ) {
			delete_option( 'rewrite_rules' );
		}
	}
}
