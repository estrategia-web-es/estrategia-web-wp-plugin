<?php
namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

/**
 * THE DEFINITIONS AS DATA OF EACH SITE (17-10-2026): one post of the private
 * type eweb_content_type per content type, with the definition's JSON as its
 * content. Created and edited in «Estrategia Web → Content types» (the
 * editor, assets/constructor.js) through the REST route of class-rest.php,
 * loaded into a new site from its repo's content/ and brought back to the
 * repo by the site's own scripts (Lavandería: content/semilla/cargar.mjs and
 * despliegue/bajar-contenido.mjs). David, 17-10-2026: «para mí es dato: un
 * JSON en content».
 *
 * WHY A POST AND NOT AN OPTION, the decision:
 * - REVISIONS for free: every save of a definition keeps the previous JSON
 *   (supports «revisions»), so a client who breaks a type from the panel can
 *   be taken back to yesterday's without a backup.
 * - ONE ROW PER TYPE: two people editing two types never overwrite each
 *   other (one option would be the whole array, last save wins), and the
 *   content loads protect each one by its own fingerprint (Lavandería's
 *   _semilla_huella meta works on any post: title, content, status).
 * - EXPORT: Tools › Export carries them (can_export) with the site's
 *   content, where they belong.
 * - WPML: declared «not translatable» (Wpml::filter_config): a definition is
 *   ONE for every language — its labels and slugs are per language INSIDE
 *   the JSON — and WPML must not offer to duplicate it.
 * - The price: one query per request to read them (all(); a few rows, on the
 *   post_type index). Measured nothing heavier is needed; if a site ever has
 *   hundreds, cache them in an autoloaded option here.
 *
 * WHAT IS STORED: post_title and post_name = the type name; post_content =
 * the RAW definition (what the person wrote, not normalized: defaults stay
 * defaults if the plugin changes them); post_status «publish» = active
 * (registered), «draft» = saved but switched off (the type and its URLs
 * disappear, its entries stay: the old «enabled_types» checkbox of
 * modules/site, which is how a template is switched off).
 *
 * How to maintain:
 * - Who and when: whoever changes how a definition is stored. Everything
 *   writes through save() — the REST route, the migration, the tests — so a
 *   new rule (a key that must never be stored) goes there.
 * - Boundary case, with a name: a definition whose JSON no longer parses
 *   (someone wrote the row by SQL). all() returns it with 'definition' null
 *   and Loader lists it as an error; it is never registered half.
 * - THE TRAPS, both measured on the first save:
 *   · wp_insert_post() UNSLASHES what it gets: the «é» or «\/» of a JSON
 *     lose their backslash and the JSON stops parsing. save() wp_slash()es.
 *   · For a user without unfiltered_html (DISALLOW_UNFILTERED_HTML, a
 *     multisite admin) kses filters post_content and eats anything that
 *     looks like a tag. encode() escapes < > & ' (JSON_HEX_*), so there is
 *     nothing to eat, and keep_raw() puts back what was sent for this post
 *     type in any case.
 */

final class Store {

	/** ≤ 20 characters (WordPress cuts a post type there). The plugin's own prefix: it is the plugin's type. */
	public const POST_TYPE = 'eweb_content_type';

