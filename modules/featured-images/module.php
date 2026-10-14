<?php
/**
 * Module Name: Featured image tuning
 * Description: Featured images with a fixed width in px download a file of that size, and a logo whose alt text only repeats the title shown next to it is marked as decorative.
 * Default: on
 * Requires:
 *
 * Nació con las fichas de cliente de la portada de Lavandería (14-10-2026):
 * un core/post-featured-image dentro de una Consulta, a 82×54 px, servía
 * una imagen de 300×224 con sizes="auto, (max-width:1384px) 100vw, 1384px"
 * (PageSpeed: imágenes mal dimensionadas) y su alt repetía el nombre del
 * cliente que la ficha ya pinta al lado (Lighthouse, accesibilidad: «texto
 * alternativo redundante»). Tres arreglos, genéricos:
 *
 * 1. sizes: si el bloque tiene un ancho FIJO en px (atributo width «82px»),
 *    el <img> lleva sizes="82px" (con «auto, » delante si es diferida). Otras
 *    unidades (%, em, vw) o sin ancho: no se toca; ahí el sizes de WordPress
 *    o el del contenedor (el carrusel) es mejor que una suposición.
 *    El carrusel respeta un sizes que ya es un ancho fijo en px (ver
 *    modules/carousel/includes/carousel-images.php).
 * 2. Un tamaño pequeño, «estrategia-web-small»: 200 px de ancho, alto libre,
 *    sin recortar. Sin él, entre la miniatura (150×150, RECORTADA: no entra
 *    en el srcset de una imagen que no es cuadrada) y la mediana (300) no hay
 *    candidato para 82 px a 2x. Se genera en TODA subida nueva (un fichero
 *    de pocos KB más por foto). LO YA SUBIDO NO LO TIENE: en el servidor hay
 *    que pasar `wp media regenerate --only-missing --yes` una vez.
 * 3. alt="" (decorativa) cuando la imagen destacada está en la plantilla de
 *    una Consulta (core/post-template) que TAMBIÉN pinta el título de la
 *    entrada (core/post-title), y el alt de la imagen ES ese título (igual
 *    sin contar mayúsculas, espacios ni etiquetas). Criterio escrito: solo se
 *    quita lo que ya se lee al lado, palabra por palabra; un alt que describe
 *    la foto («Secadoras industriales…») no se toca nunca, aunque haya
 *    título. Y nunca si la imagen es un ENLACE (isLink): sin alt, el enlace
 *    se quedaría sin nombre.
 *
 * Por qué nace encendido: lo usa Lavandería, ya desplegada, y no cambia
 * nada visible (el alt solo lo oye un lector de pantalla, y ahí deja de
 * repetirse). Se enciende solo al desplegar.
 *
 * Cómo se mantiene:
 * - Quién: quien toque imágenes destacadas o el carrusel. Cuándo: si
 *   WordPress empieza a poner él un sizes al ancho fijo (lo mira en cada
 *   versión mayor: el día que lo haga, el punto 1 sobra).
 * - Caso frontera, con nombre: la ficha de cliente CON enlace a su web
 *   (featured image con isLink). Ahí el alt se queda aunque repita el
 *   título: es el nombre del enlace.
 * - Filtro: estrategia_web_featured_image_decorative( $decorative, $post_id, $alt )
 *   para decidir caso a caso.
 * - La trampa: el carrusel corre DESPUÉS (wp_content_img_tag, sobre toda la
 *   plantilla) y ponía su sizes a todas las fotos con srcset, también a
 *   estas. Por eso respeta ahora un sizes que ya es «Npx».
 * - Prueba: modules/featured-images/tests/ (nivel 2).
 */

namespace EstrategiaWeb\FeaturedImages;

defined( 'ABSPATH' ) || exit;

const SMALL = 'estrategia-web-small';

add_action( 'after_setup_theme', static fn() => add_image_size( SMALL, 200, 0, false ), 20 );

