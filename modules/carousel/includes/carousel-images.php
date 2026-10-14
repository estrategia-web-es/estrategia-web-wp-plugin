<?php
/**
 * estrategia-web/carousel — prioridad, carga diferida y sizes de sus fotos (0.7.4).
 *
 * Qué resuelve (auditoría de Lavandería, 06-10-2026): en escritorio el
 * elemento LCP del inicio era la diapositiva 2 del carrusel y solo la 1
 * llevaba fetchpriority="high"; y todas las fotos llevaban el sizes que
 * WordPress pone por defecto, «(max-width: 1920px) 100vw», así que en un
 * carrusel de tres a la vista el navegador bajaba una foto de 1536 para un
 * hueco de ~500.
 *
 * Cómo funciona, en dos tiempos:
 * 1. render.php marca cada <img> con data-estrategia-web-carousel="<id>:<n>" (n =
 *    diapositiva, desde 0) y apunta aquí, por id, cuántas diapositivas se
 *    pueden ver al cargar y qué sizes les toca (images_register).
 * 2. El filtro wp_content_img_tag, que corre DESPUÉS de que WordPress haya
 *    puesto srcset, sizes, loading y fetchpriority a cada foto, lo corrige:
 *    - Diapositivas que se ven al cargar: si WordPress dio fetchpriority
 *      "high" a una de ellas (o sea, decidió que el carrusel es lo primero de
 *      la página), TODAS las visibles llevan "high" y ninguna loading="lazy".
 *      Si no se la dio (el carrusel está más abajo), se deja lo que decidió:
 *      la prioridad no se regala a un carrusel que no está a la vista.
 *    - Las que no se ven al cargar: loading="lazy", sin fetchpriority, y
 *      sizes="auto, …" (con lazy, Chrome usa el ancho pintado de verdad).
 *    - sizes: el del carrusel, a las que tienen srcset.
 *    Al final se quita la marca: no llega a la página.
 *
 * Cuántas se ven al cargar: ceil( el mayor de perViewMobile/Tablet/Desktop ).
 * El servidor no sabe el ancho de la ventana, así que se cuenta el caso más
 * ancho. Medido en Lavandería (06-10-2026), carrusel de portada 1/2/3: a 402
 * se ve la 1 y a 1512 la 1, la 2 y la 3 (la 3, cortada). La última, en un
 * carrusel en bucle, queda a la IZQUIERDA de la primera (order de view.js) y
 * fuera de la vista: no cuenta. Se cambia con el filtro
 * estrategia_web_carousel_eager_slides( $n, $attributes ).
 *
 * El sizes por defecto sale de perView y del ancho del carrusel: 100vw si es
 * align full; si no, el wideSize o el contentSize de theme.json cuando son px
 * (si no lo son, como si fuera full). Se ignora el hueco entre diapositivas,
 * que sobrestima unos píxeles: mejor un poco grande que borrosa. Con los
 * cortes de 480 y 782 (los de style.css), uno de 1/2/3 a todo el ancho da:
 *     (max-width: 480px) 100vw, (max-width: 782px) calc(100vw / 2), calc(100vw / 3)
 *
 * Cómo se mantiene:
 * - Quién: quien toque el carrusel. Cuándo: si cambian los cortes de
 *   style.css (480/782) se cambian también en images_sizes().
 * - Caso frontera: un hijo que pinta las diapositivas a otro ancho que el de
 *   perView (Lavandería: la portada pone a cada foto su proporción, 442 / 629
 *   / 596 de ancho a 596 de alto) NO acierta con el cálculo: escribe su sizes
 *   en el atributo imageSizes del bloque. Es un AJUSTE, no un bloque nuevo.
 *   También existe el filtro estrategia_web_carousel_image_sizes( $sizes, $attributes ).
 * - La trampa: el filtro solo ve las fotos que pasan por
 *   wp_filter_content_tags (contenido de entradas y plantillas, que es donde
 *   vive un carrusel). Un carrusel pintado por otro camino (do_blocks en un
 *   shortcode propio) se queda con lo que ponga WordPress y con la marca en
 *   el HTML, que no hace nada.
 * - La otra trampa: WordPress solo da fetchpriority="high" a UNA foto por
 *   página, la primera grande (más de 50.000 px²) que encuentra. Si antes del
 *   carrusel hay otra foto grande, el carrusel no se trata como portada.
 * - Prueba: tests/carousel-images.test.mjs (nivel 3).
 *
 * Desde la 1.1.0 del plugin vive aquí (antes, inc/carousel-images.php del
 * tema) y en el espacio de nombres EstrategiaWeb\Carousel: images_register(),
 * images_mark()… Los FILTROS conservan su nombre de siempre.
 */

