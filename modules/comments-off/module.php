<?php
namespace EstrategiaWeb\CommentsOff;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: No comments
 * Description: Turns comments off everywhere: closed on every post, no Comments menu, dashboard widget or toolbar item, no comment blocks on the site and no comment feeds.
 * Default: off
 * Requires:
 * Category: Content
 *
 * For business sites without a blog (Lavandería: comments had already been
 * closed by hand with WP-CLI). Everything happens on the fly, with filters:
 * it writes NOTHING to the database. Switching the module off returns each
 * post to its usual comment_status, and any existing comments are still there.
 *
 * Why it ships off: it changes something visible (a menu disappears, a blog
 * that had comments open gets them closed). With "on" it would switch itself
 * on in every deployed site that already has its module list saved (edge
 * case in includes/class-modules.php), and a site with a blog would lose its
 * comments without anyone deciding it.
 *
 * What it removes, and how:
 * - Open/closed: comments_open and pings_open to false, and the defaults
 *   for new posts (default_comment_status and default_ping_status) to
 *   "closed" without touching the saved option.
 * - Existing comments are not rendered (empty comments_array, and 0 from
 *   get_comments_number).
 * - "comments" and "trackbacks" support on ALL post types (removes the
 *   column and the editor panel), late on init to catch the types
 *   registered by the theme and plugins.
 * - Dashboard: the Comments menu (and visiting edit-comments.php leads to
 *   the Dashboard), the comments in the Activity widget and the counts in
 *   "At a Glance"; the bubble in the admin bar.
 * - Front end: the comment blocks (core/comments with everything inside it,
 *   the form, the count and the link, Latest Comments) are not rendered even
 *   if they are in a theme template.
 * - Feeds: no link to the comments feed in <head>, and requesting it gives 404.
 * - XML-RPC: pingback.ping and the X-Pingback header are removed.
 *
 * How to maintain:
 * - Who: whoever touches the plugin. When: if WordPress adds a new comments
 *   block (today's: those starting with core/comment and core/post-comments,
 *   plus core/latest-comments), it goes into BLOCKS.
 * - Criterion: anything that lets someone see or write a comment goes in.
 *   The "Settings → Discussion" screen stays: it is WordPress' own and does
 *   no harm, and with the module on its values simply do not apply.
 * - Edge case, by name: the /wp/v2/comments REST route stays registered
 *   (the editor uses it for WordPress 6.9 block notes, which are comments of
 *   type "note"), but commenting is impossible because comments_open is
 *   false. That is why REST routes are NOT removed here.
 * - The pitfall: remove_post_type_support on init at priority 10 does not
 *   see types registered later on init; that is why it runs at 100 and also
 *   on registered_post_type for those arriving even later.
 * - Test: modules/comments-off/tests/comments-off.test.mjs (level 2).
 */

/** Blocks that are not rendered: by name prefix. */
const BLOCKS = array( 'core/comment', 'core/post-comments', 'core/latest-comments' );

add_filter( 'comments_open', '__return_false', 20 );
add_filter( 'pings_open', '__return_false', 20 );
add_filter( 'pre_option_default_comment_status', static fn() => 'closed' );
add_filter( 'pre_option_default_ping_status', static fn() => 'closed' );
add_filter( 'comments_array', static fn() => array(), 20 );
add_filter( 'get_comments_number', static fn() => 0, 20 );

$estrategia_web_no_support = static function ( $post_type ): void {
	if ( post_type_supports( (string) $post_type, 'comments' ) ) {
		remove_post_type_support( (string) $post_type, 'comments' );
	}
	if ( post_type_supports( (string) $post_type, 'trackbacks' ) ) {
		remove_post_type_support( (string) $post_type, 'trackbacks' );
	}
};
add_action(
	'init',
	static function () use ( $estrategia_web_no_support ): void {
		array_map( $estrategia_web_no_support, get_post_types() );
	},
	100
);
add_action( 'registered_post_type', $estrategia_web_no_support );

// Dashboard.
add_action(
	'admin_menu',
	static function (): void {
		remove_menu_page( 'edit-comments.php' );
	},
	999
);
add_action(
	'admin_init',
	static function (): void {
		global $pagenow;
		if ( 'edit-comments.php' === $pagenow || 'comment.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}
);
add_action(
	'wp_dashboard_setup',
	static function (): void {
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}
);
// The Activity widget only renders recent comments if the query returns any.
add_filter( 'dashboard_recent_comments_query_args', static fn( $args ) => array_merge( (array) $args, array( 'comment__in' => array( 0 ) ) ) );
add_action(
	'admin_head-index.php',
	static function (): void {
		echo '<style>#dashboard_right_now .comment-count,#dashboard_right_now .comment-mod-count{display:none}</style>';
	}
);
add_action(
	'admin_bar_menu',
	static function ( $bar ): void {
		$bar->remove_node( 'comments' );
	},
	999
);

// Front end: blocks and feeds.
add_filter(
	'render_block',
	static function ( $content, $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		foreach ( BLOCKS as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return '';
			}
		}
		return $content;
	},
	10,
	2
);
add_filter( 'feed_links_show_comments_feed', '__return_false' );
add_filter( 'feed_links_extra_show_post_comments_feed', '__return_false' );
add_action(
	'template_redirect',
	static function (): void {
		if ( is_comment_feed() ) {
			wp_die( esc_html__( 'Comments are closed.', 'estrategia-web' ), '', array( 'response' => 404 ) );
		}
	},
	1
);

// XML-RPC.
add_filter(
	'xmlrpc_methods',
	static function ( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}
);
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);
