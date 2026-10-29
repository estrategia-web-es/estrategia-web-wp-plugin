<?php
/**
 * Where views and clicks arrive: POST /wp-json/estrategia-web/v1/banners/hits
 * with {"views": [ids], "click": id}. The zone block's view.js sends it with
 * sendBeacon.
 *
 * WHY THROUGH THE BROWSER AND NOT ON THE SERVER: the page is cached (LiteSpeed,
 * SiteGround). Counting while rendering counts one view per cache fill, which
 * is what AdRotate did behind LiteSpeed. And clicks do not go through a
 * redirect URL of ours for the same reason (a cached 302 counts nothing): the
 * link goes straight to the advertiser and the click is a beacon. Without
 * JavaScript the banner still works, it is just not counted.
 *
 * What it accepts: at most MAX_IDS ids per request, and only published
 * banners. It is public on purpose (anyone can view a banner), so it can be
 * inflated by hand: these are a medium's figures for its advertisers, not
 * billing. Bots that do not run JavaScript do not count, which is most of them.
 *
 * Personal data: none. Neither IP nor browser are stored: a counter per day.
 */

namespace EstrategiaWeb\Banners;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const MAX_IDS = 20;

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'estrategia-web/v1',
			'/banners/hits',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'hits' ),
				'args'                => array(
					'views' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'default' => array() ),
					'click' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
	}

	public static function hits( \WP_REST_Request $request ): \WP_REST_Response {
		do_action( 'litespeed_control_set_nocache', 'estrategia-web banners' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's hook.
		$views = array_slice( array_unique( array_map( 'intval', (array) $request['views'] ) ), 0, self::MAX_IDS );
		$click = (int) $request['click'];
		$valid = self::published( array_merge( $views, $click ? array( $click ) : array() ) );
		foreach ( $views as $id ) {
			if ( isset( $valid[ $id ] ) ) {
				Store::add( $id, 1, $id === $click ? 1 : 0 );
			}
		}
		if ( $click && isset( $valid[ $click ] ) && ! in_array( $click, $views, true ) ) {
			Store::add( $click, 0, 1 );
		}
		$response = new \WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The ids that are published banners, as keys.
	 *
	 * @param int[] $ids
	 * @return array<int, true>
	 */
	private static function published( array $ids ): array {
		$ids = array_values( array_filter( $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$found = get_posts(
			array(
				'post_type'      => Post_Type::TYPE,
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'fields'         => 'ids',
				'posts_per_page' => count( $ids ),
				'no_found_rows'  => true,
			)
		);
		return array_fill_keys( array_map( 'intval', $found ), true );
	}
}
