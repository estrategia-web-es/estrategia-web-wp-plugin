<?php
/**
 * WHAT IS LEFT HERE of the usual content types of a corporate site (clients,
 * testimonials, locations, projects, portfolio). Up to 1.4.x this class
 * REGISTERED them, switched on by «enabled_types» of the company details.
 * Since 17-10-2026 they are definitions of the content-types module, made
 * from its templates (modules/content-types/templates/, same type, taxonomy
 * and meta names), stored in each site's database and edited in
 * «Estrategia Web → Content types»; a site that had them gets them stored
 * once, with the URLs it serves today (modules/content-types/includes/
 * class-site-types.php, which also keeps «enabled_types» working as a
 * switch).
 *
 *   key          registered       taxonomy
 *   client       eweb_client      eweb_client_sector
 *   testimonial  eweb_testimonial
 *   location     eweb_location
 *   project      eweb_project     eweb_project_category
 *   portfolio    eweb_portfolio   eweb_portfolio_category
 *
 * What STAYS in this module because it is not a definition's business:
 * - post_class(): `has-image-fit-contain` on a client whose image is a logo
 *   (contract with the themes, README, classes table). The plugin adds the
 *   class, never CSS.
 * - meta_keys() / meta_fields(): the fields of one of these types as the
 *   site's DEFINITION has them, for the `estrategia-web-site/location`
 *   Block Bindings source (class-location.php). They follow the definition:
 *   a field the site adds to its locations in the panel is offered by that
 *   source too.
 * - Type_Names: the prefix. (The aliases of the pre-1.0.0 and unprefixed
 *   names went in 2.0.1.)
 * - The «enabled_types» and «rewrite_slugs» keys of the option (Settings):
 *   contract, read by the migration and by the switch.
 *
 * How to maintain:
 * - Who and when: whoever touches one of these types. Its fields, labels,
 *   slugs and WPML modes are in the TEMPLATE (for new sites) and in each
 *   site's stored definition (for that site): never here again.
 * - Boundary case, with a name: the content-types module OFF. Nothing
 *   registers these types (entries stay); meta_fields() is empty and the
 *   location source gives ''. Company details warns about it.
 * - The trap: post_class() is PHP, so it only shows on the site. The preview
 *   of a Query in the editor is rendered by JS and does not carry the class:
 *   there the logos look cropped even though on the site they do not
 *   (deduced from core, not tested).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Content_Types {

	/** @return string[] The meta keys of a type, by KEY («location»), as its definition has them. */
	public static function meta_keys( string $type ): array {
		return array_keys( self::meta_fields( $type ) );
	}

	/** @return array<string,string> Meta key => label in the request's language, from the site's definition of that type. */
	public static function meta_fields( string $type ): array {
		$definition = class_exists( \EstrategiaWeb\ContentTypes\Loader::class ) ? \EstrategiaWeb\ContentTypes\Loader::type( Type_Names::name( $type ) ) : null;
		$out        = array();
		foreach ( $definition ? $definition['fields'] : array() as $field ) {
			$out[ $field['name'] ] = \EstrategiaWeb\ContentTypes\Schema::text( $field['label'], \EstrategiaWeb\Plugin::locale() );
		}
		return $out;
	}

	/**
	 * post_class filter: a client with estrategia_web_client_image_fit = 'contain' carries the
	 * `has-image-fit-contain` class (on its <li> in a Query, on its single page).
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
}
