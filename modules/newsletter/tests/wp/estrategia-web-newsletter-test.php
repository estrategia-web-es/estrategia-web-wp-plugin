<?php
/**
 * TESTS ONLY. Loaded by the mu-plugin that tests/blueprint.json writes into
 * the Playground WordPress, and it does nothing unless
 * ESTRATEGIA_WEB_NEWSLETTER_TESTING is defined (only that loader defines it).
 *
 * 1. A FAKE MAILCHIMP. pre_http_request answers every request to
 *    *.api.mailchimp.com as Mailchimp would (shapes copied from atlas's
 *    bin/mailchimp.js, which talks to the real one) and logs it: method,
 *    URL, Authorization and JSON body. The test WordPress is offline anyway
 *    (tests/wp/estrategia-web-test-env.php); this filter runs before that one.
 *      POST /wp-json/estrategia-web-newsletter-test/v1/mock {mode}   resets the log
 *      GET  /wp-json/estrategia-web-newsletter-test/v1/log
 *    Modes: ok · dnd (the new campaign comes back drag-and-drop) ·
 *    put_fails (PUT /content answers 400) · no_model (no campaign sent).
 * 2. THE SEED. POST /wp-json/estrategia-web-newsletter-test/v1/seed: two
 *    categories («Prova portada», the featured one, and «Actualitat»), two
 *    photos written straight to uploads (one whose «large» is WebP with its
 *    .jpg still next to it; one that only exists in WebP) and three posts.
 *    Idempotent: what it made before is deleted first.
 * 3. GET/POST /wp-json/estrategia-web-newsletter-test/v1/option: the stored
 *    option as it is, and merging keys into it (through Settings::sanitize).
 *
 * How to maintain: a new Mailchimp call of class-mailchimp.php gets its
 * answer in answer() in the same commit; an unknown call answers 404, so a
 * forgotten one shows up as a red test, not as a silent pass. The trap: the
 * shapes here are what WE believe Mailchimp returns; only a real draft on
 * Gastronòmicament proves them (said in the module's CHANGELOG entry).
 *
 * @package estrategia-web
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_NEWSLETTER_TESTING' ) || true !== ESTRATEGIA_WEB_NEWSLETTER_TESTING ) {
	return;
}

const ESTRATEGIA_WEB_NEWSLETTER_TEST_MODE = 'estrategia_web_newsletter_test_mode';
const ESTRATEGIA_WEB_NEWSLETTER_TEST_LOG  = 'estrategia_web_newsletter_test_log';

/**
 * The fake Mailchimp's answer: [status, body].
 *
 * @param array<string, mixed>|null $body
 * @return array{0: int, 1: array<string, mixed>|null}
 */
function estrategia_web_newsletter_test_answer( string $mode, string $method, string $path, ?array $body ): array {
	if ( 'GET' === $method && 'campaigns' === $path ) {
		if ( 'no_model' === $mode ) {
			return array( 200, array( 'campaigns' => array(), 'total_items' => 0 ) );
		}
		return array(
			200,
			array(
				'campaigns' => array(
					array(
						'id'         => 'model1',
						'send_time'  => '2026-10-29T08:00:00+00:00',
						'recipients' => array(
							'list_id'      => 'L1',
							'segment_opts' => array(
								'saved_segment_id' => 0,
								'match'            => 'all',
								'conditions'       => array( array( 'condition_type' => 'Interests', 'field' => 'interests-abc', 'op' => 'interestcontains', 'value' => array( 'f00' ) ) ),
							),
						),
						'settings'   => array(
							'subject_line'  => 'Setmana passada',
							'title'         => 'Setmana passada',
							'from_name'     => 'Gastronòmicament',
							'reply_to'      => 'info@gastronomicament.cat',
							'template_id'   => 350969,
							'drag_and_drop' => true,
							'auto_tweet'    => true,
							'authenticate'  => true,
						),
					),
				),
			),
		);
	}
	if ( 'POST' === $method && 'campaigns' === $path ) {
		return array( 200, array( 'id' => 'new1', 'web_id' => 4321, 'status' => 'save', 'settings' => (array) ( $body['settings'] ?? array() ) ) );
	}
	if ( 'PUT' === $method && 'campaigns/new1/content' === $path ) {
		return 'put_fails' === $mode ? array( 400, array( 'title' => 'Invalid Resource', 'detail' => 'The html is not valid.' ) ) : array( 200, array( 'html' => (string) ( $body['html'] ?? '' ) ) );
	}
	if ( 'GET' === $method && 'campaigns/new1' === $path ) {
		return array(
			200,
			array(
				'id'         => 'new1',
				'web_id'     => 4321,
				'status'     => 'save',
				'settings'   => array( 'drag_and_drop' => 'dnd' === $mode, 'subject_line' => 'Del registre' ),
				'recipients' => array( 'recipient_count' => 348 ),
			),
		);
	}
	if ( 'DELETE' === $method && 'campaigns/new1' === $path ) {
		return array( 204, null );
	}
	return array( 404, array( 'title' => 'Resource Not Found', 'detail' => 'The fake Mailchimp does not know ' . $method . ' ' . $path ) );
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( false !== $pre || ! str_ends_with( $host, '.api.mailchimp.com' ) ) {
			return $pre;
		}
		$path   = (string) preg_replace( '#^/3\.0/#', '', (string) wp_parse_url( (string) $url, PHP_URL_PATH ) );
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$body   = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
		$log    = get_option( ESTRATEGIA_WEB_NEWSLETTER_TEST_LOG, array() );
		$log[]  = array(
			'method' => $method,
			'url'    => (string) $url,
			'path'   => $path,
			'auth'   => (string) ( $args['headers']['Authorization'] ?? '' ),
			'body'   => $body,
		);
		update_option( ESTRATEGIA_WEB_NEWSLETTER_TEST_LOG, $log, false );
		list( $status, $answer ) = estrategia_web_newsletter_test_answer( (string) get_option( ESTRATEGIA_WEB_NEWSLETTER_TEST_MODE, 'ok' ), $method, $path, is_array( $body ) ? $body : null );
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => null === $answer ? '' : (string) wp_json_encode( $answer ),
			'response' => array( 'code' => $status, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

add_action(
	'rest_api_init',
	static function () {
		$ns = 'estrategia-web-newsletter-test/v1';
		register_rest_route(
			$ns,
			'/mock',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					update_option( ESTRATEGIA_WEB_NEWSLETTER_TEST_MODE, (string) ( $r['mode'] ?? 'ok' ), false );
					update_option( ESTRATEGIA_WEB_NEWSLETTER_TEST_LOG, array(), false );
					return array( 'mode' => get_option( ESTRATEGIA_WEB_NEWSLETTER_TEST_MODE ) );
				},
			)
		);
		register_rest_route(
			$ns,
			'/log',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static fn() => array_values( (array) get_option( ESTRATEGIA_WEB_NEWSLETTER_TEST_LOG, array() ) ),
			)
		);
		// The stored option as it is (it is not in REST, on purpose); POST merges keys through the module's own sanitize.
		register_rest_route(
			$ns,
			'/option',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					if ( 'POST' === $r->get_method() ) {
						update_option( 'estrategia_web_newsletter', (array) $r->get_json_params() );
					}
					return get_option( 'estrategia_web_newsletter' );
				},
			)
		);
		register_rest_route(
			$ns,
			'/seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'estrategia_web_newsletter_test_seed',
			)
		);
	}
);

