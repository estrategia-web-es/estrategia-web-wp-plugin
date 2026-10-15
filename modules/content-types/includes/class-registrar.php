<?php
/**
 * WHAT EACH DEFINITION GENERATES in WordPress: register_post_type(),
 * register_taxonomy() and one register_post_meta() per field (single,
 * show_in_rest, typed, sanitized, with label: the label is what the Block
 * Bindings UI lists, so every field is ready for core/post-meta in a
 * template). Also: the «required» check on publish, and the rewrite flush
 * when the definitions change.
 *
 * The rewrite flush: md5 of every normalized definition, kept in the option
 * estrategia_web_content_types_hash. Different hash ⇒ flush_rewrite_rules()
 * ONCE, at init 99 — after this request registered the new definitions, so
 * the rules are rebuilt with them (in modules/site the flush only deletes
 * the rules, because that one runs before the new types exist). A deploy
 * that changes a JSON file therefore needs nothing else.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Registrar {

	public const HASH_OPTION = 'estrategia_web_content_types_hash';

	/** Types and taxonomies THIS module registered in this request (the others are someone else's). */
	private static array $registered = array();

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 5 ); // Before most code that queries types at init 10.
		add_action( 'init', array( self::class, 'check_relations' ), 98 );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
	}

	public static function register(): void {
		$types  = Loader::types();
		$files  = Loader::load()['files'];
		$locale = determine_locale();

		// Which types use each taxonomy: the one that declares it and those that reference it.
		$tax_types = array();
		foreach ( $types as $name => $def ) {
			foreach ( $def['taxonomies'] as $tax ) {
				$tax_types[ is_string( $tax ) ? $tax : $tax['name'] ][] = $name;
			}
		}

		foreach ( $types as $name => $def ) {
			if ( post_type_exists( $name ) ) {
				/* translators: %s: post type name */
				Loader::add_error( $files[ $name ], sprintf( __( 'The type “%s” is already registered by other code: this file is ignored.', 'estrategia-web' ), $name ) );
				continue;
			}
			$singular = Schema::text( $def['labels']['singular'], $locale );
			$plural   = Schema::text( $def['labels']['plural'], $locale );
			$result   = register_post_type(
				$name,
				array(
					'labels'        => self::labels( $singular, $plural ),
					'description'   => Schema::text( $def['description'], $locale ),
					'public'        => $def['public'],
					'show_in_rest'  => $def['show_in_rest'],
					'has_archive'   => $def['has_archive'] ? $def['slug'] : false,
					'rewrite'       => array( 'slug' => $def['slug'], 'with_front' => false ),
					'hierarchical'  => $def['hierarchical'],
					'menu_icon'     => $def['icon'],
					'menu_position' => $def['menu_position'],
					'supports'      => $def['supports'],
				)
			);
			if ( is_wp_error( $result ) ) {
				Loader::add_error( $files[ $name ], $result->get_error_message() );
				continue;
			}
			self::$registered[] = $name;

			foreach ( $def['taxonomies'] as $tax ) {
				if ( is_string( $tax ) ) {
					continue; // Registered by the file that declares it (or by WordPress: category, post_tag).
				}
				if ( taxonomy_exists( $tax['name'] ) ) {
					/* translators: %s: taxonomy name */
					Loader::add_error( $files[ $name ], sprintf( __( 'The taxonomy “%s” is already registered by other code: it is not registered again.', 'estrategia-web' ), $tax['name'] ) );
					continue;
				}
				$t_singular = Schema::text( $tax['labels']['singular'], $locale );
				$t_plural   = Schema::text( $tax['labels']['plural'], $locale );
				register_taxonomy(
					$tax['name'],
					$tax_types[ $tax['name'] ] ?? array( $name ),
					array(
						'labels'            => array(
							'name'          => $t_plural,
							'singular_name' => $t_singular,
							'menu_name'     => $t_plural,
							'all_items'     => $t_plural,
							/* translators: %s: singular name of a taxonomy, e.g. Cuisine */
							'add_new_item'  => sprintf( __( 'Add %s', 'estrategia-web' ), $t_singular ),
							/* translators: %s: singular name of a taxonomy */
							'edit_item'     => sprintf( __( 'Edit %s', 'estrategia-web' ), $t_singular ),
						),
						'hierarchical'      => $tax['hierarchical'],
						'public'            => $tax['public'],
						'show_in_rest'      => $tax['show_in_rest'],
						'show_admin_column' => $tax['show_admin_column'],
						'rewrite'           => array( 'slug' => $tax['slug'], 'with_front' => false ),
					)
				);
				self::$registered[] = $tax['name'];
			}
			foreach ( $def['taxonomies'] as $tax ) {
				if ( is_string( $tax ) && in_array( $tax, Schema::CORE_TAXONOMIES, true ) ) {
					register_taxonomy_for_object_type( $tax, $name );
				}
			}

			foreach ( $def['fields'] as $field ) {
				register_post_meta(
					$name,
					$field['name'],
					array(
						'type'              => $field['meta_type'],
						'label'             => Schema::text( $field['label'], $locale ),
						'description'       => Schema::text( $field['help'], $locale ),
						'single'            => true,
						'default'           => $field['default'],
						'show_in_rest'      => true,
						'sanitize_callback' => self::sanitizer( $field ),
						'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
					)
				);
			}
			if ( array_filter( $def['fields'], static fn( $f ) => $f['required'] ) ) {
				add_filter( 'rest_pre_insert_' . $name, array( self::class, 'check_required' ), 10, 2 );
			}
		}
	}

	/** @return string[] What this module registered in this request. */
	public static function registered(): array {
		return self::$registered;
	}

	/** A relation to a type that does not exist stores nothing but 0: say so on the screen. */
	public static function check_relations(): void {
		$files = Loader::load()['files'];
		foreach ( Loader::types() as $name => $def ) {
			foreach ( $def['fields'] as $field ) {
				foreach ( 'relation' === $field['type'] ? $field['post_type'] : array() as $target ) {
					if ( ! post_type_exists( $target ) ) {
						/* translators: 1: field name, 2: post type name */
						Loader::add_error( $files[ $name ], sprintf( __( 'The relation “%1$s” points to the type “%2$s”, which is not registered: it can only store 0.', 'estrategia-web' ), $field['name'], $target ) );
					}
				}
			}
		}
	}

	/** The admin labels from the two the file gives. */
	private static function labels( string $singular, string $plural ): array {
		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'all_items'          => $plural,
			/* translators: %s: singular name of a content type, e.g. Restaurant */
			'add_new_item'       => sprintf( __( 'Add %s', 'estrategia-web' ), $singular ),
			/* translators: %s: singular name of a content type */
			'edit_item'          => sprintf( __( 'Edit %s', 'estrategia-web' ), $singular ),
			/* translators: %s: singular name of a content type */
			'view_item'          => sprintf( __( 'View %s', 'estrategia-web' ), $singular ),
			/* translators: %s: plural name of a content type, e.g. Restaurants */
			'search_items'       => sprintf( __( 'Search %s', 'estrategia-web' ), $plural ),
			'not_found'          => __( 'Nothing found.', 'estrategia-web' ),
			'not_found_in_trash' => __( 'Nothing found in the trash.', 'estrategia-web' ),
		);
	}

	/**
	 * The sanitizer of a field. What cannot be stored becomes the empty value
	 * (or the default, for a select): never an error, like modules/site.
	 */
	public static function sanitizer( array $field ): callable {
		$default = $field['default'];
		return match ( $field['type'] ) {
			'textarea' => static fn( $v ) => sanitize_textarea_field( (string) $v ),
			'url'      => static fn( $v ) => esc_url_raw( trim( (string) $v ), array( 'http', 'https' ) ),
			'email'    => static fn( $v ) => is_email( trim( (string) $v ) ) ? sanitize_email( (string) $v ) : '',
			// Digits, spaces and + ( ) - . only: what people type in a phone number.
			'tel'      => static fn( $v ) => trim( (string) preg_replace( '/[^0-9+()\s.\-]/', '', sanitize_text_field( (string) $v ) ) ),
			'date'     => static fn( $v ) => Schema::is_date( trim( (string) $v ) ) ? trim( (string) $v ) : '',
			'image'    => static fn( $v ) => ( (int) $v > 0 && wp_attachment_is_image( (int) $v ) ) ? (int) $v : 0,
			'relation' => static fn( $v ) => ( (int) $v > 0 && in_array( get_post_type( (int) $v ), $field['post_type'], true ) ) ? (int) $v : 0,
			'select'   => static fn( $v ) => in_array( (string) $v, array_column( $field['options'], 'value' ), true ) ? (string) $v : (string) $default,
			'boolean'  => static fn( $v ) => rest_sanitize_boolean( $v ),
			'number'   => static function ( $v ) use ( $field, $default ) {
				if ( ! is_numeric( $v ) ) {
					return $default;
				}
				$n = 'integer' === $field['meta_type'] ? (int) round( (float) $v ) : (float) $v;
				$n = null !== $field['min'] ? max( $field['min'], $n ) : $n;
				return null !== $field['max'] ? min( $field['max'], $n ) : $n;
			},
			default    => static fn( $v ) => sanitize_text_field( (string) $v ),
		};
	}

	/**
	 * rest_pre_insert_<type>: a post that ends up published (or scheduled)
	 * with a required field empty is refused with a 400 that the block editor
	 * shows as a notice. Drafts may be incomplete. The final value is the one
	 * in the request if it comes (sanitized as it would be stored), or else
	 * the stored one.
	 *
	 * Only REST is checked: the block editor. NOT checked: the classic screen
	 * of a type without «editor» in supports (its meta box marks the field as
	 * required but publishes anyway; PENDIENTES), WP-CLI and update_post_meta()
	 * in code (the developer, not the editor).
	 *
	 * @param \stdClass        $prepared The post about to be saved.
	 * @param \WP_REST_Request $request
	 * @return \stdClass|\WP_Error
	 */
	public static function check_required( $prepared, $request ) {
		$id     = (int) ( $prepared->ID ?? 0 );
		$status = $prepared->post_status ?? ( $id ? get_post_status( $id ) : 'draft' );
		if ( ! in_array( $status, array( 'publish', 'future' ), true ) ) {
			return $prepared;
		}
		$def     = Loader::type( (string) ( $prepared->post_type ?? ( $id ? get_post_type( $id ) : '' ) ) );
		$missing = self::missing( $def ? $def['fields'] : array(), (array) ( $request['meta'] ?? array() ), $id );
		if ( ! $missing ) {
			return $prepared;
		}
		return new \WP_Error(
			'estrategia_web_required_fields',
			/* translators: %s: list of field names */
			sprintf( __( 'Fill in these fields before publishing: %s.', 'estrategia-web' ), implode( ', ', $missing ) ),
			array( 'status' => 400, 'fields' => array_keys( $missing ) )
		);
	}

	/**
	 * @param array $incoming Meta key => value that comes with the request.
	 * @return array<string,string> Meta key => label of each required field that would end up empty.
	 */
	public static function missing( array $fields, array $incoming, int $post_id ): array {
		$out    = array();
		$locale = determine_locale();
		foreach ( $fields as $field ) {
			if ( ! $field['required'] ) {
				continue;
			}
			$value = array_key_exists( $field['name'], $incoming )
				? call_user_func( self::sanitizer( $field ), $incoming[ $field['name'] ] )
				: ( $post_id ? get_post_meta( $post_id, $field['name'], true ) : $field['default'] );
			if ( '' === $value || null === $value || ( in_array( $field['meta_type'], array( 'integer' ), true ) && 0 === (int) $value && in_array( $field['type'], array( 'image', 'relation' ), true ) ) ) {
				$out[ $field['name'] ] = Schema::text( $field['label'], $locale );
			}
		}
		return $out;
	}

	/** The fingerprint of what is declared. */
	public static function hash(): string {
		return md5( (string) wp_json_encode( Loader::types() ) );
	}

	/** See the header: one flush when the definitions change, after they are registered. */
	public static function maybe_flush(): void {
		$hash = self::hash();
		if ( get_option( self::HASH_OPTION ) === $hash ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::HASH_OPTION, $hash, true );
		Wpml::definitions_changed();
	}
}
