<?php
/**
 * estrategia-web/carousel — recorte para el móvil de las fotos (1.1.x).
 *
 * Qué resuelve (PageSpeed móvil de Lavandería, 14-10-2026): en el móvil la
 * caja de la diapositiva del hero es VERTICAL (370/460, CSS del hijo) y la
 * foto es horizontal; con object-fit: cover se ve una franja central, pero
 * el navegador baja la foto entera a 1536 px (163 KB de ahorro posible, LCP
 * 3,7 s). Aquí, por debajo de 782 px de ventana, se sirve un RECORTE vertical
 * de la misma foto, del tamaño de la caja:
 *
 *     <picture class="estrategia-web-carousel__picture">
 *       <source media="(max-width: 781px)" srcset="…-600x746.webp 600w, …" sizes="…">
 *       <img …la de siempre, con su srcset horizontal…>
 *     </picture>
 *
 * SOLO SI SE PIDE: el plugin no sabe qué forma le da el tema a la caja en el
 * móvil. Se pide con el atributo del bloque mobileRatio («370/460», «4/5»,
 * «0.8») o, para un estilo del tema que no está en el contenido (es el caso
 * de Lavandería: la forma la pone el CSS de is-style-hero-carousel), con el
 * filtro estrategia_web_carousel_mobile_crop( $crop, $attributes ), que
 * recibe null o el array de abajo y devuelve lo mismo.
 *     $crop = array(
 *         'ratio'  => 370 / 460,              // ancho / alto de la caja
 *         'widths' => array( 480, 720, 960, 1200 ), // anchos del srcset
 *         'sizes'  => '…',                          // sizes del <source>
 *         'media'  => '(max-width: 781px)',
 *     );
 * mobileImageSizes (atributo) sustituye el sizes calculado (perViewMobile por
 * debajo de 480, perViewTablet hasta 781). Anchos 480 / 720 / 960 / 1200, por
 * la caja real: en el móvil mide 340-400 px CSS. 720 es el Moto G de
 * Lighthouse (379 px a 1,75x = 663); con 600 / 900 se iba al de 900 y
 * Lighthouse seguía marcando 45 KB de sobra en la LCP (medido en Lavandería
 * local, 14-10-2026). 960 cubre un 2x y 1200 un 3x; nunca más que el recorte
 * más grande que da la foto (sin ampliar).
 *
 * PUNTO DE ENFOQUE: el recorte se centra donde la diapositiva ya lo dice:
 * object-position en el style del propio <img> (lo pone core/cover desde su
 * focalPoint, o cualquiera que se lo escriba), en %; si no hay, el centro
 * (50 % 50 %), que es lo que hace object-fit: cover sin object-position. El
 * filtro estrategia_web_carousel_mobile_focal( [x, y] (0-1), $attachment_id, $img )
 * lo cambia. OJO: un object-position puesto por CSS del tema (por
 * nth-child, como el de escritorio de Lavandería) NO se ve desde aquí; el de
 * su móvil es 50 % 50 %, que es el centro, así que cuadra.
 *
 * DÓNDE SE GUARDA: el recorte se genera con wp_get_image_editor la PRIMERA
 * vez que se pinta la página y se apunta como un tamaño más del adjunto en
 * sus metadatos (sizes['estrategia-web-crop-r804-f50x50-900'] …), así que:
 * - es idempotente: la segunda vez solo se mira el meta y que el fichero
 *   exista; si falta (alguien lo borró, `wp media regenerate`), se rehace;
 * - sale en el formato de los demás tamaños: WebP si el módulo webp está
 *   encendido o la foto ya lo era (lo decide image_editor_output_format, el
 *   mismo filtro que usan los tamaños de WordPress);
 * - al borrar el adjunto, WordPress borra también estos ficheros (están en
 *   sizes). Cambiar ratio, enfoque o anchos crea otros nombres; los viejos se
 *   quedan hasta que se regenere o se borre el adjunto.
 *
 * Cómo se mantiene:
 * - Quién: quien toque el carrusel. Cuándo: si cambia el corte de 782 px de
 *   style.css, cambia el media por defecto (MEDIA).
 * - Caso frontera, con nombre: una diapositiva cuya foto YA tiene la forma de
 *   la caja (±1 %) no se recorta: no hay nada que ahorrar.
 * - Otro: las fotos de una Consulta (imagen destacada) no llevan la clase
 *   wp-image-ID y WordPress no le pasa su id a wp_content_img_tag: no se
 *   recortan. Hoy solo lo usa el hero, que son core/image.
 * - La trampa: la primera visita tras publicar paga el recorte (unos cientos
 *   de ms por foto y ancho). Tras desplegar, abrir la portada una vez.
 * - Prueba: tests/carousel-mobile-crop.test.mjs del tema (nivel 3) y
 *   modules/carousel/tests/mobile-crop-pure.test.mjs (nivel 1, la geometría).
 */

namespace EstrategiaWeb\Carousel;

