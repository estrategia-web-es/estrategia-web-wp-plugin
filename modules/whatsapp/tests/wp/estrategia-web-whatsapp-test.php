<?php
/**
 * TESTS ONLY. Loaded by the mu-plugin that tests/blueprint.json writes into the
 * Playground WordPress, and it does nothing unless ESTRATEGIA_WEB_BLOCKS_TESTING
 * is defined (only that loader defines it).
 *
 * The seed of the WhatsApp button's browser tests
 * (modules/whatsapp/tests/*.test.mjs), which came from the parent theme's
 * tests/fixtures/setup/setup.php on 17-10-2026:
 *
 * - the site options (phone and message), the ones a block without
 *   attributes uses;
 * - /prueba-whatsapp/ — two buttons: one with attributes (number with spaces
 *   and «+», message with accents, bottom-left) and one WITHOUT attributes,
 *   which takes the site options and goes bottom-right. In the theme the
 *   second one was the footer's (parts/footer.html); here the theme is
 *   Playground's default (a plugin block must work in any block theme), so the page carries it.
 *   28-10-2026: a 3000 px spacer between them makes the page long enough to
 *   scroll, so the same page serves «fixed while scrolling» and «under the
 *   cookie notice» (they had a page of their own, a second load).
 *
 * POST /wp-json/estrategia-web-whatsapp-test/v1/seed. Idempotent.
 *
 * How to maintain: a new test page of the button goes here. A page of
 * ANOTHER module goes in that module's seed.
 *
 * @package estrategia-web
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ESTRATEGIA_WEB_BLOCKS_TESTING' ) || true !== ESTRATEGIA_WEB_BLOCKS_TESTING ) {
	return;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'estrategia-web-whatsapp-test/v1',
			'/seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					kses_remove_filters();
					update_option( 'estrategia_web_whatsapp_phone', '34611223344' );
					update_option( 'estrategia_web_whatsapp_message', 'Mensaje desde la opción del sitio' );
					$existing = get_page_by_path( 'prueba-whatsapp' );
					wp_insert_post(
						array(
							'ID'           => $existing ? $existing->ID : 0,
							'post_type'    => 'page',
							'post_status'  => 'publish',
							'post_title'   => 'Prueba de WhatsApp',
							'post_name'    => 'prueba-whatsapp',
							'post_content' => wp_slash(
								'<!-- wp:paragraph --><p>Botones de WhatsApp.</p><!-- /wp:paragraph -->'
								. '<!-- wp:spacer {"height":"3000px"} --><div style="height:3000px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->'
								. '<!-- wp:paragraph --><p>Abajo</p><!-- /wp:paragraph -->'
								. '<!-- wp:estrategia-web/whatsapp {"phone":"+34 600 11 12 22","message":"Hola, ¿tenéis hora mañana?","position":"bottom-left"} /-->'
								. '<!-- wp:estrategia-web/whatsapp /-->'
							),
						)
					);
					return array(
						'ok'    => true,
						'theme' => get_stylesheet(),
					);
				},
			)
		);
	}
);
