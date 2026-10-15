<?php
/**
 * estrategia-web/map — "two-click" map.
 *
 * What it is: a static image (from the media library) with the address, a
 * "Show map" button that ONLY THEN inserts the Google Maps iframe, and an
 * "Open in Google Maps" link. Before the click nothing is requested from
 * Google: the iframe is in the HTML without src (about:blank) and view.js
 * sets the URL.
 *
 * Decisions:
 * - The visitor's click is the consent for THAT load, so the map does not
 *   need a cookie notice. Below the button it says what happens on click.
 * - If the site declares the "maps" category in the consent (the cookie
 *   notice of the estrategia-web-site plugin since parent 0.7.0; before,
 *   inc/consent.php) and the visitor accepted it, the map loads straight
 *   away, also if they accept it later with the page open (the
 *   estrategia-web:consent event).
 *   WITHOUT THE PLUGIN the map works the same: static image or box, and
 *   "Show map" loads that map on click. It only talks to the notice through
 *   its JS contract (window.estrategiaWebConsent?.…, the event), never through PHP, so as
 *   not to depend on it being there (tests/map.test.mjs runs without it).
 * - The embed URL is validated: https, host www.google.com or maps.google.com
 *   and path /maps/embed. Pasting the whole <iframe> Google gives (Share ›
 *   Embed a map) is accepted: the src is extracted.
 * - With no image chosen, a neutral box with the address is shown.
 * - Editor: edit.js (classic script) because of the media library image
 *   picker, which autoRegister does not offer; the preview is ServerSideRender.
 * - NO IMAGE by default (0.3.0): before the click a box shows the site's
 *   DATA as text (name, address, phone with tel:, opening hours), in the
 *   theme colours, and below it "Show map" and "Open in Google Maps".
 *   Why: a Google Maps screenshot as a photo looked bad (David, 02-10-2026)
 *   and pretended to be a map that cannot be moved. An image is still
 *   possible (chosen, or the featured one with useFeaturedImage) but it is
 *   the exception. With "maps" accepted in the consent the iframe is rendered
 *   directly, as before.
 *   Edge case: the box says what the block knows; a location's data arrives
 *   through bindings (e.g. the estrategia-web-site/location source).
 *   The trap: binding sources return the text already escaped and with <br>;
 *   render.php turns it back into plain text before escaping it (otherwise
 *   "&amp;amp;" or "<br>" shows up on screen).
 * - STATIC MAP (0.5.0, staticMapUrl or staticMapId attributes): an
 *   OpenStreetMap image generated ONCE with tools/static-map.mjs (brand
 *   coloured marker and "© OpenStreetMap" inside) goes UNDER the data box,
 *   which stays: when wide, the box floats on the left over the map; when
 *   narrow (< 480 px of block), it goes below. It is not a Google screenshot
 *   (its licence does not allow it, and requesting it on each visit sends the
 *   IP to Google without consent). It is served from the site ITSELF:
 *   staticMapUrl is a theme path ("assets/maps/sede.webp", looked up in the
 *   child and then the parent), a site path ("/wp-content/…") or a URL on the
 *   same domain; anything else is discarded (estrategia_web_map_static_url).
 *   staticMapId is the same image uploaded to the media library. It accepts
 *   bindings (each location in a Query, its own map). If there is a static
 *   map, the "photo" (imageId/imageUrl/useFeaturedImage) is not used.
 *   Edge case: the static map does not move or zoom; whoever wants to move
 *   it clicks "Show map", which loads Google and GRANTS the "maps" consent
 *   category (window.estrategiaWebConsent.grant), so the other maps on
 *   the site load without asking. If the site does not declare "maps", the
 *   click only loads that map, as before.
 *   The trap: the marker is in the CENTRE of the image and the box covers
 *   the left; that is why the box never exceeds 42 % of the width. A child
 *   theme that widens it covers the marker.
 * - NO DETAILS (0.6.0, showDetails attribute, default YES): with
 *   showDetails:false the block does not render its data box (nor the
 *   address under a photo). For when the location card already shows them
 *   alongside: on Lavandería the address appeared twice, in the card and
 *   inside the map. What remains: with a static map or photo, ONLY the image,
 *   and below it "Show map" and "Open in Google Maps"; without an image, only
 *   the buttons (the frame is not rendered until there is a map: an empty
 *   box would look broken).
 *   The address is still passed: it is needed for the Google Maps link, the
 *   iframe title and the static map's alt text.
 *       <!-- wp:estrategia-web/map {"showDetails":false,"metadata":{"bindings":{
 *         "address":…,"embedUrl":…,"staticMapUrl":…}}} /-->
 *   Why an attribute and not a guess: the block cannot see what is next to
 *   it (a sibling in the same group may or may not be the card). The child
 *   theme's pattern knows, and it is the one that sets it.
 *   Edge case: a standalone map (contact page without a card) keeps the
 *   default: without details it would not say where it is.
 * - Inside a Query (0.2.0): its text attributes accept Block Bindings
 *   (modules/map/module.php), so the address and the embed URL can come
 *   from each entry's fields (core/post-meta); and with no image chosen it
 *   uses the entry's featured image (usesContext postId). That way a list of
 *   locations that lives in the database gets one map each.
 *
 * How to maintain:
 * - Who: whoever touches the map module (until 1.0.0, the parent theme). When: if Google changes the embed URL
 *   format (adjust estrategia_web_map_embed_url) or when adding a provider.
 * - Edge case: another provider (OpenStreetMap) is an ADJUSTMENT if it is
 *   another iframe URL with the same mechanics: widen the validation here. A
 *   map with its own markers and provider JS is SOMETHING ELSE: a child block.
 * - The trap that cost an afternoon (01-10-2026): the iframe does NOT have
 *   loading="lazy". With lazy, if the src is set on hydration (consent
 *   already accepted) while the iframe is still hidden, Chrome never loads
 *   it: src set, frame at about:blank, blank box. Without src nothing loads
 *   anyway, so lazy saved nothing.
 * - Another: the "Open in Google Maps" link is a plain <a> and makes no
 *   request until clicked; the tests check REQUESTS, not occurrences of
 *   "google.com" in the HTML, which are there by design.
 *
 * @package estrategia-web
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'estrategia_web_map_embed_url' ) ) {
	/**
	 * Returns a safe Google Maps embed URL, or '' if the value is not one.
	 * Accepts the bare URL or the whole <iframe> code Google gives.
	 *
	 * @param string $value Attribute value.
	 */
	function estrategia_web_map_embed_url( string $value ): string {
		$value = trim( html_entity_decode( $value ) );
		if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $value, $match ) ) {
			$value = $match[1];
		}
		$parts = wp_parse_url( $value );
		if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) ) {
			return '';
		}
		if ( ! in_array( $parts['host'], array( 'www.google.com', 'maps.google.com' ), true ) ) {
			return '';
		}
		if ( empty( $parts['path'] ) || ! str_starts_with( $parts['path'], '/maps/embed' ) ) {
			return '';
		}
		return esc_url_raw( $value );
	}
}

