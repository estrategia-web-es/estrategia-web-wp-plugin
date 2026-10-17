<?php
/**
 * THE REST ROUTE OF THE CONSTRUCTOR (17-10-2026): what the panel editor
 * (assets/constructor.js) and the sites' content loads use to read and write
 * the definitions stored in the database (class-store.php). Administrators
 * only (manage_options): a definition decides URLs and what the editors see.
 *
 *   GET    /estrategia-web/v1/content-types                 every definition (database and files), the templates, the site languages
 *   POST   /estrategia-web/v1/content-types                 {definition, active?, confirm?}   create (also: import a JSON)
 *   GET    /estrategia-web/v1/content-types/<name>          one, with its raw JSON (export)
 *   POST   /estrategia-web/v1/content-types/<name>          {definition, active?, confirm?}   update (PUT and PATCH too)
 *   DELETE /estrategia-web/v1/content-types/<name>          delete (refused while it has entries)
 *   POST   /estrategia-web/v1/content-types/templates/<key> copy a template into the database («Add testimonials»)
 *
 * WRITING, in this order: Schema::validate() (with the template it comes
 * from) → Schema::check_all() against every other valid definition, from
 * the database and from files → a name already registered by other code →
 * Changes::check() against what is stored. Errors: 400 and nothing is
 * written. Warnings (a field removed, a URL that moves…): 409
 * «estrategia_web_ct_confirm» with the list and nothing written, unless the
 * request says confirm: true — so the panel can show them and ask. A
 * content load sends confirm: true (it is the site's own repo).
 * A URL change, once written, creates the 301s (redirects()).
 *
 * DELETING A TYPE THAT HAS ENTRIES IS REFUSED (decided 17-10-2026), not
 * «deleted leaving them»: entries of a type that no longer exists vanish from
 * the admin — nobody can see, edit or empty them — and the type can only be
 * brought back by recreating it with the exact same name. The way to make a
 * type disappear without losing anything is to switch it OFF (active: false):
 * its menu, its URLs and its REST route go, its entries and its definition
 * stay, and switching it on brings everything back. To really delete it:
 * empty it first (its entries, trash included), then delete.
 *
 * How to maintain:
 * - Who and when: whoever adds an action to the panel. It goes here as a
 *   route (never a write from the page that skips validation), with its case
 *   in tests/constructor.test.mjs (level 2).
 * - Boundary case, with a name: a definition that only exists as a FILE
 *   (Loader). GET lists it with source «file»; writing to its name CREATES the
 *   database copy, which wins from then on (that is «import a theme's JSON»).
 * - The trap: a definition saved in this request is NOT registered until the
 *   next one (types register at init 5; REST runs later). Anything here that
 *   needs the NEW type registered (its permalinks) cannot have it: the
 *   redirects are computed with the OLD registration, which is exactly what
 *   they need (the old URLs).
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NS = 'estrategia-web/v1';

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( 'manage_options' );
		register_rest_route(
			self::NS,
			'/content-types',
			array(
				array( 'methods' => 'GET', 'callback' => array( self::class, 'index' ), 'permission_callback' => $admin ),
				array( 'methods' => 'POST', 'callback' => array( self::class, 'create' ), 'permission_callback' => $admin ),
			)
		);
		register_rest_route(
			self::NS,
			'/content-types/templates/(?P<key>[a-z][a-z0-9_]*)',
			array( 'methods' => 'POST', 'callback' => array( self::class, 'add_template' ), 'permission_callback' => $admin )
		);
		register_rest_route(
			self::NS,
			'/content-types/(?P<name>[a-z][a-z0-9_]*)',
			array(
				array( 'methods' => 'GET', 'callback' => array( self::class, 'show' ), 'permission_callback' => $admin ),
				array( 'methods' => 'POST, PUT, PATCH', 'callback' => array( self::class, 'update' ), 'permission_callback' => $admin ),
				array( 'methods' => 'DELETE', 'callback' => array( self::class, 'delete' ), 'permission_callback' => $admin ),
			)
		);
	}

	/** Everything the panel shows. */
	public static function index(): array {
		$loaded = Loader::load();
		$items  = array();
		foreach ( Store::all() as $name => $row ) {
			$items[ $name ] = self::item( $name );
		}
		foreach ( $loaded['files'] as $name => $source ) {
			$items[ $name ] ??= self::item( $name );
		}
		// Files with errors have no name to list them by: they go apart.
		$broken = array();
		foreach ( $loaded['errors'] as $source => $errors ) {
			if ( ! Loader::is_database( $source ) && ! in_array( $source, $loaded['files'], true ) ) {
				$broken[] = array( 'source' => $source, 'errors' => $errors );
			}
		}
		$templates = array();
		$locale    = determine_locale();
		foreach ( Templates::all() as $key => $template ) {
			$templates[] = array(
				'key'    => $key,
				'name'   => $template['name'],
				'label'  => Schema::text( $template['labels']['plural'], $locale ),
				'added'  => isset( $items[ $template['name'] ] ),
				// Registered by someone else (modules/site's own types while they exist): adding it would clash.
				'taken'  => ! isset( $items[ $template['name'] ] ) && post_type_exists( $template['name'] ),
			);
		}
		return array(
			'types'     => array_values( $items ),
			'broken'    => $broken,
			'templates' => $templates,
			'languages' => self::languages(),
			'redirects' => self::redirects_on(),
			'dirs'      => Loader::dirs(),
			'fieldTypes' => Schema::FIELD_TYPES,
			'supports'  => Schema::SUPPORTS,
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function show( \WP_REST_Request $request ) {
		$item = self::item( (string) $request['name'] );
		return null === $item['definition'] && 'none' === $item['source'] ? self::error( 'estrategia_web_ct_not_found', __( 'There is no content type with that name.', 'estrategia-web' ), 404 ) : rest_ensure_response( $item );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function create( \WP_REST_Request $request ) {
		$def = $request['definition'];
		if ( ! is_array( $def ) ) {
			return self::error( 'estrategia_web_ct_invalid', __( 'Send the definition as a JSON object in “definition”.', 'estrategia-web' ), 400 );
		}
		if ( Store::get( (string) ( $def['name'] ?? '' ) ) ) {
			/* translators: %s: post type name */
			return self::error( 'estrategia_web_ct_exists', sprintf( __( 'The type “%s” already exists: edit it instead (or import it over it).', 'estrategia-web' ), (string) $def['name'] ), 409 );
		}
		return self::write( $def, null, $request );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function update( \WP_REST_Request $request ) {
		$name = (string) $request['name'];
		$row  = Store::get( $name );
		$def  = $request['definition'];
		if ( null === $def && null !== $request['active'] && $row ) {
			// Only switching it on or off.
			Store::set_active( $row['id'], (bool) $request['active'] );
			return rest_ensure_response( self::item( $name ) );
		}
		if ( ! is_array( $def ) ) {
			return self::error( 'estrategia_web_ct_invalid', __( 'Send the definition as a JSON object in “definition”.', 'estrategia-web' ), 400 );
		}
		return self::write( $def, $row, $request, $name );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function delete( \WP_REST_Request $request ) {
		$name = (string) $request['name'];
		$row  = Store::get( $name );
		if ( ! $row ) {
			return self::error( 'estrategia_web_ct_not_found', __( 'There is no content type with that name in the database (a type from a file is deleted in its file).', 'estrategia-web' ), 404 );
		}
		$entries = Store::entries( $name );
		if ( $entries > 0 ) {
			/* translators: %d: number of entries */
			return self::error( 'estrategia_web_ct_has_entries', sprintf( _n( 'It has %d entry, so it cannot be deleted: switch it off instead (its entries stay and come back when you switch it on), or empty it first, trash included.', 'It has %d entries, so it cannot be deleted: switch it off instead (its entries stay and come back when you switch it on), or empty it first, trash included.', $entries, 'estrategia-web' ), $entries ), 409, array( 'entries' => $entries ) );
		}
		Store::delete( $row['id'] );
		return rest_ensure_response( array( 'deleted' => true, 'name' => $name ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function add_template( \WP_REST_Request $request ) {
		$template = Templates::get( (string) $request['key'] );
		if ( ! $template ) {
			return self::error( 'estrategia_web_ct_not_found', __( 'There is no template with that name.', 'estrategia-web' ), 404 );
		}
		if ( Store::get( $template['name'] ) ) {
			/* translators: %s: post type name */
			return self::error( 'estrategia_web_ct_exists', sprintf( __( 'The type “%s” already exists: edit it instead (or import it over it).', 'estrategia-web' ), $template['name'] ), 409 );
		}
		$request->set_param( 'confirm', true );
		return self::write( Templates::materialize( $template ), null, $request );
	}

	/**
	 * The common path of every write (see the header).
	 *
	 * @param array      $def  The RAW definition received.
	 * @param array|null $row  What Store has under the name in the URL (null: new).
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function write( array $def, ?array $row, \WP_REST_Request $request, string $url_name = '' ) {
		unset( $def['$schema'] );
		if ( $row && is_string( $def['name'] ?? null ) && $def['name'] !== $row['name'] && Store::get( $def['name'] ) ) {
			/* translators: %s: post type name */
			return self::error( 'estrategia_web_ct_exists', sprintf( __( 'The type “%s” already exists: edit it instead (or import it over it).', 'estrategia-web' ), $def['name'] ), 409 );
		}
		$errors = Schema::validate( $def, Templates::for_definition( $def ) );
		if ( ! $errors ) {
			$others = Loader::raw();
			unset( $others[ Loader::DB_SOURCE . ( $row['name'] ?? $def['name'] ) ], $others[ Loader::DB_SOURCE . $def['name'] ] );
			// A file with the same name is overridden by the database (Loader): it does not count as a clash.
			$others = array_filter( $others, static fn( $other ) => ( $other['name'] ?? null ) !== $def['name'] );
			$mine   = Loader::DB_SOURCE . $def['name'];
			// Last: check_all() lets the FIRST one keep a name, and what is already stored keeps it.
			$errors = Schema::check_all( array_merge( $others, array( $mine => $def ) ) )[ $mine ] ?? array();
		}
		if ( ! $errors && ! in_array( $def['name'], Registrar::registered(), true ) && post_type_exists( $def['name'] ) && ! Loader::type( $def['name'] ) ) {
			/* translators: %s: post type name */
			$errors[] = sprintf( __( 'The name “%s” is already a post type of WordPress or of other code: choose another.', 'estrategia-web' ), $def['name'] );
		}
		if ( $errors ) {
			return self::error( 'estrategia_web_ct_invalid', __( 'The definition has errors: nothing was saved.', 'estrategia-web' ), 400, array( 'errors' => array_values( $errors ) ) );
		}

		$old     = $row && $row['definition'] && ! Schema::validate( $row['definition'], Templates::for_definition( $row['definition'] ) ) ? Schema::normalize( $row['definition'] ) : null;
		$old   ??= ( '' !== $url_name ? Loader::type( $url_name ) : null ); // A type from a file being imported over: its URLs move too.
		$new     = Schema::normalize( $def );
		$counts  = array( 'entries' => $old ? Store::entries( $old['name'] ) : 0, 'terms' => array() );
		foreach ( $old ? $old['taxonomies'] : array() as $tax ) {
			if ( is_array( $tax ) ) {
				$counts['terms'][ $tax['name'] ] = Store::terms( $tax['name'] );
			}
		}
		$check = Changes::check( $old, $new, $counts );
		if ( $check['errors'] ) {
			return self::error( 'estrategia_web_ct_invalid', __( 'The definition has errors: nothing was saved.', 'estrategia-web' ), 400, array( 'errors' => $check['errors'] ) );
		}
		if ( $check['slugs'] && ! self::redirects_on() ) {
			$check['warnings'][] = __( 'The Redirects module is off, so no redirects are created: the old addresses will give «not found» (404). Switch it on in Estrategia Web → Modules before saving to keep them working.', 'estrategia-web' );
		}
		if ( $check['warnings'] && ! rest_sanitize_boolean( $request['confirm'] ) ) {
			return self::error( 'estrategia_web_ct_confirm', __( 'Saving this changes things that are already published. Read the list and confirm.', 'estrategia-web' ), 409, array( 'warnings' => $check['warnings'] ) );
		}

		$redirects = $check['slugs'] && self::redirects_on() ?self::redirects( $check['slugs'], $old ) : array( 'created' => 0, 'skipped' => array() );
		$active    = null === $request['active'] ? ( $row ? $row['active'] : true ) : (bool) $request['active'];
		$id        = Store::save( $def, $active, $row ? $row['id'] : 0 );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$item              = self::item( $def['name'] );
		$item['warnings']  = $check['warnings'];
		$item['redirects'] = $redirects;
		return new \WP_REST_Response( $item, $row ? 200 : 201 );
	}

	/**
	 * The 301s for URL bases that changed, in the redirects module. The paths
	 * are the ones WordPress builds NOW — this request still has the old
	 * registration (see «The trap»). A rule whose SOURCE is one of the new
	 * paths is removed first: it pointed away from an address that is now
	 * live again (a slug changed and changed back), and it would make the new
	 * rule a loop.
	 *
	 * @param array<int,array{0:string,1:string,2:string,3:string}> $slugs Changes::check()['slugs'].
	 * @return array{created:int,skipped:string[]}
	 */
	private static function redirects( array $slugs, array $old ): array {
		$created = 0;
		$skipped = array();
		foreach ( $slugs as [ $kind, $name, $from, $to, $lang ] ) {
			$paths = array();
			if ( 'type' === $kind ) {
				if ( $old['has_archive'] && ( $archive = get_post_type_archive_link( $name ) ) ) {
					$paths[] = wp_make_link_relative( $archive );
				}
				$ids = get_posts( array( 'post_type' => $name, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
				foreach ( $ids as $id ) {
					$paths[] = wp_make_link_relative( (string) get_permalink( $id ) );
				}
			} elseif ( taxonomy_exists( $name ) ) {
				foreach ( (array) get_terms( array( 'taxonomy' => $name, 'hide_empty' => false ) ) as $term ) {
					$link = is_object( $term ) ? get_term_link( $term ) : '';
					if ( is_string( $link ) ) {
						$paths[] = wp_make_link_relative( $link );
					}
				}
			}
			$paths = self::paths_in( array_unique( $paths ), $lang );
			foreach ( Changes::redirect_paths( $paths, $from, $to ) as $source => $target ) {
				$existing = \EstrategiaWeb\Redirects\Store::all_by_key();
				$key      = \EstrategiaWeb\Redirects\Rules::key( $target, \EstrategiaWeb\Redirects\Store::base() );
				if ( isset( $existing[ $key ] ) ) {
					\EstrategiaWeb\Redirects\Store::delete( array( $existing[ $key ]['id'] ) );
				}
				/* translators: %s: type or taxonomy name */
				[ $id, $code ] = \EstrategiaWeb\Redirects\Importer::save_one( array( 'source' => $source, 'target' => $target, 'type' => 301, 'note' => sprintf( __( 'URL of “%s” changed in Content types', 'estrategia-web' ), $name ) ) );
				if ( $id ) {
					++$created;
				} else {
					$skipped[] = $source . ' (' . \EstrategiaWeb\Redirects\Importer::message( $code ) . ')';
				}
			}
		}
		return array( 'created' => $created, 'skipped' => $skipped );
	}

	/**
	 * The paths of ONE language: '' = all (a slug that is the same string in
	 * every language); WPML's default language = those without a language
	 * directory in front; another = those under /<code>/. WPML in «language
	 * directory» mode, which is what these sites use; with ?lang= or a
	 * domain per language, every path counts as the default's.
	 *
	 * @param string[] $paths
	 * @return string[]
	 */
	private static function paths_in( array $paths, string $lang ): array {
		$codes = array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) );
		if ( '' === $lang || ! $codes ) {
			return $paths;
		}
		$default = (string) apply_filters( 'wpml_default_language', null );
		return array_values(
			array_filter(
				$paths,
				static function ( $path ) use ( $codes, $lang, $default ) {
					$first = explode( '/', trim( (string) $path, '/' ) )[0] ?? '';
					$in    = in_array( $first, $codes, true ) ? $first : $default;
					return $in === $lang;
				}
			)
		);
	}

	/** One type as the panel shows it: from the database, from a file, or neither. */
	private static function item( string $name ): array {
		$loaded = Loader::load();
		$row    = Store::get( $name );
		$source = $row ? 'database' : ( isset( $loaded['files'][ $name ] ) ? 'file' : 'none' );
		$where  = $row ? Loader::DB_SOURCE . $name : ( $loaded['files'][ $name ] ?? '' );
		$def    = $row ? $row['definition'] : ( $loaded['raw'][ $where ] ?? null );
		return array(
			'name'       => $name,
			'source'     => $source,
			'file'       => 'file' === $source ? $where : '',
			'id'         => $row ? $row['id'] : 0,
			'active'     => $row ? $row['active'] : 'file' === $source,
			'registered' => in_array( $name, Registrar::registered(), true ),
			'template'   => is_array( $def ) ? ( $def['template'] ?? null ) : null,
			'entries'    => Store::entries( $name ),
			'errors'     => $loaded['errors'][ $where ] ?? array(),
			'definition' => $def,
			'modified'   => $row ? $row['modified'] : '',
		);
	}

	/** @return string[] The languages a label can be written in: WPML's active ones, or the site's. Two-letter codes, the default first. */
	public static function languages(): array {
		$out     = array();
		$default = apply_filters( 'wpml_default_language', null );
		$active  = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_string( $default ) && '' !== $default ) {
			$out[] = $default;
		}
		foreach ( is_array( $active ) ? array_keys( $active ) : array() as $code ) {
			$out[] = (string) $code;
		}
		if ( ! $out ) {
			$out[] = strtolower( explode( '_', get_locale() )[0] );
		}
		return array_values( array_unique( $out ) );
	}

	private static function redirects_on(): bool {
		return class_exists( \EstrategiaWeb\Redirects\Importer::class );
	}

	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
	}
}