defined( 'ABSPATH' ) || exit;

const MOBILE_MEDIA  = '(max-width: 781px)';
const MOBILE_WIDTHS = array( 480, 720, 960, 1200 );

/**
 * Pure: «370/460», «4:5», «0.8» → 0.8043…; anything else → null.
 *
 * @param mixed $value Attribute value.
 */
function mobile_ratio_parse( $value ): ?float {
	$value = trim( (string) $value );
	if ( preg_match( '/^(\d+(?:\.\d+)?)\s*[\/:x]\s*(\d+(?:\.\d+)?)$/', $value, $m ) && (float) $m[2] > 0 ) {
		$ratio = (float) $m[1] / (float) $m[2];
	} elseif ( is_numeric( $value ) ) {
		$ratio = (float) $value;
	} else {
		return null;
	}
	return $ratio >= 0.2 && $ratio <= 5 ? $ratio : null;
}

/**
 * The crop a carousel asks for, or null. See the header.
 *
 * @param array $attributes Block attributes.
 */
function mobile_crop_config( array $attributes ): ?array {
	$crop  = null;
	$ratio = mobile_ratio_parse( $attributes['mobileRatio'] ?? '' );
	if ( null !== $ratio ) {
		$mobile = max( 1, (float) ( $attributes['perViewMobile'] ?? 1 ) );
		$tablet = max( 1, (float) ( $attributes['perViewTablet'] ?? 2 ) );
		$sizes  = trim( (string) ( $attributes['mobileImageSizes'] ?? '' ) );
		$crop   = array(
			'ratio'  => $ratio,
			'widths' => MOBILE_WIDTHS,
			'sizes'  => '' !== $sizes ? $sizes : images_sizes_for( $mobile, $tablet, $tablet, null ),
			'media'  => MOBILE_MEDIA,
		);
	}
	/**
	 * The mobile crop of a carousel's images (null = none). See modules/carousel/includes/carousel-mobile-crop.php.
	 *
	 * @param array|null $crop       { ratio, widths, sizes, media } or null.
	 * @param array      $attributes Block attributes.
	 */
	$crop = apply_filters( 'estrategia_web_carousel_mobile_crop', $crop, $attributes );
	if ( ! is_array( $crop ) ) {
		return null;
	}
	$ratio = is_numeric( $crop['ratio'] ?? null ) ? (float) $crop['ratio'] : mobile_ratio_parse( $crop['ratio'] ?? '' );
	if ( ! $ratio || $ratio < 0.2 || $ratio > 5 ) {
		return null;
	}
	$widths = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $crop['widths'] ?? MOBILE_WIDTHS ) ), static fn( $w ) => $w >= 50 ) ) );
	sort( $widths );
	return array(
		'ratio'  => $ratio,
		'widths' => $widths ? $widths : MOBILE_WIDTHS,
		'sizes'  => trim( (string) ( $crop['sizes'] ?? '100vw' ) ),
		'media'  => trim( (string) ( $crop['media'] ?? MOBILE_MEDIA ) ),
	);
}

/**
 * Pure: the crop box [x, y, w, h] of ratio $ratio inside a W×H image,
 * centred on the focal point (fractions 0-1) and kept inside the image.
 *
 * @param int   $width  Image width.
 * @param int   $height Image height.
 * @param float $ratio  Wanted width / height.
 * @param float $fx     Focal x (0-1).
 * @param float $fy     Focal y (0-1).
 * @return int[]
 */
function mobile_crop_box( int $width, int $height, float $ratio, float $fx, float $fy ): array {
	if ( $width / $height > $ratio ) {
		$ch = $height;
		$cw = (int) min( $width, round( $height * $ratio ) );
	} else {
		$cw = $width;
		$ch = (int) min( $height, round( $width / $ratio ) );
	}
	$cx = (int) round( min( max( $fx * $width - $cw / 2, 0 ), $width - $cw ) );
	$cy = (int) round( min( max( $fy * $height - $ch / 2, 0 ), $height - $ch ) );
	return array( $cx, $cy, $cw, $ch );
}

/**
 * Pure: the widths that fit in a crop $crop_width px wide (no upscaling).
 * If none fits, one at the crop's own width.
 *
 * @param int   $crop_width Crop width in the source.
 * @param int[] $widths     Wanted widths, ascending.
 * @return int[]
 */
function mobile_crop_widths( int $crop_width, array $widths ): array {
	$fit = array_values( array_filter( $widths, static fn( $w ) => $w <= $crop_width ) );
	return $fit ? $fit : array( $crop_width );
}

/**
 * Pure: focal point from an inline style («object-position: 30% 60%»), or the centre.
 *
 * @param string $style The img style attribute.
 * @return float[] [x, y] in 0-1.
 */
