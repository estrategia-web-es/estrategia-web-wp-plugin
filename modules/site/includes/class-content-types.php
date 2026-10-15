<?php
/**
 * The usual content types of a corporate site, each one registered ONLY if it
 * is enabled in «Company details» (enabled_types).
 *
 *   key          registered       what          URL (es_ES)  taxonomy
 *   client       eweb_client      Clients       /clientes/   eweb_client_sector
 *   testimonial  eweb_testimonial Testimonials  /opiniones/
 *   location     eweb_location    Locations     /sedes/
 *   project      eweb_project     Projects      /proyectos/  eweb_project_category
 *   portfolio    eweb_portfolio   Portfolio     /portfolio/  eweb_portfolio_category
 *
 * The names (type, taxonomy, meta) are CONTRACT: the theme and the content
 * load use them in Query blocks and in Block Bindings (core/post-meta).
 * Here everything goes by KEY («client») and is registered with
 * Type_Names::name() («eweb_client», 1.2.0): the prefix lives only in
 * class-type-names.php, and its header explains why the option still speaks
 * in keys.
 *
 * Disabling a type deletes NOTHING: its posts stay in wp_posts with their
 * post_type, WordPress simply does not know them while it is not registered
 * (not in the menu, not in REST, not on the site). When it is enabled again
 * they come back as they were. No content is deleted here or in uninstall.php.
 *
 * How to maintain:
 * - A new type: Settings TYPES (it is the schema enum), definitions() here,
 *   its labels, the README and a test. It is a MINOR version (it adds).
 *   Removing or renaming one is MAJOR. It and its taxonomy also go into
 *   Settings::REWRITE_NAMES and DEFAULTS['rewrite_slugs'].
 * - Public slugs (0.2.0, slug()): translated ← the option's `rewrite_slugs`
 *   ← the `estrategia_web_site_rewrite_slug` filter. The routes belong to the
 *   PLUGIN: a site sets them in the option (over REST or in the content load),
 *   not in its theme. The filter stays for whatever does not fit there. Edge
 *   case: a site with a PAGE /clientes/ and the `client` type enabled; the
 *   type archive overrides it, and it is fixed with
 *   {"rewrite_slugs":{"client":"cliente"}}.
 * - A new field: in its type's META, with type and sanitiser. If it does not
 *   start with the type name (`client_…`), it does not go in: that way it does
 *   not clash with another plugin's meta. A new `location` field goes ON ITS
 *   OWN into the `estrategia-web-site/location` source (Location reads
 *   meta_fields()): add its row to that source's table in the README.
 * - A field with a default other than ''/0 carries 'default' in META
 *   (the first one, `estrategia_web_client_image_fit` = 'cover', 0.2.0).
 * - Post classes (post_class): the plugin adds classes that DESCRIBE the data
 *   (`has-image-fit-contain` on a client that is a logo) and never CSS. They
 *   are contract with the themes (README, classes table). Edge case: a client
 *   without the field saved (from 0.1.0) counts as 'cover' and carries no
 *   class, so a theme that does not know the class looks the same as before.
 * - Edge case: «services». Not here, on purpose: on these sites a service is
 *   a PAGE (with its own design), not a repeated entry. If a site needs
 *   service entries, it is considered here as a new type.
 * - The trap: the public slug is translated with gettext (msgid in English,
 *   «clients» → «clientes» in es_ES). Changing the site language CHANGES the
 *   URLs. On an already published site, set it in `rewrite_slugs` before
 *   changing the language.
 * - And the other one: after enabling or disabling a type, or changing a set
 *   slug, the URL rules have to be regenerated. It happens on its own
 *   (maybe_flush deletes `rewrite_rules` and WordPress rebuilds them on the
 *   next load), but a page cache may serve an old 404 for a while. A slug
 *   changed by the FILTER is not seen by maybe_flush: whoever changes the
 *   filter should save the permalinks.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Content_Types {

	/** @return array<string, array> Definition of each type, only what differs between them. */
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
					// 0.2.0. 'contain' = the image is a LOGO (not cropped); see post_class().
					'estrategia_web_client_image_fit' => array( 'type' => 'string', 'sanitize' => 'fit', 'default' => 'cover', 'label' => __( 'Image fit (cover for a photo, contain for a logo)', 'estrategia-web' ) ),
					// Unpublished. What used to go in brackets in the title («Portic
					// Villas (agencia de alquiler vacacional)»): the name is not
					// translated and this is (wpml-config.xml, «translate»).
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
					// 'embed' (unpublished; formerly 'url'): accepts pasting the whole <iframe> and keeps its src.
					'estrategia_web_location_map_embed' => array( 'type' => 'string', 'sanitize' => 'embed', 'label' => __( 'Map embed URL', 'estrategia-web' ) ),
					// 0.4.0. For staticMapUrl / staticMapId of estrategia-web/map (parent theme). See local_image_url().
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

	/** Registers the enabled types, with their taxonomy and their fields. On init. */
	public static function register(): void {
		$definitions = self::definitions();
		$position    = 21;
		foreach ( Settings::enabled_types() as $type ) {
			$def  = $definitions[ $type ];
			$slug = self::slug( $def['slug'], $type );
			$name = Type_Names::name( $type );

			register_post_type(
				$name,
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
					Type_Names::name( $tax['name'] ),
					$name,
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
					$name,
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
	 * The public slug of a type or taxonomy, in order: the translated one
	 * («clientes») ← the one set in the option (`rewrite_slugs`, 0.2.0) ← the
	 * filter, which still overrides both.
	 */
	private static function slug( string $translated, string $name ): string {
		$fixed = (string) ( ( (array) Settings::get( 'rewrite_slugs' ) )[ $name ] ?? '' );
		/**
		 * Changes the public slug of a type (or of its taxonomy) on a site.
		 *
		 * @param string $slug The `rewrite_slugs` slug if there is one; otherwise, the translated one («clientes»).
		 * @param string $name The KEY of the type or taxonomy («client», «client_sector»), without
		 *                     the prefix of the registered name (see Type_Names): that is how it was before 1.2.0.
		 */
		return (string) apply_filters( 'estrategia_web_site_rewrite_slug', '' !== $fixed ? $fixed : $translated, $name );
	}

	/** @return string[] The meta keys of a type (for the test and the README). */
	public static function meta_keys( string $type ): array {
		return array_keys( self::definitions()[ $type ]['meta'] ?? array() );
	}

	/** @return array<string,string> Meta key => human-readable name (the locations source shows them in the editor). */
	public static function meta_fields( string $type ): array {
		return array_map( static fn( $meta ) => $meta['label'], self::definitions()[ $type ]['meta'] ?? array() );
	}

	/**
	 * @return array<string,array{label:string,type:string,sanitize:string}> A type's fields
	 * with their sanitiser kind: the editor panel (Meta_Panel) picks the control with it.
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
			// The code Google Maps gives (Share › Embed a map) is an <iframe>:
			// only its src is saved. A bare URL goes through the same as with 'url'.
			'embed'    => static fn( $v ) => esc_url_raw( trim( html_entity_decode( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', (string) $v, $m ) ? $m[1] : (string) $v ) ), array( 'http', 'https' ) ),
			'email'    => static fn( $v ) => is_email( trim( (string) $v ) ) ? sanitize_email( (string) $v ) : '',
			'textarea' => static fn( $v ) => sanitize_textarea_field( (string) $v ),
			// 0 = no rating; anything else is clamped to the 1-5 range.
			'rating'   => static fn( $v ) => (int) $v <= 0 ? 0 : min( 5, (int) $v ),
			// Only an id that really is a client; otherwise, 0.
			'client'   => static fn( $v ) => ( (int) $v > 0 && Type_Names::name( 'client' ) === get_post_type( (int) $v ) ) ? (int) $v : 0,
			// Only two values; anything else (or empty) is the default one.
			'fit'      => static fn( $v ) => 'contain' === trim( (string) $v ) ? 'contain' : 'cover',
			// 0.4.0, static map of a location.
			'local_image' => static fn( $v ) => self::local_image_url( (string) $v ),
			'image_id'    => static fn( $v ) => ( (int) $v > 0 && wp_attachment_is_image( (int) $v ) ) ? (int) $v : 0,
			default    => static fn( $v ) => sanitize_text_field( (string) $v ),
		};
	}

	/**
	 * URL of an image served by THIS site, or '' (0.4.0, `estrategia_web_location_static_map`).
	 * The same three forms accepted by staticMapUrl of estrategia-web/map in the
	 * parent theme (estrategia_web_map_static_url), which checks it again at render time:
	 *
	 *   assets/maps/sede.webp            THEME path (child, then parent); saved without «./»
	 *   /wp-content/uploads/mapa.webp    site path
	 *   https://this-domain/…/mapa.webp  absolute URL on the SAME host as home_url()
	 *
	 * Why so strict: a map from another domain is a request to a third party
	 * without consent on page load (with the visitor's IP); the theme would
	 * discard it anyway, but here it is noticed on save and not on the site.
	 * Edge case: «//otro.test/a.png» has a host even though it has no scheme; it
	 * is treated as another domain. The trap: esc_url_raw() prepends «http://»
	 * to a path without a leading «/»; that is why paths are validated by hand.
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
		// Path: only URL characters that need no escaping (no spaces, quotes or «<»).
		if ( ! preg_match( '#^[A-Za-z0-9._~/\-]+(\?[A-Za-z0-9._~=&%\-]*)?$#', $value ) ) {
			return '';
		}
		return preg_replace( '#^\./#', '', $value );
	}

	/**
	 * post_class filter: a client with estrategia_web_client_image_fit = 'contain' carries the
	 * `has-image-fit-contain` class (on its <li> in a Query, on its single page).
	 * The plugin does NOT add CSS: how a logo is rendered is the theme's job (README).
	 *
	 * The trap: it is PHP, so it only shows on the site. The preview of a Query
	 * in the editor is rendered by JS and does not carry this class: there the
	 * logos look cropped even though on the site they do not (deduced from core,
	 * not tested).
	 *
	 * @param string[] $classes
	 */
	public static function post_class( $classes, $css_class = '', $post_id = 0 ): array {
		$classes = (array) $classes;
		$post_id = (int) $post_id;
		if ( $post_id > 0 && Type_Names::name( 'client' ) === get_post_type( $post_id ) && 'contain' === get_post_meta( $post_id, 'estrategia_web_client_image_fit', true ) ) {
			$classes[] = 'has-image-fit-contain';
		}
		return $classes;
	}

	/**
	 * If the enabled types or a set slug (rewrite_slugs) change, the URL rules
	 * are dropped so that WordPress rebuilds them on the next load, already
	 * with the new values. (flush_rewrite_rules() here would rebuild them with
	 * the OLD ones: this load registered the types from before the change.)
	 */
	public static function maybe_flush( $old, $new ): void {
		$types = static function ( $o ): array {
			$t = is_array( $o ) ? (array) ( $o['enabled_types'] ?? array() ) : array();
			sort( $t );
			return $t;
		};
		// With none set, array() and all-empty are the same (an option from 0.1.0).
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