if ( ! function_exists( 'estrategia_web_map_static_url' ) ) {
	/**
	 * URL of the static map image (0.5.0), only if it is served by THIS site.
	 * A relative path («assets/maps/sede.webp») is a file of the active theme
	 * (the child, then the parent). An absolute URL must be on the site's own
	 * host: a static map from a third party would be a request without consent.
	 *
	 * @param string $value Attribute value.
	 */
	function estrategia_web_map_static_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( empty( $parts['host'] ) ) {
			if ( str_starts_with( $value, '/' ) ) {
				return esc_url_raw( home_url( $value ) );
			}
			$path = preg_replace( '#^\./#', '', $value );
			if ( str_contains( $path, '..' ) ) {
				return '';
			}
			foreach ( array( get_stylesheet_directory() => get_stylesheet_directory_uri(), get_template_directory() => get_template_directory_uri() ) as $dir => $uri ) {
				if ( file_exists( $dir . '/' . $path ) ) {
					return esc_url_raw( $uri . '/' . $path );
				}
			}
			return '';
		}
		$site = wp_parse_url( home_url() );
		return isset( $site['host'] ) && strtolower( $parts['host'] ) === strtolower( $site['host'] ) ? esc_url_raw( $value ) : '';
	}
}

// The data comes from attributes or from Block Bindings: a text source brings
// it with <br> instead of line breaks (the estrategia-web-site ones do); they
// are turned back into line breaks so they are escaped here only once.
$estrategia_web_text      = static fn( string $key ): string => trim( html_entity_decode( wp_strip_all_tags( preg_replace( '/<br\s*\/?>/i', "\n", (string) $attributes[ $key ] ) ), ENT_QUOTES, 'UTF-8' ) );
$estrategia_web_place     = $estrategia_web_text( 'placeName' );
$estrategia_web_phone     = $estrategia_web_text( 'phone' );
$estrategia_web_hours     = $estrategia_web_text( 'hours' );
$estrategia_web_address   = $estrategia_web_text( 'address' );
$estrategia_web_embed     = estrategia_web_map_embed_url( (string) $attributes['embedUrl'] );
$estrategia_web_maps_url  = trim( (string) $attributes['mapsUrl'] );
if ( '' === $estrategia_web_maps_url && '' !== $estrategia_web_address ) {
	$estrategia_web_maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $estrategia_web_address );
}

