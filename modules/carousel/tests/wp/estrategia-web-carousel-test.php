<?php
/**
 * TESTS ONLY. Loaded by the mu-plugin that tests/blueprint.json writes into the
 * Playground WordPress, and it does nothing unless ESTRATEGIA_WEB_BLOCKS_TESTING
 * is defined (only that loader defines it).
 *
 * The seed of the carousel's browser tests (modules/carousel/tests/*.test.mjs),
 * which came from the parent theme's tests/fixtures/setup/setup.php on
 * 17-10-2026 with the same pages, posts and literals:
 *
 * - /prueba-carrusel/ — five slides, no loop (carousel.test.mjs; in the
 *   theme it was the carousel of /prueba-modulos/).
 * - /prueba-carrusel-consulta/ — a core/query of six posts (carousel-query).
 * - /prueba-carrusel-bucle/ — looping ones: blocks, query, a two-row grid
 *   (class rejilla-prueba), three slides with 2.4 in view and a single slide
 *   (carousel-loop, -infinite, -glide).
 * - /prueba-carrusel-fotos/ — 1920×1280 photos drawn with GD, at the top and
 *   far below (carousel-images, carousel-preload).
 * - /prueba-carrusel-movil/ — mobileRatio 4/5 and preloadSlides 2
 *   (carousel-mobile-crop, carousel-preload).
 * - /prueba-carrusel-impar/ — 7 of 3 and 23 posts in one row and in a
 *   12-column grid (class rejilla-impar) (carousel-any-count).
 *
 * POST /wp-json/estrategia-web-carousel-test/v1/seed. Idempotent: pages and
 * posts are rewritten by slug, the photos are made only the first time.
 *
 * What the THEME did and is done here instead: the CSS of the two grids
 * (rejilla-prueba, rejilla-impar), which a site's child sets for its client
 * strip. The test runs in Playground's default theme (see tests/browser.mjs).
 *
 * The content is in Spanish on purpose: it is test data for a Spanish site and
 * the tests look for those literals.
 *
 * How to maintain: a new test page of the carousel goes here, with its slug
 * and its test in the list above. A page of ANOTHER module goes in that
 * module's seed.
 *
 * @package estrategia-web
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_BLOCKS_TESTING' ) || true !== ESTRATEGIA_WEB_BLOCKS_TESTING ) {
	return;
}

// A carousel in a two-row GRID, like Lavandería's client strip
// (carousel-infinite.test.mjs), and 23 cards in 12 columns
// (carousel-any-count.test.mjs): the bottom row has 11.
add_action(
	'wp_head',
	static function () {
		echo '<style>.rejilla-prueba .estrategia-web-carousel__track{display:grid;grid-template-columns:repeat(3,70%);}</style>';
		echo '<style>.rejilla-impar .estrategia-web-carousel__track{display:grid;grid-template-columns:repeat(12,170px);gap:12px}</style>';
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'estrategia-web-carousel-test/v1',
			'/seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'estrategia_web_carousel_test_seed',
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
function estrategia_web_carousel_test_page( string $slug, string $title, string $content ): int {
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
 * Creates or rewrites a published post by slug.
 *
 * @param string $slug  Slug.
 * @param string $title Title.
 */
function estrategia_web_carousel_test_post( string $slug, string $title ): void {
	$post = get_page_by_path( $slug, OBJECT, 'post' );
	wp_insert_post(
		array(
			'ID'          => $post ? $post->ID : 0,
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_name'   => $slug,
		)
	);
}

