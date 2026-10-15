<?php
/**
 * The «Location details» panel (and «Client details», «Testimonial details»)
 * in the block editor's DOCUMENT sidebar: the Content_Types fields of that
 * type, with a control for each.
 *
 * Why it exists (07-10-2026): David wanted to change the phone number of the
 * Maioris location and could not find where. The block editor does not show
 * metas, and the «Custom fields» panel does not appear with these types. It
 * was changed through WP-CLI.
 *
 * How it is built: assets/meta-panel.js (classic script, UNCOMPILED, like
 * assets/editor.js) registers a PluginDocumentSettingPanel that reads and
 * writes the post's `meta` with core/editor. It saves with the post's «Save»
 * button, through /wp/v2/<type> (show_in_rest of register_post_meta), with the
 * same sanitising and the same permission (edit_post) as any other path.
 * Nothing is saved here: the script is only told which fields there are.
 *
 * EDGE CASE by name: the CLIENT. It has no 'editor' in supports, so WordPress
 * gives it the CLASSIC screen and the panel above does not exist. For it (and
 * for any type that does not use the block editor) the same fields show in a
 * meta box (add_meta_box / save_meta_box), with nonce, edit_post and the same
 * sanitising (update_post_meta goes through register_post_meta). It showed up
 * in the first browser test: the client panel never appeared. For location
 * and testimonial, which do open the block editor, the meta box is not
 * registered: the panel is the native way, and a meta box there forces a page
 * reload on save.
 *
 * WPML (wpml-config.xml, <custom-fields>). THE CRITERION, field by field:
 * - «copy» (WPML copies it from the original to each translation when the
 *   original is saved): whatever is THE SAME in every language. Numbers,
 *   emails, links, map codes, image ids, ratings, the image fit. That way a
 *   phone number is changed once and is valid for both languages.
 * - «translate»: the text a person reads that changes with the language.
 *   Address («Nave 14» / «Unit 14»), opening hours («Lunes a viernes» /
 *   «Monday to Friday»), business type, role of a testimonial's author.
 * - Edge case by name: the AUTHOR of a testimonial. It is a proper name
 *   («Marta G.»), so «copy»; if a site ever signs as «Client from Palma», it
 *   would have to switch to «translate». And the client website (client_url):
 *   «copy», even though there are sites with /en/; whoever wants it per
 *   language changes it here and in the XML.
 * WPML::COPY and WPML::TRANSLATE below are the list; wpml-config.xml has to
 * say the same, and modules/site/tests/meta-panel.test.mjs (level 1) fails if
 * it does not.
 *
 * With WPML, in a TRANSLATION the «copy» fields show locked with the note
 * «changed in the original language»: WPML would overwrite them on the next
 * copy from the original.
 *
 * THE COPY IS REQUESTED BY THIS MODULE (sync_copy_field), it is not left to
 * WPML. Measured on 14-10-2026 with WPML 5.1 on local Lavandería: changing the
 * phone in the panel and saving left the English location with the OLD phone.
 * The cause, read in WPML (inc/post-translation/wpml-post-translation.class.php):
 * on save_post (priority 100, after_save_post) it copies ONLY the metas it has
 * seen change earlier in that same request, and forgets the list when
 * save_post ends. The REST API (the block editor) runs wp_update_post — and
 * with it save_post — and AFTERWARDS saves the metas: they arrive too late. A
 * standalone update_post_meta (WP-CLI, the seed) does not even go through
 * save_post. That is why, every time a «copy» field is added, changed or
 * deleted on the ORIGINAL of one of these types, wpml_sync_custom_field
 * (WPML's public API) is called. When writing to the translation WPML fires
 * updated_post_meta again, but that post is a translation and is discarded;
 * the $syncing guard cuts any other round. The client meta box goes through
 * here too (and WPML copies again on its save_post: harmless).
 * Test: tests/wpml-sync.test.mjs (level 1, WPML double).
 * The trap when testing it with real WPML: WPML does not read wpml-config.xml
 * until one of the screens on its list is opened (Plugins, Languages…;
 * WPML_Config::load_config). In a freshly seeded Playground the «copy» list is
 * EMPTY and wpml_sync_custom_field does nothing: it looks like the fix fails.
 * Open /wp-admin/plugins.php before measuring.
 *
 * How to maintain:
 * - Who and when: whoever adds a field to a type in Content_Types. The field
 *   shows up ON ITS OWN in the panel (the control is chosen by its
 *   'sanitize', see KINDS); in the same commit, its line in COPY or TRANSLATE
 *   and in wpml-config.xml, and its help in help() if it is not
 *   self-explanatory.
 * - A new sanitiser in Content_Types::sanitizer() needs its control here
 *   (KINDS) and in meta-panel.js; without it, it shows as a single-line text.
 * - The trap: a type without 'custom-fields' in supports does not expose
 *   `meta` over REST and the panel would save nowhere without an error. All
 *   three carry it.
 * - And the other one: if `client` gets 'editor' one day, it moves to the
 *   panel on its own (the meta box stops being registered); the browser test
 *   (tests/meta-panel-editor.test.mjs) will have to change screen.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Meta_Panel {

	/**
	 * Types with a panel, by KEY. Project and portfolio have no fields. What
	 * comes from WordPress (the screen, the post) is the registered name
	 * («eweb_location», 1.2.0) and is turned into a key with Type_Names::key().
	 */
	public const TYPES = array( 'location', 'client', 'testimonial' );

	/** Sanitiser kind (Content_Types) → panel control. Anything not listed, text. */
	private const KINDS = array(
		'text'        => 'text',
		'email'       => 'email',
		'url'         => 'url',
		'embed'       => 'textarea',
		'textarea'    => 'textarea',
		'rating'      => 'rating',
		'fit'         => 'fit',
		'client'      => 'client',
		'local_image' => 'text',
		'image_id'    => 'image',
	);

	/** Fields WPML copies from the original (see the header). The same in wpml-config.xml. */
	public const WPML_COPY = array(
		'estrategia_web_location_phone',
		'estrategia_web_location_email',
		'estrategia_web_location_map_url',
		'estrategia_web_location_map_embed',
		'estrategia_web_location_static_map',
		'estrategia_web_location_static_map_id',
		'client_url',
		'estrategia_web_client_image_fit',
		'estrategia_web_testimonial_author',
		'estrategia_web_testimonial_rating',
		'testimonial_client',
	);

	/** Fields translated in each language. The same in wpml-config.xml. */
	public const WPML_TRANSLATE = array(
		'estrategia_web_location_address',
		'estrategia_web_location_schedule',
		'estrategia_web_client_business_type',
		'testimonial_role',
	);

	public static function boot(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		foreach ( self::TYPES as $type ) {
			add_action( 'save_post_' . Type_Names::name( $type ), array( self::class, 'save_meta_box' ), 10, 2 );
		}
		add_filter( 'estrategia_web_editor_script_handles', static fn( $handles ) => array_merge( (array) $handles, array( 'estrategia-web-site-meta-panel' ) ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'sync_copy_field' ), 10, 3 );
		}
	}

	/** Keeps the copy from calling itself (see the header). */
	private static bool $syncing = false;

	/**
	 * With WPML: a «copy» field that changes on the ORIGINAL is copied straight
	 * away to its translations. See the header for why WPML alone is not enough.
	 *
	 * @param int|int[] $meta_id Meta id (an array on deleted_post_meta). Unused.
	 */
	public static function sync_copy_field( $meta_id, $post_id, $meta_key ): void {
		if ( self::$syncing || ! self::wpml_active() || ! in_array( $meta_key, self::WPML_COPY, true ) ) {
			return;
		}
		$type = (string) get_post_type( (int) $post_id );
		if ( ! in_array( Type_Names::key( $type ), self::TYPES, true ) || self::is_translation( $type, (int) $post_id ) ) {
			return;
		}
		self::$syncing = true;
		try {
			do_action( 'wpml_sync_custom_field', (int) $post_id, $meta_key );
		} finally {
			self::$syncing = false;
		}
	}

	public static function titles(): array {
		return array(
			'location'    => __( 'Location details', 'estrategia-web' ),
			'client'      => __( 'Client details', 'estrategia-web' ),
			'testimonial' => __( 'Testimonial details', 'estrategia-web' ),
		);
	}

	/** Help under the field, only where it is not self-explanatory. */
	private static function help(): array {
		return array(
			'estrategia_web_location_map_url'       => __( 'Google Maps › Share › Copy link.', 'estrategia-web' ),
			'estrategia_web_location_map_embed'     => __( 'Google Maps › Share › Embed a map. You can paste the whole iframe code.', 'estrategia-web' ),
			'estrategia_web_location_static_map_id' => __( 'Map picture shown before Google Maps loads (privacy: Google only loads after a click).', 'estrategia-web' ),
			'estrategia_web_location_static_map'    => __( 'Only if the picture is not in the media library: a theme path (assets/maps/place.webp) or a URL of this site. The picture above wins.', 'estrategia-web' ),
			'estrategia_web_client_business_type'   => __( 'What the client does, e.g. “holiday rental agency”. It is translated; the name is not.', 'estrategia-web' ),
		);
	}

	/**
	 * What assets/meta-panel.js receives for a type, or null if that type has no panel.
	 *
	 * @param string $type The REGISTERED name («eweb_location»), the one from the screen or the post.
	 */
	public static function config( string $type, int $post_id = 0 ): ?array {
		$type_key = Type_Names::key( $type );
		if ( ! in_array( $type_key, self::TYPES, true ) || ! in_array( $type_key, Settings::enabled_types(), true ) ) {
			return null;
		}
		$locked = self::is_translation( $type, $post_id );
		$help   = self::help();
		$fields = array();
		foreach ( Content_Types::meta_definitions( $type_key ) as $key => $def ) {
			$copy     = in_array( $key, self::WPML_COPY, true );
			$fields[] = array(
				'key'      => $key,
				'label'    => $def['label'],
				'kind'     => self::KINDS[ $def['sanitize'] ] ?? 'text',
				'help'     => $locked && $copy ? __( 'Changed in the original language: it is copied to the translations.', 'estrategia-web' ) : ( $help[ $key ] ?? '' ),
				'disabled' => $locked && $copy,
			);
		}
		return array(
			'postType'   => $type,
			// A testimonial's «Related client» dropdown asks for the clients by this name.
			'clientType' => Type_Names::name( 'client' ),
			'title'      => self::titles()[ $type_key ],
			'fields'   => $fields,
			'note'     => self::wpml_active() && ! $locked ? __( 'Phone, links and map are copied to the translations when you save; address and opening hours are translated in each language.', 'estrategia-web' ) : '',
		);
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return; // Only a post's editor; not the site editor or the widgets one.
		}
		$post   = get_post();
		$config = self::config( (string) $screen->post_type, $post ? (int) $post->ID : 0 );
		if ( ! $config ) {
			return;
		}
		wp_enqueue_script(
			'estrategia-web-site-meta-panel',
			ESTRATEGIA_WEB_SITE_URL . 'assets/meta-panel.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-block-editor', 'wp-i18n' ),
			ESTRATEGIA_WEB_SITE_VERSION,
			false
		);
		wp_add_inline_script( 'estrategia-web-site-meta-panel', 'window.estrategiaWebMetaPanel = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * The same list in a classic META BOX, for the types that do NOT open the
	 * block editor: the client has no 'editor' in supports, and WordPress gives
	 * it the classic screen (use_block_editor_for_post_type).
	 */
	public static function add_meta_box( string $type ): void {
		if ( use_block_editor_for_post_type( $type ) ) {
			return;
		}
		$post   = get_post();
		$config = self::config( $type, $post ? (int) $post->ID : 0 );
		if ( ! $config ) {
			return;
		}
		add_meta_box( 'estrategia-web-meta', $config['title'], array( self::class, 'render_meta_box' ), $type, 'normal', 'high', array( 'config' => $config ) );
	}

	/** @param array $box add_meta_box() with args.config. */
	public static function render_meta_box( \WP_Post $post, array $box ): void {
		$config = $box['args']['config'];
		wp_nonce_field( 'estrategia_web_meta_' . $post->ID, 'estrategia_web_meta_nonce' );
		if ( $config['note'] ) {
			echo '<p>' . esc_html( $config['note'] ) . '</p>';
		}
		echo '<table class="form-table" role="presentation">';
		foreach ( $config['fields'] as $field ) {
			$id    = 'estrategia-web-meta-' . $field['key'];
			$name  = 'estrategia_web_meta[' . $field['key'] . ']';
			$value = get_post_meta( $post->ID, $field['key'], true );
			$off   = $field['disabled'] ? ' disabled' : '';
			echo '<tr data-meta-key="' . esc_attr( $field['key'] ) . '"><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';
			switch ( $field['kind'] ) {
				case 'textarea':
					printf( '<textarea class="large-text" rows="3" id="%s" name="%s"%s>%s</textarea>', esc_attr( $id ), esc_attr( $name ), $off, esc_textarea( (string) $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'rating':
				case 'fit':
				case 'client':
					$options = self::options( $field['kind'] );
					printf( '<select id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), $off ); // phpcs:ignore WordPress.Security.EscapeOutput
					foreach ( $options as $v => $label ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $v ), selected( (string) $value, (string) $v, false ), esc_html( $label ) );
					}
					echo '</select>';
					break;
				case 'image':
					// On the classic screen, the attachment number (Media › the image › its id). No such type exists today.
					printf( '<input type="number" min="0" class="small-text" id="%s" name="%s" value="%d"%s>', esc_attr( $id ), esc_attr( $name ), (int) $value, $off ); // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				default:
					$type = in_array( $field['kind'], array( 'email', 'url' ), true ) ? $field['kind'] : 'text';
					printf( '<input type="%s" class="regular-text" id="%s" name="%s" value="%s"%s>', esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), $off ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( '' !== $field['help'] ) {
				echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	/** Saves the meta box. Sanitising is register_post_meta's (update_post_meta → sanitize_meta). */
	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST['estrategia_web_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['estrategia_web_meta_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'estrategia_web_meta_' . $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$config = self::config( $post->post_type, $post_id );
		$input  = isset( $_POST['estrategia_web_meta'] ) && is_array( $_POST['estrategia_web_meta'] ) ? wp_unslash( $_POST['estrategia_web_meta'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised on save (sanitize_meta).
		foreach ( $config ? $config['fields'] : array() as $field ) {
			if ( $field['disabled'] || ! array_key_exists( $field['key'], $input ) ) {
				continue; // Locked (WPML) or not sent: left untouched.
			}
			update_post_meta( $post_id, $field['key'], is_scalar( $input[ $field['key'] ] ) ? $input[ $field['key'] ] : '' );
		}
	}

	/** Options of the meta box dropdowns (the same as in meta-panel.js). */
	private static function options( string $kind ): array {
		if ( 'rating' === $kind ) {
			return array( 0 => __( 'No rating', 'estrategia-web' ), 1 => '★ (1)', 2 => '★★ (2)', 3 => '★★★ (3)', 4 => '★★★★ (4)', 5 => '★★★★★ (5)' );
		}
		if ( 'fit' === $kind ) {
			return array(
				'cover'   => __( 'Photo (fills the box, may be cropped)', 'estrategia-web' ),
				'contain' => __( 'Logo (whole, never cropped)', 'estrategia-web' ),
			);
		}
		$out = array( 0 => __( '— None —', 'estrategia-web' ) );
		foreach ( get_posts( array( 'post_type' => Type_Names::name( 'client' ),'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $client ) {
			$out[ $client->ID ] = $client->post_title;
		}
		return $out;
	}

	private static function wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** With WPML: whether this post is the translation of another one (not the original). */
	private static function is_translation( string $type, int $post_id ): bool {
		if ( ! self::wpml_active() || $post_id <= 0 ) {
			return false;
		}
		$details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $post_id, 'element_type' => 'post_' . $type ) );
		return is_object( $details ) && ! empty( $details->source_language_code );
	}
}
