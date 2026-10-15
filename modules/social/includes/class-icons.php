<?php
/**
 * Network icons: the included collection (icons/<slug>.svg, credits and
 * licences in icons/CREDITS.txt) and SVGs uploaded to the media library.
 *
 * Both are output INLINE (<svg> inside the link, not <img>), with
 * fill="currentColor": that way they take the colour of the surrounding
 * text and the theme needs to know nothing about them. aria-hidden and
 * focusable=false: the accessible name is carried by the link, not the
 * drawing.
 *
 * How to maintain:
 * - A new collection icon: its file in icons/, its line in CREDITS.txt and
 *   its name in labels(). If the network has its own domain, also in HOSTS
 *   (to guess it from the link).
 * - Edge case: an UPLOADED SVG. It is sanitized again when rendered
 *   (Svg_Sanitizer of the site module) even though it was sanitized on
 *   upload: it may have been uploaded before sanitizing existed, or via FTP.
 *   If it carries its own colours (fill="#e1306c"), they are kept:
 *   currentColor is only set on the root and only if it has no fill, so
 *   whatever does not state its colour inherits. Whoever uploads a coloured
 *   icon wants it that way; a pure black one will come out black (export it
 *   without fill).
 * - The trap: an uploaded file that is not an SVG image in the media library
 *   (a PNG picked by hand via REST) is NOT rendered: icon_id is only valid
 *   if the attachment is image/svg+xml (Links::sanitize sets it to 0 on save).
 */

namespace EstrategiaWeb\Social;

use EstrategiaWeb\Site\Svg_Sanitizer;

defined( 'ABSPATH' ) || exit;

final class Icons {

	/** Link domain → icon. Saves choosing it by hand. */
	private const HOSTS = array(
		'instagram.com'   => 'instagram',
		'facebook.com'    => 'facebook',
		'fb.com'          => 'facebook',
		'linkedin.com'    => 'linkedin',
		'x.com'           => 'x',
		'twitter.com'     => 'x',
		'youtube.com'     => 'youtube',
		'youtu.be'        => 'youtube',
		'tiktok.com'      => 'tiktok',
		'wa.me'           => 'whatsapp',
		'whatsapp.com'    => 'whatsapp',
		't.me'            => 'telegram',
		'telegram.me'     => 'telegram',
		'pinterest.com'   => 'pinterest',
		'pinterest.es'    => 'pinterest',
		'maps.app.goo.gl' => 'google-maps',
		'g.page'          => 'google',
		'tripadvisor.com' => 'tripadvisor',
		'tripadvisor.es'  => 'tripadvisor',
		'threads.net'     => 'threads',
		'threads.com'     => 'threads',
		'bsky.app'        => 'bluesky',
		'vimeo.com'       => 'vimeo',
	);

	/** @return array<string,string> slug => visible name, in dropdown order. */
	public static function labels(): array {
		return array(
			'instagram'   => 'Instagram',
			'facebook'    => 'Facebook',
			'linkedin'    => 'LinkedIn',
			'x'           => 'X (Twitter)',
			'youtube'     => 'YouTube',
			'tiktok'      => 'TikTok',
			'whatsapp'    => 'WhatsApp',
			'telegram'    => 'Telegram',
			'pinterest'   => 'Pinterest',
			'google'      => __( 'Google (Business Profile)', 'estrategia-web' ),
			'google-maps' => 'Google Maps',
			'tripadvisor' => 'Tripadvisor',
			'threads'     => 'Threads',
			'bluesky'     => 'Bluesky',
			'vimeo'       => 'Vimeo',
			'email'       => __( 'Email', 'estrategia-web' ),
			'website'     => __( 'Website', 'estrategia-web' ),
		);
	}

	public static function exists( string $slug ): bool {
		return isset( self::labels()[ $slug ] ) && is_readable( self::file( $slug ) );
	}

	/** The icon for a link by its domain; “website” if it is no known network. */
	public static function guess( string $url ): string {
		if ( str_starts_with( strtolower( $url ), 'mailto:' ) ) {
			return 'email';
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^(www|m|es|mobile)\./', '', $host );
		if ( isset( self::HOSTS[ $host ] ) ) {
			return self::HOSTS[ $host ];
		}
		// google.com/maps… and google.es/maps… are maps; business.google.com, the listing.
		if ( preg_match( '/(^|\.)google\.[a-z.]+$/', $host ) ) {
			return str_starts_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/maps' ) ? 'google-maps' : 'google';
		}
		return 'website';
	}

	/** Collection <svg>, ready to render. '' if it does not exist. */
	public static function svg( string $slug, string $class = '' ): string {
		if ( ! self::exists( $slug ) ) {
			return '';
		}
		return self::decorate( (string) file_get_contents( self::file( $slug ) ), $class ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** <svg> of an SVG attachment in the media library, sanitized again. '' if it is not a readable SVG. */
	public static function attachment_svg( int $id, string $class = '' ): string {
		if ( $id <= 0 || 'image/svg+xml' !== get_post_mime_type( $id ) ) {
			return '';
		}
		$file = (string) get_attached_file( $id );
		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}
		$clean = Svg_Sanitizer::sanitize( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $clean ? self::decorate( $clean, $class ) : '';
	}

	/** The whole collection (slug => <svg>), for the screen's previews. */
	public static function all(): array {
		$out = array();
		foreach ( array_keys( self::labels() ) as $slug ) {
			$out[ $slug ] = self::svg( $slug );
		}
		return array_filter( $out );
	}

	private static function file( string $slug ): string {
		return dirname( __DIR__ ) . '/icons/' . sanitize_key( $slug ) . '.svg';
	}

	/**
	 * Removes width/height (CSS sets the size), and adds aria-hidden,
	 * focusable and the class, plus fill="currentColor" on the root if it has no other.
	 */
	private static function decorate( string $svg, string $class ): string {
		$doc = new \DOMDocument();
		if ( ! @$doc->loadXML( $svg, LIBXML_NONET ) || ! $doc->documentElement || 'svg' !== $doc->documentElement->localName ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return '';
		}
		$root = $doc->documentElement;
		$root->removeAttribute( 'width' );
		$root->removeAttribute( 'height' );
		$root->setAttribute( 'aria-hidden', 'true' );
		$root->setAttribute( 'focusable', 'false' );
		if ( ! $root->hasAttribute( 'fill' ) ) {
			$root->setAttribute( 'fill', 'currentColor' ); // Whatever does not state its colour inherits the text's.
		}
		if ( '' !== $class ) {
			$root->setAttribute( 'class', $class );
		}
		return (string) $doc->saveXML( $root );
	}
}
