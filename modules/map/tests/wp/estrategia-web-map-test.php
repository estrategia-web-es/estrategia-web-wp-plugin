<?php
/**
 * TESTS ONLY. Loaded by the mu-plugin that tests/blueprint.json writes into the
 * Playground WordPress, and it does nothing unless ESTRATEGIA_WEB_BLOCKS_TESTING
 * is defined (only that loader defines it).
 *
 * The seed of the map's browser tests (modules/map/tests/*.test.mjs), which
 * came from the parent theme's tests/fixtures/setup/setup.php on 17-10-2026
 * with the same pages, posts and literals:
 *
 * - /prueba-mapa/ — a map without an image, with all its details (map,
 *   map-card, map-in-flex; in the theme it was the map of /prueba-modulos/).
 * - /prueba-mapa-consulta/ — a core/query of two posts, the address bound to
 *   the prueba_direccion field and the first with a featured image (map-query).
 * - /prueba-mapa-estatico/ — a static map served by the site and another one
 *   from a third-party domain, which must be discarded (map-static).
 * - /prueba-mapa-sin-datos/ — showDetails:false with and without an image
 *   (map-details).
 *
 * POST /wp-json/estrategia-web-map-test/v1/seed. Idempotent: pages, posts and
 * images are rewritten or reused by slug, never duplicated.
 *
 * What the THEME did and is done here instead: registering the post field
 * prueba_direccion (a site would do it in its plugin or child). And the static
 * map image is no longer a file of the repo (the theme had an OSM tile made
 * with tools/static-map.mjs): it is drawn with GD and uploaded to the media
 * library, because what is tested is that the image is the site's own, not
 * what it shows.
 *
 * The content is in Spanish on purpose: it is test data for a Spanish site and
 * the tests look for those literals.
 *
 * How to maintain: a new test page of the map goes here, with its slug and
 * its test in the list above. A page of ANOTHER module goes in that module's
 * seed.
 *
 * @package estrategia-web
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_BLOCKS_TESTING' ) || true !== ESTRATEGIA_WEB_BLOCKS_TESTING ) {
	return;
}

// A post field to bind estrategia-web/map to core/post-meta inside a Query (map-query.test.mjs).
add_action(
	'init',
	static function () {
		register_post_meta(
			'post',
			'prueba_direccion',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'estrategia-web-map-test/v1',
			'/seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'estrategia_web_map_test_seed',
			)
		);
	}
);

/**
 * Creates or rewrites a published page by slug.
 *
 * @param string $slug    Slug.
 * @param string $title   Title.
 * @param string $content Block markup.
 */
function estrategia_web_map_test_page( string $slug, string $title, string $content ): int {
	$existing = get_page_by_path( $slug );
	return (int) wp_insert_post(
		array(
			'ID'           => $existing ? $existing->ID : 0,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => wp_slash( $content ),
		)
	);
}

/**
 * An image of the media library by slug, created the first time.
 *
 * @param string   $slug  Attachment slug (also the file name).
 * @param string   $title Title.
 * @param callable $draw  Returns [ bytes, mime type, extension ].
 */
