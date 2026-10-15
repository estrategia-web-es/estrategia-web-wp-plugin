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

final class Schema {

	/** WordPress cuts a post type name at 20 characters and a taxonomy at 32. */
	public const MAX_TYPE = 20;
	public const MAX_TAXONOMY = 32;
	/** Our own limit for a meta key (the column allows 255): long keys are typos waiting to happen. */
	public const MAX_FIELD = 64;

	/** Prefixes a site may NOT use. eweb_ is Estrategia Web's own types; wp_ and _ are WordPress's. */
	public const RESERVED_PREFIXES = array( 'eweb_', 'estrategia_web_', 'wp_', 'ew_' );

	public const TYPE_KEYS = array( '$schema', 'prefix', 'name', 'slug', 'labels', 'description', 'icon', 'supports', 'public', 'has_archive', 'show_in_rest', 'hierarchical', 'menu_position', 'wpml', 'taxonomies', 'fields' );
	public const TAXONOMY_KEYS = array( 'name', 'slug', 'labels', 'hierarchical', 'public', 'show_in_rest', 'show_admin_column', 'wpml' );
	public const FIELD_KEYS = array( 'name', 'type', 'label', 'help', 'required', 'default', 'wpml', 'options', 'post_type', 'min', 'max', 'integer' );
	public const LABEL_KEYS = array( 'singular', 'plural' );

