<?php
namespace EstrategiaWeb\Carousel;

defined( 'ABSPATH' ) || exit;

/**
 * estrategia-web/carousel — mobile crop of the images (1.1.x).
 *
 * What it solves (Lavandería mobile PageSpeed, 14-10-2026): on mobile the
 * hero slide box is VERTICAL (370/460, child theme CSS) and the image is
 * horizontal; with object-fit: cover a central strip is shown, but the
 * browser downloads the whole image at 1536 px (163 KB of possible savings,
 * LCP 3.7 s). Here, below a 782 px window, a vertical CROP of the same image
 * is served, at the size of the box:
 *
 *     <picture class="estrategia-web-carousel__picture">
 *       <source media="(max-width: 781px)" srcset="…-600x746.webp 600w, …" sizes="…">
 *       <img …the usual one, with its horizontal srcset…>
 *     </picture>
 *
 * ONLY IF ASKED FOR: the plugin does not know what shape the theme gives the
 * box on mobile. It is requested with the block attribute mobileRatio
 * ("370/460", "4/5", "0.8") or, for a theme style that is not in the content
 * (Lavandería's case: the shape comes from the is-style-hero-carousel CSS),
 * with the filter estrategia_web_carousel_mobile_crop( $crop, $attributes ),
 * which receives null or the array below and returns the same.
 *     $crop = array(
 *         'ratio'  => 370 / 460,              // box width / height
 *         'widths' => array( 480, 720, 960, 1200 ), // srcset widths
 *         'sizes'  => '…',                          // <source> sizes
 *         'media'  => '(max-width: 781px)',
 *     );
 * mobileImageSizes (attribute) replaces the computed sizes (perViewMobile
 * below 480, perViewTablet up to 781). Widths 480 / 720 / 960 / 1200, from
 * the real box: on mobile it measures 340-400 CSS px. 720 is Lighthouse's
 * Moto G (379 px at 1.75x = 663); with 600 / 900 it picked the 900 one and
 * Lighthouse still flagged 45 KB too many on the LCP (measured on local
 * Lavandería, 14-10-2026). 960 covers 2x and 1200 3x; never more than the
 * largest crop the image allows (no upscaling).
 *
 * FOCAL POINT: the crop is centred where the slide already says:
 * object-position in the <img>'s own style (core/cover sets it from its
 * focalPoint, or anyone who writes it), in %; if there is none, the centre
 * (50 % 50 %), which is what object-fit: cover does without object-position.
 * The filter estrategia_web_carousel_mobile_focal( [x, y] (0-1), $attachment_id, $img )
 * changes it. CAREFUL: an object-position set by theme CSS (by nth-child,
 * like Lavandería's desktop one) is NOT visible from here; its mobile one is
 * 50 % 50 %, the centre, so it matches.
 *
 * WHERE IT IS STORED: the crop is generated with wp_get_image_editor the
 * FIRST time the page is rendered and recorded as one more size of the
 * attachment in its metadata (sizes['estrategia-web-crop-r804-f50x50-900'] …),
 * so:
 * - it is idempotent: the second time only the meta and the file's existence
 *   are checked; if the file is missing (someone deleted it,
 *   `wp media regenerate`), it is rebuilt;
 * - it comes out in the same format as the other sizes: WebP if the webp
 *   module is on or the image already was WebP (decided by
 *   image_editor_output_format, the same filter WordPress sizes use);
 * - when the attachment is deleted, WordPress deletes these files too (they
 *   are in sizes). Changing ratio, focal point or widths creates other names;
 *   the old ones stay until the attachment is regenerated or deleted.
 *
 * How to maintain:
 * - Who: whoever touches the carousel. When: if the 782 px breakpoint of
 *   style.css changes, change the default media (MEDIA).
 * - Edge case, by name: a slide whose image ALREADY has the box's shape
 *   (±1 %) is not cropped: there is nothing to save.
 * - Another: images from a Query (featured image) do not have the
 *   wp-image-ID class and WordPress does not pass their id to
 *   wp_content_img_tag: they are not cropped. Today only the hero uses this,
 *   and those are core/image.
 * - The trap: the first visit after publishing pays for the crop (a few
 *   hundred ms per image and width). After deploying, open the home page once.
 * - Test: modules/carousel/tests/carousel.test.mjs (level 3; the mobile crop part is in ./images.mjs) and
 *   modules/carousel/tests/mobile-crop-pure.test.mjs (level 1, the geometry).
 */

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
		return array(); // Already has the box's shape.
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
