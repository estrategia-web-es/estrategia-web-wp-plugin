<?php
/**
 * THE PLUGIN'S TEMPLATES (17-10-2026): the five types of a corporate site
 * that modules/site used to register in PHP — clients, testimonials,
 * locations, projects, portfolio — as definitions in templates/<key>.json.
 * «Add testimonials» in «Estrategia Web → Content types» COPIES the template
 * into the site's database (Store), and from then on that copy is the site's:
 * editable like any other definition, and a later plugin update does NOT
 * change it (a template is a starting point, not a dependency).
 *
 * Same names as the old types, on purpose (type, taxonomy and meta: the
 * contract of the themes, the content loads and the data already stored), so
 * a site that had them registered by modules/site changes nothing by moving
 * to the copy (Migration). That is why a template may use the plugin's
 * reserved prefixes: Schema::validate() lets the names it ships keep them.
 *
 *   key          type              taxonomy                  fields
 *   client       eweb_client       eweb_client_sector        url, image fit, business type
 *   testimonial  eweb_testimonial  —                         author, role, rating, related client
 *   location     eweb_location     —                         address, phone, email, hours, map link, embed, static map ×2
 *   project      eweb_project      eweb_project_category     —
 *   portfolio    eweb_portfolio    eweb_portfolio_category   —
 *
 * The labels are in English, Spanish and Catalan (the plugin's languages:
 * languages/*.po), taken from the .po files of the old PHP labels so the
 * admin reads the same.
 *
 * How to maintain:
 * - Who and when: whoever changes what a NEW site gets with «Add …». Sites
 *   that already copied it keep their copy: a change here reaches them only
 *   if someone edits their definition (or imports the new JSON). Say so in
 *   the CHANGELOG.
 * - A new template: templates/<key>.json with "template": "<key>" and a
 *   prefix of the plugin (eweb_); tests/constructor-pure.test.mjs validates
 *   every file here. Its labels in the three languages.
 * - Boundary case, with a name: a template whose type a site ALREADY has
 *   from a file of its theme (Loader). The template is not offered: one
 *   name, one definition.
 * - The trap: the type names here are the CONTRACT (Type_Names in
 *   modules/site, the themes' patterns, each site's content/semilla/tipos.mjs).
 *   Never rename one in a template: the copies in the sites keep the old
 *   name and the two would part ways.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Templates {

	/** @var array<string,array>|null key => raw definition */
	private static ?array $cache = null;

	public static function dir(): string {
		return ESTRATEGIA_WEB_CONTENT_TYPES_DIR . 'templates';
	}

	/** @return array<string,array> Template key => raw definition, in menu order. */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$out = array();
		foreach ( (array) glob( self::dir() . '/*.json' ) as $file ) {
			$def = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a file of the plugin.
			if ( is_array( $def ) && isset( $def['template'] ) ) {
				unset( $def['$schema'] );
				$out[ (string) $def['template'] ] = $def;
			}
		}
		uasort( $out, static fn( $a, $b ) => ( $a['menu_position'] ?? 99 ) <=> ( $b['menu_position'] ?? 99 ) );
		return self::$cache = $out;
	}

	/** The raw template, or null. */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/** The template a definition says it comes from, if that template exists. */
	public static function for_definition( $def ): ?array {
		return is_array( $def ) && is_string( $def['template'] ?? null ) ? self::get( $def['template'] ) : null;
	}

	/**
	 * What «Add …» stores: the template with its URL slugs resolved to the
	 * SITE language (Plugin::site_locale(), WPML's default: «sedes» on a
	 * Spanish site, «seus» on a Catalan one). The files keep one slug per
	 * language so a new site gets the right one; a definition takes ONE slug
	 * until slugs per language arrive in the schema (branch content-types/url),
	 * and then this only has to stop resolving them.
	 */
	public static function materialize( array $template, ?string $locale = null ): array {
		$locale ??= \EstrategiaWeb\Plugin::site_locale();
		if ( isset( $template['slug'] ) && is_array( $template['slug'] ) ) {
			$template['slug'] = Schema::text( $template['slug'], $locale );
		}
		foreach ( $template['taxonomies'] ?? array() as $i => $tax ) {
			if ( is_array( $tax ) && isset( $tax['slug'] ) && is_array( $tax['slug'] ) ) {
				$template['taxonomies'][ $i ]['slug'] = Schema::text( $tax['slug'], $locale );
			}
		}
		return $template;
	}

	/** The template that registers a type name, or null («eweb_client» → client's). */
	public static function by_type( string $type ): ?array {
		foreach ( self::all() as $template ) {
			if ( ( $template['name'] ?? '' ) === $type ) {
				return $template;
			}
		}
		return null;
	}
}
