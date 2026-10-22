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
		// «required» on the classic screen (types without «editor»): see check_required_classic().
		add_filter( 'wp_insert_post_data', array( self::class, 'check_required_classic' ), 10, 2 );
		add_filter( 'redirect_post_location', array( self::class, 'classic_redirect' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'classic_notice' ) );
	}

	/** Meta key => true: required fields the classic form tried to empty on a published post; the meta box leaves them alone. */
	private static array $kept = array();
	/** What happened in this save, for the redirect: '', 'draft' or 'kept', and the keys. */
	private static array $classic = array( '', array() );

	/**
	 * wp_insert_post_data: «required» on the CLASSIC screen, the form of
	 * post.php for a type without «editor» (its fields are the meta box of
	 * class-fields-panel.php). WordPress has no «refuse this save» there —
	 * the form posts, the post is written, and the user is redirected — so
	 * the standard ways out are the ones core itself uses when a publish
	 * cannot happen:
	 *   - NOT published yet (draft, pending, new) and «Publish» or «Schedule»
	 *     pressed with a required field empty: it is saved as a DRAFT, with
	 *     everything typed kept, and the notice says which fields to fill in.
	 *   - ALREADY published (or scheduled) and a required field emptied: it
	 *     stays published — taking a live page offline because a field was
	 *     emptied would be worse — and that field keeps its previous value;
	 *     the notice says so. The same as REST, where the update is refused
	 *     and the post stays as it was.
	 * Only this form (its nonce): REST has check_required(), and WP-CLI, Quick
	 * Edit and code are not the editor (Quick Edit: the TODO).
	 *
	 * @param array $data    The post row about to be written (slashed).
	 * @param array $postarr What came in (for post.php, $_POST).
	 */
	public static function check_required_classic( $data, $postarr ) {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! $id || ! in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
			return $data;
		}
		$nonce = isset( $_POST['estrategia_web_ct_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['estrategia_web_ct_nonce'] ) ) : '';
		$def   = Loader::type( (string) ( $data['post_type'] ?? '' ) );
		if ( ! $def || '' === $nonce || ! wp_verify_nonce( $nonce, 'estrategia_web_ct_' . $id ) ) {
			return $data;
		}
		$input = isset( $_POST['estrategia_web_ct'] ) && is_array( $_POST['estrategia_web_ct'] ) ? wp_unslash( $_POST['estrategia_web_ct'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only read to decide; sanitized on save.
		// A field locked by WPML in a translation is not sent: its stored value is the one that counts.
		$missing = self::missing( $def['fields'], array_map( static fn( $v ) => is_scalar( $v ) ? $v : '', (array) $input ), $id );
		if ( ! $missing ) {
			return $data;
		}
		$was = get_post_status( $id );
		if ( in_array( $was, array( 'publish', 'future' ), true ) ) {
			self::$kept    = array_fill_keys( array_keys( $missing ), true );
			self::$classic = array( 'kept', array_keys( $missing ) );
			// Still empty after keeping the old value (published before the field was required): the notice asks for it all the same.
			return $data;
		}
		$data['post_status'] = 'draft';
		// What core does for a draft without a chosen date; otherwise the failed attempt would fix the publication date.
		if ( empty( $postarr['edit_date'] ) ) {
			$data['post_date_gmt'] = '0000-00-00 00:00:00';
		}
		self::$classic = array( 'draft', array_keys( $missing ) );
		return $data;
	}

	/** Fields_Panel::save_meta_box(): the keys it must not write in this save. */
	public static function kept(): array {
		return self::$kept;
	}

	/**
	 * redirect_post_location: the notice travels in the URL, like core's
	 * «message». And core says «Post published» (message=6) for a publish
	 * that ended as a draft — the status is read before our change counts —
	 * so that becomes «Post draft updated» (10).
	 */
	public static function classic_redirect( $location, $post_id ) {
		[ $what, $keys ] = self::$classic;
		if ( '' === $what ) {
			return $location;
		}
		if ( 'draft' === $what && 'draft' === get_post_status( (int) $post_id ) ) {
			$location = add_query_arg( 'message', 10, $location );
		}
		return add_query_arg( array( 'estrategia_web_ct_required' => $what, 'estrategia_web_ct_fields' => implode( ',', $keys ) ), $location );
	}

	/** The notice on post.php after classic_redirect(). Field names are looked up again: the URL only carries keys. */
	public static function classic_notice(): void {
		$what = isset( $_GET['estrategia_web_ct_required'] ) ? sanitize_key( wp_unslash( $_GET['estrategia_web_ct_required'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$post = isset( $_GET['post'] ) ? get_post( (int) $_GET['post'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$def  = $post ? Loader::type( $post->post_type ) : null;
		if ( ! in_array( $what, array( 'draft', 'kept' ), true ) || ! $def ) {
			return;
		}
		$keys   = array_map( 'sanitize_key', explode( ',', isset( $_GET['estrategia_web_ct_fields'] ) ? (string) wp_unslash( $_GET['estrategia_web_ct_fields'] ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$locale = determine_locale();
		$labels = array();
		foreach ( $def['fields'] as $field ) {
			if ( in_array( $field['name'], $keys, true ) ) {
				$labels[] = Schema::text( $field['label'], $locale );
			}
		}
		if ( ! $labels ) {
			return;
		}
		$text = 'draft' === $what
			/* translators: %s: list of field names */
			? __( 'Not published: it has been saved as a draft. Fill in these fields to publish it: %s.', 'estrategia-web' )
			/* translators: %s: list of field names */
			: __( 'These fields cannot be empty in a published item, so they keep their previous value: %s.', 'estrategia-web' );
		printf( '<div class="notice notice-error" id="estrategia-web-ct-required"><p>%s</p></div>', esc_html( sprintf( $text, implode( ', ', $labels ) ) ) );
	}

	public static function register(): void {
		$types  = Loader::types();
		$files  = Loader::load()['files'];
		$locale = \EstrategiaWeb\Plugin::locale(); // Not determine_locale(): with WPML it can lag behind (see Plugin::load_textdomain()).

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
					'labels'        => self::labels( $singular, $plural ) + self::optional_labels( $def['labels'], $locale ),
					'description'   => Schema::text( $def['description'], $locale ),
					'public'        => $def['public'],
					'show_in_rest'  => $def['show_in_rest'],
					'has_archive'   => $def['has_archive'] ? self::slug( $def['slug'] ) : false,
					'rewrite'       => array( 'slug' => self::slug( $def['slug'] ), 'with_front' => false ),
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
						'rewrite'           => array( 'slug' => self::slug( $tax['slug'] ), 'with_front' => false ),
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

	/**
	 * The slug that is REGISTERED: the SITE language's (Plugin::site_locale(),
	 * WPML's default language), never the request's. The why is
	 * Plugin::url_slug()'s: the rewrite rules are stored once, so a slug that
	 * followed the request would build links the stored rules do not know.
	 * The other languages are WPML slug translations on top (Wpml::sync_slugs()),
	 * and WPML expects exactly this: the registered slug is the original.
	 *
	 * @param string|array<string,string> $slug
	 */
	public static function slug( $slug ): string {
		return Schema::text( $slug, \EstrategiaWeb\Plugin::site_locale() );
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

	/** The featured image labels a definition gives (Schema::TYPE_LABEL_KEYS), in WordPress's names. */
	private static function optional_labels( array $labels, string $locale ): array {
		$out = array();
		foreach ( array( 'featured_image', 'set_featured_image' ) as $key ) {
			if ( isset( $labels[ $key ] ) ) {
				$out[ $key ] = Schema::text( $labels[ $key ], $locale );
			}
		}
		return $out;
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
			'rating'      => static fn( $v ) => Sanitize::rating( $v ),
			'embed'       => static fn( $v ) => Sanitize::embed( $v ),
			'local_image' => static fn( $v ) => Sanitize::local_image( (string) $v ),
			'hours'       => static fn( $v ) => Hours::sanitize( $v ),
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
	 * This is REST: the block editor. The classic screen of a type without
	 * «editor» has check_required_classic(). NOT checked: Quick Edit, WP-CLI
	 * and update_post_meta() in code (the developer, not the editor).
	 *
	 * @param \stdClass        $prepared The post about to be saved.
	 * @param \WP_REST_Request $request
	 * @return \stdClass|\WP_Error
	 */
	public static function check_required( $prepared, $request ) {
		$id    = (int) ( $prepared->ID ?? 0 );
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
			$sent = array_key_exists( $field['name'], $incoming );
			if ( 'number' === $field['type'] ) {
				// 0 is a number (a free entry costs 0 €): empty is NOT GIVEN, never stored or sent blank.
				$empty = $sent ? ! is_numeric( $incoming[ $field['name'] ] ) : ! ( $post_id && metadata_exists( 'post', $post_id, $field['name'] ) );
			} else {
				$value = $sent
					? call_user_func( self::sanitizer( $field ), $incoming[ $field['name'] ] )
					: ( $post_id ? get_post_meta( $post_id, $field['name'], true ) : $field['default'] );
				// image and relation store an ID: 0 is «none».
				$empty = '' === $value || null === $value || ( in_array( $field['type'], array( 'image', 'relation' ), true ) && 0 === (int) $value );
			}
			if ( $empty ) {
				$out[ $field['name'] ] = Schema::text( $field['label'], $locale );
			}
		}
		return $out;
	}

	/** The fingerprint of what is declared — and of the site language, which picks the registered slugs. */
	public static function hash(): string {
		return md5( (string) wp_json_encode( array( Loader::types(), \EstrategiaWeb\Plugin::site_locale() ) ) );
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
