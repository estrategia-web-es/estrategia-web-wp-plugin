<?php
/**
 * Los iconos de las redes: la colección incluida (icons/<slug>.svg, créditos
 * y licencias en icons/CREDITS.txt) y los SVG subidos a la mediateca.
 *
 * Los dos salen EN LÍNEA (<svg> dentro del enlace, no <img>), con
 * fill="currentColor": así toman el color del texto que los rodea y el
 * tema no tiene que saber nada de ellos. aria-hidden y focusable=false: el
 * nombre accesible lo lleva el enlace, no el dibujo.
 *
 * Cómo se mantiene:
 * - Un icono nuevo de la colección: su fichero en icons/, su línea en
 *   CREDITS.txt y su nombre en labels(). Si la red tiene dominio propio,
 *   también en HOSTS (para adivinarlo del enlace).
 * - Caso frontera: un SVG SUBIDO. Se vuelve a sanear al pintarlo
 *   (Svg_Sanitizer del módulo site) aunque ya se saneara al subir: pudo
 *   subirse antes de existir el saneado, o por FTP. Si trae sus colores
 *   (fill="#e1306c"), se respetan: currentColor solo se pone en la raíz y
 *   solo si no trae fill, así que hereda lo que no diga su color. Quien sube
 *   un icono con color lo quiere así; uno en negro puro saldrá negro (que
 *   lo exporte sin fill).
 * - La trampa: un SVG subido que no sea de la mediateca de imágenes SVG
 *   (un PNG elegido a mano por REST) NO se pinta: icon_id solo vale si el
 *   adjunto es image/svg+xml (Links::sanitize lo pone a 0 al guardar).
 */

namespace EstrategiaWeb\Social;

use EstrategiaWeb\Site\Svg_Sanitizer;

defined( 'ABSPATH' ) || exit;

final class Icons {

	/** Dominio del enlace → icono. Sirve para no tener que elegirlo a mano. */
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

	/** @return array<string,string> slug => nombre visible, en el orden del desplegable. */
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

	/** El icono que toca a un enlace por su dominio; «website» si no es de ninguna red conocida. */
	public static function guess( string $url ): string {
		if ( str_starts_with( strtolower( $url ), 'mailto:' ) ) {
			return 'email';
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^(www|m|es|mobile)\./', '', $host );
		if ( isset( self::HOSTS[ $host ] ) ) {
			return self::HOSTS[ $host ];
		}
		// google.com/maps… y google.es/maps… son mapas; business.google.com, la ficha.
		if ( preg_match( '/(^|\.)google\.[a-z.]+$/', $host ) ) {
			return str_starts_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/maps' ) ? 'google-maps' : 'google';
		}
		return 'website';
	}

	/** <svg> de la colección, listo para pintar. '' si no existe. */
	public static function svg( string $slug, string $class = '' ): string {
		if ( ! self::exists( $slug ) ) {
			return '';
		}
		return self::decorate( (string) file_get_contents( self::file( $slug ) ), $class ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** <svg> de un adjunto SVG de la mediateca, saneado otra vez. '' si no es un SVG legible. */
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

	/** Todos los de la colección (slug => <svg>), para las vistas previas de la pantalla. */
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
	 * Quita width/height (el tamaño lo pone el CSS), y pone aria-hidden,
	 * focusable y la clase, y fill="currentColor" en la raíz si no trae otro.
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
			$root->setAttribute( 'fill', 'currentColor' ); // Lo que no diga su color, hereda el del texto.
		}
		if ( '' !== $class ) {
			$root->setAttribute( 'class', $class );
		}
		return (string) $doc->saveXML( $root );
	}
}
