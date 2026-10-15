<?php
/**
 * What happens on every request to the public site.
 *
 * WHEN THE TABLE IS CONSULTED (decided and measured, 14-10-2026): only when
 * the request is going to be a 404. On template_redirect with priority 1,
 * which runs after WordPress decides the 404 (WP::handle_404, inside wp())
 * and BEFORE redirect_canonical (priority 10), which would otherwise guess a
 * similar page (redirect_guess_404_permalink) and get ahead of the rule.
 *
 * - A page that exists: ZERO queries to the table (tests/server.test.mjs
 *   checks this by counting the request's queries).
 * - A 404: ONE query on the unique index (source_hash IN (…)), and if it
 *   matches, one more write (the counter). If it doesn't match and the 404
 *   log is enabled, one or two more on the log.
 *
 * The consequence, and it is the named edge case: a rule whose SOURCE EXISTS
 * on the site does not fire (/contacto/ → /contacto-nuevo/ with the page
 * /contacto/ published). That is what you want when migrating a site (an old
 * rule must not hide a live page), and the screen warns about it on save. To
 * retire a live page: delete it or set it to draft, and then the rule kicks
 * in by itself.
 *
 * A 410 does not redirect: it returns status 410 and lets the theme render
 * its «not found» page (is_404 stays true), so the visitor sees the site and
 * not a blank page.
 *
 * The target is built from the RAW «home» option, not home_url(): WPML
 * filters home_url() with the request's language and an /en/… would end up
 * as /en/en/…. The CSV paths already carry their language.
 *
 * The request's query is NOT passed on to the target (/viejo/?utm=x →
 * /nuevo/): the target is what the rule says, nothing more. If keeping the
 * utm parameters is ever needed, it is a filter:
 * estrategia_web_redirects_target.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	public static function boot(): void {
		add_action( 'template_redirect', array( self::class, 'on_404' ), 1 );
	}

	public static function on_404(): void {
		if ( ! is_404() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised in Rules::split and never printed.
		$rule = Store::match( $uri );
		if ( ! $rule ) {
			if ( Settings::get()['log_404'] ) {
				Store::log_404( $uri );
			}
			return;
		}
		Store::hit( $rule['id'] );

		if ( 410 === $rule['type'] ) {
			status_header( 410 );
			nocache_headers();
			return; // The theme renders its 404 with status 410.
		}

		$target = $rule['target'];
		if ( '/' === $target[0] && 0 !== strpos( $target, '//' ) ) {
			$target = untrailingslashit( (string) get_option( 'home' ) ) . $target;
		}
		/**
		 * The target of a redirect, right before sending it.
		 *
		 * @param string $target Absolute URL.
		 * @param array  $rule   id, source, target, type.
		 * @param string $uri    The request as it arrived.
		 */
		$target = (string) apply_filters( 'estrategia_web_redirects_target', $target, $rule, $uri );
		if ( wp_redirect( $target, $rule['type'], 'Estrategia Web' ) ) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the target is written by the administrator and may be another site.
			exit;
		}
	}
}