if ( '' === $estrategia_web_embed && '' === $estrategia_web_maps_url ) {
	if ( wp_is_serving_rest_request() ) {
		printf(
			'<p %s>%s</p>',
			get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html__( 'Map: add the address and the Google Maps embed URL in the block settings.', 'estrategia-web' )
		);
	}
	return;
}

$estrategia_web_static = '';
if ( $attributes['staticMapId'] ) {
	$estrategia_web_static = (string) wp_get_attachment_image_url( (int) $attributes['staticMapId'], 'full' );
}
if ( '' === $estrategia_web_static ) {
	$estrategia_web_static = estrategia_web_map_static_url( $estrategia_web_text( 'staticMapUrl' ) );
}

$estrategia_web_image = '';
if ( '' !== $estrategia_web_static ) {
	$estrategia_web_image = sprintf(
		'<img class="estrategia-web-map__static" src="%s" alt="%s" loading="lazy" decoding="async" data-wp-bind--hidden="context.loaded">',
		esc_url( $estrategia_web_static ),
		esc_attr(
			'' !== $estrategia_web_address
				/* translators: %s: postal address. */
				? sprintf( __( 'Street map around %s', 'estrategia-web' ), str_replace( "\n", ', ', $estrategia_web_address ) )
				: __( 'Street map of the area', 'estrategia-web' )
		)
	);
} elseif ( $attributes['imageId'] ) {
	$estrategia_web_image = wp_get_attachment_image(
		(int) $attributes['imageId'],
		'large',
		false,
		array(
			'class'               => 'estrategia-web-map__image',
			'alt'                 => $attributes['imageAlt'],
			'loading'             => 'lazy',
			'data-wp-bind--hidden' => 'context.loaded',
		)
	);
}
// Inside a Query, ONLY if asked for (useFeaturedImage, 0.3.0; in 0.2.0 it
// was always): the entry's featured image.
if ( '' === $estrategia_web_image && '' === $attributes['imageUrl'] && $attributes['useFeaturedImage'] && ! empty( $block->context['postId'] ) && has_post_thumbnail( (int) $block->context['postId'] ) ) {
	$estrategia_web_image = wp_get_attachment_image(
		get_post_thumbnail_id( (int) $block->context['postId'] ),
		'large',
		false,
		array(
			'class'                => 'estrategia-web-map__image',
			'alt'                  => $attributes['imageAlt'],
			'loading'              => 'lazy',
			'data-wp-bind--hidden' => 'context.loaded',
		)
	);
}
if ( '' === $estrategia_web_image && '' !== $attributes['imageUrl'] ) {
	$estrategia_web_image = sprintf(
		'<img class="estrategia-web-map__image" src="%s" alt="%s" loading="lazy" data-wp-bind--hidden="context.loaded">',
		esc_url( $attributes['imageUrl'] ),
		esc_attr( $attributes['imageAlt'] )
	);
}

// Photo = an image that REPLACES the box (the 0.2.0 one). The static map is
// not a photo: it goes UNDER the data box (0.5.0).
$estrategia_web_has_photo = '' !== $estrategia_web_image && '' === $estrategia_web_static;

// No details (0.6.0, showDetails:false): neither box nor address under the
// photo, because the card next to it already shows them. Without an image,
// the frame is not rendered until there is a map: an empty box before the
// click would look broken.
$estrategia_web_details     = ! isset( $attributes['showDetails'] ) || false !== $attributes['showDetails'];
$estrategia_web_frame_later = ! $estrategia_web_details && '' === $estrategia_web_image;
$estrategia_web_no_frame    = false;
if ( $estrategia_web_frame_later && '' === $estrategia_web_embed ) {
	// Nothing to show before or after the click: just the link.
	$estrategia_web_frame_later = false;
	$estrategia_web_no_frame    = true;
}

$estrategia_web_title = '' !== $estrategia_web_address
	/* translators: %s: postal address. */
	? sprintf( __( 'Map: %s', 'estrategia-web' ), $estrategia_web_address )
	: __( 'Map', 'estrategia-web' );