	public const FIELD_TYPES = array( 'text', 'textarea', 'number', 'url', 'email', 'tel', 'image', 'date', 'select', 'relation', 'boolean' );
	public const SUPPORTS = array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'page-attributes', 'post-formats' );
	public const FIELD_WPML = array( 'copy', 'translate', 'ignore' );
	public const TYPE_WPML = array( 'translate', 'display-as-translated', 'ignore' );

	/** Taxonomies a type may reference by name without declaring them. */
	public const CORE_TAXONOMIES = array( 'category', 'post_tag' );

	/**
	 * The errors of ONE definition (empty: valid). Cross-file checks (the same
	 * name in two files) are in check_all().
	 *
	 * @param mixed $def What json_decode( …, true ) returned.
	 * @return string[]
	 */
	public static function validate( $def ): array {
		if ( ! is_array( $def ) || array_is_list( $def ) ) {
			return array( __( 'The file is not a JSON object.', 'estrategia-web' ) );
		}
		$errors = self::unknown_keys( $def, self::TYPE_KEYS, '' );

		$prefix = $def['prefix'] ?? null;
		if ( ! is_string( $prefix ) || ! preg_match( '/^[a-z][a-z0-9]{1,8}_$/', $prefix ) ) {
			$errors[] = __( '“prefix” must be 2 to 9 lowercase letters or digits followed by “_” (e.g. “gment_”).', 'estrategia-web' );
			$prefix   = null;
		} elseif ( in_array( $prefix, self::RESERVED_PREFIXES, true ) ) {
			/* translators: %s: a prefix such as eweb_ */
			$errors[] = sprintf( __( 'The prefix “%s” is reserved (Estrategia Web or WordPress). Choose the site’s own prefix.', 'estrategia-web' ), $prefix );
			$prefix   = null;
		}

		$errors = array_merge( $errors, self::check_name( $def['name'] ?? null, $prefix, self::MAX_TYPE, 'name' ) );
		$errors = array_merge( $errors, self::check_slug( $def['slug'] ?? null, 'slug' ) );
		$errors = array_merge( $errors, self::check_labels( $def['labels'] ?? null, 'labels', true ) );
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

		$errors = array_merge( $errors, self::check_taxonomies( $def['taxonomies'] ?? array(), $prefix ) );
		return array_merge( $errors, self::check_fields( $def['fields'] ?? array(), $prefix ) );
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
				'slug'              => $tax['slug'] ?? str_replace( '_', '-', substr( $tax['name'], strlen( $def['prefix'] ) ) ),
				'labels'            => $tax['labels'],
				'hierarchical'      => $tax['hierarchical'] ?? true,
				'public'            => $tax['public'] ?? true,
				'show_in_rest'      => $tax['show_in_rest'] ?? true,
				'show_admin_column' => $tax['show_admin_column'] ?? true,
				'wpml'              => $tax['wpml'] ?? 'translate',
			);
		}
		return array(
			'prefix'        => $def['prefix'],
			'name'          => $def['name'],
			'slug'          => $def['slug'] ?? str_replace( '_', '-', substr( $def['name'], strlen( $def['prefix'] ) ) ),
			'labels'        => $def['labels'],
			'description'   => $def['description'] ?? '',
			'icon'          => $def['icon'] ?? 'dashicons-admin-post',
			'supports'      => array_values( array_unique( $supports ) ),
			'public'        => $def['public'] ?? true,
			'has_archive'   => $def['has_archive'] ?? true,
			'show_in_rest'  => $def['show_in_rest'] ?? true,
			'hierarchical'  => $def['hierarchical'] ?? false,
			'menu_position' => $def['menu_position'] ?? null,
			'wpml'          => $def['wpml'] ?? 'translate',
			'taxonomies'    => $taxonomies,
			'fields'        => $fields,
		);
	}

	/** What each field type stores in register_post_meta() and its WPML default. */
	public static function normalize_field( array $field ): array {
		$type = $field['type'];
		$meta = match ( $type ) {
			'number'           => ! empty( $field['integer'] ) ? 'integer' : 'number',
			'image', 'relation' => 'integer',
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
			'options'   => $options,
			'post_type' => (array) ( $field['post_type'] ?? array() ),
			'min'       => $field['min'] ?? null,
			'max'       => $field['max'] ?? null,
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

	private static function check_fields( $fields, ?string $prefix ): array {
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
			// Keys that only make sense for one type: a typo there would be silently ignored.
			$only = array( 'options' => 'select', 'post_type' => 'relation', 'min' => 'number', 'max' => 'number', 'integer' => 'number' );
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
		} elseif ( 'image' === $type ) {
			if ( $has ) {
				/* translators: %s: where in the file */
				$errors[] = sprintf( __( '%s: an image cannot have a default (it is the ID of an attachment).', 'estrategia-web' ), $where );
			}
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
		if ( null !== $prefix && ( ! str_starts_with( $name, $prefix ) || strlen( $name ) === strlen( $prefix ) ) ) {
			/* translators: 1: where in the file, 2: the name, 3: the prefix */
			$errors[] = sprintf( __( '%1$s “%2$s” must start with the prefix “%3$s”.', 'estrategia-web' ), $where, $name, $prefix );
		}
		foreach ( self::RESERVED_PREFIXES as $reserved ) {
			if ( str_starts_with( $name, $reserved ) ) {
				/* translators: 1: where in the file, 2: the name, 3: a reserved prefix */
				$errors[] = sprintf( __( '%1$s “%2$s” uses the reserved prefix “%3$s”.', 'estrategia-web' ), $where, $name, $reserved );
			}
		}
		return $errors;
	}

	private static function check_slug( $slug, string $where ): array {
		if ( null === $slug ) {
			return array();
		}
		// Slashes allowed: «carta/vins» is a valid rewrite base.
		if ( ! is_string( $slug ) || ! preg_match( '#^[a-z0-9][a-z0-9-]*(/[a-z0-9][a-z0-9-]*)*$#', $slug ) ) {
			/* translators: %s: where in the file */
			return array( sprintf( __( '%s must be lowercase letters, digits, “-” and “/” (a URL path without slashes at the ends).', 'estrategia-web' ), $where ) );
		}
		return array();
	}

	private static function check_labels( $labels, string $where, bool $required ): array {
		if ( null === $labels ) {
			/* translators: %s: where in the file */
			return $required ? array( sprintf( __( '%s is required: {"singular": …, "plural": …}.', 'estrategia-web' ), $where ) ) : array();
		}
		if ( ! is_array( $labels ) || array_is_list( $labels ) ) {
			/* translators: %s: where in the file */
			return array( sprintf( __( '%s must be an object: {"singular": …, "plural": …}.', 'estrategia-web' ), $where ) );
		}
		$errors = self::unknown_keys( $labels, self::LABEL_KEYS, $where . '.' );
		foreach ( self::LABEL_KEYS as $key ) {
			if ( ! isset( $labels[ $key ] ) || ! self::is_text( $labels[ $key ] ) ) {
				/* translators: 1: where in the file, 2: singular or plural */
				$errors[] = sprintf( __( '%1$s.%2$s is required: a string or an object of locale → string.', 'estrategia-web' ), $where, $key );
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
