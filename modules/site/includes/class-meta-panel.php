<?php
/**
 * El panel «Datos de la sede» (y «Datos del cliente», «Datos de la opinión»)
 * en la barra lateral del DOCUMENTO del editor de bloques: los campos de
 * Content_Types de ese tipo, con un control para cada uno.
 *
 * Por qué existe (07-10-2026): David quiso cambiar el teléfono de la sede
 * Maioris y no encontró dónde. El editor de bloques no enseña las metas, y
 * el panel «Campos personalizados» no sale con estos tipos. Se cambió por
 * WP-CLI.
 *
 * Cómo está hecho: assets/meta-panel.js (script clásico, SIN COMPILAR, como
 * assets/editor.js) registra un PluginDocumentSettingPanel que lee y escribe
 * `meta` de la entrada con core/editor. Guarda con el botón «Guardar» de la
 * entrada, por /wp/v2/<tipo> (show_in_rest de register_post_meta), con el
 * mismo saneado y el mismo permiso (edit_post) que cualquier otra vía. Aquí
 * no se guarda nada: solo se le dice al script qué campos hay.
 *
 * CASO FRONTERA con nombre: el CLIENTE. No tiene 'editor' en supports, así
 * que WordPress le da la pantalla CLÁSICA y el panel de arriba no existe.
 * Para él (y para cualquier tipo que no use el editor de bloques) los
 * mismos campos salen en una meta box (add_meta_box / save_meta_box), con
 * nonce, edit_post y el mismo saneado (update_post_meta pasa por
 * register_post_meta). Se vio en la primera prueba de navegador: el panel
 * del cliente no aparecía nunca. Para sede y opinión, que sí abren el
 * editor de bloques, la meta box no se registra: el panel es lo nativo y
 * una meta box ahí obliga a recargar la página al guardar.
 *
 * WPML (wpml-config.xml, <custom-fields>). EL CRITERIO, campo a campo:
 * - «copy» (WPML lo copia del original a cada traducción al guardar el
 *   original): lo que es IGUAL en todos los idiomas. Números, correos,
 *   enlaces, códigos de mapa, ids de imagen, valoraciones, el ajuste de
 *   imagen. Así un teléfono se cambia una vez y vale para los dos idiomas.
 * - «translate»: el texto que lee una persona y cambia con el idioma.
 *   Dirección («Nave 14» / «Unit 14»), horario («Lunes a viernes»), tipo de
 *   negocio, cargo del autor de una opinión.
 * - Caso frontera con nombre: el AUTOR de una opinión. Es un nombre propio
 *   («Marta G.»), así que «copy»; si alguna web firma como «Cliente de
 *   Palma», tendría que pasar a «translate». Y la web del cliente (client_url):
 *   «copy», aunque haya webs con /en/; quien la quiera por idioma lo cambia
 *   aquí y en el XML.
 * WPML::COPY y WPML::TRANSLATE de abajo son la lista; wpml-config.xml tiene
 * que decir lo mismo, y modules/site/tests/meta-panel.test.mjs (nivel 1)
 * falla si no.
 *
 * Con WPML, en una TRADUCCIÓN los campos «copy» salen bloqueados con la
 * nota «se cambia en el idioma original»: WPML los pisaría en el siguiente
 * guardado del original. (Leído en el código de WPML, no probado: la copia
 * la hace WpmlSavePostHooks en wp_after_insert_post, prioridad 100, que la
 * API REST dispara DESPUÉS de guardar las metas.)
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien añada un campo a un tipo en Content_Types. El
 *   campo sale SOLO en el panel (el control se elige por su 'sanitize', ver
 *   KINDS); en el mismo commit, su línea en COPY o TRANSLATE y en
 *   wpml-config.xml, y su ayuda en help() si no se entiende solo.
 * - Un saneado nuevo en Content_Types::sanitizer() necesita su control aquí
 *   (KINDS) y en meta-panel.js; sin él sale como texto de una línea.
 * - La trampa: un tipo sin 'custom-fields' en supports no expone `meta` en
 *   REST y el panel guardaría en ninguna parte sin error. Los tres lo llevan.
 * - Y la otra: si a `client` se le añade 'editor' un día, pasa solo al
 *   panel (la meta box deja de registrarse); la prueba de navegador
 *   (tests/meta-panel-editor.test.mjs) tendrá que cambiar de pantalla.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Meta_Panel {

	/** Tipos con panel. Proyecto y portfolio no tienen campos. */
	public const TYPES = array( 'location', 'client', 'testimonial' );

	/** Clase de saneado (Content_Types) → control del panel. Lo que no está, texto. */
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

	/** Campos que WPML copia del original (ver la cabecera). Iguales en wpml-config.xml. */
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

	/** Campos que se traducen en cada idioma. Iguales en wpml-config.xml. */
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
			add_action( 'save_post_' . $type, array( self::class, 'save_meta_box' ), 10, 2 );
		}
		add_filter( 'estrategia_web_editor_script_handles', static fn( $handles ) => array_merge( (array) $handles, array( 'estrategia-web-site-meta-panel' ) ) );
	}

	public static function titles(): array {
		return array(
			'location'    => __( 'Location details', 'estrategia-web' ),
			'client'      => __( 'Client details', 'estrategia-web' ),
			'testimonial' => __( 'Testimonial details', 'estrategia-web' ),
		);
	}

	/** Ayuda bajo el campo, solo donde no se entiende solo. */
	private static function help(): array {
		return array(
			'estrategia_web_location_map_url'       => __( 'Google Maps › Share › Copy link.', 'estrategia-web' ),
			'estrategia_web_location_map_embed'     => __( 'Google Maps › Share › Embed a map. You can paste the whole iframe code.', 'estrategia-web' ),
			'estrategia_web_location_static_map_id' => __( 'Map picture shown before Google Maps loads (privacy: Google only loads after a click).', 'estrategia-web' ),
			'estrategia_web_location_static_map'    => __( 'Only if the picture is not in the media library: a theme path (assets/maps/place.webp) or a URL of this site. The picture above wins.', 'estrategia-web' ),
			'estrategia_web_client_business_type'   => __( 'What the client does, e.g. “holiday rental agency”. It is translated; the name is not.', 'estrategia-web' ),
		);
	}

	/** Lo que recibe assets/meta-panel.js para un tipo, o null si ese tipo no lleva panel. */
	public static function config( string $type, int $post_id = 0 ): ?array {
		if ( ! in_array( $type, self::TYPES, true ) || ! in_array( $type, Settings::enabled_types(), true ) ) {
			return null;
		}
		$locked = self::is_translation( $type, $post_id );
		$help   = self::help();
		$fields = array();
		foreach ( Content_Types::meta_definitions( $type ) as $key => $def ) {
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
			'postType' => $type,
			'title'    => self::titles()[ $type ],
			'fields'   => $fields,
			'note'     => self::wpml_active() && ! $locked ? __( 'Phone, links and map are copied to the translations when you save; address and opening hours are translated in each language.', 'estrategia-web' ) : '',
		);
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return; // Solo el editor de una entrada; no el del sitio ni el de widgets.
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
	 * La misma lista en una META BOX clásica, para los tipos que NO abren el
	 * editor de bloques: el cliente no tiene 'editor' en supports, y
	 * WordPress le da la pantalla clásica (use_block_editor_for_post_type).
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

	/** @param array $box add_meta_box() con args.config. */
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
					// En la pantalla clásica, el número del adjunto (Medios › la imagen › su id). No hay tipo así hoy.
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

	/** Guarda la meta box. El saneado es el de register_post_meta (update_post_meta → sanitize_meta). */
	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST['estrategia_web_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['estrategia_web_meta_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'estrategia_web_meta_' . $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$config = self::config( $post->post_type, $post_id );
		$input  = isset( $_POST['estrategia_web_meta'] ) && is_array( $_POST['estrategia_web_meta'] ) ? wp_unslash( $_POST['estrategia_web_meta'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- se sanea al guardar (sanitize_meta).
		foreach ( $config ? $config['fields'] : array() as $field ) {
			if ( $field['disabled'] || ! array_key_exists( $field['key'], $input ) ) {
				continue; // Bloqueado (WPML) o no enviado: no se toca.
			}
			update_post_meta( $post_id, $field['key'], is_scalar( $input[ $field['key'] ] ) ? $input[ $field['key'] ] : '' );
		}
	}

	/** Opciones de los desplegables de la meta box (las mismas que en meta-panel.js). */
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
		foreach ( get_posts( array( 'post_type' => 'client', 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $client ) {
			$out[ $client->ID ] = $client->post_title;
		}
		return $out;
	}

	private static function wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** Con WPML: si esta entrada es la traducción de otra (no el original). */
	private static function is_translation( string $type, int $post_id ): bool {
		if ( ! self::wpml_active() || $post_id <= 0 ) {
			return false;
		}
		$details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $post_id, 'element_type' => 'post_' . $type ) );
		return is_object( $details ) && ! empty( $details->source_language_code );
	}
}
