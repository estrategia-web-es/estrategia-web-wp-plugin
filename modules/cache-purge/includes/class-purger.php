<?php
namespace EstrategiaWeb\CachePurge;

defined( 'ABSPATH' ) || exit;

/**
 * Empties a published post's cached page when its fields change outside the
 * editor. The why, the criterion and the pitfall: modules/cache-purge/module.php.
 *
 * Flow: a field of a post is added, updated or deleted → note() keeps the
 * post id if the change counts → on shutdown, flush() empties each noted post
 * ONCE (a script that writes ten fields of one post purges it once) with every
 * adapter whose plugin is installed, and fires estrategia_web_purge_post.
 */
final class Purger {

	/**
	 * Fields WordPress itself writes all the time, which change nothing on the page.
	 */
	const IGNORED_KEYS = array( '_edit_lock', '_edit_last', '_encloseme', '_pingme', '_wp_desired_post_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' );

	/**
	 * Cache plugins: how to know it is installed => how to empty ONE post.
	 * 'by' says what the call takes: the post id or its URL.
	 */
	const ADAPTERS = array(
		// SiteGround Speed Optimizer. The URL exactly as get_permalink() gives it (pitfall in module.php).
		'siteground'      => array(
			'if' => 'sg_cachepress_purge_cache',
			'by' => 'url',
		),
		'wp-rocket'       => array(
			'if' => 'rocket_clean_post',
			'by' => 'id',
		),
		'w3-total-cache'  => array(
			'if' => 'w3tc_flush_post',
			'by' => 'id',
		),
		'wp-super-cache'  => array(
			'if' => 'wp_cache_post_change',
			'by' => 'id',
		),
		// LiteSpeed Cache has no function: it listens to an action. LSCWP_V is its version constant.
		'litespeed-cache' => array(
			'if'     => 'LSCWP_V',
			'action' => 'litespeed_purge_post',
			'by'     => 'id',
		),
	);

	/**
	 * Post ids waiting for shutdown.
	 *
	 * @var array<int,true>
	 */
	private static array $pending = array();

	public static function hook(): void {
		add_action( 'added_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta' ), 10, 3 );
		add_action( 'shutdown', array( self::class, 'flush' ) );
	}

	/**
	 * Hooked to added/updated/deleted_post_meta. The first argument is the meta
	 * id (an array of ids on delete): not used.
	 *
	 * @param mixed  $meta_id  Unused.
	 * @param int    $post_id  The post.
	 * @param string $meta_key The field.
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ): void {
		$post_id = (int) $post_id;
		if ( self::counts( (string) $meta_key, (string) get_post_status( $post_id ), self::who_counts( $post_id ) ) ) {
			self::$pending[ $post_id ] = true;
		}
	}

	/**
	 * Whether a field change purges: a field that is not WordPress's own
	 * bookkeeping, on a published post, made by someone who counts.
	 */
	public static function counts( string $meta_key, string $status, bool $who ): bool {
		return $who && 'publish' === $status && ! in_array( $meta_key, self::IGNORED_KEYS, true );
	}

	/**
	 * Who counts: WP-CLI, cron, or a logged-in user who can edit the post. A
	 * visitor never (edge case in module.php: view counters).
	 */
	public static function who_counts( int $post_id ): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Hooked to shutdown: empties each noted post once.
	 */
	public static function flush(): void {
		$ids           = array_keys( self::$pending );
		self::$pending = array();
		foreach ( $ids as $post_id ) {
			self::purge( $post_id );
		}
	}

	/**
	 * Empties one post with every installed adapter and fires the action.
	 *
	 * @return string[] The adapters that ran (for the test).
	 */
	public static function purge( int $post_id ): array {
		$url = (string) get_permalink( $post_id );
		$ran = array();
		foreach ( self::ADAPTERS as $name => $adapter ) {
			$arg = 'url' === $adapter['by'] ? $url : $post_id;
			if ( isset( $adapter['action'] ) ) {
				if ( defined( $adapter['if'] ) ) {
					do_action( $adapter['action'], $arg ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own public hook.
					$ran[] = $name;
				}
			} elseif ( function_exists( $adapter['if'] ) ) {
				call_user_func( $adapter['if'], $arg );
				$ran[] = $name;
			}
		}
		/**
		 * Fires after a post's cached page has been emptied by this module, so a
		 * cache that is not among the adapters can empty its own copy.
		 *
		 * @param int    $post_id The post.
		 * @param string $url     Its URL, exactly as get_permalink() returns it.
		 */
		do_action( 'estrategia_web_purge_post', $post_id, $url );
		return $ran;
	}
}
