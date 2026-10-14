<?php
/**
 * Subidas de imágenes: límite, umbral de reducción y borrar el original.
 *
 * Los tres ajustes viven en UNA opción, estrategia_web_uploads:
 *   { max_mb: 20, threshold: 2560, delete_original: true }
 * Se editan en «Ajustes → Medios» (sección propia) y por REST
 * (/wp/v2/settings, show_in_rest). Si la opción no existe valen DEFAULTS.
 *
 * - max_mb → filtro upload_size_limit. Es un TOPE: manda el menor entre esto
 *   y lo que deja el PHP del servidor (subir el valor por encima del PHP no
 *   sirve de nada y anunciarlo sería mentir). 0 = sin tope propio. Ojo: es lo
 *   que anuncian y comprueban la mediateca y el editor (plupload); la API
 *   REST de medios no lo vuelve a mirar (solo en multisitio), igual que con
 *   el MU-plugin de antes.
 * - threshold → filtro big_image_size_threshold. Una foto con el lado largo
 *   mayor se guarda reducida («-scaled»). 0 = no reducir nunca.
 * - delete_original → al terminar de generar los tamaños
 *   (wp_generate_attachment_metadata, prioridad 20, después de que WordPress
 *   haya escrito original_image), se borra el fichero original si es otro
 *   que el principal y se quita original_image del meta. La versión reducida
 *   pasa a ser la de referencia.
 *
 * Trampas (heredadas del MU-plugin, siguen valiendo):
 * - Lo ya subido NO cambia: sus originales siguen en el disco.
 * - Sin original, «Editar imagen» y `wp media regenerate` trabajan sobre la
 *   reducida: es lo que se quiere, pero no hay vuelta atrás.
 * - Cambiar el umbral no reduce lo ya subido; solo lo que se suba después.
 */

namespace EstrategiaWeb\Uploads;

defined( 'ABSPATH' ) || exit;

final class Uploads {

	public const OPTION = 'estrategia_web_uploads';

	/** Los valores del MU-plugin de Lavandería (12-10-2026). 2560 = el de serie de WordPress. */
	public const DEFAULTS = array(
		'max_mb'          => 20,
		'threshold'       => 2560,
		'delete_original' => true,
	);

