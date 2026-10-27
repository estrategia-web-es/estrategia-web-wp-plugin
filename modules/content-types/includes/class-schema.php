<?php
/**
 * THE SCHEMA: what a content type definition may say, the checks it has to
 * pass, and the defaults it gets. Pure PHP — no WordPress call except __() —
 * so the level 1 test runs it with the machine's PHP (tests/php/schema.php).
 *
 * content-types.schema.json (next to module.php) says the SAME thing for the
 * code editor (autocomplete, red squiggles). It is a second copy on purpose:
 * PHP cannot run a JSON Schema validator without a dependency, and the editor
 * cannot run PHP. tests/schema.test.mjs fails if the two lists of keys, field
 * types, supports or WPML modes drift apart.
 *
 * One file = one post type (plus the taxonomies it declares). Every name the
 * file registers — the type, its taxonomies, its fields — starts with the
 * file's `prefix`, so a collision check is one search per prefix
 * (tools/buscar-colisiones.mjs).
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-hours.php'; // check_type_rules() validates an «hours» default with it (also in the level 1 tests, which load this file alone).

final class Schema {

	/** WordPress cuts a post type name at 20 characters and a taxonomy at 32. */
	public const MAX_TYPE = 20;
	public const MAX_TAXONOMY = 32;
	/** Our own limit for a meta key (the column allows 255): long keys are typos waiting to happen. */
	public const MAX_FIELD = 64;

	/** Prefixes a site may NOT use. eweb_ is Estrategia Web's own types; wp_ and _ are WordPress's. */
	public const RESERVED_PREFIXES = array( 'eweb_', 'estrategia_web_', 'wp_', 'ew_' );

	public const TYPE_KEYS = array( '$schema', 'template', 'prefix', 'name', 'slug', 'labels', 'description', 'icon', 'supports', 'public', 'has_archive', 'show_in_rest', 'hierarchical', 'menu_position', 'wpml', 'fields_box', 'groups', 'taxonomies', 'fields' );
	public const TAXONOMY_KEYS = array( 'name', 'slug', 'labels', 'hierarchical', 'public', 'show_in_rest', 'show_admin_column', 'wpml' );
	public const FIELD_KEYS = array( 'name', 'type', 'label', 'help', 'required', 'default', 'wpml', 'group', 'options', 'post_type', 'min', 'max', 'integer', 'true_label', 'false_label' );

	/**
	 * WHERE THE FIELDS ARE EDITED (1.8.0, David: «una página vacía y todos
	 * los datos en el panel estrecho»): «sidebar» (default) is the «<Singular>
	 * details» panel of the block editor's sidebar; «main» is a meta box in
	 * the MAIN area, under the content, wide, with the fields in their
	 * «groups». A type without «editor» always gets the meta box (WordPress
	 * gives it the classic screen), whatever this says.
	 */
	public const FIELDS_BOX = array( 'sidebar', 'main' );

	/**
	 * Field names a template SHIPPED before and no longer does (1.8.0: the
	 * location's free texts, duplicated by the data they were made of). A
	 * site's stored copy still has them until it runs its migration
	 * (wp estrategia-web migrate-location-data), and must keep validating —
	 * a definition with ONE error is not registered at all, and the site
	 * would lose its locations the day the plugin is updated. Template key →
	 * names. Removed with the migration (2.0.0).
	 */
	public const RETIRED_FIELDS = array(
		'location' => array( 'estrategia_web_location_address', 'estrategia_web_location_schedule', 'estrategia_web_location_map_url', 'estrategia_web_location_map_embed', 'estrategia_web_location_static_map' ),
	);
	/** Required in every «labels» (a type's and a taxonomy's). */
	public const LABEL_KEYS = array( 'singular', 'plural' );
	/**
	 * Optional, a TYPE's labels only (17-10-2026, for the plugin's templates:
	 * Lavandería's admin had to read the same after the move). details: the
	 * title of the fields panel (default «<Singular> details»);
	 * featured_image / set_featured_image: what WordPress calls the featured
	 * image of this type («Static map image» on a location).
	 */
	public const TYPE_LABEL_KEYS = array( 'singular', 'plural', 'details', 'featured_image', 'set_featured_image' );

	/**
	 * rating, embed and local_image (17-10-2026): the special sanitizers of the
	 * old modules/site types, now field types any definition can use — a
	 * rating 0–5 shown as stars, a map embed that accepts the whole <iframe>,
	 * an image of THIS site given by path or URL. See Registrar::sanitizer().
	 * hours (22-10-2026): opening hours per day of the week, stored as JSON
	 * text (class-hours.php); modules/local-business turns it into schema.org.
	 */
	public const FIELD_TYPES = array( 'text', 'textarea', 'number', 'url', 'email', 'tel', 'image', 'date', 'select', 'relation', 'boolean', 'rating', 'embed', 'local_image', 'hours' );
	public const SUPPORTS = array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'page-attributes', 'post-formats' );
	public const FIELD_WPML = array( 'copy', 'translate', 'ignore' );
	public const TYPE_WPML = array( 'translate', 'display-as-translated', 'ignore' );

	/** Taxonomies a type may reference by name without declaring them. */
	public const CORE_TAXONOMIES = array( 'category', 'post_tag' );

	/**
	 * The errors of ONE definition (empty: valid). Cross-file checks (the same
	 * name in two files) are in check_all().
	 *
	 * A definition made from one of the plugin's TEMPLATES (it carries
	 * "template": "testimonial"; templates/ and class-templates.php) is
	 * validated against that template, which the caller passes: the names the
	 * template ships (eweb_testimonial, estrategia_web_testimonial_author…)
	 * keep the plugin's reserved prefixes and their old shapes — renaming them
	 * would orphan the data already stored under them — and the type name
	 * must stay the template's. Anything the site ADDS (a new field) follows
	 * the usual rules with the definition's own prefix. Boundary case, with a
	 * name: «estrategia_web_client_url» on eweb_client starts with neither the
	 * definition's prefix nor anything the site may use; it passes because the
	 * template ships it, not because it looks right.
	 *
	 * @param mixed      $def      What json_decode( …, true ) returned.
	 * @param array|null $template The RAW template it names, if it names one that exists.
	 * @return string[]
	 */
	public static function validate( $def, ?array $template = null ): array {
		if ( ! is_array( $def ) || array_is_list( $def ) ) {
			return array( __( 'The file is not a JSON object.', 'estrategia-web' ) );
		}
		$errors = self::unknown_keys( $def, self::TYPE_KEYS, '' );

		$from = $def['template'] ?? null;
		if ( null !== $from && ( ! is_string( $from ) || ! $template ) ) {
			/* translators: %s: a template key such as testimonial */
			$errors[] = sprintf( __( '“template”: there is no template “%s” in the plugin.', 'estrategia-web' ), self::show( $from ) );
			$template = null;
		}
		if ( null === $from ) {
			$template = null; // A template is only applied when the definition says it comes from one.
		}
		self::$exempt = $template ? self::shipped_names( $template ) : array();
		try {
			$prefix = $def['prefix'] ?? null;
			if ( ! is_string( $prefix ) || ! preg_match( '/^[a-z][a-z0-9]{1,8}_$/', $prefix ) ) {
				$errors[] = __( '“prefix” must be 2 to 9 lowercase letters or digits followed by “_” (e.g. “gment_”).', 'estrategia-web' );
				$prefix   = null;
			} elseif ( in_array( $prefix, self::RESERVED_PREFIXES, true ) && ! ( $template && ( $template['prefix'] ?? null ) === $prefix ) ) {
				/* translators: %s: a prefix such as eweb_ */
				$errors[] = sprintf( __( 'The prefix “%s” is reserved (Estrategia Web or WordPress). Choose the site’s own prefix.', 'estrategia-web' ), $prefix );
				$prefix   = null;
			}
			if ( $template && isset( $def['name'] ) && $def['name'] !== ( $template['name'] ?? null ) ) {
				/* translators: 1: a type name, 2: a template key */
				$errors[] = sprintf( __( '“name” must be “%1$s”: it is the type of the template “%2$s”.', 'estrategia-web' ), (string) ( $template['name'] ?? '' ), (string) $from );
			}
			// A template's own prefix is reserved, and it is the one its additions use: not an error there.
			self::$own_reserved = null !== $prefix && in_array( $prefix, self::RESERVED_PREFIXES, true ) ? $prefix : '';
			return self::validate_body( $def, $prefix, $errors );
		} finally {
			self::$exempt       = array();
			self::$own_reserved = '';
		}
	}

	/** Names a template ships: exempt from the prefix rules (see validate()). Static: validate() is the only writer. */
	private static array $exempt = array();
	private static string $own_reserved = '';

	/** @return string[] The type, taxonomy and field names a raw template declares. */
	public static function shipped_names( array $template ): array {
		$names = array( (string) ( $template['name'] ?? '' ) );
		foreach ( (array) ( $template['taxonomies'] ?? array() ) as $tax ) {
			if ( is_array( $tax ) && isset( $tax['name'] ) ) {
				$names[] = (string) $tax['name'];
			}
		}
		foreach ( (array) ( $template['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && isset( $field['name'] ) ) {
				$names[] = (string) $field['name'];
			}
		}
		$names = array_merge( $names, self::RETIRED_FIELDS[ (string) ( $template['template'] ?? '' ) ] ?? array() );
		return array_values( array_filter( $names ) );
	}

	/** validate() after the prefix and the template: every key but those two. */
	private static function validate_body( array $def, ?string $prefix, array $errors ): array {
		$errors = array_merge( $errors, self::check_name( $def['name'] ?? null, $prefix, self::MAX_TYPE, 'name' ) );
		$errors = array_merge( $errors, self::check_slug( $def['slug'] ?? null, 'slug' ) );
		$errors = array_merge( $errors, self::check_labels( $def['labels'] ?? null, 'labels', true, self::TYPE_LABEL_KEYS ) );
		if ( isset( $def['description'] ) && ! self::is_text( $def['description'] ) ) {
			$errors[] = __( '“description” must be a string or an object of locale → string.', 'estrategia-web' );
		}
		if ( isset( $def['icon'] ) && ( ! is_string( $def['icon'] ) || ! preg_match( '/^dashicons-[a-z0-9-]+$/', $def['icon'] ) ) ) {
			$errors[] = __( '“icon” must be a Dashicons class such as “dashicons-food”.', 'estrategia-web' );
		}
		if ( isset( $def['supports'] ) ) {
			if ( ! is_array( $def['supports'] ) || ! array_is_list( $def['supports'] ) ) {
				$errors[] = __( '“supports” must be a list.', 'estrategia-web' );
			} else {
				foreach ( $def['supports'] as $support ) {
					if ( ! in_array( $support, self::SUPPORTS, true ) ) {
						/* translators: %s: a value from the JSON file */
						$errors[] = sprintf( __( '“supports”: unknown value “%s”.', 'estrategia-web' ), self::show( $support ) );
					}
				}
			}
		}
		foreach ( array( 'public', 'has_archive', 'show_in_rest', 'hierarchical' ) as $flag ) {
			if ( isset( $def[ $flag ] ) && ! is_bool( $def[ $flag ] ) ) {
				/* translators: %s: a key of the JSON file */
				$errors[] = sprintf( __( '“%s” must be true or false.', 'estrategia-web' ), $flag );
			}
		}
		if ( isset( $def['menu_position'] ) && ( ! is_int( $def['menu_position'] ) || $def['menu_position'] < 0 ) ) {
			$errors[] = __( '“menu_position” must be a whole number from 0.', 'estrategia-web' );
		}
		if ( isset( $def['wpml'] ) && ! in_array( $def['wpml'], self::TYPE_WPML, true ) ) {
			/* translators: %s: list of allowed values */
			$errors[] = sprintf( __( '“wpml” must be one of: %s.', 'estrategia-web' ), implode( ', ', self::TYPE_WPML ) );
		}

		if ( isset( $def['fields_box'] ) && ! in_array( $def['fields_box'], self::FIELDS_BOX, true ) ) {
			/* translators: %s: list of allowed values */
			$errors[] = sprintf( __( '“fields_box” must be one of: %s.', 'estrategia-web' ), implode( ', ', self::FIELDS_BOX ) );
		}
		$groups = self::check_groups( $def['groups'] ?? array(), $errors );

		$errors = array_merge( $errors, self::check_taxonomies( $def['taxonomies'] ?? array(), $prefix ) );
		return array_merge( $errors, self::check_fields( $def['fields'] ?? array(), $prefix, $groups ) );
	}

	/**
	 * «groups» (1.8.0): the sections of the main-area meta box, in order —
	 * [{"key": "address", "label": {…}, "collapsed": false}]. A field joins
	 * one with «group»: "address". Fields without a group go first, untitled.
	 *
	 * @param string[] $errors Appended to.
	 * @return string[] The valid group keys.
	 */
	private static function check_groups( $groups, array &$errors ): array {
		if ( ! is_array( $groups ) || ! array_is_list( $groups ) ) {
			$errors[] = __( '“groups” must be a list.', 'estrategia-web' );
			return array();
		}
		$keys = array();
		foreach ( $groups as $i => $group ) {
			/* translators: %d: position in the list, from 1 */
			$where = sprintf( __( 'groups[%d]', 'estrategia-web' ), $i + 1 );
			if ( ! is_array( $group ) || array_is_list( $group ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: must be an object.', 'estrategia-web' ), $where );
				continue;
			}
			$errors = array_merge( $errors, self::unknown_keys( $group, array( 'key', 'label', 'collapsed' ), $where . '.' ) );
			$key    = $group['key'] ?? null;
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.key must be lowercase letters, digits and “_”, starting with a letter.', 'estrategia-web' ), $where );
			} elseif ( in_array( $key, $keys, true ) ) {
				/* translators: %s: a group key */
				$errors[] = sprintf( __( 'The group “%s” is declared twice.', 'estrategia-web' ), $key );
			} else {
				$keys[] = $key;
			}
			if ( ! isset( $group['label'] ) || ! self::is_text( $group['label'] ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.label is required (a string or an object of locale → string).', 'estrategia-web' ), $where );
			}
			if ( isset( $group['collapsed'] ) && ! is_bool( $group['collapsed'] ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.collapsed must be true or false.', 'estrategia-web' ), $where );
			}
		}
		return $keys;
	}

	/**
	 * Checks that need every file at once: a type or taxonomy declared twice,
	 * a taxonomy referenced but declared nowhere.
	 *
	 * @param array<string,array> $defs File name => VALID definition.
	 * @return array<string,string[]> File name => its extra errors (only files with some).
	 */
	public static function check_all( array $defs ): array {
		$errors  = array();
		$types   = array();
		$taxes   = array();
		foreach ( $defs as $file => $def ) {
			$name = $def['name'];
			if ( isset( $types[ $name ] ) ) {
				/* translators: 1: type name, 2: another file name */
				$errors[ $file ][] = sprintf( __( 'The type “%1$s” is already declared in %2$s.', 'estrategia-web' ), $name, $types[ $name ] );
			}
			$types[ $name ] ??= $file;
			foreach ( $def['taxonomies'] ?? array() as $tax ) {
				if ( is_array( $tax ) ) {
					if ( isset( $taxes[ $tax['name'] ] ) || isset( $types[ $tax['name'] ] ) ) {
						/* translators: 1: taxonomy name, 2: another file name */
						$errors[ $file ][] = sprintf( __( 'The name “%1$s” is already declared in %2$s.', 'estrategia-web' ), $tax['name'], $taxes[ $tax['name'] ] ?? $types[ $tax['name'] ] );
					}
					$taxes[ $tax['name'] ] ??= $file;
				}
			}
		}
		foreach ( self::slug_clashes( $defs ) as $file => $found ) {
			$errors[ $file ] = array_merge( $errors[ $file ] ?? array(), $found );
		}
		// WPML's setting is per meta KEY, not per type: one key, one mode.
		$modes = array();
		foreach ( $defs as $file => $def ) {
			foreach ( $def['fields'] ?? array() as $field ) {
				$mode = self::normalize_field( $field )['wpml'];
				if ( isset( $modes[ $field['name'] ] ) && $modes[ $field['name'] ][0] !== $mode ) {
					/* translators: 1: field name, 2: WPML mode, 3: another file name, 4: WPML mode there */
					$errors[ $file ][] = sprintf( __( 'The field “%1$s” has wpml “%2$s” here and “%4$s” in %3$s: WPML keeps one mode per meta key.', 'estrategia-web' ), $field['name'], $mode, $modes[ $field['name'] ][1], $modes[ $field['name'] ][0] );
				}
				$modes[ $field['name'] ] ??= array( $mode, $file );
			}
		}
		foreach ( $defs as $file => $def ) {
			foreach ( $def['taxonomies'] ?? array() as $tax ) {
				if ( is_string( $tax ) &&! isset( $taxes[ $tax ] ) && ! in_array( $tax, self::CORE_TAXONOMIES, true ) ) {
					/* translators: %s: taxonomy name */
					$errors[ $file ][] = sprintf( __( 'The taxonomy “%s” is not declared in any file (declare it as an object in one of them).', 'estrategia-web' ), $tax );
				}
			}
		}
		return $errors;
	}

	/**
	 * Two types or taxonomies with the same URL base in the same language:
	 * WordPress would keep the rules of the first one and the second one's
	 * posts would be 404s (or open the other's). Compared language by
	 * language — the languages that appear in any slug object, each slug
	 * resolved as the registrar resolves it (Schema::text: that language,
	 * then en, then the first) — so «vins» in Catalan and «vins» in a
	 * French-only slug of another type do not count as a clash.
	 *
	 * @param array<string,array> $defs File name => VALID definition.
	 * @return array<string,string[]>
	 */
	private static function slug_clashes( array $defs ): array {
		$entries = array(); // [file, name, slug]
		$langs   = array();
		foreach ( $defs as $file => $def ) {
			$items = array( array( $def['name'], $def['slug'] ?? null ) );
			foreach ( $def['taxonomies'] ?? array() as $tax ) {
				if ( is_array( $tax ) ) {
					$items[] = array( $tax['name'], $tax['slug'] ?? null );
				}
			}
			foreach ( $items as [ $name, $slug ] ) {
				$slug      = $slug ?? self::default_slug( $name, $def['prefix'] );
				$entries[] = array( $file, $name, $slug );
				foreach ( is_array( $slug ) ? array_keys( $slug ) : array() as $locale ) {
					$langs[ strtolower( explode( '_', (string) $locale )[0] ) ] = true;
				}
			}
		}
		$errors = array();
		foreach ( $langs ? array_keys( $langs ) : array( 'en' ) as $lang ) {
			$seen = array();
			foreach ( $entries as [ $file, $name, $slug ] ) {
				$value = self::text( $slug, $lang );
				// The same pair clashing in several languages is reported once (the first language).
				if ( isset( $seen[ $value ] ) && $seen[ $value ][1] !== $name && ! isset( $errors[ $file ][ $value . '|' . $seen[ $value ][1] ] ) ) {
					/* translators: 1: a URL slug, 2: a language code, 3: type or taxonomy name, 4: a file name */
					$errors[ $file ][ $value . '|' . $seen[ $value ][1] ] = sprintf( __( 'The slug “%1$s” (language “%2$s”) is already the URL of “%3$s” in %4$s: two types or taxonomies cannot share a URL.', 'estrategia-web' ), $value, $lang, $seen[ $value ][1], $seen[ $value ][0] );
				}
				$seen[ $value ] ??= array( $file, $name );
			}
		}
		return array_map( 'array_values', $errors );
	}

	/** The slug when the file gives none: the name without the prefix, «-» for «_». */
	private static function default_slug( string $name, string $prefix ): string {
		return str_replace( '_', '-', substr( $name, strlen( $prefix ) ) );
	}

	/**
	 * The definition with every default filled in: what the registrar, the
	 * WPML config and the editor panel read. Call it only on a VALID one.
	 */
	public static function normalize( array $def ): array {
		$fields   = array();
		foreach ( $def['fields'] ?? array() as $field ) {
			$fields[] = self::normalize_field( $field );
		}
		$supports = $def['supports'] ?? array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' );
		// The trap (modules/site, class-meta-panel.php): without custom-fields
		// a type does not expose `meta` in REST and the panel saves into nothing.
		if ( $fields && ! in_array( 'custom-fields', $supports, true ) ) {
			$supports[] = 'custom-fields';
		}
		$taxonomies = array();
		foreach ( $def['taxonomies'] ?? array() as $tax ) {
			$taxonomies[] = is_string( $tax ) ? $tax : array(
				'name'              => $tax['name'],
				'slug'              => $tax['slug'] ?? self::default_slug( $tax['name'], $def['prefix'] ),
				'labels'            => $tax['labels'],
				'hierarchical'      => $tax['hierarchical'] ?? true,
				'public'            => $tax['public'] ?? true,
				'show_in_rest'      => $tax['show_in_rest'] ?? true,
				'show_admin_column' => $tax['show_admin_column'] ?? true,
				'wpml'              => $tax['wpml'] ?? 'display-as-translated',
			);
		}
		return array(
			'template'      => $def['template'] ?? null,
			'prefix'        => $def['prefix'],
			'name'          => $def['name'],
			// A string or locale → string, as in the file: the registrar picks the site language's (Schema::text).
			'slug'          => $def['slug'] ?? self::default_slug( $def['name'], $def['prefix'] ),
			'labels'        => $def['labels'],
			'description'   => $def['description'] ?? '',
			'icon'          => $def['icon'] ?? 'dashicons-admin-post',
			'supports'      => array_values( array_unique( $supports ) ),
			'public'        => $def['public'] ?? true,
			'has_archive'   => $def['has_archive'] ?? true,
			'show_in_rest'  => $def['show_in_rest'] ?? true,
			'hierarchical'  => $def['hierarchical'] ?? false,
			'menu_position' => $def['menu_position'] ?? null,
			'wpml'          => $def['wpml'] ?? 'display-as-translated',
			'fields_box'    => $def['fields_box'] ?? 'sidebar',
			'groups'        => array_map( static fn( $g ) => array( 'key' => $g['key'], 'label' => $g['label'], 'collapsed' => (bool) ( $g['collapsed'] ?? false ) ), $def['groups'] ?? array() ),
			'taxonomies'    => $taxonomies,
			'fields'        => $fields,
		);
	}

	/** What each field type stores in register_post_meta() and its WPML default. */
	public static function normalize_field( array $field ): array {
		$type = $field['type'];
		$meta = match ( $type ) {
			'number'           => ! empty( $field['integer'] ) ? 'integer' : 'number',
			'image', 'relation', 'rating' => 'integer',
			'boolean'          => 'boolean',
			default            => 'string',
		};
		$empty   = match ( $meta ) {
			'integer' => 0,
			'number'  => 0,
			'boolean' => false,
			default   => '',
		};
		$options = array();
		foreach ( $field['options'] ?? array() as $option ) {
			$options[] = is_array( $option ) ? array( 'value' => (string) $option['value'], 'label' => $option['label'] ) : array( 'value' => (string) $option, 'label' => (string) $option );
		}
		return array(
			'name'      => $field['name'],
			'type'      => $type,
			'meta_type' => $meta,
			'label'     => $field['label'],
			'help'      => $field['help'] ?? '',
			'required'  => (bool) ( $field['required'] ?? false ),
			'default'   => $field['default'] ?? $empty,
			// Text a person reads changes with the language; everything else is the same in all of them.
			'wpml'      => $field['wpml'] ?? ( in_array( $type, array( 'text', 'textarea' ), true ) ? 'translate' : 'copy' ),
			'group'     => $field['group'] ?? '',
			'options'   => $options,
			'post_type' => (array) ( $field['post_type'] ?? array() ),
			'min'       => $field['min'] ?? null,
			'max'       => $field['max'] ?? null,
			// boolean: what the content-field binding shows; null = the translated «Yes» / «No».
			'true_label'  => $field['true_label'] ?? null,
			'false_label' => $field['false_label'] ?? null,
		);
	}

	/**
	 * A label in the current language. A label is a string, or an object
	 * locale → string: «ca», «es_ES», «en». Order: exact locale, its language
	 * («ca» for «ca_ES»), «en», the first one.
	 *
	 * @param string|array<string,string> $text
	 */
	public static function text( $text, string $locale ): string {
		if ( is_string( $text ) ) {
			return $text;
		}
		if ( ! is_array( $text ) || ! $text ) {
			return '';
		}
		$lang = strtolower( explode( '_', $locale )[0] );
		foreach ( array( $locale, $lang, 'en', 'en_US' ) as $key ) {
			if ( isset( $text[ $key ] ) && is_string( $text[ $key ] ) ) {
				return $text[ $key ];
			}
		}
		return (string) reset( $text );
	}

	private static function check_taxonomies( $taxonomies, ?string $prefix ): array {
		if ( ! is_array( $taxonomies ) || ! array_is_list( $taxonomies ) ) {
			return array( __( '“taxonomies” must be a list.', 'estrategia-web' ) );
		}
		$errors = array();
		foreach ( $taxonomies as $i => $tax ) {
			/* translators: %d: position in the list, from 1 */
			$where = sprintf( __( 'taxonomies[%d]', 'estrategia-web' ), $i + 1 );
			if ( is_string( $tax ) ) {
				if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $tax ) ) {
					/* translators: %s: where in the file */
					$errors[] = sprintf( __( '%s: a reference must be a taxonomy name.', 'estrategia-web' ), $where );
				}
				continue;
			}
			if ( ! is_array( $tax ) || array_is_list( $tax ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: must be a taxonomy name or an object.', 'estrategia-web' ), $where );
				continue;
			}
			$errors = array_merge( $errors, self::unknown_keys( $tax, self::TAXONOMY_KEYS, $where . '.' ) );
			$errors = array_merge( $errors, self::check_name( $tax['name'] ?? null, $prefix, self::MAX_TAXONOMY, $where . '.name' ) );
			$errors = array_merge( $errors, self::check_slug( $tax['slug'] ?? null, $where . '.slug' ) );
			$errors = array_merge( $errors, self::check_labels( $tax['labels'] ?? null, $where . '.labels', true ) );
			foreach ( array( 'hierarchical', 'public', 'show_in_rest', 'show_admin_column' ) as $flag ) {
				if ( isset( $tax[ $flag ] ) && ! is_bool( $tax[ $flag ] ) ) {
					/* translators: 1: where in the file, 2: a key */
					$errors[] = sprintf( __( '%1$s.%2$s must be true or false.', 'estrategia-web' ), $where, $flag );
				}
			}
			if ( isset( $tax['wpml'] ) && ! in_array( $tax['wpml'], self::TYPE_WPML, true ) ) {
				/* translators: 1: where in the file, 2: allowed values */
				$errors[] = sprintf( __( '%1$s.wpml must be one of: %2$s.', 'estrategia-web' ), $where, implode( ', ', self::TYPE_WPML ) );
			}
		}
		return $errors;
	}

	/** @param string[] $groups The definition's group keys (a field's «group» must be one). */
	private static function check_fields( $fields, ?string $prefix, array $groups = array() ): array {
		if ( ! is_array( $fields ) || ! array_is_list( $fields ) ) {
			return array( __( '“fields” must be a list.', 'estrategia-web' ) );
		}
		$errors = array();
		$seen   = array();
		foreach ( $fields as $i => $field ) {
			/* translators: %d: position in the list, from 1 */
			$where = sprintf( __( 'fields[%d]', 'estrategia-web' ), $i + 1 );
			if ( ! is_array( $field ) || array_is_list( $field ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: must be an object.', 'estrategia-web' ), $where );
				continue;
			}
			$errors = array_merge( $errors, self::unknown_keys( $field, self::FIELD_KEYS, $where . '.' ) );
			$name   = $field['name'] ?? null;
			$errors = array_merge( $errors, self::check_name( $name, $prefix, self::MAX_FIELD, $where . '.name' ) );
			if ( is_string( $name ) ) {
				if ( isset( $seen[ $name ] ) ) {
					/* translators: %s: field name */
					$errors[] = sprintf( __( 'The field “%s” is declared twice.', 'estrategia-web' ), $name );
				}
				$seen[ $name ] = true;
			}
			$type = $field['type'] ?? null;
			if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
				/* translators: 1: where in the file, 2: allowed values */
				$errors[] = sprintf( __( '%1$s.type must be one of: %2$s.', 'estrategia-web' ), $where, implode( ', ', self::FIELD_TYPES ) );
				continue;
			}
			if ( ! isset( $field['label'] ) || ! self::is_text( $field['label'] ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.label is required (a string or an object of locale → string).', 'estrategia-web' ), $where );
			}
			if ( isset( $field['help'] ) && ! self::is_text( $field['help'] ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.help must be a string or an object of locale → string.', 'estrategia-web' ), $where );
			}
			if ( isset( $field['required'] ) && ! is_bool( $field['required'] ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.required must be true or false.', 'estrategia-web' ), $where );
			}
			if ( isset( $field['wpml'] ) && ! in_array( $field['wpml'], self::FIELD_WPML, true ) ) {
				/* translators: 1: where in the file, 2: allowed values */
				$errors[] = sprintf( __( '%1$s.wpml must be one of: %2$s.', 'estrategia-web' ), $where, implode( ', ', self::FIELD_WPML ) );
			}
			if ( isset( $field['group'] ) && ! in_array( $field['group'], $groups, true ) ) {
				/* translators: 1: where in the file, 2: a value from the JSON file */
				$errors[] = sprintf( __( '%1$s.group “%2$s” is not one of the definition’s «groups».', 'estrategia-web' ), $where, self::show( $field['group'] ) );
			}
			// Keys that only make sense for one type: a typo there would be silently ignored.
			$only = array( 'options' => 'select', 'post_type' => 'relation', 'min' => 'number', 'max' => 'number', 'integer' => 'number', 'true_label' => 'boolean', 'false_label' => 'boolean' );
			foreach ( $only as $key => $for ) {
				if ( array_key_exists( $key, $field ) && $type !== $for ) {
					/* translators: 1: where in the file, 2: a key, 3: a field type */
					$errors[] = sprintf( __( '%1$s.%2$s is only for fields of type “%3$s”.', 'estrategia-web' ), $where, $key, $for );
				}
			}
			$errors = array_merge( $errors, self::check_type_rules( $field, $type, $where ) );
		}
		return $errors;
	}

	/** What each field type needs, and whether its default is a value it could store. */
	private static function check_type_rules( array $field, string $type, string $where ): array {
		$errors = array();
		$has    = array_key_exists( 'default', $field );
		$def    = $field['default'] ?? null;
		if ( 'select' === $type ) {
			$options = $field['options'] ?? null;
			$values  = array();
			if ( ! is_array( $options ) || ! array_is_list( $options ) || ! $options ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: a select needs “options”, a non-empty list.', 'estrategia-web' ), $where );
			} else {
				foreach ( $options as $option ) {
					$value = is_array( $option ) ? ( $option['value'] ?? null ) : $option;
					$ok    = is_array( $option ) ? ( is_string( $value ) && isset( $option['label'] ) && self::is_text( $option['label'] ) && ! array_diff( array_keys( $option ), array( 'value', 'label' ) ) ) : is_string( $option );
					if ( ! $ok || '' === $value ) {
						/* translators: %s: where in the file */
						$errors[] = sprintf( __( '%s.options: each option is a non-empty string or {"value": "…", "label": …}.', 'estrategia-web' ), $where );
						break;
					}
					$values[] = $value;
				}
				if ( count( $values ) !== count( array_unique( $values ) ) ) {
					/* translators: %s: where in the file */
					$errors[] = sprintf( __( '%s.options: two options have the same value.', 'estrategia-web' ), $where );
				}
				if ( $has && ! in_array( $def, $values, true ) ) {
					/* translators: %s: where in the file */
					$errors[] = sprintf( __( '%s.default must be the value of one of the options.', 'estrategia-web' ), $where );
				}
			}
		} elseif ( 'relation' === $type ) {
			$targets = $field['post_type'] ?? null;
			$list    = is_string( $targets ) ? array( $targets ) : $targets;
			if ( ! is_array( $list ) || ! $list || array_filter( $list, static fn( $t ) => ! is_string( $t ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,19}$/', $t ) ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: a relation needs “post_type”, a post type name or a list of them.', 'estrategia-web' ), $where );
			}
			if ( $has ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: a relation cannot have a default (it is the ID of a post).', 'estrategia-web' ), $where );
			}
		} elseif ( 'number' === $type ) {
			foreach ( array( 'min', 'max' ) as $key ) {
				if ( isset( $field[ $key ] ) && ! is_int( $field[ $key ] ) && ! is_float( $field[ $key ] ) ) {
					/* translators: 1: where in the file, 2: min or max */
					$errors[] = sprintf( __( '%1$s.%2$s must be a number.', 'estrategia-web' ), $where, $key );
				}
			}
			if ( isset( $field['integer'] ) && ! is_bool( $field['integer'] ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.integer must be true or false.', 'estrategia-web' ), $where );
			}
			if ( isset( $field['min'], $field['max'] ) && is_numeric( $field['min'] ) && is_numeric( $field['max'] ) && $field['min'] > $field['max'] ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: min is greater than max.', 'estrategia-web' ), $where );
			}
			$int = ! empty( $field['integer'] );
			if ( $has && ( ! ( is_int( $def ) || ( ! $int && is_float( $def ) ) ) || ( isset( $field['min'] ) && $def < $field['min'] ) || ( isset( $field['max'] ) && $def > $field['max'] ) ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.default must be a number within min and max (a whole number if “integer”).', 'estrategia-web' ), $where );
			}
		} elseif ( 'boolean' === $type ) {
			if ( ! empty( $field['required'] ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: a boolean cannot be required (“no” is an answer).', 'estrategia-web' ), $where );
			}
			if ( $has && ! is_bool( $def ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.default must be true or false.', 'estrategia-web' ), $where );
			}
			// "" is allowed on purpose: «Terrace» when true and nothing when false.
			foreach ( array( 'true_label', 'false_label' ) as $key ) {
				if ( array_key_exists( $key, $field ) && '' !== $field[ $key ] && ! self::is_text( $field[ $key ] ) ) {
					/* translators: 1: where in the file, 2: true_label or false_label */
					$errors[] = sprintf( __( '%1$s.%2$s must be a string ("" to show nothing) or an object of locale → string.', 'estrategia-web' ), $where, $key );
				}
			}
		} elseif ( 'rating' === $type ) {
			if ( $has && ( ! is_int( $def ) || $def < 0 || $def > 5 ) ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s.default must be a whole number from 0 (no rating) to 5.', 'estrategia-web' ), $where );
			}
		} elseif ( 'image' === $type ) {
			if ( $has ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: an image cannot have a default (it is the ID of an attachment).', 'estrategia-web' ), $where );
			}
		} elseif ( 'hours' === $type && $has && ( ! is_string( $def ) || Hours::sanitize( $def ) !== $def ) ) {
			/* translators: %s: where in the file */
			$errors[] = sprintf( __( '%s.default must be opening hours as stored, e.g. {"mo":["08:00-14:00"]}, or "".', 'estrategia-web' ), $where );
		} elseif ( $has && ! is_string( $def ) ) {
			/* translators: %s: where in the file */
			$errors[] = sprintf( __( '%s.default must be a string.', 'estrategia-web' ), $where );
		} elseif ( $has && 'date' === $type && ! self::is_date( $def ) ) {
			/* translators: %s: where in the file */
			$errors[] = sprintf( __( '%s.default must be a date as YYYY-MM-DD.', 'estrategia-web' ), $where );
		}
		return $errors;
	}

	/** A real calendar date as YYYY-MM-DD (also used by the sanitizer). */
	public static function is_date( $value ): bool {
		return is_string( $value ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function check_name( $name, ?string $prefix, int $max, string $where ): array {
		if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
			/* translators: %s: where in the file */
			return array( sprintf( __( '%s must be lowercase letters, digits and “_”, starting with a letter.', 'estrategia-web' ), $where ) );
		}
		$errors = array();
		if ( strlen( $name ) > $max ) {
			/* translators: 1: where in the file, 2: the name, 3: max length, 4: its length */
			$errors[] = sprintf( __( '%1$s “%2$s” is longer than %3$d characters (%4$d): WordPress would cut it.', 'estrategia-web' ), $where, $name, $max, strlen( $name ) );
		}
		if ( in_array( $name, self::$exempt, true ) ) {
			return $errors; // Shipped by the template (see validate()).
		}
		if ( null !== $prefix && ( ! str_starts_with( $name, $prefix ) || strlen( $name ) === strlen( $prefix ) ) ) {
			/* translators: 1: where in the file, 2: the name, 3: the prefix */
			$errors[] = sprintf( __( '%1$s “%2$s” must start with the prefix “%3$s”.', 'estrategia-web' ), $where, $name, $prefix );
		}
		foreach ( self::RESERVED_PREFIXES as $reserved ) {
			if ( str_starts_with( $name, $reserved ) && $reserved !== self::$own_reserved ) {
				/* translators: 1: where in the file, 2: the name, 3: a reserved prefix */
				$errors[] = sprintf( __( '%1$s “%2$s” uses the reserved prefix “%3$s”.', 'estrategia-web' ), $where, $name, $reserved );
			}
		}
		return $errors;
	}

	/**
	 * A slug is a string, or an object locale → string like the labels
	 * ({"ca": "restaurants", "es": "restaurantes"}): one URL base per language
	 * (with WPML, class-wpml.php sync_slugs()). Each one: ASCII lowercase,
	 * digits, «-» and «/» — a URL path that needs no encoding.
	 */
	private static function check_slug( $slug, string $where ): array {
		if ( null === $slug ) {
			return array();
		}
		$one = static fn( $s ): bool => is_string( $s ) && (bool) preg_match( '#^[a-z0-9][a-z0-9-]*(/[a-z0-9][a-z0-9-]*)*$#', $s );
		if ( is_array( $slug ) && $slug && ! array_is_list( $slug ) ) {
			foreach ( $slug as $locale => $value ) {
				if ( ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', (string) $locale ) || ! $one( $value ) ) {
					/* translators: %s: where in the file */
					return array( sprintf( __( '%s must be a URL path (lowercase ASCII letters, digits, “-” and “/”, no slashes at the ends) or an object of locale → such a path.', 'estrategia-web' ), $where ) );
				}
			}
			return array();
		}
		// Slashes allowed: «carta/vins» is a valid rewrite base.
		if ( ! $one( $slug ) ) {
			/* translators: %s: where in the file */
			return array( sprintf( __( '%s must be a URL path (lowercase ASCII letters, digits, “-” and “/”, no slashes at the ends) or an object of locale → such a path.', 'estrategia-web' ), $where ) );
		}
		return array();
	}

	/**
	 * The slug given for ONE language, with no fallback: null when the
	 * definition does not say (WPML then uses the original, the site
	 * language's). $keys: the WPML language code and its locale («es»,
	 * «es_ES»). A plain-string slug says nothing per language: null.
	 *
	 * @param string|array<string,string> $slug
	 * @param string[]                    $keys
	 */
	public static function slug_in( $slug, array $keys ): ?string {
		if ( ! is_array( $slug ) ) {
			return null;
		}
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && '' !== $key && isset( $slug[ $key ] ) ) {
				return $slug[ $key ];
			}
			$lang = is_string( $key ) ? strtolower( explode( '_', $key )[0] ) : '';
			if ( '' !== $lang && isset( $slug[ $lang ] ) ) {
				return $slug[ $lang ];
			}
		}
		return null;
	}

	/** @param string[] $allowed Every key it may have: the required LABEL_KEYS, plus TYPE_LABEL_KEYS' optional ones for a type. */
	private static function check_labels( $labels, string $where, bool $required, array $allowed = self::LABEL_KEYS ): array {
		if ( null === $labels ) {
			/* translators: %s: where in the file */
			return $required ? array( sprintf( __( '%s is required: {"singular": …, "plural": …}.', 'estrategia-web' ), $where ) ) : array();
		}
		if ( ! is_array( $labels ) || array_is_list( $labels ) ) {
			/* translators: %s: where in the file */
			return array( sprintf( __( '%s must be an object: {"singular": …, "plural": …}.', 'estrategia-web' ), $where ) );
		}
		$errors = self::unknown_keys( $labels, $allowed, $where . '.' );
		foreach ( self::LABEL_KEYS as $key ) {
			if ( ! isset( $labels[ $key ] ) || ! self::is_text( $labels[ $key ] ) ) {
				/* translators: 1: where in the file, 2: singular or plural */
				$errors[] = sprintf( __( '%1$s.%2$s is required: a string or an object of locale → string.', 'estrategia-web' ), $where, $key );
			}
		}
		foreach ( array_diff( $allowed, self::LABEL_KEYS ) as $key ) {
			if ( isset( $labels[ $key ] ) && ! self::is_text( $labels[ $key ] ) ) {
				/* translators: 1: where in the file, 2: a label key such as details */
				$errors[] = sprintf( __( '%1$s.%2$s must be a string or an object of locale → string.', 'estrategia-web' ), $where, $key );
			}
		}
		return $errors;
	}

	/** A string, or a non-empty object of locale («ca», «es_ES») → string. */
	private static function is_text( $value ): bool {
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		if ( ! is_array( $value ) || ! $value || array_is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $locale => $text ) {
			if ( ! preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', (string) $locale ) || ! is_string( $text ) || '' === trim( $text ) ) {
				return false;
			}
		}
		return true;
	}

	/** Strict on purpose: «requierd» would otherwise be ignored without a word. */
	private static function unknown_keys( array $value, array $allowed, string $where ): array {
		$errors = array();
		foreach ( array_diff( array_keys( $value ), $allowed ) as $key ) {
			/* translators: 1: a key, 2: allowed keys */
			$errors[] = sprintf( __( 'Unknown key “%1$s”. Allowed: %2$s.', 'estrategia-web' ), $where . $key, implode( ', ', $allowed ) );
		}
		return $errors;
	}

	private static function show( $value ): string {
		return is_scalar( $value ) ? (string) $value : gettype( $value );
	}
}