?>
<figure
	<?php echo get_block_wrapper_attributes( array( 'class' => 'estrategia-web-map' . ( '' !== $estrategia_web_static ? ' has-static-map' : '' ) . ( $estrategia_web_details ? '' : ' is-without-details' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	data-wp-interactive="estrategia-web/map"
	<?php echo wp_interactivity_data_wp_context( array( 'loaded' => false, 'embedUrl' => $estrategia_web_embed ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	data-wp-init="callbacks.init"
	data-wp-class--is-loaded="context.loaded"
>
	<?php if ( ! $estrategia_web_no_frame ) : ?>
	<div class="estrategia-web-map__frame"<?php echo $estrategia_web_frame_later ? ' hidden data-wp-bind--hidden="!context.loaded"' : ''; ?>>
		<?php if ( '' !== $estrategia_web_image ) : ?>
			<?php echo $estrategia_web_image; // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping above. ?>
		<?php endif; ?>
		<?php if ( ! $estrategia_web_has_photo && $estrategia_web_details ) : ?>
			<div class="estrategia-web-map__card" data-wp-bind--hidden="context.loaded">
				<?php if ( '' !== $estrategia_web_place ) : ?>
				<p class="estrategia-web-map__name"><?php echo esc_html( $estrategia_web_place ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $estrategia_web_address ) : ?>
				<address class="estrategia-web-map__card-address"><?php echo nl2br( esc_html( $estrategia_web_address ), false ); ?></address>
				<?php endif; ?>
				<?php if ( '' !== $estrategia_web_phone ) : ?>
				<p class="estrategia-web-map__phone"><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $estrategia_web_phone ), array( 'tel' ) ); ?>"><?php echo esc_html( $estrategia_web_phone ); ?></a></p>
				<?php endif; ?>
				<?php if ( '' !== $estrategia_web_hours ) : ?>
				<p class="estrategia-web-map__hours"><?php echo nl2br( esc_html( $estrategia_web_hours ), false ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( '' !== $estrategia_web_embed ) : ?>
		<iframe
			class="estrategia-web-map__iframe"
			title="<?php echo esc_attr( $estrategia_web_title ); ?>"
			hidden
			data-wp-bind--hidden="!context.loaded"
			data-wp-bind--src="state.src"
			referrerpolicy="strict-origin-when-cross-origin"
			allowfullscreen
		></iframe>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<figcaption class="estrategia-web-map__caption">
		<?php if ( '' !== $estrategia_web_address && $estrategia_web_has_photo && $estrategia_web_details ) : ?>
		<address class="estrategia-web-map__address"><?php echo esc_html( $estrategia_web_address ); ?></address>
		<?php endif; ?>
		<?php if ( '' !== $estrategia_web_embed ) : ?>
		<div class="estrategia-web-map__actions" data-wp-bind--hidden="context.loaded">
			<button type="button" class="wp-element-button estrategia-web-map__load" data-wp-on--click="actions.load"><?php esc_html_e( 'Show map', 'estrategia-web' ); ?></button>
			<p class="estrategia-web-map__notice"><?php esc_html_e( 'The map is served by Google. Loading it sends your IP address to Google, which may set its own cookies.', 'estrategia-web' ); ?></p>
		</div>
		<?php endif; ?>
		<?php if ( '' !== $estrategia_web_maps_url ) : ?>
		<?php
		// A distinct accessible name per location (1.1.x): with two maps on the
		// page, two identical links to different targets fail in Lighthouse
		// ("Links have distinct names"). The visible text goes FIRST inside the
		// name (WCAG 2.5.3, label in name); the location (or the address, if it
		// has no name) is only heard by the screen reader.
		// It goes in aria-label and NOT in a hidden span: the span counts in
		// innerText and in copied text, and the address would appear twice where
		// the card already shows it alongside (showDetails:false; the theme's
		// map-details test). Without location or address there is no aria-label:
		// the name is the usual text.
		$estrategia_web_link_for = preg_replace( '/\s+/', ' ', '' !== $estrategia_web_place ? $estrategia_web_place : $estrategia_web_address );
		$estrategia_web_link_aria = '' === $estrategia_web_link_for ? '' : sprintf(
			/* translators: 1: place name (or its address), 2: «(opens in a new tab)». The text before the colon must be the visible text of the link. */
			__( 'Open in Google Maps: %1$s %2$s', 'estrategia-web' ),
			$estrategia_web_link_for,
			__( '(opens in a new tab)', 'estrategia-web' )
		);
		?>
		<a class="estrategia-web-map__link" href="<?php echo esc_url( $estrategia_web_maps_url ); ?>" target="_blank" rel="noopener noreferrer"<?php echo '' !== $estrategia_web_link_aria ? ' aria-label="' . esc_attr( $estrategia_web_link_aria ) . '"' : ''; ?>>
			<?php esc_html_e( 'Open in Google Maps', 'estrategia-web' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'estrategia-web' ); ?></span>
		</a>
		<?php endif; ?>
	</figcaption>
</figure>