	public static function boot(): void {
		add_filter( 'upload_size_limit', array( self::class, 'upload_size_limit' ) );
		add_filter( 'big_image_size_threshold', array( self::class, 'threshold' ) );
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'delete_original' ), 20, 2 );
		add_action( 'init', array( self::class, 'register_setting' ) );
		add_action( 'admin_init', array( self::class, 'settings_fields' ) );
	}

	/**
	 * The saved settings, completed with the defaults and sanitized.
	 *
	 * @param mixed $value Raw value (null = read the option).
	 * @return array{max_mb: int, threshold: int, delete_original: bool}
	 */
	public static function settings( $value = null ): array {
		if ( null === $value && function_exists( 'get_option' ) ) {
			$value = get_option( self::OPTION, array() );
		}
		return self::sanitize( $value );
	}

	/**
	 * Pure: completes and clamps a settings array. A checkbox that is not sent
	 * means «off» only when the form was sent (key present with any value or
	 * the whole array came from the form); a missing key means the default.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$out   = self::DEFAULTS;
		if ( isset( $value['max_mb'] ) && is_numeric( $value['max_mb'] ) ) {
			$out['max_mb'] = max( 0, min( 1024, (int) $value['max_mb'] ) );
		}
		if ( isset( $value['threshold'] ) && is_numeric( $value['threshold'] ) ) {
			$threshold = max( 0, (int) $value['threshold'] );
			// Por debajo de 320 px no es reducir, es estropear: se queda en 320 (0 sigue siendo «nunca»).
			$out['threshold'] = 0 === $threshold ? 0 : max( 320, min( 20000, $threshold ) );
		}
		if ( array_key_exists( 'delete_original', $value ) ) {
			$out['delete_original'] = filter_var( $value['delete_original'], FILTER_VALIDATE_BOOLEAN );
		}
		return $out;
	}

	/**
	 * Pure: the upload limit in bytes, given what WordPress/PHP allows.
	 *
	 * @param int   $size     WordPress' limit (PHP upload_max_filesize / post_max_size).
	 * @param array $settings Sanitized settings.
	 */
	public static function limit_for( int $size, array $settings ): int {
		$ours = (int) $settings['max_mb'] * 1024 * 1024;
		if ( $ours <= 0 ) {
			return $size;
		}
		return $size > 0 ? min( $size, $ours ) : $ours;
	}

	/**
	 * Filter upload_size_limit.
	 *
	 * @param int $size Bytes.
	 */
	public static function upload_size_limit( $size ): int {
		return self::limit_for( (int) $size, self::settings() );
	}

	/**
	 * Filter big_image_size_threshold. 0 in the settings → false (never scale).
	 *
	 * @param int|false $threshold WordPress' threshold.
	 * @return int|false
	 */
	public static function threshold( $threshold ) {
		$ours = self::settings()['threshold'];
		return $ours > 0 ? $ours : false;
	}

	/**
	 * Filter wp_generate_attachment_metadata (priority 20): deletes the original
	 * file kept by WordPress next to the scaled/converted one.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array
	 */
	public static function delete_original( $metadata, $attachment_id ) {
		if ( ! is_array( $metadata ) || empty( $metadata['original_image'] ) ) {
			return $metadata;
		}
		/**
		 * Whether to delete the original upload after WordPress scaled or converted it.
		 * (Hasta la 1.1.0, en el MU-plugin de Lavandería: estrategia_web_subidas_borrar_original.)
		 *
		 * @param bool $delete        The «Delete the original» setting.
		 * @param int  $attachment_id Attachment id.
		 */
		if ( ! apply_filters( 'estrategia_web_uploads_delete_original', self::settings()['delete_original'], (int) $attachment_id ) ) {
			return $metadata;
		}
		$original = wp_get_original_image_path( $attachment_id );
		$current  = get_attached_file( $attachment_id );
		if ( $original && $current && $original !== $current && file_exists( $original ) && file_exists( $current ) ) {
			wp_delete_file( $original );
		}
		unset( $metadata['original_image'] );
		return $metadata;
	}

	public static function register_setting(): void {
		register_setting(
			'media',
			self::OPTION,
			array(
				'type'              => 'object',
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( self::class, 'sanitize_form' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'max_mb'          => array( 'type' => 'integer', 'minimum' => 0 ),
							'threshold'       => array( 'type' => 'integer', 'minimum' => 0 ),
							'delete_original' => array( 'type' => 'boolean' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Sanitize for the settings form: an unchecked checkbox is not sent at all,
	 * so on that screen a missing delete_original means false.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_form( $value ): array {
		$value = is_array( $value ) ? $value : array();
		if ( isset( $value['_form'] ) ) {
			$value['delete_original'] = ! empty( $value['delete_original'] );
			unset( $value['_form'] );
		}
		return self::sanitize( $value );
	}

	public static function settings_fields(): void {
		add_settings_section(
			'estrategia_web_uploads',
			__( 'Image uploads', 'estrategia-web' ),
			static function () {
				echo '<p>' . esc_html__( 'Applies to images uploaded from now on. Images already in the library do not change.', 'estrategia-web' ) . '</p>';
				echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[_form]" value="1">';
			},
			'media'
		);
		$settings = self::settings();
		add_settings_field(
			'estrategia_web_uploads_max_mb',
			__( 'Maximum upload size (MB)', 'estrategia-web' ),
			static function () use ( $settings ) {
				printf(
					'<input type="number" min="0" step="1" class="small-text" id="estrategia_web_uploads_max_mb" name="%1$s[max_mb]" value="%2$d"> <p class="description">%3$s</p>',
					esc_attr( self::OPTION ),
					(int) $settings['max_mb'],
					esc_html__( 'The server limit still applies if it is lower. 0 = only the server limit.', 'estrategia-web' )
				);
			},
			'media',
			'estrategia_web_uploads',
			array( 'label_for' => 'estrategia_web_uploads_max_mb' )
		);
		add_settings_field(
			'estrategia_web_uploads_threshold',
			__( 'Scale down images larger than (px)', 'estrategia-web' ),
			static function () use ( $settings ) {
				printf(
					'<input type="number" min="0" step="1" class="small-text" id="estrategia_web_uploads_threshold" name="%1$s[threshold]" value="%2$d"> <p class="description">%3$s</p>',
					esc_attr( self::OPTION ),
					(int) $settings['threshold'],
					esc_html__( 'Longest side. WordPress default: 2560. 0 = never scale down.', 'estrategia-web' )
				);
			},
			'media',
			'estrategia_web_uploads',
			array( 'label_for' => 'estrategia_web_uploads_threshold' )
		);
		add_settings_field(
			'estrategia_web_uploads_delete_original',
			__( 'Original file', 'estrategia-web' ),
			static function () use ( $settings ) {
				printf(
					'<label><input type="checkbox" name="%1$s[delete_original]" value="1"%2$s> %3$s</label>',
					esc_attr( self::OPTION ),
					checked( $settings['delete_original'], true, false ),
					esc_html__( 'Delete the original after scaling it down (only the scaled copy is kept).', 'estrategia-web' )
				);
			},
			'media',
			'estrategia_web_uploads'
		);
	}
}
