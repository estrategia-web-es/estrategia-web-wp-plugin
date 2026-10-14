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
 *   ← filtro `estrategia_web_site_rewrite_slug`. Las rutas son del PLUGIN: una web
 *   las fija en la opción (por REST o en la carga de contenido), no en su
 *   tema. El filtro se queda para lo que no cabe ahí. Caso frontera: una web
 *   con una PÁGINA /clientes/ y el tipo `client` activado; el archivo del tipo
 *   la pisa, y se arregla con {"rewrite_slugs":{"client":"cliente"}}.
 * - Un campo nuevo: en META de su tipo, con tipo y saneado. Si no empieza por
 *   el nombre del tipo (`client_…`), no entra: así no choca con el meta de
 *   otro plugin. Un campo nuevo de `location` entra SOLO en la fuente
 *   `estrategia-web-site/location` (Location lee meta_fields()): añade su fila en la
 *   tabla de esa fuente en el README.
 * - Un campo con valor por defecto distinto de ''/0 lleva 'default' en META
 *   (el primero, `estrategia_web_client_image_fit` = 'cover', 0.2.0).
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

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Content_Types {

	/** @return array<string, array> Definición de cada tipo, solo lo que cambia entre ellos. */
	private static function definitions(): array {
		return array(
			'client'      => array(
				'icon'     => 'dashicons-groups',
				'slug'     => _x( 'clients', 'URL slug', 'estrategia-web' ),
				'supports' => array( 'title', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ),
				'labels'   => array(
					'name'               => __( 'Clients', 'estrategia-web' ),
					'singular_name'      => __( 'Client', 'estrategia-web' ),
					'add_new_item'       => __( 'Add client', 'estrategia-web' ),
					'edit_item'          => __( 'Edit client', 'estrategia-web' ),
					'new_item'           => __( 'New client', 'estrategia-web' ),
					'view_item'          => __( 'View client', 'estrategia-web' ),
					'view_items'         => __( 'View clients', 'estrategia-web' ),
					'search_items'       => __( 'Search clients', 'estrategia-web' ),
					'not_found'          => __( 'No clients found.', 'estrategia-web' ),
					'not_found_in_trash' => __( 'No clients found in Trash.', 'estrategia-web' ),
					'all_items'          => __( 'All clients', 'estrategia-web' ),
					'featured_image'     => __( 'Logo or photo', 'estrategia-web' ),
					'set_featured_image' => __( 'Set logo or photo', 'estrategia-web' ),
				),
				'taxonomy' => array(
					'name'   => 'client_sector',
					'slug'   => _x( 'client-sector', 'URL slug', 'estrategia-web' ),
					'labels' => array(
						'name'          => __( 'Sectors', 'estrategia-web' ),
						'singular_name' => __( 'Sector', 'estrategia-web' ),
						'add_new_item'  => __( 'Add sector', 'estrategia-web' ),
						'edit_item'     => __( 'Edit sector', 'estrategia-web' ),
						'search_items'  => __( 'Search sectors', 'estrategia-web' ),
						'all_items'     => __( 'All sectors', 'estrategia-web' ),
						'not_found'     => __( 'No sectors found.', 'estrategia-web' ),
					),
				),
				'meta'     => array(
					'client_url'       => array( 'type' => 'string', 'sanitize' => 'url', 'label' => __( 'Client website', 'estrategia-web' ) ),
					// 0.2.0. 'contain' = la imagen es un LOGO (no se recorta); ver post_class().
					'estrategia_web_client_image_fit' => array( 'type' => 'string', 'sanitize' => 'fit', 'default' => 'cover', 'label' => __( 'Image fit (cover for a photo, contain for a logo)', 'estrategia-web' ) ),
					// Sin publicar. Lo que iba entre paréntesis en el título («Portic
					// Villas (agencia de alquiler vacacional)»): el nombre no se
					// traduce y esto sí (wpml-config.xml, «translate»).
					'estrategia_web_client_business_type' => array( 'type' => 'string', 'sanitize' => 'text', 'label' => __( 'Business type', 'estrategia-web' ) ),
				),
			),
			'testimonial' => array(
				'icon'     => 'dashicons-format-quote',
				'slug'     => _x( 'testimonials', 'URL slug', 'estrategia-web' ),
				'supports' => array( 'title', 'editor', 'thumbnail', 'page-attributes', 'custom-fields' ),
				'labels'   => array(
					'name'               => __( 'Testimonials', 'estrategia-web' ),
					'singular_name'      => __( 'Testimonial', 'estrategia-web' ),
					'add_new_item'       => __( 'Add testimonial', 'estrategia-web' ),
					'edit_item'          => __( 'Edit testimonial', 'estrategia-web' ),
					'new_item'           => __( 'New testimonial', 'estrategia-web' ),
					'view_item'          => __( 'View testimonial', 'estrategia-web' ),
					'view_items'         => __( 'View testimonials', 'estrategia-web' ),
					'search_items'       => __( 'Search testimonials', 'estrategia-web' ),
					'not_found'          => __( 'No testimonials found.', 'estrategia-web' ),
					'not_found_in_trash' => __( 'No testimonials found in Trash.', 'estrategia-web' ),
					'all_items'          => __( 'All testimonials', 'estrategia-web' ),
					'featured_image'     => __( 'Author photo', 'estrategia-web' ),
					'set_featured_image' => __( 'Set author photo', 'estrategia-web' ),
				),
				'meta'     => array(
					'estrategia_web_testimonial_author' => array( 'type' => 'string', 'sanitize' => 'text', 'label' => __( 'Author', 'estrategia-web' ) ),
					'testimonial_role'   => array( 'type' => 'string', 'sanitize' => 'text', 'label' => __( 'Author role or company', 'estrategia-web' ) ),
					'estrategia_web_testimonial_rating' => array( 'type' => 'integer', 'sanitize' => 'rating', 'label' => __( 'Rating (1 to 5)', 'estrategia-web' ) ),
					'testimonial_client' => array( 'type' => 'integer', 'sanitize' => 'client', 'label' => __( 'Related client', 'estrategia-web' ) ),
				),
			),
			'location'    => array(
				'icon'     => 'dashicons-location',
				'slug'     => _x( 'locations', 'URL slug', 'estrategia-web' ),
				'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ),
				'labels'   => array(
					'name'               => __( 'Locations', 'estrategia-web' ),
					'singular_name'      => __( 'Location', 'estrategia-web' ),
					'add_new_item'       => __( 'Add location', 'estrategia-web' ),
					'edit_item'          => __( 'Edit location', 'estrategia-web' ),
					'new_item'           => __( 'New location', 'estrategia-web' ),
					'view_item'          => __( 'View location', 'estrategia-web' ),
					'view_items'         => __( 'View locations', 'estrategia-web' ),
					'search_items'       => __( 'Search locations', 'estrategia-web' ),
					'not_found'          => __( 'No locations found.', 'estrategia-web' ),
					'not_found_in_trash' => __( 'No locations found in Trash.', 'estrategia-web' ),
					'all_items'          => __( 'All locations', 'estrategia-web' ),
					'featured_image'     => __( 'Static map image', 'estrategia-web' ),
					'set_featured_image' => __( 'Set static map image', 'estrategia-web' ),
				),
				'meta'     => array(
					'estrategia_web_location_address'   => array( 'type' => 'string', 'sanitize' => 'textarea', 'label' => __( 'Address', 'estrategia-web' ) ),
					'estrategia_web_location_phone'     => array( 'type' => 'string', 'sanitize' => 'text', 'label' => __( 'Phone', 'estrategia-web' ) ),
					'estrategia_web_location_email'     => array( 'type' => 'string', 'sanitize' => 'email', 'label' => __( 'Email', 'estrategia-web' ) ),
					'estrategia_web_location_schedule'  => array( 'type' => 'string', 'sanitize' => 'textarea', 'label' => __( 'Opening hours', 'estrategia-web' ) ),
					'estrategia_web_location_map_url'   => array( 'type' => 'string', 'sanitize' => 'url', 'label' => __( 'Google Maps link', 'estrategia-web' ) ),
					// 'embed' (sin publicar; antes 'url'): admite pegar el <iframe> entero y se queda con su src.
					'estrategia_web_location_map_embed' => array( 'type' => 'string', 'sanitize' => 'embed', 'label' => __( 'Map embed URL', 'estrategia-web' ) ),
					// 0.4.0. Para staticMapUrl / staticMapId de estrategia-web/map (tema padre). Ver local_image_url().
					'estrategia_web_location_static_map'    => array( 'type' => 'string', 'sanitize' => 'local_image', 'label' => __( 'Static map image (theme path or URL of this site)', 'estrategia-web' ) ),
					'estrategia_web_location_static_map_id' => array( 'type' => 'integer', 'sanitize' => 'image_id', 'label' => __( 'Static map image (media library ID)', 'estrategia-web' ) ),
				),
			),
			'project'     => array(
				'icon'     => 'dashicons-portfolio',
				'slug'     => _x( 'projects', 'URL slug', 'estrategia-web' ),
				'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields', 'revisions' ),
				'labels'   => array(
					'name'               => __( 'Projects', 'estrategia-web' ),
					'singular_name'      => __( 'Project', 'estrategia-web' ),
					'add_new_item'       => __( 'Add project', 'estrategia-web' ),
					'edit_item'          => __( 'Edit project', 'estrategia-web' ),
					'new_item'           => __( 'New project', 'estrategia-web' ),
					'view_item'          => __( 'View project', 'estrategia-web' ),
					'view_items'         => __( 'View projects', 'estrategia-web' ),
					'search_items'       => __( 'Search projects', 'estrategia-web' ),
					'not_found'          => __( 'No projects found.', 'estrategia-web' ),
					'not_found_in_trash' => __( 'No projects found in Trash.', 'estrategia-web' ),
					'all_items'          => __( 'All projects', 'estrategia-web' ),
				),
				'taxonomy' => array(
					'name'   => 'project_category',
					'slug'   => _x( 'project-category', 'URL slug', 'estrategia-web' ),
					'labels' => array(
						'name'          => __( 'Project categories', 'estrategia-web' ),
						'singular_name' => __( 'Project category', 'estrategia-web' ),
						'add_new_item'  => __( 'Add category', 'estrategia-web' ),
						'edit_item'     => __( 'Edit category', 'estrategia-web' ),
						'search_items'  => __( 'Search categories', 'estrategia-web' ),
						'all_items'     => __( 'All categories', 'estrategia-web' ),
						'not_found'     => __( 'No categories found.', 'estrategia-web' ),
					),
				),
				'meta'     => array(),
			),
			'portfolio'   => array(
				'icon'     => 'dashicons-images-alt2',
				'slug'     => _x( 'portfolio', 'URL slug', 'estrategia-web' ),
				'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields', 'revisions' ),
				'labels'   => array(
					'name'               => __( 'Portfolio', 'estrategia-web' ),
					'singular_name'      => __( 'Portfolio item', 'estrategia-web' ),
					'add_new_item'       => __( 'Add portfolio item', 'estrategia-web' ),
					'edit_item'          => __( 'Edit portfolio item', 'estrategia-web' ),
					'new_item'           => __( 'New portfolio item', 'estrategia-web' ),
					'view_item'          => __( 'View portfolio item', 'estrategia-web' ),
					'view_items'         => __( 'View portfolio', 'estrategia-web' ),
					'search_items'       => __( 'Search portfolio', 'estrategia-web' ),
					'not_found'          => __( 'No portfolio items found.', 'estrategia-web' ),
					'not_found_in_trash' => __( 'No portfolio items found in Trash.', 'estrategia-web' ),
					'all_items'          => __( 'All portfolio items', 'estrategia-web' ),
				),
				'taxonomy' => array(
					'name'   => 'portfolio_category',
					'slug'   => _x( 'portfolio-category', 'URL slug', 'estrategia-web' ),
					'labels' => array(
						'name'          => __( 'Portfolio categories', 'estrategia-web' ),
						'singular_name' => __( 'Portfolio category', 'estrategia-web' ),
						'add_new_item'  => __( 'Add category', 'estrategia-web' ),
						'edit_item'     => __( 'Edit category', 'estrategia-web' ),
						'search_items'  => __( 'Search categories', 'estrategia-web' ),
						'all_items'     => __( 'All categories', 'estrategia-web' ),
						'not_found'     => __( 'No categories found.', 'estrategia-web' ),
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
		return (string) apply_filters( 'estrategia_web_site_rewrite_slug', '' !== $fixed ? $fixed : $translated, $name );
	}

	/** @return string[] Las claves de meta de un tipo (para la prueba y el README). */
	public static function meta_keys( string $type ): array {
		return array_keys( self::definitions()[ $type ]['meta'] ?? array() );
	}

	/** @return array<string,string> Clave de meta => nombre legible (la fuente de sedes los enseña en el editor). */
	public static function meta_fields( string $type ): array {
		return array_map( static fn( $meta ) => $meta['label'], self::definitions()[ $type ]['meta'] ?? array() );
	}

	/**
	 * @return array<string,array{label:string,type:string,sanitize:string}> Los campos de un tipo
	 * con su clase de saneado: el panel del editor (Meta_Panel) elige el control con ella.
	 */
	public static function meta_definitions( string $type ): array {
		return array_map(
			static fn( $meta ) => array( 'label' => $meta['label'], 'type' => $meta['type'], 'sanitize' => $meta['sanitize'] ),
			self::definitions()[ $type ]['meta'] ?? array()
		);
	}

	private static function sanitizer( string $kind ): callable {
		return match ( $kind ) {
			'url'      => static fn( $v ) => esc_url_raw( trim( (string) $v ), array( 'http', 'https' ) ),
			// El código que da Google Maps (Compartir › Insertar un mapa) es un <iframe>:
			// se guarda solo su src. Una URL suelta pasa igual que con 'url'.
			'embed'    => static fn( $v ) => esc_url_raw( trim( html_entity_decode( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', (string) $v, $m ) ? $m[1] : (string) $v ) ), array( 'http', 'https' ) ),
			'email'    => static fn( $v ) => is_email( trim( (string) $v ) ) ? sanitize_email( (string) $v ) : '',
			'textarea' => static fn( $v ) => sanitize_textarea_field( (string) $v ),
			// 0 = sin valoración; cualquier otra cosa se lleva al intervalo 1-5.
			'rating'   => static fn( $v ) => (int) $v <= 0 ? 0 : min( 5, (int) $v ),
			// Solo un id que sea de verdad un cliente; si no, 0.
			'client'   => static fn( $v ) => ( (int) $v > 0 && 'client' === get_post_type( (int) $v ) ) ? (int) $v : 0,
			// Solo dos valores; cualquier otro (o vacío) es el de por defecto.
			'fit'      => static fn( $v ) => 'contain' === trim( (string) $v ) ? 'contain' : 'cover',
			// 0.4.0, mapa estático de una sede.
			'local_image' => static fn( $v ) => self::local_image_url( (string) $v ),
			'image_id'    => static fn( $v ) => ( (int) $v > 0 && wp_attachment_is_image( (int) $v ) ) ? (int) $v : 0,
			default    => static fn( $v ) => sanitize_text_field( (string) $v ),
		};
	}

	/**
	 * URL de una imagen servida por ESTE sitio, o '' (0.4.0, `estrategia_web_location_static_map`).
	 * Las mismas tres formas que acepta staticMapUrl de estrategia-web/map en el tema
	 * padre (estrategia_web_map_static_url), que vuelve a comprobarlo al pintar:
	 *
	 *   assets/maps/sede.webp            ruta del TEMA (hijo, luego padre); se guarda sin «./»
	 *   /wp-content/uploads/mapa.webp    ruta del sitio
	 *   https://este-dominio/…/mapa.webp URL absoluta del MISMO host que home_url()
	 *
	 * Por qué tan estricto: un mapa de otro dominio es una petición a un tercero
	 * sin consentimiento al cargar la página (con la IP del visitante); el
	 * tema lo descartaría igual, pero aquí se nota al guardar y no en la web.
	 * Caso frontera: «//otro.test/a.png» tiene host aunque no lleve esquema; se
	 * trata como otro dominio. La trampa: esc_url_raw() a una ruta sin «/»
	 * delante le pone «http://»; por eso las rutas se validan a mano.
	 */
	public static function local_image_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( false === $parts || str_contains( $value, '..' ) ) {
			return '';
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( ! preg_match( '/\.(webp|png|jpe?g|gif|avif|svg)$/i', $path ) ) {
			return '';
		}
		if ( isset( $parts['host'] ) || isset( $parts['scheme'] ) ) {
			$site = wp_parse_url( home_url() );
			$ok   = in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
				&& isset( $parts['host'], $site['host'] )
				&& strtolower( $parts['host'] ) === strtolower( $site['host'] );
			return $ok ? esc_url_raw( $value, array( 'http', 'https' ) ) : '';
		}
		// Ruta: solo caracteres de URL que no hay que escapar (sin espacios, comillas ni «<»).
		if ( ! preg_match( '#^[A-Za-z0-9._~/\-]+(\?[A-Za-z0-9._~=&%\-]*)?$#', $value ) ) {
			return '';
		}
		return preg_replace( '#^\./#', '', $value );
	}

	/**
	 * Filtro post_class: un cliente con estrategia_web_client_image_fit = 'contain' lleva la
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
		if ( $post_id > 0 && 'client' === get_post_type( $post_id ) && 'contain' === get_post_meta( $post_id, 'estrategia_web_client_image_fit', true ) ) {
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
