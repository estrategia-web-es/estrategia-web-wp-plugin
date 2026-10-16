<?php
/**
 * The front page translated with WPML: /en/ shows the English front page (0.5.3).
 *
 * What it does: on the public side, `page_on_front` (and `page_for_posts`)
 * are translated to the request's language with `wpml_object_id`. That is
 * what WPML should do by itself, and in the test WordPress it does; on the
 * Lavandería Mallorca server (WPML 5.0.2, 06-10-2026) it did NOT: with
 * English active and the English front page properly linked (same trid in
 * icl_translations), WordPress still saw the Spanish one on /en/,
 * `is_front_page()` returned false and `redirect_canonical` sent /en/ →
 * /en/home/ (without the redirect, a 404). Measured with a diagnostic
 * header: page_on_front=6 with lang=en and no filter on
 * option_page_on_front. Why WPML doesn't hook in there was never found out;
 * this filter covers it either way.
 *
 * How to maintain:
 * - If WPML has already translated the value, `wpml_object_id` returns the
 *   same id: the filter doesn't get in the way when WPML does work (which is
 *   why it isn't checked beforehand).
 * - Without WPML it does nothing: the `wpml_object_id` filter doesn't exist
 *   and the value is returned as is.
 * - Public side only: in the dashboard and over REST, the option must be
 *   the default language's, which is the one that gets saved. REST is
 *   checked in translate(), not in boot() (see there why).
 * - The edge case: a front page WITHOUT a translation into the requested
 *   language. The original is returned (return_original = true), which is
 *   what WPML shows with «display as translated».
 * - The trap: checking it via WP-CLI doesn't work, because WPML doesn't load
 *   its public side there; you have to request the page (curl to /en/ must
 *   give 200, not 301).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Wpml_Front_Page {

	public static function boot(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! apply_filters( 'estrategia_web_site_wpml_front_page', true ) ) {
			return;
		}
		add_filter( 'option_page_on_front', array( self::class, 'translate' ), 20 );
		add_filter( 'option_page_for_posts', array( self::class, 'translate' ), 20 );
	}

	/**
	 * The REST check lives HERE, at read time, and not in boot(): boot() runs
	 * at plugins_loaded and REST_REQUEST is only defined later (parse_request),
	 * so checking it there let every REST request through. Measured on
	 * Lavandería (15-10-2026): over REST, page_on_front came back as the int
	 * 109 instead of the stored "109", the content load's fingerprint of the
	 * front-page options changed between two loads and the second one listed
	 * its own front page as edited in the dashboard. Hence also: when nothing
	 * is translated, the value goes back untouched, type included
	 * (tests/front-page-pure.test.mjs).
	 *
	 * @param mixed $id
	 */
	public static function translate( $id ) {
		if ( wp_is_serving_rest_request() || ! (int) $id || ! has_filter( 'wpml_object_id' ) ) {
			return $id;
		}
		return (int) apply_filters( 'wpml_object_id', (int) $id, 'page', true );
	}
}
