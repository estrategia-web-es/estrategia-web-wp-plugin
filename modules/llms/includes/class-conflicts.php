<?php
namespace EstrategiaWeb\Llms;

defined( 'ABSPATH' ) || exit;

/**
 * Something else that serves /llms.txt. The tab warns about it at the top;
 * nothing switches itself off (David, 31-10-2026: «like redirects», a module
 * that can be switched off and warns, so it does not compete with Rank Math).
 *
 * - Rank Math's «LLMS Txt» module. Verified 31-10-2026 in its own code,
 *   seo-by-rank-math 1.0.280 from wordpress.org: the module is registered as
 *   «llms-txt» (includes/module/class-manager.php, with a hyphen and «-txt»:
 *   NOT «llms»), class RankMath\LLMS\LLMS_Txt, and is active when that slug
 *   is in the rank_math_modules option, the same check as redirects'
 *   Conflicts::owner(). It answers on template_redirect, after ours
 *   (parse_request), so with both on ours is served and Rank Math's is hidden.
 * - A loose llms.txt file in the WordPress root. The web server serves it
 *   before WordPress runs, so it hides this module. The edge case with a name
 *   is estrategiaweb.es (header of module.php).
 *
 * How to maintain: another plugin with its own llms.txt is one more check in
 * rank_math()'s style (a constant it ALWAYS defines when loaded, plus its
 * module switch if it has one) and one more line in the tab. The trap: like
 * redirects, Rank Math loads after this plugin (alphabetical order), so
 * RANK_MATH_VERSION only exists from plugins_loaded on; the tab asks much
 * later.
 */
final class Conflicts {

	/** Rank Math is loaded and its llms.txt module is on. */
	public static function rank_math(): bool {
		return defined( 'RANK_MATH_VERSION' ) && in_array( 'llms-txt', (array) get_option( 'rank_math_modules', array() ), true );
	}

	/** Is there a llms.txt file in $root (ABSPATH)? */
	public static function loose_file( string $root ): bool {
		return is_file( trailingslashit( $root ) . 'llms.txt' );
	}
}