/**
 * 1. sizes = the fixed width in px.
 */
add_filter(
	'render_block_core/post-featured-image',
	static function ( $html, $block ) {
		$width = trim( (string) ( $block['attrs']['width'] ?? '' ) );
		if ( ! preg_match( '/^\d+(?:\.\d+)?px$/', $width ) || ! str_contains( (string) $html, '<img' ) ) {
			return $html;
		}
		$tag = new \WP_HTML_Tag_Processor( (string) $html );
		while ( $tag->next_tag( 'IMG' ) ) {
			if ( null === $tag->get_attribute( 'srcset' ) ) {
				continue;
			}
			$lazy = 'lazy' === $tag->get_attribute( 'loading' );
			$tag->set_attribute( 'sizes', ( $lazy ? 'auto, ' : '' ) . $width );
		}
		return $tag->get_updated_html();
	},
	10,
	2
);

/**
 * Whether a parsed template (inner blocks of core/post-template) shows the
 * post title and a featured image that is not a link.
 *
 * @param array $blocks Parsed blocks.
 * @return array{title: bool, image: bool, link: bool}
 */
function scan( array $blocks ): array {
	$found = array(
		'title' => false,
		'image' => false,
		'link'  => false,
	);
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? '';
		if ( 'core/post-title' === $name ) {
			$found['title'] = true;
		} elseif ( 'core/post-featured-image' === $name ) {
			$found['image'] = true;
			$found['link']  = $found['link'] || ! empty( $block['attrs']['isLink'] );
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$inner = scan( $block['innerBlocks'] );
			$found = array(
				'title' => $found['title'] || $inner['title'],
				'image' => $found['image'] || $inner['image'],
				'link'  => $found['link'] || $inner['link'],
			);
		}
	}
	return $found;
}

/**
 * Pure: two texts are «the same» for a reader (case, spaces, tags and entities ignored).
 *
 * @param string $a One text.
 * @param string $b The other.
 */
function same_text( string $a, string $b ): bool {
	$norm = static fn( string $s ): string => mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' ) ) ) );
	return '' !== $norm( $a ) && $norm( $a ) === $norm( $b );
}

/**
 * 3. alt="" when the image only repeats the title shown in the same entry.
 */
add_filter(
	'render_block_core/post-template',
	static function ( $html, $block ) {
		if ( ! str_contains( (string) $html, 'wp-post-image' ) ) {
			return $html;
		}
		$found = scan( (array) ( $block['innerBlocks'] ?? array() ) );
		if ( ! $found['title'] || ! $found['image'] || $found['link'] ) {
			return $html;
		}
		$tag  = new \WP_HTML_Tag_Processor( (string) $html );
		$post = 0;
		while ( $tag->next_tag() ) {
			if ( 'LI' === $tag->get_tag() && $tag->has_class( 'wp-block-post' ) ) {
				$post = 0;
				foreach ( $tag->class_list() as $class ) {
					if ( preg_match( '/^post-(\d+)$/', $class, $m ) ) {
						$post = (int) $m[1];
					}
				}
				continue;
			}
			if ( 'IMG' !== $tag->get_tag() || ! $tag->has_class( 'wp-post-image' ) || ! $post ) {
				continue;
			}
			$alt        = (string) $tag->get_attribute( 'alt' );
			$decorative = same_text( $alt, get_the_title( $post ) );
			/**
			 * Whether a featured image in a query is decorative (alt="") because the
			 * entry already shows its title. See modules/featured-images/module.php.
			 *
			 * @param bool   $decorative Default: its alt text is the post title.
			 * @param int    $post_id    Post id.
			 * @param string $alt        Current alt text.
			 */
			if ( apply_filters( 'estrategia_web_featured_image_decorative', $decorative, $post, $alt ) ) {
				$tag->set_attribute( 'alt', '' );
			}
		}
		return $tag->get_updated_html();
	},
	10,
	2
);
