<?php
/**
 * THE FIELDS IN THE EDITOR: a panel «<Singular> details» in the document
 * sidebar of the block editor, with one control per field of the type. The
 * generalized copy of modules/site's «Location details» panel
 * (class-meta-panel.php, where the why is told): same approach, but the
 * fields come from the JSON definitions instead of a PHP list.
 *
 * assets/fields-panel.js is a classic script, NO BUILD STEP, like the rest
 * of the plugin. It reads and writes the post's `meta` with core/editor and
 * saves with the post's Save button, through /wp/v2/<type> — same sanitizer
 * and permission (edit_post) as any other way in. Nothing is saved here for
 * the block editor: PHP only tells the script which fields there are.
 *
 * BOUNDARY CASE, with a name: a type WITHOUT «editor» in supports. WordPress
 * gives it the CLASSIC screen, where the panel does not exist. For it, the
 * same fields go into a meta box (render_meta_box / save_meta_box) with a
 * nonce, edit_post and the same sanitizer (update_post_meta goes through
 * register_post_meta). Same as modules/site's client. «required» there:
 * Registrar::check_required_classic() (a draft with a notice; a published
 * post keeps the previous value).
 *
 * With WPML, in a TRANSLATION the «copy» fields are locked with the note
 * «changed in the original language»: WPML would overwrite them.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Fields_Panel {

	public const HANDLE = 'estrategia-web-content-types-panel';

	public static function boot(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post', array( self::class, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_box' ) );
		// So that wp.i18n gets this script's translations (includes/class-blocks.php).
		add_filter( 'estrategia_web_editor_script_handles', static fn( $handles ) => array_merge( (array) $handles, array( self::HANDLE ) ) );
	}

	/** The choices of a rating field: the same here (classic screen) and in fields-panel.js (it gets them in config). */
	public static function rating_options(): array {
		return array( 0 => __( 'No rating', 'estrategia-web' ), 1 => '★ (1)', 2 => '★★ (2)', 3 => '★★★ (3)', 4 => '★★★★ (4)', 5 => '★★★★★ (5)' );
	}

	/** @return string[] The seven day names of an «hours» field, Monday first, in the editor's language. */
	public static function day_names(): array {
		global $wp_locale;
		$out = array();
		foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $n ) {
			$out[] = $wp_locale ? (string) $wp_locale->get_weekday( $n ) : array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' )[ $n ];
		}
		return $out;
	}

	/** What assets/fields-panel.js receives for a type, or null if it is not one of ours or has no fields. */
	public static function config( string $type, int $post_id = 0 ): ?array {
		$def = Loader::type( $type );
		if ( ! $def || ! $def['fields'] || ! in_array( $type, Registrar::registered(), true ) ) {
			return null;
		}
		$locale = determine_locale();
		$locked = Wpml::is_translation( $type, $post_id );
		$fields = array();
		foreach ( $def['fields'] as $field ) {
			$copy     = 'copy' === $field['wpml'];
			$fields[] = array(
				'key'       => $field['name'],
				'kind'      => $field['type'],
				'label'     => Schema::text( $field['label'], $locale ),
				'help'      => $locked && $copy ? __( 'Changed in the original language: it is copied to the translations.', 'estrategia-web' ) : Schema::text( $field['help'], $locale ),
				'required'  => $field['required'],
				'disabled'  => $locked && $copy,
				'integer'   => 'integer' === $field['meta_type'],
				'min'       => $field['min'],
				'max'       => $field['max'],
				'options'   => 'rating' === $field['type']
					? array_map( static fn( $v, $l ) => array( 'value' => (string) $v, 'label' => $l ), array_keys( self::rating_options() ), self::rating_options() )
					: array_map( static fn( $o ) => array( 'value' => $o['value'], 'label' => Schema::text( $o['label'], $locale ) ), $field['options'] ),
				'postTypes' => $field['post_type'],
				'default'   => $field['default'],
				'group'     => $field['group'],
			);
		}
		return array(
			'postType' => $type,
			'box'      => self::uses_box( $type, $def ),
			'groups'   => array_map( static fn( $g ) => array( 'key' => $g['key'], 'label' => Schema::text( $g['label'], $locale ), 'collapsed' => $g['collapsed'] ), $def['groups'] ),
			/* translators: %s: singular name of a content type, e.g. Restaurant */
			'title'    => isset( $def['labels']['details'] ) ? Schema::text( $def['labels']['details'], $locale ) : sprintf( __( '%s details', 'estrategia-web' ), Schema::text( $def['labels']['singular'], $locale ) ),
			'fields'   => $fields,
			'days'     => self::day_names(),
			'note'     => Wpml::active() && ! $locked && Wpml::copy_keys( $def ) ? __( 'Fields that are the same in every language are copied to the translations when you save; the others are translated in each language.', 'estrategia-web' ) : '',
		);
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return; // Only the editor of a post; not the site editor or widgets.
		}
		$post   = get_post();
		$config = self::config( (string) $screen->post_type, $post ? (int) $post->ID : 0 );
		if ( ! $config || $config['box'] ) {
			return; // «fields_box: main»: the fields are in the meta box, not in the sidebar (one place to edit them).
		}
		wp_enqueue_script(
			self::HANDLE,
			ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/fields-panel.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-block-editor', 'wp-i18n' ),
			ESTRATEGIA_WEB_VERSION,
			false
		);
		wp_add_inline_script( self::HANDLE, 'window.estrategiaWebContentTypesPanel = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * The meta box instead of the sidebar panel: a type WITHOUT «editor»
	 * (WordPress gives it the classic screen: the only place there is) or
	 * one whose definition says «fields_box: main» (1.8.0).
	 */
	public static function uses_box( string $type, ?array $def = null ): bool {
		$def = $def ?? Loader::type( $type );
		return ! use_block_editor_for_post_type( $type ) || ( $def && 'main' === ( $def['fields_box'] ?? 'sidebar' ) );
	}

	/** The meta box (see the header and uses_box()). */
	public static function add_meta_box( string $type ): void {
		$post   = get_post();
		$config = self::config( $type, $post ? (int) $post->ID : 0 );
		if ( $config && $config['box'] ) {
			add_meta_box( 'estrategia-web-content-types', $config['title'], array( self::class, 'render_meta_box' ), $type, 'normal', 'high', array( 'config' => $config ) );
		}
	}

	/** The meta box's script and style: the media library picker and «Copy Monday to every day». */
	public static function enqueue_box( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		$config = $screen ? self::config( (string) $screen->post_type ) : null;
		if ( ! $config || ! $config['box'] ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'estrategia-web-ct-box', ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/fields-box.css', array(), ESTRATEGIA_WEB_VERSION );
		wp_enqueue_script( 'estrategia-web-ct-box', ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/fields-box.js', array(), ESTRATEGIA_WEB_VERSION, true );
		// Its texts from PHP (like the constructor's Page::strings()): an admin script gets no inline wp.i18n here.
		wp_add_inline_script(
			'estrategia-web-ct-box',
			'window.estrategiaWebCtBox = ' . wp_json_encode(
				array(
					'choose'  => __( 'Choose image', 'estrategia-web' ),
					'replace' => __( 'Replace image', 'estrategia-web' ),
					'remove'  => __( 'Remove', 'estrategia-web' ),
					'copy'    => __( 'Copy Monday to every day', 'estrategia-web' ),
					'copied'  => __( 'Monday’s hours copied to every day.', 'estrategia-web' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The meta box: the fields with no group first, then each of the
	 * definition's «groups» in order, as a <details> (closed if the group is
	 * «collapsed»). Under a group, what other code adds with the filter
	 * estrategia_web_content_types_box_preview (the location's «Shown on the
	 * site»: Location_Fields::preview()).
	 *
	 * @param array $box add_meta_box() with args.config.
	 */
	public static function render_meta_box( \WP_Post $post, array $box ): void {
		$config = $box['args']['config'];
		wp_nonce_field( 'estrategia_web_ct_' . $post->ID, 'estrategia_web_ct_nonce' );
		echo '<div class="estrategia-web-ct-box">';
		if ( $config['note'] ) {
			echo '<p>' . esc_html( $config['note'] ) . '</p>';
		}
		$known  = array_column( $config['groups'], 'key' );
		$by     = array( '' => array() );
		foreach ( $config['fields'] as $field ) {
			$by[ in_array( $field['group'], $known, true ) ? $field['group'] : '' ][] = $field;
		}
		if ( $by[''] ) {
			echo '<div class="estrategia-web-ct-fields">';
			self::render_fields( $post, $config, $by[''] );
			echo '</div>';
		}
		foreach ( $config['groups'] as $group ) {
			if ( empty( $by[ $group['key'] ] ) ) {
				continue;
			}
			printf( '<details class="estrategia-web-ct-group" data-group="%s"%s><summary>%s</summary><div class="estrategia-web-ct-fields">', esc_attr( $group['key'] ), $group['collapsed'] ? '' : ' open', esc_html( $group['label'] ) );
			self::render_fields( $post, $config, $by[ $group['key'] ] );
			echo '</div>';
			/**
			 * HTML added under a group of the fields meta box (already escaped by whoever adds it).
			 *
			 * @param string   $html  What other callbacks added.
			 * @param string   $group The group's key.
			 * @param \WP_Post $post  The post being edited.
			 */
			echo apply_filters( 'estrategia_web_content_types_box_preview', '', $group['key'], $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by its callbacks.
			echo '</details>';
		}
		echo '</div>';
	}

	/** One control per field (the classic markup the meta box has always had, one <div> each). */
	private static function render_fields( \WP_Post $post, array $config, array $fields ): void {
		foreach ( $fields as $field ) {
			$id    = 'estrategia-web-ct-' . $field['key'];
			$name  = 'estrategia_web_ct[' . $field['key'] . ']';
			$value = get_post_meta( $post->ID, $field['key'], true );
			$attrs = ( $field['disabled'] ? ' disabled' : '' ) . ( $field['required'] ? ' aria-required="true"' : '' );
			$label = $field['label'] . ( $field['required'] ? ' *' : '' );
			$wide  = in_array( $field['kind'], array( 'textarea', 'embed', 'hours', 'image' ), true ) ? ' estrategia-web-ct-field--wide' : '';
			echo '<div class="estrategia-web-ct-field estrategia-web-ct-field--' . esc_attr( $field['kind'] ) . $wide . '" data-meta-key="' . esc_attr( $field['key'] ) . '">';
			if ( 'hours' !== $field['kind'] ) {
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			}
			switch ( $field['kind'] ) {
				case 'textarea':
				case 'embed':
					printf( '<textarea class="large-text" rows="3" id="%s" name="%s"%s>%s</textarea>', esc_attr( $id ), esc_attr( $name ), $attrs, esc_textarea( (string) $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'select':
				case 'relation':
				case 'rating':
					printf( '<select id="%s" name="%s"%s>', esc_attr( $id ), esc_attr( $name ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput
					foreach ( self::options( $field ) as $v => $text ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $v ), selected( (string) $value, (string) $v, false ), esc_html( $text ) );
					}
					echo '</select>';
					break;
				case 'boolean':
					// The hidden 0 first: an unticked box sends nothing.
					printf( '<input type="hidden" name="%s" value="0"><input type="checkbox" id="%s" name="%s" value="1"%s%s>', esc_attr( $name ), esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				case 'hours':
					// One line per day: «08:00-14:00, 16:00-20:00»; empty = closed. fields-box.js adds «Copy Monday to every day».
					echo '<fieldset class="estrategia-web-ct-hours"><legend>' . esc_html( $label ) . '</legend>';
					foreach ( Hours::DAYS as $i => $day ) {
						$day_id = $id . '-' . $day;
						printf(
							'<p class="estrategia-web-ct-day"><label for="%1$s">%2$s</label> <input type="text" class="regular-text" id="%1$s" name="%3$s[%4$s]" value="%5$s" placeholder="09:00-14:00, 16:00-20:00" data-day="%4$s"%6$s></p>',
							esc_attr( $day_id ),
							esc_html( $config['days'][ $i ] ?? $day ),
							esc_attr( $name ),
							esc_attr( $day ),
							esc_attr( Hours::to_line( $value, $day ) ),
							$attrs // phpcs:ignore WordPress.Security.EscapeOutput
						);
					}
					echo '</fieldset>';
					break;
				case 'image':
					// The attachment number (Media › the image › its ID). fields-box.js turns it into a
					// «Choose image» button with the picture (the media library); without JavaScript, the number.
					$src = (int) $value > 0 ? (string) wp_get_attachment_image_url( (int) $value, 'medium' ) : '';
					printf(
						'<span class="estrategia-web-ct-image" data-src="%s"><input type="number" min="0" class="small-text" id="%s" name="%s" value="%d"%s></span>',
						esc_url( $src ),
						esc_attr( $id ),
						esc_attr( $name ),
						(int) $value,
						$attrs // phpcs:ignore WordPress.Security.EscapeOutput
					);
					break;
				default:
					$html_type = array( 'url' => 'url', 'email' => 'email', 'tel' => 'tel', 'date' => 'date', 'number' => 'number' )[ $field['kind'] ] ?? 'text';
					$range     = 'number' === $field['kind'] ? ( null !== $field['min'] ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '' ) . ( null !== $field['max'] ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '' ) . ( $field['integer'] ? ' step="1"' : ' step="any"' ) : '';
					printf( '<input type="%s" class="regular-text" id="%s" name="%s" value="%s"%s%s>', esc_attr( $html_type ), esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), $range, $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( '' !== $field['help'] ) {
				echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
			}
			echo '</div>';
		}
	}

	/** Saves the meta box. The sanitizer is register_post_meta's (update_post_meta → sanitize_meta). */
	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST['estrategia_web_ct_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['estrategia_web_ct_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'estrategia_web_ct_' . $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$config = self::config( $post->post_type, $post_id );
		$input  = isset( $_POST['estrategia_web_ct'] ) && is_array( $_POST['estrategia_web_ct'] ) ? wp_unslash( $_POST['estrategia_web_ct'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized on save (sanitize_meta).
		foreach ( $config ? $config['fields'] : array() as $field ) {
			if ( $field['disabled'] || ! array_key_exists( $field['key'], $input ) || isset( Registrar::kept()[ $field['key'] ] ) ) {
				continue; // Locked (WPML), not sent, or a required one emptied on a published post (Registrar::check_required_classic()): left alone.
			}
			$value = $input[ $field['key'] ];
			if ( 'hours' === $field['kind'] && is_array( $value ) ) {
				// One line per day (render_meta_box) → day → ranges; the sanitizer makes it the stored JSON.
				$value = array_map( static fn( $line ) => Hours::from_line( (string) $line ), array_intersect_key( $value, array_flip( Hours::DAYS ) ) );
			}
			update_post_meta( $post_id, $field['key'], is_scalar( $value ) || 'hours' === $field['kind'] ? $value : '' );
		}
	}

	/** Options of a select or a relation on the classic screen (the same as fields-panel.js). */
	private static function options( array $field ): array {
		if ( 'rating' === $field['kind'] ) {
			return self::rating_options();
		}
		if ( 'select' === $field['kind'] ) {
			// «None» only when it can be stored: with a default, '' becomes the default.
			$out = $field['required'] || '' !== $field['default'] ? array() : array( '' => __( '— None —', 'estrategia-web' ) );
			foreach ( $field['options'] as $option ) {
				$out[ $option['value'] ] = $option['label'];
			}
			return $out;
		}
		$out = array( 0 => __( '— None —', 'estrategia-web' ) );
		foreach ( get_posts( array( 'post_type' => $field['postTypes'], 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $related ) {
			$out[ $related->ID ] = $related->post_title;
		}
		return $out;
	}
}