function estrategia_web_map_test_image( string $slug, string $title, callable $draw ): int {
	$existing = get_posts( array( 'post_type' => 'attachment', 'name' => $slug, 'posts_per_page' => 1, 'post_status' => 'inherit' ) );
	if ( $existing ) {
		return (int) $existing[0]->ID;
	}
	[ $bytes, $mime, $extension ] = $draw();
	$upload                       = wp_upload_bits( $slug . '.' . $extension, null, $bytes );
	return (int) wp_insert_attachment(
		array(
			'post_title'     => $title,
			'post_name'      => $slug,
			'post_mime_type' => $mime,
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
}

/** The seed (see the header). */
function estrategia_web_map_test_seed(): array {
	kses_remove_filters();

	$embed = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3075.5!2d2.6502!3d39.5696';

	estrategia_web_map_test_page(
		'prueba-mapa',
		'Prueba de mapa',
		'<!-- wp:heading --><h2 class="wp-block-heading">Mapa</h2><!-- /wp:heading -->'
		. '<!-- wp:estrategia-web/map {"placeName":"Ayuntamiento de prueba","address":"Plaça de Cort 1, 07001 Palma","phone":"+34 971 22 59 00","hours":"De lunes a viernes\nDe 9:00 a 14:00","embedUrl":"https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3075.5!2d2.6502!3d39.5696!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMznCsDM0JzEwLjYiTiAywrAzOScwMC43IkU!5e0!3m2!1ses!2ses!4v1700000000000"} /-->'
	);

	// Map inside a Query (0.2.0): two posts with their address in a field and
	// the first one with a featured image (a 1×1 PNG).
	$featured = estrategia_web_map_test_image(
		'mapa-estatico-prueba',
		'Mapa estático de prueba',
		static fn() => array( base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC' ), 'image/png', 'png' ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	);
	for ( $i = 1; $i <= 2; $i++ ) {
		$slug    = 'entrada-mapa-' . $i;
		$post    = get_page_by_path( $slug, OBJECT, 'post' );
		$post_id = wp_insert_post(
			array(
				'ID'          => $post ? $post->ID : 0,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Entrada mapa ' . $i,
				'post_name'   => $slug,
				'meta_input'  => array( 'prueba_direccion' => 'Calle de Prueba ' . $i . ', Palma' ),
			)
		);
		if ( 1 === $i ) {
			set_post_thumbnail( $post_id, $featured );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}
	estrategia_web_map_test_page(
		'prueba-mapa-consulta',
		'Prueba de mapa en una consulta',
		'<!-- wp:query {"queryId":8,"query":{"perPage":2,"pages":0,"offset":0,"postType":"post","order":"asc","orderBy":"title","search":"mapa","inherit":false}} -->'
		. '<div class="wp-block-query"><!-- wp:post-template -->'
		. '<!-- wp:post-title /-->'
		. '<!-- wp:estrategia-web/map {"embedUrl":"https://www.google.com/maps/embed?pb=!1m18!1m12","useFeaturedImage":true,"metadata":{"bindings":{"address":{"source":"core/post-meta","args":{"key":"prueba_direccion"}}}}} /-->'
		. '<!-- /wp:post-template --></div>'
		. '<!-- /wp:query -->'
	);

	// Static map (0.5.0): an image served by the site (an 1200×600 «map»
	// drawn with GD: grey blocks and white streets) and a URL from ANOTHER
	// domain, which the block must discard (zero third-party requests).
	$static = estrategia_web_map_test_image(
		'mapa-estatico',
		'Mapa estático',
		static function () {
			$image = imagecreatetruecolor( 1200, 600 );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 226, 222, 214 ) );
			$street = imagecolorallocate( $image, 255, 255, 255 );
			for ( $x = 0; $x < 1200; $x += 150 ) {
				imagefilledrectangle( $image, $x, 0, $x + 12, 599, $street );
			}
			for ( $y = 0; $y < 600; $y += 120 ) {
				imagefilledrectangle( $image, 0, $y, 1199, $y + 12, $street );
			}
			ob_start();
			imagepng( $image );
			return array( (string) ob_get_clean(), 'image/png', 'png' );
		}
	);
	$static_url = wp_make_link_relative( (string) wp_get_attachment_url( $static ) );
	estrategia_web_map_test_page(
		'prueba-mapa-estatico',
		'Prueba de mapa estático',
		'<!-- wp:estrategia-web/map {"placeName":"Sede con mapa estático","address":"Plaça de Cort 1, 07001 Palma","phone":"+34 971 22 59 00","embedUrl":"' . $embed . '","staticMapUrl":"' . esc_url_raw( $static_url ) . '"} /-->'
		. '<!-- wp:estrategia-web/map {"placeName":"Sede con mapa de fuera","address":"Calle de Fuera 1, Palma","embedUrl":"' . $embed . '","staticMapUrl":"https://tile.openstreetmap.org/16/33248/24911.png"} /-->'
	);

	// Without its details box (0.6.0, showDetails:false): the first with a
	// static map, the second without an image; both with the address.
	estrategia_web_map_test_page(
		'prueba-mapa-sin-datos',
		'Prueba de mapa sin datos',
		'<!-- wp:paragraph --><p class="ficha-sede">Plaça de Cort 1, 07001 Palma</p><!-- /wp:paragraph -->'
		. '<!-- wp:estrategia-web/map {"showDetails":false,"placeName":"Sede sin recuadro","address":"Plaça de Cort 1, 07001 Palma","embedUrl":"' . $embed . '","staticMapUrl":"' . esc_url_raw( $static_url ) . '"} /-->'
		. '<!-- wp:estrategia-web/map {"showDetails":false,"placeName":"Sede sin recuadro ni imagen","address":"Calle Sin Imagen 2, Palma","embedUrl":"' . $embed . '"} /-->'
	);

	return array(
		'ok'         => true,
		'theme'      => get_stylesheet(),
		'static_url' => $static_url,
	);
}