	/** @var array<string,array{id:int,name:string,definition:?array,json:string,active:bool,modified:string}>|null */
	private static ?array $cache = null;

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 1 ); // Before Registrar (init 5) reads them.
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_raw' ), 99, 3 );
		foreach ( array( 'save_post_' . self::POST_TYPE, 'deleted_post', 'trashed_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget' ) );
		}
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Content type definitions', 'estrategia-web' ),
					'singular_name' => __( 'Content type definition', 'estrategia-web' ),
				),
				'public'       => false,
				'show_ui'      => false, // Edited in «Estrategia Web → Content types», not as posts.
				'show_in_rest' => false, // Its own route: class-rest.php, which validates.
				'rewrite'      => false,
				'query_var'    => false,
				'can_export'   => true,
				'supports'     => array( 'title', 'revisions' ),
				'capabilities' => array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'create_posts' ), 'manage_options' ),
				'map_meta_cap' => false,
			)
		);
	}

	/**
	 * Every stored definition, active or not, by type name. Read once per
	 * request; a row whose JSON does not parse keeps its post name as key.
	 *
	 * @return array<string,array{id:int,name:string,definition:?array,json:string,active:bool,modified:string}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$posts = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'numberposts'            => -1,
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'suppress_filters'       => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- WPML: every definition, whatever the language of the request.
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$def  = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $post->post_content ), true );
			$def  = is_array( $def ) && ! array_is_list( $def ) ? $def : null;
			$name = is_string( $def['name'] ?? null ) ? $def['name'] : (string) $post->post_name;
			$out[ $name ] ??= array(
				'id'         => (int) $post->ID,
				'name'       => $name,
				'definition' => $def,
				'json'       => (string) $post->post_content,
				'active'     => 'publish' === $post->post_status,
				'modified'   => (string) $post->post_modified_gmt,
			);
		}
		return self::$cache = $out;
	}

	/** One stored definition by type name, or null. */
	public static function get( string $name ): ?array {
		return self::all()[ $name ] ?? null;
	}

	/**
	 * Stores a RAW definition (already validated by the caller: class-rest.php
	 * and Migration do). With $id, that row is updated (a rename keeps its id
	 * and its revisions).
	 *
	 * @return int|\WP_Error The post id.
	 */
	public static function save( array $definition, bool $active = true, int $id = 0 ) {
		unset( $definition['$schema'] ); // A path on someone's disk; meaningless in the database.
		$name = (string) ( $definition['name'] ?? '' );
		$data = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $name,
			'post_name'    => $name,
			'post_status'  => $active ? 'publish' : 'draft',
			'post_content' => wp_slash( self::encode( $definition ) ),
		);
		if ( $id ) {
			$data['ID'] = $id;
		}
		$result = $id ? wp_update_post( $data, true ) : wp_insert_post( $data, true );
		self::forget();
		Loader::forget();
		return $result;
	}

	/** Deletes the row of a definition (and its revisions). Its entries are not touched: see class-rest.php. */
	public static function delete( int $id ): bool {
		$done = (bool) wp_delete_post( $id, true );
		self::forget();
		Loader::forget();
		return $done;
	}

	/** Switches a definition on or off without touching its JSON. */
	public static function set_active( int $id, bool $active ): bool {
		$done = ! is_wp_error( wp_update_post( array( 'ID' => $id, 'post_status' => $active ? 'publish' : 'draft' ), true ) );
		self::forget();
		Loader::forget();
		return $done;
	}

	/** The JSON as it is stored: readable (pretty, unescaped Unicode) and with nothing kses could take for HTML. */
	public static function encode( array $definition ): string {
		return (string) wp_json_encode( $definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS );
	}

	/**
	 * wp_insert_post_data: whatever filters ran on post_content (kses for a
	 * user without unfiltered_html), a definition is stored as it was sent.
	 * Only for this post type, and only what came in this very call.
	 *
	 * @param array $data        Slashed post data about to be written.
	 * @param array $postarr     Sanitized input.
	 * @param array $unsanitized The input before sanitize_post().
	 */
	public static function keep_raw( $data, $postarr, $unsanitized = array() ) {
		if ( self::POST_TYPE === ( $data['post_type'] ?? '' ) && isset( $unsanitized['post_content'] ) ) {
			$data['post_content'] = $unsanitized['post_content'];
		}
		return $data;
	}

	/** Forget what was read (after a write, and in tests). */
	public static function forget(): void {
		self::$cache = null;
	}

	/**
	 * How many entries a type has in the database, in any status but
	 * auto-draft (trash included: emptying it is the person's decision).
	 * Counted in wp_posts directly: the type may not be registered right now.
	 */
	public static function entries( string $type ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'inherit')", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** How many terms a taxonomy has (registered or not). */
	public static function terms( string $taxonomy ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
