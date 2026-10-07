<?php
/**
 * Subir SVG a la mediateca, si `allow_svg` está activado, SOLO para quien ya
 * puede meter HTML sin filtrar (unfiltered_html) o administra la web
 * (manage_options), y SIEMPRE saneado (Svg_Sanitizer) antes de guardarlo.
 *
 * Por qué esas capacidades: un SVG es código que el navegador ejecuta. Quien
 * tiene unfiltered_html ya puede meter un <script> en una página, así que
 * subir un SVG no le da nada nuevo; un autor o un colaborador, sí. Y aun así
 * se sanea: el SVG puede venir de un tercero (el logo que manda un cliente) y
 * quien lo sube no lo ha leído.
 *
 * Qué toca de WordPress:
 * - upload_mimes: añade svg solo para quien puede.
 * - wp_check_filetype_and_ext: finfo dice «image/svg+xml», «text/plain» o
 *   «text/html» según lleve o no la declaración XML; sin esto WordPress lo
 *   rechaza «por seguridad» aunque esté en upload_mimes.
 * - wp_handle_upload_prefilter / wp_handle_sideload_prefilter: aquí se
 *   comprueba el permiso OTRA VEZ (no fiarse solo de upload_mimes) y se
 *   reescribe el fichero temporal ya saneado. Sideload es el camino de la API
 *   REST cuando el fichero llega como cuerpo crudo.
 * - wp_generate_attachment_metadata: ancho y alto (del width/height o del
 *   viewBox) y un tamaño «full», que es lo que hace que wp_get_attachment_image()
 *   y la imagen destacada funcionen con un SVG.
 * - wp_prepare_attachment_for_js: las medidas y el tamaño en la mediateca.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien vea un SVG legítimo roto tras subirlo. Lo que se
 *   ajusta es la lista blanca de Svg_Sanitizer, nunca se desactiva el saneado.
 * - Caso frontera: un .svgz (comprimido). NO se admite: habría que
 *   descomprimir para sanear, y ningún logo lo necesita.
 * - La trampa: el tema hijo viejo de Lavandería permitía SVG con solo
 *   upload_mimes, sin sanear y para cualquiera con upload_files. No copiar eso
 *   en ningún tema: con este plugin activo sobra, y si un tema añade svg a
 *   upload_mimes por su cuenta, este prefilter sigue saneando y sigue
 *   negándoselo a un autor.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Svg {

	public const MIME = 'image/svg+xml';

	public static function boot(): void {
		add_filter( 'upload_mimes', array( self::class, 'mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( self::class, 'check_filetype' ), 10, 4 );
		add_filter( 'wp_handle_upload_prefilter', array( self::class, 'prefilter' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( self::class, 'prefilter' ) );
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'metadata' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( self::class, 'for_js' ), 10, 3 );
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'allow_svg' );
	}

	public static function user_can(): bool {
		return current_user_can( 'unfiltered_html' ) || current_user_can( 'manage_options' );
	}

	public static function mimes( array $mimes ): array {
		if ( self::enabled() && self::user_can() ) {
			$mimes['svg'] = self::MIME;
		} else {
			// Si otro (un tema) lo añadió, quien no puede tampoco lo ve aquí.
			unset( $mimes['svg'], $mimes['svgz'] );
		}
		return $mimes;
	}

	public static function check_filetype( $data, $file, $filename, $mimes ) {
		if ( 'svg' === strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) ) && self::enabled() && self::user_can() ) {
			$data['ext']  = 'svg';
			$data['type'] = self::MIME;
		}
		return $data;
	}

	/** @param array $file Elemento de $_FILES (name, tmp_name, type, size, error). */
	public static function prefilter( $file ) {
		$name = (string) ( $file['name'] ?? '' );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
			return $file;
		}
		if ( 'svgz' === $ext || ! self::enabled() || ! self::user_can() ) {
			$file['error'] = __( 'You are not allowed to upload SVG files.', 'estrategia-web-site' );
			return $file;
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		$raw = '' !== $tmp && is_readable( $tmp ) ? (string) file_get_contents( $tmp ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$clean = '' === $raw ? null : Svg_Sanitizer::sanitize( $raw );
		if ( null === $clean ) {
			$file['error'] = __( 'This SVG file could not be read or is not safe, so it has not been uploaded.', 'estrategia-web-site' );
			return $file;
		}
		file_put_contents( $tmp, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$file['size'] = strlen( $clean );
		$file['type'] = self::MIME;
		return $file;
	}

	public static function metadata( $metadata, $attachment_id ) {
		if ( self::MIME !== get_post_mime_type( $attachment_id ) ) {
			return $metadata;
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return $metadata;
		}
		[ $width, $height ] = Svg_Sanitizer::dimensions( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$metadata = is_array( $metadata ) ? $metadata : array();
		$metadata['width']  = $width;
		$metadata['height'] = $height;
		$metadata['file']   = _wp_relative_upload_path( $path );
		$metadata['sizes']  = array(
			'full' => array(
				'file'      => wp_basename( $path ),
				'width'     => $width,
				'height'    => $height,
				'mime-type' => self::MIME,
			),
		);
		return $metadata;
	}

	public static function for_js( $response, $attachment, $meta ) {
		if ( self::MIME !== ( $response['mime'] ?? '' ) ) {
			return $response;
		}
		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		$response['width']  = $width;
		$response['height'] = $height;
		$response['sizes']  = array(
			'full' => array(
				'url'         => $response['url'],
				'width'       => $width,
				'height'      => $height,
				'orientation' => $height > $width ? 'portrait' : 'landscape',
			),
		);
		return $response;
	}
}
