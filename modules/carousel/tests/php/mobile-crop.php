<?php
/**
 * Called by modules/carousel/tests/mobile-crop-pure.test.mjs with the
 * machine's PHP (no WordPress): the geometry of the mobile crop. A new case
 * is a new key here and its assert there.
 */

namespace EstrategiaWeb\Carousel;

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/carousel-mobile-crop.php';

$r = 370 / 460;
echo json_encode(
	array(
		'ratio_slash'   => round( (float) mobile_ratio_parse( '370/460' ), 4 ),
		'ratio_colon'   => mobile_ratio_parse( '4:5' ),
		'ratio_number'  => mobile_ratio_parse( '0.8' ),
		'ratio_bad'     => array( mobile_ratio_parse( '' ), mobile_ratio_parse( 'vertical' ), mobile_ratio_parse( '1/0' ), mobile_ratio_parse( '100' ) ),
		// Lavandería landscape photo (2560×1707), 370/460 box, centre.
		'box_center'    => mobile_crop_box( 2560, 1707, $r, 0.5, 0.5 ),
		// Focal point at the far left: the crop sticks to the edge, it does not overflow.
		'box_left'      => mobile_crop_box( 2560, 1707, $r, 0.0, 0.5 ),
		'box_right'     => mobile_crop_box( 2560, 1707, $r, 0.9, 0.5 ),
		// Portrait photo taller than the box: cropped in height.
		'box_tall'      => mobile_crop_box( 1000, 2000, $r, 0.5, 0.3 ),
		'widths_big'    => mobile_crop_widths( 1373, array( 600, 900, 1200 ) ),
		'widths_mid'    => mobile_crop_widths( 1000, array( 600, 900, 1200 ) ),
		'widths_small'  => mobile_crop_widths( 400, array( 600, 900, 1200 ) ),
		'focal_cover'   => mobile_focal_from_style( 'object-position:22% 50%' ),
		'focal_spaces'  => mobile_focal_from_style( 'width:100%; object-position: 89.5% 30%;' ),
		'focal_none'    => mobile_focal_from_style( 'object-fit:cover' ),
		'focal_keyword' => mobile_focal_from_style( 'object-position: left top' ),
	)
);

/** The bare minimum of WordPress the file needs when it loads. */
function apply_filters( string $hook, $value ) {
	return $value;
}
