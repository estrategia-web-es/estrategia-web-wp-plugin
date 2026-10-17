<?php
/**
 * THE OLD TYPES OF modules/site, NOW TEMPLATES (17-10-2026). Up to 1.4.x the
 * site module registered clients, testimonials, locations, projects and
 * portfolio in PHP, switched on by the «enabled_types» key of the
 * estrategia_web_site option (Company details). Now they are definitions in
 * the database made from this module's templates (class-templates.php), and
 * this class is the bridge, so that NOTHING visible changes on a site that
 * had them:
 *
 * 1. THE MIGRATION (migrate(), once per site, on init 2 — after Store
 *    registers its post type at init 1 and before Registrar reads the
 *    definitions at init 5, so the same request already registers them):
 *    for each template whose type is enabled in «enabled_types» OR has
 *    entries in the database, and has no definition yet, it stores the
 *    template's definition — switched ON if it was enabled, OFF if it only
 *    had entries (as before: not registered, entries kept). Its URL slugs are
 *    the ones the site SERVES TODAY (current_slug()), not the template's:
 *    the site language's (the «rewrite_slugs» key of the option, the
 *    estrategia_web_site_rewrite_slug filter, or the old gettext slug, which
 *    is the template's in that language) and, for every other WPML language,
 *    what WPML serves now (its slug translation if there is one; if not, the
 *    same slug — Lavandería: /en/sedes/, never /en/locations/).
 *    Done when the option estrategia_web_content_types_site_types exists.
 *
 * 2. «enabled_types» KEEPS WORKING as a switch (changed()), so content loads
 *    and themes that still write it do not break: a key that appears adds
 *    its template (or switches its definition on), a key that disappears
 *    switches OFF the definition made from that template. A type ADDED this
 *    way with no entries takes the template's slugs per language (it is a
 *    new type); with entries, the ones it has today. The Company details
 *    screen no longer shows the checkboxes: types are switched on and off in
 *    «Estrategia Web → Content types».
 *
 * 3. «rewrite_slugs» KEEPS WORKING too, for the SITE language: a key that
 *    changes there sets that language's slug of the definition made from the
 *    template (Lavandería's content load sends {"client": "cliente"}: the
 *    /clientes/ page is its own); emptied, back to the template's slug in
 *    that language. No 301s here (the old option never made any). A slug
 *    edited in the panel afterwards wins until the option changes again.
 *
 * How to maintain:
 * - Who and when: whoever adds a template that replaces something a site
 *   already had registered by other code. Brand-new templates need nothing
 *   here.
 * - Boundary case, with a name: a site that switched a type OFF in the
 *   panel while «enabled_types» still lists it. Nothing switches it on
 *   again: changed() only acts on what CHANGES in the option, never on what
 *   it already said.
 * - Another: two requests at once on the first load after the update. An
 *   add_option() lock (atomic: the option name is unique) lets only one
 *   migrate; the other registers what is already stored.
 * - THE TRAP: the migration must run with the content-types module ON. A
 *   site that turns it off loses the types from the admin and their URLs
 *   (entries stay); the site module's Company details screen warns about it
 *   (modules/site/includes/admin/class-admin.php). That is why this module
 *   is Default: on since this release.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Site_Types {

	public const OPTION   = 'estrategia_web_site';
	public const MIGRATED = 'estrategia_web_content_types_site_types';
	public const LOCK     = 'estrategia_web_content_types_site_types_lock';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'migrate' ), 2 );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'changed' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, static fn( $name, $value ) => self::changed( array(), $value ), 10, 2 );
	}

	/** @return string[] The template keys «enabled_types» lists. */
	public static function enabled( $option ): array {
		$keys = is_array( $option ) ? (array) ( $option['enabled_types'] ?? array() ) : array();
		return array_values( array_intersect( array_keys( Templates::all() ), array_map( 'strval', $keys ) ) );
	}

	/** See the header, 1. Idempotent: what already has a definition is not touched. */
	public static function migrate(): void {
		if ( false !== get_option( self::MIGRATED, false ) ) {
			return;
		}
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK ) < 300 ) {
				return; // Another request is migrating.
			}
			update_option( self::LOCK, time(), false ); // A lock left by a request that died.
		}
		try {
			$done    = array();
			$enabled = self::enabled( get_option( self::OPTION, array() ) );
			foreach ( Templates::all() as $key => $template ) {
				$on      = in_array( $key, $enabled, true );
				$entries = Store::entries( $template['name'] );
				if ( Store::get( $template['name'] ) || ( ! $on && 0 === $entries ) ) {
					continue;
				}
				$id = Store::save( self::definition( $key, true ), $on );
				if ( ! is_wp_error( $id ) ) {
					$done[ $key ] = $on ? 'on' : 'off';
				}
			}
			update_option( self::MIGRATED, array( 'version' => ESTRATEGIA_WEB_VERSION, 'types' => $done ), false );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** See the header, 2. update_option_estrategia_web_site. */
	public static function changed( $old, $new ): void {
		$before = self::enabled( $old );
		$after  = self::enabled( $new );
		foreach ( array_diff( $after, $before ) as $key ) {
			$name = Templates::get( $key )['name'];
			$row  = Store::get( $name );
			if ( $row ) {
				if ( ! $row['active'] ) {
					Store::set_active( $row['id'], true );
				}
				continue;
			}
			Store::save( self::definition( $key, Store::entries( $name ) > 0 ), true );
		}
		foreach ( array_diff( $before, $after ) as $key ) {
			$row = Store::get( Templates::get( $key )['name'] );
			if ( $row && $row['active'] && ( $row['definition']['template'] ?? null ) === $key ) {
				Store::set_active( $row['id'], false );
			}
		}
		// «rewrite_slugs» that changes moves the site language's slug of the definition made from
		// that template (the old option's behaviour: Lavandería's {"client": "cliente"}). Emptied:
		// back to the template's slug in that language (the old gettext one).
		$old_slugs = self::fixed_slugs( $old );
		$new_slugs = self::fixed_slugs( $new );
		foreach ( Templates::all() as $key => $template ) {
			$row = Store::get( $template['name'] );
			if ( ! $row || ! $row['definition'] || ( $row['definition']['template'] ?? null ) !== $key ) {
				continue;
			}
			$def   = $row['definition'];
			$dirty = false;
			if ( ( $old_slugs[ $key ] ?? '' ) !== ( $new_slugs[ $key ] ?? '' ) ) {
				$def['slug'] = self::with_site_slug( $def['slug'] ?? null, $new_slugs[ $key ] ?? self::gettext_slug( $key, $template['slug'] ), $template['slug'] );
				$dirty       = true;
			}
			foreach ( $def['taxonomies'] ?? array() as $i => $tax ) {
				$tax_key = is_array( $tax ) ? substr( $tax['name'], strlen( $def['prefix'] ) ) : '';
				if ( '' !== $tax_key && ( $old_slugs[ $tax_key ] ?? '' ) !== ( $new_slugs[ $tax_key ] ?? '' ) ) {
					$original = self::template_taxonomy_slug( $template, $tax['name'] ) ?? $tax_key;
					$def['taxonomies'][ $i ]['slug'] = self::with_site_slug( $tax['slug'] ?? null, $new_slugs[ $tax_key ] ?? self::gettext_slug( $tax_key, $original ), $original );
					$dirty = true;
				}
			}
			if ( $dirty ) {
				Store::save( $def, $row['active'], $row['id'] );
			}
		}
	}

	/** @return array<string,string> The non-empty «rewrite_slugs» of an option value. */
	private static function fixed_slugs( $option ): array {
		$slugs = is_array( $option ) ? (array) ( $option['rewrite_slugs'] ?? array() ) : array();
		return array_filter( array_map( 'strval', $slugs ), 'strlen' );
	}

	/**
	 * A slug with the SITE language's value replaced: $fixed, or (empty) the
	 * template's in that language. A plain string stays a string.
	 *
	 * @param string|array<string,string>|null $slug
	 * @param string|array<string,string>      $template_slug
	 * @return string|array<string,string>
	 */
	private static function with_site_slug( $slug, string $fixed, $template_slug ) {
		$value = '' !== $fixed ? $fixed : Schema::text( $template_slug, \EstrategiaWeb\Plugin::site_locale() );
		if ( is_array( $slug ) ) {
			$slug[ Rest::languages()[0] ] = $value;
			return $slug;
		}
		return $value;
	}

	/**
	 * THE SLUG modules/site REGISTERED for a key: its gettext translation into
	 * the SITE language (Plugin::url_slug() picks that file whatever the
	 * request's language). Kept as gettext on purpose, not read from the
	 * template: it is what a 1.4.x site really served in ANY locale — the
	 * template only knows en, es and ca — and these msgids are also what
	 * tests/catalan.test.mjs and tests/wplang.test.mjs measure url_slug() with.
	 * A key that is not one of these: the template's slug in that locale.
	 *
	 * @param string|array<string,string> $template_slug
	 */
	public static function gettext_slug( string $key, $template_slug ): string {
		$slugs = array(
			'client'             => _x( 'clients', 'URL slug', 'estrategia-web' ),
			'client_sector'      => _x( 'client-sector', 'URL slug', 'estrategia-web' ),
			'testimonial'        => _x( 'testimonials', 'URL slug', 'estrategia-web' ),
			'location'           => _x( 'locations', 'URL slug', 'estrategia-web' ),
			'project'            => _x( 'projects', 'URL slug', 'estrategia-web' ),
			'project_category'   => _x( 'project-category', 'URL slug', 'estrategia-web' ),
			'portfolio'          => _x( 'portfolio', 'URL slug', 'estrategia-web' ),
			'portfolio_category' => _x( 'portfolio-category', 'URL slug', 'estrategia-web' ),
		);
		return $slugs[ $key ] ?? Schema::text( $template_slug, \EstrategiaWeb\Plugin::site_locale() );
	}

	/** The slug a template gives one of its taxonomies, or null. */
	private static function template_taxonomy_slug( array $template, string $name ) {
		foreach ( $template['taxonomies'] ?? array() as $tax ) {
			if ( is_array( $tax ) && $tax['name'] === $name ) {
				return $tax['slug'] ?? null;
			}
		}
		return null;
	}

	/**
	 * The definition stored for a template key. $keep_urls: the slugs the site
	 * serves today (a type that already had URLs); otherwise the template's.
	 */
	public static function definition( string $key, bool $keep_urls ): array {
		$def = Templates::materialize( (array) Templates::get( $key ) );
		if ( ! $keep_urls ) {
			// A new type: the template's slugs, except a «rewrite_slugs» the site set (its language only).
			$fixed = self::fixed_slugs( get_option( self::OPTION, array() ) );
			if ( isset( $fixed[ $key ] ) ) {
				$def['slug'] = self::with_site_slug( $def['slug'], $fixed[ $key ], $def['slug'] );
			}
			foreach ( $def['taxonomies'] ?? array() as $i => $tax ) {
				$tax_key = is_array( $tax ) ? substr( $tax['name'], strlen( $def['prefix'] ) ) : '';
				if ( isset( $fixed[ $tax_key ] ) ) {
					$def['taxonomies'][ $i ]['slug'] = self::with_site_slug( $tax['slug'], $fixed[ $tax_key ], $tax['slug'] );
				}
			}
			return $def;
		}
		$def['slug'] = self::current_slug( $key, $def['name'], $def['slug'], 'post' );
		foreach ( $def['taxonomies'] ?? array() as $i => $tax ) {
			if ( is_array( $tax ) ) {
				$tax_key = substr( $tax['name'], strlen( $def['prefix'] ) ); // eweb_client_sector → client_sector, the option's key.
				$def['taxonomies'][ $i ]['slug'] = self::current_slug( $tax_key, $tax['name'], $tax['slug'] ?? $tax_key, 'taxonomy' );
			}
		}
		return $def;
	}

	/**
	 * What a type or taxonomy of the old site module serves today, as a slug
	 * per language. The site language's: «rewrite_slugs» ← the old gettext
	 * slug (= the template's in the site locale) ← the filter, the same order
	 * as the old Content_Types::slug(). The other WPML languages: WPML's slug
	 * translation if it has one, else the same slug (what WPML serves when
	 * the slug is not translated).
	 *
	 * @param string|array<string,string> $template_slug
	 * @return array<string,string> language code => slug
	 */
	public static function current_slug( string $key, string $name, $template_slug, string $kind ): array {
		$option = get_option( self::OPTION, array() );
		$fixed  = is_array( $option ) ? (string) ( ( (array) ( $option['rewrite_slugs'] ?? array() ) )[ $key ] ?? '' ) : '';
		$base   = '' !== $fixed ? $fixed : self::gettext_slug( $key, $template_slug );
		/** The old site module's filter, kept for what it registered (documented in modules/site's README section). */
		$base  = (string) apply_filters( 'estrategia_web_site_rewrite_slug', $base, $key );
		$langs = Rest::languages();
		$out   = array();
		foreach ( $langs as $i => $code ) {
			$slug         = 0 === $i ? $base : (string) apply_filters( 'wpml_get_translated_slug', $base, $name, $code, $kind );
			$out[ $code ] = '' !== $slug ? $slug : $base;
		}
		return $out;
	}
}
