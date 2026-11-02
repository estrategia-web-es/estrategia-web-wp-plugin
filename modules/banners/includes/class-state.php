<?php
/**
 * THE STATE IS STORED (2.6.0, David, 02-11-2026: «se calcula a partir del
 * estado de publicación y las dos fechas — esto no me gusta»).
 * meta estrategia_web_banner_state = active | scheduled | expired | off, the
 * value of Rules::state() (the rule stays there, pure and tested), written:
 *   - when a banner is saved: the edit screen (save_post, after the fields),
 *     quick/bulk edit and REST (rest_after_insert, after its meta);
 *   - at the moments it changes: on saving, one WP-Cron event at the start and
 *     one at the end (hook estrategia_web_banner_state, args [id]), the same
 *     mechanism as the theme's scheduled front page;
 *   - once a day for every banner (estrategia_web_banners_states), the safety
 *     net if an event did not run;
 *   - once for all banners when the module's version of this changes
 *     (option estrategia_web_banners_state_version): the banners from before.
 * Who reads it: the list (views, sorting), Post_Type::data() and therefore
 * the zone block on the server. NOT the browser: view.js still shows and
 * hides by data-start/data-end, because the page is cached and a page saved
 * at 9:00 must show at 10:00 the banner that starts at 10:00.
 * How to maintain:
 * - Who: whoever touches the dates or the publishing of a banner. Anything
 *   that writes those metas by another way (a script, SQL) calls refresh().
 * - Edge case, by name: WP-Cron runs on visits, so a change can arrive a few
 *   minutes late; the list may show «Scheduled» a moment after the start.
 *   The front does not care (view.js). A real cron on the server removes it.
 * - The trap: save_post runs BEFORE REST writes the meta of the request;
 *   refreshing only there stored the state of the old dates. Hence
 *   rest_after_insert as well.
 */

namespace EstrategiaWeb\Banners;

defined( 'ABSPATH' ) || exit;

final class State {

	public const META    = 'estrategia_web_banner_state';
	public const EVENT   = 'estrategia_web_banner_state';
	public const DAILY   = 'estrategia_web_banners_states';
	public const VERSION = 1;
	public const VERSION_OPT = 'estrategia_web_banners_state_version';

	/** From highest to lowest: the order of the list's «State» column when sorted descending. */
	public const ORDER = array( Rules::ACTIVE, Rules::SCHEDULED, Rules::EXPIRED, Rules::OFF );

	public static function boot(): void {
		add_action( 'save_post_' . Post_Type::TYPE, array( self::class, 'on_save' ), 20 );
		add_action( 'rest_after_insert_' . Post_Type::TYPE, array( self::class, 'on_rest' ) );
		add_action( 'before_delete_post', array( self::class, 'forget' ) );
		add_action( 'trashed_post', array( self::class, 'on_save' ) ); // After the status change (wp_trash_post runs before it).
		add_action( 'untrashed_post', array( self::class, 'on_save' ) );
		add_action( self::EVENT, array( self::class, 'refresh' ) );
		add_action( self::DAILY, array( self::class, 'refresh_all' ) );
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 20 );
		add_action( 'init', array( self::class, 'register' ) );
	}

	/** Readable through REST (a script can see the state); written only by refresh(), never by a request. */
	public static function register(): void {
		register_post_meta(
			Post_Type::TYPE,
			self::META,
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => array( 'schema' => array( 'enum' => array_merge( self::ORDER, array( '' ) ), 'readonly' => true ) ),
				'auth_callback' => '__return_false',
			)
		);
	}

	public static function on_save( $post_id ): void {
		if ( ! wp_is_post_revision( (int) $post_id ) && ! wp_is_post_autosave( (int) $post_id ) ) {
			self::refresh( (int) $post_id );
		}
	}

	public static function on_rest( \WP_Post $post ): void {
		self::refresh( $post->ID );
	}

	/** Stores the banner's state and schedules its next change. Returns the state. */
	public static function refresh( $post_id ): string {
		$post = get_post( (int) $post_id );
		if ( ! $post || Post_Type::TYPE !== $post->post_type ) {
			return '';
		}
		$start = (int) get_post_meta( $post->ID, Post_Type::FIELDS['start'], true );
		$end   = (int) get_post_meta( $post->ID, Post_Type::FIELDS['end'], true );
		$now   = time();
		$state = Rules::state( $post->post_status, $start, $end, $now );
		update_post_meta( $post->ID, self::META, $state );

		wp_clear_scheduled_hook( self::EVENT, array( $post->ID ) );
		if ( 'publish' === $post->post_status ) {
			if ( $start > $now ) {
				wp_schedule_single_event( $start, self::EVENT, array( $post->ID ) );
			} elseif ( $end > 0 && $end >= $now ) {
				// Rules::state(): the end second itself still shows, so it changes the second after.
				wp_schedule_single_event( $end + 1, self::EVENT, array( $post->ID ) );
			}
		}
		return $state;
	}

	/** Every banner (the daily net, and the upgrade). */
	public static function refresh_all(): int {
		$ids = get_posts(
			array(
				'post_type'      => Post_Type::TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			self::refresh( (int) $id );
		}
		return count( $ids );
	}

	public static function forget( $post_id ): void {
		wp_clear_scheduled_hook( self::EVENT, array( (int) $post_id ) );
	}

	/** Once per VERSION: every banner gets its state, and the daily net is scheduled. */
	public static function maybe_upgrade(): void {
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
		if ( (int) get_option( self::VERSION_OPT, 0 ) >= self::VERSION ) {
			return;
		}
		self::refresh_all();
		update_option( self::VERSION_OPT, self::VERSION, true );
	}

	/** The stored state, or the rule's if it is missing (a banner nobody has refreshed yet). */
	public static function of( \WP_Post $post ): string {
		$state = (string) get_post_meta( $post->ID, self::META, true );
		return in_array( $state, self::ORDER, true ) ? $state : self::refresh( $post->ID );
	}
}
