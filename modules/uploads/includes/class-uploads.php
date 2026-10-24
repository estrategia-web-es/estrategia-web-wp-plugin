<?php
/**
 * Image uploads: size limit, scale-down threshold and deleting the original.
 *
 * The three settings live in ONE option, estrategia_web_uploads:
 *   { max_mb: 20, threshold: 2560, delete_original: true }
 * They are edited in "Estrategia Web › Settings › Media" (1.7.0; up to
 * 1.6.1, a section of WordPress's own Settings → Media) and over REST
 * (/wp/v2/settings, show_in_rest). If the option does not exist, DEFAULTS apply.
 *
 * Its settings group is its OWN (estrategia_web_uploads), not WordPress's
 * «media» (1.7.0). The trap that moved it: options.php saves every option of
 * a group and one missing from the POST arrives as null; with the fields no
 * longer on WordPress's Media screen, saving that screen would have sent
 * null and sanitize_form() would have reset the three values to DEFAULTS.
 * The Settings page adds the option to its Media tab's group by itself
 * (render_section() declares it: includes/admin/class-settings-page.php).
 *
 * - max_mb → upload_size_limit filter. It is a CAP: the lower of this and
 *   what the server's PHP allows wins (raising it above PHP does nothing,
 *   and announcing it would be a lie). 0 = no cap of our own. Note: this is
 *   what the media library and the editor (plupload) announce and check; the
 *   media REST API does not check it again (only on multisite), same as with
 *   the old MU-plugin.
 * - threshold → big_image_size_threshold filter. A photo whose longest side
 *   is larger is stored scaled down ("-scaled"). 0 = never scale down.
 * - delete_original → once the sizes have been generated
 *   (wp_generate_attachment_metadata, priority 20, after WordPress has
 *   written original_image), the original file is deleted if it differs
 *   from the main one, and original_image is removed from the meta. The
 *   scaled version becomes the reference.
 *
 * Pitfalls (inherited from the MU-plugin, still valid):
 * - What is already uploaded does NOT change: its originals stay on disk.
 * - Without the original, "Edit image" and `wp media regenerate` work on the
 *   scaled copy: that is intended, but there is no going back.
 * - Changing the threshold does not scale down existing uploads; only later ones.
 */

namespace EstrategiaWeb\Uploads;

defined( 'ABSPATH' ) || exit;

final class Uploads {

	public const OPTION = 'estrategia_web_uploads';

	/** The values from Lavandería's MU-plugin (12-10-2026). 2560 = WordPress' default. */
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
		add_action( \EstrategiaWeb\Admin\Settings_Page::HOOK, array( self::class, 'settings_section' ) );
	}

	/** On Settings_Page::HOOK: the Media tab and this section (after site's SVG, 10). */
	public static function settings_section(): void {
		\EstrategiaWeb\Admin\Settings_Page::add_tab( 'media', __( 'Media', 'estrategia-web' ), 30 );
		\EstrategiaWeb\Admin\Settings_Page::add_section(
			'media',
			array(
				'id'       => 'uploads',
				'priority' => 20,
				'options'  => array( self::OPTION ),
				'render'   => array( self::class, 'render_section' ),
			)
		);
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
			// Below 320 px it is not scaling down, it is ruining: clamp to 320 (0 still means "never").
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
		 * (Up to 1.1.0, in Lavandería's MU-plugin: estrategia_web_subidas_borrar_original.)
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
			self::OPTION, // Its own group, not «media»: see the header.
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

	/**
	 * The section of Settings › Media: the three fields, inside the tab's
	 * shared form (the page prints <form> and settings_fields()). The hidden
	 * _form tells sanitize_form() that an absent checkbox means «no».
	 */
	public static function render_section(): void {
		$settings = self::settings();
		?>
		<h2><?php esc_html_e( 'Image uploads', 'estrategia-web' ); ?></h2>
		<p><?php esc_html_e( 'Applies to images uploaded from now on. Images already in the library do not change.', 'estrategia-web' ); ?></p>
		<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[_form]" value="1">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="estrategia_web_uploads_max_mb"><?php esc_html_e( 'Maximum upload size (MB)', 'estrategia-web' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" class="small-text" id="estrategia_web_uploads_max_mb" name="<?php echo esc_attr( self::OPTION ); ?>[max_mb]" value="<?php echo (int) $settings['max_mb']; ?>">
					<p class="description"><?php esc_html_e( 'The server limit still applies if it is lower. 0 = only the server limit.', 'estrategia-web' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="estrategia_web_uploads_threshold"><?php esc_html_e( 'Scale down images larger than (px)', 'estrategia-web' ); ?></label></th>
				<td>
					<input type="number" min="0" step="1" class="small-text" id="estrategia_web_uploads_threshold" name="<?php echo esc_attr( self::OPTION ); ?>[threshold]" value="<?php echo (int) $settings['threshold']; ?>">
					<p class="description"><?php esc_html_e( 'Longest side. WordPress default: 2560. 0 = never scale down.', 'estrategia-web' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Original file', 'estrategia-web' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delete_original]" value="1" <?php checked( $settings['delete_original'] ); ?>> <?php esc_html_e( 'Delete the original after scaling it down (only the scaled copy is kept).', 'estrategia-web' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}
}
