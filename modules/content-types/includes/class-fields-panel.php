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
		// So that wp.i18n gets this script's translations (includes/class-blocks.php).
		add_filter( 'estrategia_web_editor_script_handles', static fn( $handles ) => array_merge( (array) $handles, array( self::HANDLE ) ) );
	}

	/** The choices of a rating field: the same here (classic screen) and in fields-panel.js (it gets them in config). */
	public static function rating_options(): array {
		return array( 0 => __( 'No rating', 'estrategia-web' ), 1 => '★ (1)', 2 => '★★ (2)', 3 => '★★★ (3)', 4 => '★★★★ (4)', 5 => '★★★★★ (5)' );
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
			);
		}
		return array(
			'postType' => $type,
			/* translators: %s: singular name of a content type, e.g. Restaurant */
			'title'    => isset( $def['labels']['details'] ) ? Schema::text( $def['labels']['details'], $locale ) : sprintf( __( '%s details', 'estrategia-web' ), Schema::text( $def['labels']['singular'], $locale ) ),
			'fields'   => $fields,
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
		if ( ! $config ) {
			return;
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

	/** The classic-screen meta box (see the header). */
	public static function add_meta_box( string $type ): void {
		if ( use_block_editor_for_post_type( $type ) ) {
			return;
		}
		$post   = get_post();
		$config = self::config( $type, $post ? (int) $post->ID : 0 );
		if ( $config ) {
			add_meta_box( 'estrategia-web-content-types', $config['title'], array( self::class, 'render_meta_box' ), $type, 'normal', 'high', array( 'config' => $config ) );
		}
	}

	/** @param array $box add_meta_box() with args.config. */
	public static function render_meta_box( \WP_Post $post, array $box ): void {
		$config = $box['args']['config'];
		wp_nonce_field( 'estrategia_web_ct_' . $post->ID, 'estrategia_web_ct_nonce' );
		if ( $config['note'] ) {
			echo '<p>' . esc_html( $config['note'] ) . '</p>';
		}
		echo '<table class="form-table" role="presentation">';
		foreach ( $config['fields'] as $field ) {
			$id    = 'estrategia-web-ct-' . $field['key'];
			$name  = 'estrategia_web_ct[' . $field['key'] . ']';
			$value = get_post_meta( $post->ID, $field['key'], true );
			$attrs = ( $field['disabled'] ? ' disabled' : '' ) . ( $field['required'] ? ' aria-required="true"' : '' );
			$label = $field['label'] . ( $field['required'] ? ' *' : '' );
			echo '<tr data-meta-key="' . esc_attr( $field['key'] ) . '"><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
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
				case 'image':
					// On the classic screen, the attachment number (Media › the image › its ID).
					printf( '<input type="number" min="0" class="small-text" id="%s" name="%s" value="%d"%s>', esc_attr( $id ), esc_attr( $name ), (int) $value, $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput
					break;
				default:
					$html_type = array( 'url' => 'url', 'email' => 'email', 'tel' => 'tel', 'date' => 'date', 'number' => 'number' )[ $field['kind'] ] ?? 'text';
					$range     = 'number' === $field['kind'] ? ( null !== $field['min'] ? ' min="' . esc_attr( (string) $field['min'] ) . '"' : '' ) . ( null !== $field['max'] ? ' max="' . esc_attr( (string) $field['max'] ) . '"' : '' ) . ( $field['integer'] ? ' step="1"' : ' step="any"' ) : '';
					printf( '<input type="%s" class="regular-text" id="%s" name="%s" value="%s"%s%s>', esc_attr( $html_type ), esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), $range, $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( '' !== $field['help'] ) {
				echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
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
			update_post_meta( $post_id, $field['key'], is_scalar( $input[ $field['key'] ] ) ? $input[ $field['key'] ] : '' );
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