/** The seed (see the header). */
function estrategia_web_carousel_test_seed(): array {
	kses_remove_filters();

	$group = static fn( string $text, int $padding = 48 ): string => '<!-- wp:group {"backgroundColor":"surface","style":{"spacing":{"padding":{"top":"var:preset|spacing|' . $padding . '","bottom":"var:preset|spacing|' . $padding . '","left":"var:preset|spacing|24","right":"var:preset|spacing|24"}}}} -->'
		. '<div class="wp-block-group has-surface-background-color has-background">' . $text . '</div><!-- /wp:group -->';

	$slides = '';
	for ( $i = 1; $i <= 5; $i++ ) {
		$slides .= $group( '<!-- wp:paragraph --><p>Diapositiva ' . $i . '</p><!-- /wp:paragraph -->' );
	}

	estrategia_web_carousel_test_page(
		'prueba-carrusel',
		'Prueba de carrusel',
		'<!-- wp:heading --><h2 class="wp-block-heading">Carrusel</h2><!-- /wp:heading -->'
		. '<!-- wp:estrategia-web/carousel {"label":"Carrusel de prueba","showDots":true,"loop":false,"perViewMobile":1,"perViewTablet":2,"perViewDesktop":2} -->'
		. $slides
		. '<!-- /wp:estrategia-web/carousel -->'
	);

	// Six posts for the carousels with a core/query inside: each <li> of its
	// post-template is a slide.
	for ( $i = 1; $i <= 6; $i++ ) {
		estrategia_web_carousel_test_post( 'entrada-carrusel-' . $i, 'Entrada carrusel ' . $i );
	}
	$query_block = static fn( int $id ): string => '<!-- wp:query {"queryId":' . $id . ',"query":{"perPage":6,"pages":0,"offset":0,"postType":"post","order":"asc","orderBy":"title","search":"carrusel","inherit":false}} -->'
		. '<div class="wp-block-query"><!-- wp:post-template -->'
		. $group( '<!-- wp:post-title /-->' )
		. '<!-- /wp:post-template --></div>'
		. '<!-- /wp:query -->';

	// Looping (0.5.0), WITHOUT the loop attribute: looping is the default.
	estrategia_web_carousel_test_page(
		'prueba-carrusel-bucle',
		'Prueba de carrusel en bucle',
		'<!-- wp:estrategia-web/carousel {"label":"Carrusel en bucle","showDots":true,"perViewMobile":1,"perViewTablet":2,"perViewDesktop":2} -->'
		. $slides
		. '<!-- /wp:estrategia-web/carousel -->'
		. '<!-- wp:estrategia-web/carousel {"label":"Carrusel en bucle con consulta","showDots":true,"perViewMobile":1,"perViewTablet":2,"perViewDesktop":2} -->'
		. $query_block( 9 )
		. '<!-- /wp:estrategia-web/carousel -->'
		// Infinite (0.6.0): in a two-row grid (rejilla-prueba), six posts = three columns.
		. '<!-- wp:estrategia-web/carousel {"label":"Carrusel en dos filas","className":"rejilla-prueba"} -->'
		. $query_block( 10 )
		. '<!-- /wp:estrategia-web/carousel -->'
		// 0.7.2: three slides and 2.4 in view (Lavandería's home page).
		. '<!-- wp:estrategia-web/carousel {"label":"Carrusel con una de más","showDots":true,"perViewMobile":1,"perViewTablet":2.4,"perViewDesktop":2.4} -->'
		. implode( '<!-- /wp:group -->', array_slice( explode( '<!-- /wp:group -->', $slides ), 0, 3 ) ) . '<!-- /wp:group -->'
		. '<!-- /wp:estrategia-web/carousel -->'
		// Infinite (0.6.0): with a single slide there is nothing to do.
		. '<!-- wp:estrategia-web/carousel {"label":"Carrusel de una","showDots":true} -->'
		. '<!-- wp:paragraph --><p>Única diapositiva</p><!-- /wp:paragraph -->'
		. '<!-- /wp:estrategia-web/carousel -->'
	);

	estrategia_web_carousel_test_page(
		'prueba-carrusel-consulta',
		'Prueba de carrusel con consulta',
		'<!-- wp:estrategia-web/carousel {"label":"Carrusel con consulta","showDots":true,"loop":false,"perViewMobile":1,"perViewTablet":2,"perViewDesktop":2} -->'
		. $query_block( 7 )
		. '<!-- /wp:estrategia-web/carousel -->'
	);

	// Photos (0.7.4): 1920×1280 with their intermediate sizes (so that there
	// is a srcset), drawn with GD: a gradient with a number, nothing to download.
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$photo_block = static function ( int $n ): string {
		$existing = get_posts( array( 'post_type' => 'attachment', 'name' => 'foto-carrusel-' . $n, 'posts_per_page' => 1, 'post_status' => 'inherit' ) );
		if ( $existing ) {
			$id = $existing[0]->ID;
		} else {
			$image = imagecreatetruecolor( 1920, 1280 );
			for ( $x = 0; $x < 1920; $x += 16 ) {
				imagefilledrectangle( $image, $x, 0, $x + 15, 1279, imagecolorallocate( $image, ( $x / 8 ) % 256, ( 40 * $n ) % 256, 160 ) );
			}
			imagestring( $image, 5, 900, 620, 'Foto ' . $n, imagecolorallocate( $image, 255, 255, 255 ) );
			ob_start();
			imagejpeg( $image, null, 70 );
			$upload = wp_upload_bits( 'foto-carrusel-' . $n . '.jpg', null, (string) ob_get_clean() );
			$id     = wp_insert_attachment(
				array(
					'post_title'     => 'Foto carrusel ' . $n,
					'post_name'      => 'foto-carrusel-' . $n,
					'post_mime_type' => 'image/jpeg',
					'post_status'    => 'inherit',
				),
				$upload['file']
			);
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		}
		return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full","linkDestination":"none"} -->'
			. '<figure class="wp-block-image size-full"><img src="' . esc_url( wp_get_attachment_url( $id ) ) . '" alt="Foto ' . $n . '" class="wp-image-' . $id . '"/></figure>'
			. '<!-- /wp:image -->';
	};

	estrategia_web_carousel_test_page(
		'prueba-carrusel-fotos',
		'Prueba de carrusel de fotos',
		'<!-- wp:estrategia-web/carousel {"label":"Fotos arriba","align":"full","perViewMobile":1,"perViewTablet":2,"perViewDesktop":3} -->'
		. $photo_block( 1 ) . $photo_block( 2 ) . $photo_block( 3 ) . $photo_block( 4 )
		. '<!-- /wp:estrategia-web/carousel -->'
		. '<!-- wp:spacer {"height":"3000px"} --><div style="height:3000px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->'
		. '<!-- wp:estrategia-web/carousel {"label":"Fotos abajo","perViewMobile":1,"perViewTablet":1,"perViewDesktop":1} -->'
		. $photo_block( 5 ) . $photo_block( 6 ) . $photo_block( 7 )
		. '<!-- /wp:estrategia-web/carousel -->'
	);

	// Mobile crop (1.1.x, mobileRatio) and the desktop preload of slide 2
	// (1.4.2, preloadSlides): Lavandería's case.
	estrategia_web_carousel_test_page(
		'prueba-carrusel-movil',
		'Prueba de carrusel con recorte para el móvil',
		'<!-- wp:estrategia-web/carousel {"label":"Fotos recortadas","align":"full","perViewMobile":1,"perViewTablet":2,"perViewDesktop":3,"mobileRatio":"4/5","mobileImageSizes":"100vw","preloadSlides":2} -->'
		. $photo_block( 1 ) . $photo_block( 2 ) . $photo_block( 3 )
		. '<!-- /wp:estrategia-web/carousel -->'
	);

	// Any number of slides (1.4.3): 23 posts like Lavandería's 23 clients, in
	// one row with 4.5 in view and in a 12-column grid; and 7 slides with 3 in view.
	for ( $i = 1; $i <= 23; $i++ ) {
		estrategia_web_carousel_test_post( sprintf( 'cliente-impar-%02d', $i ), sprintf( 'Cliente impar %02d', $i ) );
	}
	$odd_query = static fn( int $id ): string => '<!-- wp:query {"queryId":' . $id . ',"query":{"perPage":50,"pages":0,"offset":0,"postType":"post","order":"asc","orderBy":"title","search":"cliente impar","inherit":false}} -->'
		. '<div class="wp-block-query"><!-- wp:post-template -->'
		. $group( '<!-- wp:post-title /-->', 24 )
		. '<!-- /wp:post-template --></div>'
		. '<!-- /wp:query -->';
	$seven = '';
	for ( $i = 1; $i <= 7; $i++ ) {
		$seven .= $group( '<!-- wp:paragraph --><p>Siete ' . $i . '</p><!-- /wp:paragraph -->' );
	}
	estrategia_web_carousel_test_page(
		'prueba-carrusel-impar',
		'Prueba de carrusel con cualquier número',
		'<!-- wp:estrategia-web/carousel {"label":"Siete de tres","showDots":true,"perViewMobile":1,"perViewTablet":3,"perViewDesktop":3} -->'
		. $seven
		. '<!-- /wp:estrategia-web/carousel -->'
		. '<!-- wp:estrategia-web/carousel {"label":"Veintitrés en una fila","showDots":true,"perViewMobile":1,"perViewTablet":4.5,"perViewDesktop":4.5} -->'
		. $odd_query( 11 )
		. '<!-- /wp:estrategia-web/carousel -->'
		. '<!-- wp:estrategia-web/carousel {"label":"Veintitrés en dos filas","className":"rejilla-impar"} -->'
		. $odd_query( 12 )
		. '<!-- /wp:estrategia-web/carousel -->'
	);

	return array(
		'ok'    => true,
		'theme' => get_stylesheet(),
	);
}