function mobile_focal_from_style( string $style ): array {
	if ( preg_match( '/object-position\s*:\s*(-?\d+(?:\.\d+)?)%\s+(-?\d+(?:\.\d+)?)%/i', $style, $m ) ) {
		return array( max( 0, min( 1, (float) $m[1] / 100 ) ), max( 0, min( 1, (float) $m[2] / 100 ) ) );
	}
	return array( 0.5, 0.5 );
}

/**
 * The cropped files of an attachment for a ratio and focal point, created
 * the first time and stored as sizes in its metadata. Empty if the image
 * cannot be cropped (no file, no editor, already that shape).
 *
 * @param int   $id     Attachment id.
 * @param float $ratio  Width / height.
 * @param float $fx     Focal x (0-1).
 * @param float $fy     Focal y (0-1).
 * @param int[] $widths Wanted widths.
 * @return array<int, array{url: string, width: int, height: int}>
 */
function mobile_crops( int $id, float $ratio, float $fx, float $fy, array $widths ): array {
	$meta = wp_get_attachment_metadata( $id );
	$file = get_attached_file( $id );
	if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) || ! $file || ! file_exists( $file ) ) {
		return array();
	}
	$width  = (int) $meta['width'];
	$height = (int) $meta['height'];
	if ( abs( $width / $height - $ratio ) / $ratio < 0.01 ) {
		return array(); // Ya tiene la forma de la caja.
	}
	list( $cx, $cy, $cw, $ch ) = mobile_crop_box( $width, $height, $ratio, $fx, $fy );
	$fxi    = (int) round( $fx * 100 );
	$fyi    = (int) round( $fy * 100 );
	$prefix = sprintf( 'estrategia-web-crop-r%d-f%dx%d-', (int) round( $ratio * 1000 ), $fxi, $fyi );
	$dir    = dirname( $file );
	$url    = dirname( (string) wp_get_attachment_url( $id ) );
	$out    = array();
	$dirty  = false;
	foreach ( mobile_crop_widths( $cw, $widths ) as $w ) {
		$h    = (int) round( $w / $ratio );
		$name = $prefix . $w;
		$size = $meta['sizes'][ $name ] ?? null;
		if ( ! is_array( $size ) || empty( $size['file'] ) || ! file_exists( $dir . '/' . $size['file'] ) ) {
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) || is_wp_error( $editor->crop( $cx, $cy, $cw, $ch, $w, $h ) ) ) {
				continue;
			}
			$saved = $editor->save( $editor->generate_filename( sprintf( 'm%dx%d-%dx%d', $fxi, $fyi, $w, $h ) ) );
			if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
				continue;
			}
			$size                    = array(
				'file'      => $saved['file'],
				'width'     => (int) $saved['width'],
				'height'    => (int) $saved['height'],
				'mime-type' => $saved['mime-type'],
				'filesize'  => (int) ( $saved['filesize'] ?? filesize( $saved['path'] ) ),
			);
			$meta['sizes'][ $name ] = $size;
			$dirty                  = true;
		}
		$out[] = array(
			'url'    => $url . '/' . $size['file'],
			'width'  => (int) $size['width'],
			'height' => (int) $size['height'],
		);
	}
	if ( $dirty ) {
		wp_update_attachment_metadata( $id, $meta );
	}
	return $out;
}

/**
 * Wraps a carousel <img> in a <picture> with the mobile crop. Returns the
 * img untouched if there is nothing to crop.
 *
 * @param string $img           The final img tag.
 * @param array  $crop          mobile_crop_config().
 * @param int    $attachment_id Attachment id (0 = unknown).
 * @param string $style         The img style attribute (for the focal point).
 */
function mobile_picture( string $img, array $crop, int $attachment_id, string $style ): string {
	if ( $attachment_id <= 0 ) {
		return $img;
	}
	/**
	 * The focal point of a carousel image for its mobile crop, [x, y] in 0-1.
	 *
	 * @param float[] $focal         From the img's object-position, or the centre.
	 * @param int     $attachment_id Attachment id.
	 * @param string  $img           The img tag.
	 */
	$focal = (array) apply_filters( 'estrategia_web_carousel_mobile_focal', mobile_focal_from_style( $style ), $attachment_id, $img );
	$fx    = max( 0, min( 1, (float) ( $focal[0] ?? 0.5 ) ) );
	$fy    = max( 0, min( 1, (float) ( $focal[1] ?? 0.5 ) ) );
	$files = mobile_crops( $attachment_id, $crop['ratio'], $fx, $fy, $crop['widths'] );
	if ( ! $files ) {
		return $img;
	}
	$srcset = implode( ', ', array_map( static fn( $f ) => esc_url( $f['url'] ) . ' ' . $f['width'] . 'w', $files ) );
	return sprintf(
		'<picture class="estrategia-web-carousel__picture"><source media="%1$s" srcset="%2$s" sizes="%3$s" width="%4$d" height="%5$d">%6$s</picture>',
		esc_attr( $crop['media'] ),
		esc_attr( $srcset ),
		esc_attr( $crop['sizes'] ),
		$files[0]['width'],
		$files[0]['height'],
		$img
	);
}