namespace EstrategiaWeb\Carousel;

defined( 'ABSPATH' ) || exit;

/**
 * Carousels rendered in this request: id => { eager: int, sizes: string, hero: bool }.
 *
 * @return array<string, array{eager: int, sizes: string, hero: bool}>
 */
function &images_registry(): array {
	static $registry = array();
	return $registry;
}

/**
 * Remembers how the images of one carousel have to load. Called by render.php.
 *
 * @param string $id         Carousel id (the track id).
 * @param array  $attributes Block attributes.
 */
function images_register( string $id, array $attributes ): void {
	$per_view = max(
		(float) ( $attributes['perViewMobile'] ?? 1 ),
		(float) ( $attributes['perViewTablet'] ?? 2 ),
		(float) ( $attributes['perViewDesktop'] ?? 3 ),
		1
	);
	/**
	 * How many slides can be seen when the page loads (they get priority, never lazy).
	 *
	 * @param int   $eager      Default: ceil( largest perView ).
	 * @param array $attributes Block attributes.
	 */
	$eager = (int) apply_filters( 'estrategia_web_carousel_eager_slides', (int) ceil( $per_view ), $attributes );

	$registry        = &images_registry();
	$registry[ $id ] = array(
		'eager' => max( 0, $eager ),
		'sizes' => images_sizes( $attributes ),
		'hero'  => false,
	);
}

/**
 * The sizes attribute for the slides of a carousel. See the header.
 *
 * @param array $attributes Block attributes.
 */
function images_sizes( array $attributes ): string {
	$sizes = trim( (string) ( $attributes['imageSizes'] ?? '' ) );
	if ( '' === $sizes ) {
		$cap = null;
		if ( 'full' !== ( $attributes['align'] ?? '' ) ) {
			$key    = 'wide' === ( $attributes['align'] ?? '' ) ? 'wideSize' : 'contentSize';
			$layout = (string) wp_get_global_settings( array( 'layout', $key ) );
			if ( preg_match( '/^(\d+(?:\.\d+)?)px$/', trim( $layout ), $m ) ) {
				$cap = (float) $m[1];
			}
		}
		$sizes = images_sizes_for(
			max( 1, (float) ( $attributes['perViewMobile'] ?? 1 ) ),
			max( 1, (float) ( $attributes['perViewTablet'] ?? 2 ) ),
			max( 1, (float) ( $attributes['perViewDesktop'] ?? 3 ) ),
			$cap
		);
	}
	/**
	 * The sizes attribute of the images in a carousel.
	 *
	 * @param string $sizes      Computed (or the block's imageSizes).
	 * @param array  $attributes Block attributes.
	 */
	return trim( (string) apply_filters( 'estrategia_web_carousel_image_sizes', $sizes, $attributes ) );
}

/**
 * Pure part of the sizes: per view at each container breakpoint, the carousel
 * as wide as the window up to $cap px.
 *
 * @param float      $mobile  Per view up to 480px.
 * @param float      $tablet  Per view up to 782px.
 * @param float      $desktop Per view above.
 * @param float|null $cap     Max width of the carousel in px, null = the window.
 */
function images_sizes_for( float $mobile, float $tablet, float $desktop, ?float $cap ): string {
	$fmt   = static fn( float $n ): string => rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
	$vw    = static fn( float $n ): string => 1.0 === $n ? '100vw' : 'calc(100vw / ' . $fmt( $n ) . ')';
	$steps = array( array( 480.0, $mobile ), array( 782.0, $tablet ), array( INF, $desktop ) );
	$parts = array();
	foreach ( $steps as list( $limit, $n ) ) {
		if ( null !== $cap && $cap <= $limit ) {
			$parts[] = array( $cap, $vw( $n ) );
			$parts[] = array( null, $fmt( round( $cap / $n ) ) . 'px' );
			break;
		}
		$parts[] = array( is_finite( $limit ) ? $limit : null, $vw( $n ) );
	}
	// Merge neighbours with the same value: 1/1/1 is just 100vw.
	$merged = array();
	foreach ( $parts as $part ) {
		$last = end( $merged );
		if ( $last && $last[1] === $part[1] ) {
			$merged[ count( $merged ) - 1 ] = $part;
			continue;
		}
		$merged[] = $part;
	}
	return implode(
		', ',
		array_map( static fn( $p ) => null === $p[0] ? $p[1] : '(max-width: ' . $fmt( $p[0] ) . 'px) ' . $p[1], $merged )
	);
}

