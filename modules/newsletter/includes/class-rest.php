<?php
/**
 * The newsletter screen's REST routes (estrategia-web/v1), for whoever can
 * edit other people's posts (editors and administrators: CAP):
 *
 *   GET  /newsletter/posts?search=   what to propose (Builder::candidates)
 *   POST /newsletter/preview {ids}   the email for those posts, in that order (Builder::build)
 *   POST /newsletter/draft   {ids}   the same email, as a DRAFT in Mailchimp (Mailchimp::create_draft)
 *
 * The draft is built AGAIN on the server from the ids: the browser never
 * sends HTML, so what reaches Mailchimp is exactly what build() makes.
 *
 * How to maintain:
 * - Who and when: whoever adds something the screen asks for. Each route,
 *   its case in tests/newsletter.test.mjs.
 * - Criterion: read routes and the draft only. There is no «send» route and
 *   there will not be one (header of class-mailchimp.php).
 * - Edge case, by name: an author (no edit_others_posts) gets 403 on all
 *   three, even on /posts: the screen is not theirs.
 * - The trap: the Mailchimp error goes back to the screen as a 502 with
 *   Mailchimp's own message, not as a 500: a 500 looks like our bug, and it
 *   is usually a wrong key or audience.
 */

namespace EstrategiaWeb\Newsletter;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NS = 'estrategia-web/v1';

	/** Who can make the newsletter. */
	public const CAP = 'edit_others_posts';

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$can = static fn(): bool => current_user_can( self::CAP );
		$ids = array(
			'ids' => array(
				'type'     => 'array',
				'items'    => array( 'type' => 'integer' ),
				'required' => true,
				'minItems' => 1,
			),
		);
		register_rest_route(
			self::NS,
			'/newsletter/posts',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'args'                => array( 'search' => array( 'type' => 'string', 'default' => '' ) ),
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Builder::candidates( trim( (string) $r['search'] ) ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/newsletter/preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'args'                => $ids,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( Builder::build( (array) $r['ids'] ) ),
			)
		);
		register_rest_route(
			self::NS,
			'/newsletter/draft',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'args'                => $ids,
				'callback'            => array( self::class, 'draft' ),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function draft( \WP_REST_Request $r ) {
		$built = Builder::build( (array) $r['ids'] );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$s     = Settings::get();
		$draft = ( new Mailchimp( $s['api_key'] ) )->create_draft( $built['subject'], $built['html'], $s['list_id'] );
		if ( is_wp_error( $draft ) ) {
			$draft->add_data( array( 'status' => 502 ) );
			return $draft;
		}
		return rest_ensure_response( $draft + array( 'warnings' => $built['warnings'] ) );
	}
}
