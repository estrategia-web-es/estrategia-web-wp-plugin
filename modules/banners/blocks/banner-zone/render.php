<?php
/**
 * estrategia-web/banner-zone — the banners of one zone.
 *
 * Attributes: zone (slug of an eweb_banner_zone term) and count (how many at
 * a time; 0 = all, one under another, which is what a sidebar wants).
 *
 * What it prints: EVERY published banner of the zone that has not expired,
 * each with its dates and weight in data-*; view.js then hides the ones out
 * of date and, if count is below the total, picks count of them by weight.
 * It is done in the browser because the page is cached: a choice made here
 * would be frozen until the cache is purged, and a banner that ends on
 * Sunday would keep showing on Monday. The server still hides the extra
 * ones (hidden), so without JavaScript the zone shows count banners and not
 * all of them.
 *
 * Nothing in the zone → nothing printed (not an empty box): the theme decides
 * what goes there when it has no advertising.
 *
 * Image banners: the image (its full size, never cropped: the theme's CSS
 * fits it) with the link in a new tab and rel="sponsored noopener". Code
 * banners: the code through wp_kses() with Kses::banner(), the tags ad
 * networks paste (scripts, <ins>, iframes, a linked image).
 *
 * Escaping: everything printed here is escaped or filtered, the code of a
 * code banner too. Until 2.10.1 that code went out raw, controlled only when
 * saved (unfiltered_html); the WordPress.org review of 08-11-2026 did not
 * accept it, and since 2.10.2 it is filtered here AS WELL. Saving it still
 * needs unfiltered_html (Post_Type: the meta box, its save and the meta's
 * auth_callback). The trap of filtering it (inline JavaScript with «&&» or
 * «<» breaks) is in the header of includes/class-kses.php; the meta box lists
 * what the filter would lose, so it does not fail in silence.
 *
 * How to maintain:
 * - The trap: a code banner that is hidden by view.js still RUNS its scripts
 *   (an adserver asks for an ad it will not show). Code banners belong in
 *   zones with count 0, or alone in their zone.
 * - Edge case: a scheduled banner (starts tomorrow) IS printed, hidden, so
 *   that the cached page shows it when its time comes.
 *
 * @var array $attributes
 */

use EstrategiaWeb\Banners\Post_Type;
use EstrategiaWeb\Banners\Rules;

defined( 'ABSPATH' ) || exit;

$estrategia_web_zone = sanitize_title( (string) ( $attributes['zone'] ?? '' ) );
if ( '' === $estrategia_web_zone ) {
	return;
}
$estrategia_web_posts = get_posts(
	array(
		'post_type'      => Post_Type::TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'tax_query'      => array( array( 'taxonomy' => Post_Type::ZONE, 'field' => 'slug', 'terms' => $estrategia_web_zone ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	)
);
$estrategia_web_banners = array();
foreach ( $estrategia_web_posts as $estrategia_web_post ) {
	$estrategia_web_d = Post_Type::data( $estrategia_web_post );
	if ( Rules::EXPIRED !== $estrategia_web_d['state'] && ( 'image' === $estrategia_web_d['kind'] || '' !== trim( $estrategia_web_d['code'] ) ) ) {
		$estrategia_web_banners[ $estrategia_web_d['id'] ] = $estrategia_web_d;
	}
}
if ( ! $estrategia_web_banners ) {
	return;
}

$estrategia_web_count = max( 0, (int) ( $attributes['count'] ?? 0 ) );
$estrategia_web_now   = array_filter( $estrategia_web_banners, static fn( array $d ): bool => Rules::ACTIVE === $d['state'] );
$estrategia_web_shown = array_flip( Rules::pick( array_map( static fn( array $d ): int => $d['weight'], $estrategia_web_now ), $estrategia_web_count, static fn( int $max ): int => random_int( 0, $max - 1 ) ) );

$estrategia_web_items = '';
foreach ( $estrategia_web_banners as $estrategia_web_id => $estrategia_web_d ) {
	if ( 'image' === $estrategia_web_d['kind'] ) {
		$estrategia_web_img  = wp_get_attachment_image( $estrategia_web_d['image'], 'full', false, array( 'loading' => 'lazy', 'alt' => get_the_title( $estrategia_web_id ) ) );
		$estrategia_web_body = '' !== $estrategia_web_d['link']
			? sprintf( '<a href="%s" target="_blank" rel="sponsored noopener">%s</a>', esc_url( $estrategia_web_d['link'] ), wp_kses( $estrategia_web_img, \EstrategiaWeb\Kses::allowed() ) )
			: wp_kses( $estrategia_web_img, \EstrategiaWeb\Kses::allowed() );
	} else {
		$estrategia_web_body = wp_kses( $estrategia_web_d['code'], \EstrategiaWeb\Kses::banner() );
	}
	$estrategia_web_items .= sprintf(
		'<div class="estrategia-web-banner estrategia-web-banner--%s" data-id="%d" data-weight="%d" data-start="%d" data-end="%d"%s>%s</div>',
		esc_attr( $estrategia_web_d['kind'] ),
		$estrategia_web_id,
		$estrategia_web_d['weight'],
		$estrategia_web_d['start'],
		$estrategia_web_d['end'],
		isset( $estrategia_web_shown[ $estrategia_web_id ] ) ? '' : ' hidden',
		$estrategia_web_body
	);
}

printf(
	'<div %s data-count="%d" data-endpoint="%s">%s</div>',
	wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'estrategia-web-banner-zone estrategia-web-banner-zone--' . $estrategia_web_zone ) ) ),
	(int) $estrategia_web_count,
	esc_url( rest_url( 'estrategia-web/v1/banners/hits' ) ),
	wp_kses( $estrategia_web_items, \EstrategiaWeb\Kses::banner() )
);
