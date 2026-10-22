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
	 * What «Add …» stores for a NEW type: the template as it is, with its URL
	 * slug per language ({"en": "locations", "es": "sedes", "ca": "seus"}).
	 * Registrar registers the SITE language's one and, with WPML, the others
	 * become slug translations (Wpml::sync_slugs()).
	 * Boundary case, with a name: a type that ALREADY has published URLs
	 * (modules/site registered it before). That one must not take these
	 * slugs — /en/sedes/ would become /en/locations/ — so Site_Types builds
	 * its slugs from what the site serves today, not from here.
	 */
	public static function materialize( array $template ): array {
		unset( $template['$schema'] );
		return $template;
	}

	/**
	 * NEW FIELDS OF A TEMPLATE, for a site that copied it before they existed
	 * (22-10-2026: the location template got the address by parts, the
	 * structured hours, the coordinates and the business type for
	 * modules/local-business; Lavandería had its copy since 17-10-2026).
	 *
	 * The decision: they are OFFERED, never added by themselves. The copy is
	 * the site's (header of this file): a plugin update that wrote into it
	 * would bring back a field the site removed on purpose, and the client's
	 * panel would change under them. So the constructor shows «The template
	 * has N new fields» with a button that puts them into the definition
	 * being edited (assets/constructor.js), and the person saves. A deploy
	 * that needs them on a published site does the same in a script, with
	 * add_missing() and Store::save() (Lavandería: despliegue/negocio-local.php).
	 *
	 * «Missing» = a field of the template whose NAME the definition does not
	 * have. A field the site changed (another label, wpml) is NOT missing: it
	 * is the site's version. Boundary case, with a name: a site that REMOVED
	 * the template's «Opening hours (for search engines)» on purpose is offered
	 * it again on every visit to its editor; the button is never pressed for
	 * it, and nothing changes by itself.
	 *
	 * @return array<int, array{after: ?string, field: array}> Each missing field with
	 *         the template field before it (null: first) — where add_missing() puts it.
	 */
	public static function missing_fields( $def ): array {
		$template = self::for_definition( $def );
		if ( ! $template || ! is_array( $def['fields'] ?? null ) ) {
			return array();
		}
		$have = array();
		foreach ( $def['fields'] as $field ) {
			if ( is_array( $field ) && isset( $field['name'] ) ) {
				$have[ (string) $field['name'] ] = true;
			}
		}
		$out    = array();
		$before = null;
		foreach ( (array) ( $template['fields'] ?? array() ) as $field ) {
			if ( ! isset( $have[ $field['name'] ] ) ) {
				$out[] = array( 'after' => $before, 'field' => $field );
			}
			$before = $field['name'];
		}
		return $out;
	}

	/**
	 * The definition with the template's missing fields put in: each one right
	 * after the field that precedes it in the template (if the definition
	 * has it; otherwise after the last one put in, or at the end). The fields
	 * the definition has stay exactly as they are, in their order.
	 */
	public static function add_missing( array $def ): array {
		$fields = array_values( (array) ( $def['fields'] ?? array() ) );
		foreach ( self::missing_fields( $def ) as $missing ) {
			$at = count( $fields );
			if ( null === $missing['after'] ) {
				$at = 0;
			} else {
				foreach ( $fields as $i => $field ) {
					if ( ( $field['name'] ?? null ) === $missing['after'] ) {
						$at = $i + 1;
						break;
					}
				}
			}
			array_splice( $fields, $at, 0, array( $missing['field'] ) );
		}
		$def['fields'] = $fields;
		return $def;
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