/** The seed (see the header). */
function estrategia_web_newsletter_test_seed(): array {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach ( get_posts( array( 'post_type' => array( 'post', 'attachment' ), 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_ew_newsletter_test', 'fields' => 'ids' ) ) as $old ) {
		wp_delete_post( $old, true );
	}
	$cat = static function ( string $name, string $slug ): int {
		$term = get_term_by( 'slug', $slug, 'category' );
		return $term ? (int) $term->term_id : (int) wp_insert_term( $name, 'category', array( 'slug' => $slug ) )['term_id'];
	};
	$featured = $cat( 'Prova portada', 'prova-portada' );
	$news     = $cat( 'Actualitat', 'prova-actualitat' );

	$uploads = wp_upload_dir( '2026/10' );
	$photo   = static function ( string $file, array $sizes, array $on_disk, string $original = '' ) use ( $uploads ): int {
		foreach ( $on_disk as $name ) {
			file_put_contents( $uploads['path'] . '/' . $name, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test files.
		}
		$id   = wp_insert_attachment( array( 'post_mime_type' => 'image/webp', 'post_title' => $file, 'post_status' => 'inherit' ), $uploads['path'] . '/' . $file );
		$meta = array( 'width' => 2000, 'height' => 1125, 'file' => $uploads['subdir'] ? ltrim( $uploads['subdir'], '/' ) . '/' . $file : $file, 'sizes' => $sizes );
		if ( '' !== $original ) {
			$meta['original_image'] = $original;
		}
		wp_update_attachment_metadata( $id, $meta );
		update_post_meta( $id, '_ew_newsletter_test', 1 );
		return $id;
	};
	$large  = static fn( string $f ): array => array( 'large' => array( 'file' => $f, 'width' => 1024, 'height' => 576, 'mime-type' => 'image/webp' ) );
	$with   = $photo( 'nl-amb-jpeg.webp', $large( 'nl-amb-jpeg-1024x576.webp' ), array( 'nl-amb-jpeg.webp', 'nl-amb-jpeg-1024x576.webp', 'nl-amb-jpeg-1024x576.jpg' ) );
	$only   = $photo( 'nl-nomes-webp.webp', $large( 'nl-nomes-webp-1024x576.webp' ), array( 'nl-nomes-webp.webp', 'nl-nomes-webp-1024x576.webp' ), 'nl-nomes-webp.jpg' );

	// The newest posts of the site (a minute ago, two, three), so they come first in «latest»; never in the future (that is «future», not «publish»).
	$ago  = static fn( int $minutes ): string => wp_date( 'Y-m-d H:i:s', time() - 60 * $minutes );
	$post = static function ( string $title, string $excerpt, array $cats, int $thumb, string $date ): int {
		$id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_excerpt'  => $excerpt,
				'post_content'  => '<!-- wp:paragraph --><p>Contingut.</p><!-- /wp:paragraph -->',
				'post_category' => $cats,
				'post_date'     => $date,
			)
		);
		if ( $thumb ) {
			set_post_thumbnail( $id, $thumb );
		}
		update_post_meta( $id, '_ew_newsletter_test', 1 );
		return $id;
	};
	return array(
		'a'     => $post( 'Nl prova A: el raor', "La temporada de pesca del raor amb una població plenament recuperada a les Illes, i més coses que no hi caben.", array( $featured, $news ), $with, $ago( 1 ) ),
		'b'     => $post( 'Nl prova B: vins & tapes', 'Curta.', array( $news ), $only, $ago( 2 ) ),
		'c'     => $post( 'Nl prova C: sense foto', '', array( $featured, $news ), 0, $ago( 3 ) ),
		'draft' => (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Nl prova esborrany', 'meta_input' => array( '_ew_newsletter_test' => 1 ) ) ),
		'base'  => $uploads['url'],
	);
}
