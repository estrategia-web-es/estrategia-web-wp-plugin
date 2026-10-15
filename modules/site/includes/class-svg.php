<?php
/**
 * Upload SVG to the media library, if `allow_svg` is enabled, ONLY for whoever
 * can already insert unfiltered HTML (unfiltered_html) or manages the site
 * (manage_options), and ALWAYS sanitised (Svg_Sanitizer) before saving it.
 *
 * Why those capabilities: an SVG is code the browser runs. Whoever has
 * unfiltered_html can already put a <script> in a page, so uploading an SVG
 * gives them nothing new; an author or a contributor, it does. And even so it
 * is sanitised: the SVG may come from a third party (the logo a client sends)
 * and whoever uploads it has not read it.
 *
 * What it touches in WordPress:
 * - upload_mimes: adds svg only for those who are allowed.
 * - wp_check_filetype_and_ext: finfo says «image/svg+xml», «text/plain» or
 *   «text/html» depending on whether it carries the XML declaration; without
 *   this WordPress rejects it «for security reasons» even if it is in upload_mimes.
 * - wp_handle_upload_prefilter / wp_handle_sideload_prefilter: here the
 *   permission is checked AGAIN (do not rely on upload_mimes alone) and the
 *   temporary file is rewritten already sanitised. Sideload is the REST API
 *   path when the file arrives as a raw body.
 * - wp_generate_attachment_metadata: width and height (from width/height or
 *   from the viewBox) and a «full» size, which is what makes
 *   wp_get_attachment_image() and the featured image work with an SVG.
 * - wp_prepare_attachment_for_js: the dimensions and size in the media library.
 *
 * How to maintain:
 * - Who and when: whoever sees a legitimate SVG broken after uploading it.
 *   What gets adjusted is Svg_Sanitizer's allowlist; sanitising is never
 *   switched off.
 * - Edge case: an .svgz (compressed). NOT accepted: it would have to be
 *   decompressed to sanitise it, and no logo needs it.
 * - The trap: the old Lavandería child theme allowed SVG with upload_mimes
 *   alone, unsanitised and for anyone with upload_files. Do not copy that into
 *   any theme: with this plugin active it is unnecessary, and if a theme adds
 *   svg to upload_mimes on its own, this prefilter keeps sanitising and keeps
 *   refusing it to an author.
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
			// If someone else (a theme) added it, whoever is not allowed does not see it here either.
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

	/** @param array $file Element of $_FILES (name, tmp_name, type, size, error). */
	public static function prefilter( $file ) {
		$name = (string) ( $file['name'] ?? '' );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
			return $file;
		}
		if ( 'svgz' === $ext || ! self::enabled() || ! self::user_can() ) {
			$file['error'] = __( 'You are not allowed to upload SVG files.', 'estrategia-web' );
			return $file;
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		$raw = '' !== $tmp && is_readable( $tmp ) ? (string) file_get_contents( $tmp ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$clean = '' === $raw ? null : Svg_Sanitizer::sanitize( $raw );
		if ( null === $clean ) {
			$file['error'] = __( 'This SVG file could not be read or is not safe, so it has not been uploaded.', 'estrategia-web' );
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