/**
 * Marks every <img> of the rendered slides with data-estrategia-web-carousel="<id>:<slide>".
 * A slide is each top-level element, except a core/query wrapper, whose
 * <li class="wp-block-post"> are the slides. If the HTML API cannot parse the
 * content, it is returned untouched (WordPress' own defaults stay).
 *
 * @param string $content Inner blocks HTML.
 * @param string $id      Carousel id.
 */
function images_mark( string $content, string $id ): string {
	if ( ! str_contains( $content, '<img' ) || ! class_exists( 'WP_HTML_Processor' ) ) {
		return $content;
	}
	$html = \WP_HTML_Processor::create_fragment( $content );
	if ( ! $html ) {
		return $content;
	}
	$slide    = -1;
	$top      = null;
	$in_query = false;
	while ( $html->next_tag() ) {
		$depth = $html->get_current_depth();
		$top ??= $depth;
		if ( $depth === $top ) {
			$in_query = $html->has_class( 'wp-block-query' );
			if ( ! $in_query ) {
				++$slide;
			}
		} elseif ( $in_query && 'LI' === $html->get_tag() && $html->has_class( 'wp-block-post' ) ) {
			++$slide;
		}
		if ( 'IMG' === $html->get_tag() && $slide >= 0 ) {
			$html->set_attribute( 'data-estrategia-web-carousel', $id . ':' . $slide );
		}
	}
	if ( $html->get_last_error() ) {
		return $content;
	}
	return $html->get_updated_html();
}

/**
 * Fixes loading, fetchpriority and sizes of a marked carousel image. See the header.
 *
 * @param string $image The img tag, already with WordPress' attributes.
 */
function images_filter( $image ) {
	if ( ! is_string( $image ) || ! str_contains( $image, 'data-estrategia-web-carousel' ) ) {
		return $image;
	}
	$tag = new \WP_HTML_Tag_Processor( $image );
	if ( ! $tag->next_tag( 'IMG' ) ) {
		return $image;
	}
	$mark = (string) $tag->get_attribute( 'data-estrategia-web-carousel' );
	$tag->remove_attribute( 'data-estrategia-web-carousel' );
	$registry = &images_registry();
	$at       = strrpos( $mark, ':' );
	$id       = false === $at ? '' : substr( $mark, 0, $at );
	if ( ! isset( $registry[ $id ] ) ) {
		return $tag->get_updated_html();
	}
	$carousel = &$registry[ $id ];
	$slide    = (int) substr( $mark, $at + 1 );
	$eager    = $slide < $carousel['eager'];

	if ( $eager ) {
		if ( 'high' === $tag->get_attribute( 'fetchpriority' ) ) {
			$carousel['hero'] = true;
		}
		if ( $carousel['hero'] ) {
			// Prioridad alta SOLO para la primera (0.7.9). Con las tres visibles
			// en «high», en el móvil se pedían ~500 KB con prioridad y solo se ve
			// una: bloqueando la 2 y la 3, el LCP local bajó de 6,6 a 4,6 s
			// (Lavandería, 06-10-2026). Las demás visibles cargan ya (sin lazy),
			// pero sin adelantarse a la primera.
			if ( 0 === $slide ) {
				$tag->set_attribute( 'fetchpriority', 'high' );
			} else {
				$tag->remove_attribute( 'fetchpriority' );
			}
			$tag->remove_attribute( 'loading' );
		}
	} else {
		$tag->set_attribute( 'loading', 'lazy' );
		$tag->remove_attribute( 'fetchpriority' );
	}

	$sizes = (string) $tag->get_attribute( 'sizes' );
	// Un sizes que ya es un ancho fijo («82px», lo pone el módulo
	// featured-images a una imagen destacada de ancho fijo) es deliberado y
	// más exacto que el de la diapositiva: se respeta (1.1.x).
	$fixed = (bool) preg_match( '/^\s*(?:auto\s*,\s*)?\d+(?:\.\d+)?px\s*$/i', $sizes );
	if ( ! $fixed && null !== $tag->get_attribute( 'srcset' ) && '' !== $carousel['sizes'] ) {
		$sizes = $carousel['sizes'];
	}
	$sizes = (string) preg_replace( '/^\s*auto\s*,\s*/i', '', $sizes );
	if ( '' !== $sizes ) {
		$lazy = 'lazy' === $tag->get_attribute( 'loading' );
		$tag->set_attribute( 'sizes', ( $lazy ? 'auto, ' : '' ) . $sizes );
	}
	return $tag->get_updated_html();
}
add_filter( 'wp_content_img_tag', __NAMESPACE__ . '\images_filter', 20 );
