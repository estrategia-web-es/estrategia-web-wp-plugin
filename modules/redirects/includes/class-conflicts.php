<?php
/**
 * Is another redirect system running on the site? If so, the screen warns at
 * the top: two systems at once step on each other and nobody knows which one
 * sent the visitor where.
 *
 * How to maintain:
 * - A new plugin to detect: one more entry in detect(), checking a constant
 *   or class the plugin ALWAYS defines when active (not its folder: it may be
 *   uploaded and deactivated). If it is third-party with a module that can be
 *   switched off (Rank Math), also check that the module is on, or the
 *   warning will show on sites where nothing is wrong.
 * - Since 2.0.1 Rank Math (with its Redirections module on) and Yoast SEO
 *   Premium are not conflicts but OWNERS: see owner() below. The warning is
 *   for the rest (Redirection, Safe Redirect Manager, AIOSEO Pro).
 * - The trap: Rank Math stores its enabled modules in the rank_math_modules
 *   option (list of slugs); the redirects one is «redirections», with -ions.
 *   With Rank Math installed but that module off, ours works.
 * - Filter estrategia_web_redirects_conflicts so a site can add its own.
 *
 * OWNER (2.0.1, David, 28-10-2026: «the module stays, but steps aside»).
 * When the site's SEO plugin has its OWN redirect manager switched on, that
 * plugin owns the redirects and this module switches itself off: no rules
 * applied, no 404 log, no screen, no WP-CLI (module.php asks owner() on
 * plugins_loaded), and the Modules screen says so («Redirects are managed by
 * Rank Math»). Its tables and rules are kept: turning the other one off
 * brings this one back as it was. The two that own:
 * - Rank Math (FREE has redirections; verified 28-10-2026 in its own code,
 *   seo-by-rank-math 1.0.280 from wordpress.org: the module «redirections»
 *   is registered in includes/module/class-manager.php, class
 *   RankMath\Redirections\Redirections, and RankMath\Helper::is_module_active()
 *   is in_array( $id, get_option( 'rank_math_modules' ) ) once the plugin is
 *   loaded). So: RANK_MATH_VERSION defined (Rank Math loaded, not just
 *   uploaded) AND «redirections» in rank_math_modules. With the module off
 *   in Rank Math's dashboard, ours works.
 * - Yoast SEO PREMIUM (Yoast FREE has no redirects: its «Redirects» menu
 *   item is an upsell with a Premium badge, registered only while Premium is
 *   inactive — wordpress-seo 28.8, src/integrations/admin/
 *   redirects-page-integration.php, Premium_Inactive_Conditional — and its
 *   notices sell Premium as «a redirect manager»). Premium's Redirect manager
 *   has no off switch (none in Yoast's docs, 28-10-2026), so: Premium active
 *   = it owns them. Detected by WPSEO_PREMIUM_FILE or WPSEO_PREMIUM_VERSION,
 *   the constants free Yoast itself checks (src/helpers/product-helper.php).
 * - The trap: plugin files load alphabetically AFTER ours (estrategia-web <
 *   seo-by-rank-math, wordpress-seo-premium), so RANK_MATH_VERSION does not
 *   exist yet while the modules load. owner() is only meaningful from
 *   plugins_loaded on, which is where module.php asks.
 * - Edge case, by name: Lavandería (Rank Math with Redirections on, 83
 *   rules there). With this module switched on by mistake it now does
 *   nothing and says why, instead of fighting Rank Math for the same URLs.
 * - Filter estrategia_web_redirects_owner (string, '' = nobody) for another
 *   system a site wants to give the redirects to.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Conflicts {

	/**
	 * The SEO plugin that manages the redirects instead of this module, or ''.
	 * Call it from plugins_loaded on (header, «The trap»).
	 */
	public static function owner(): string {
		$owner = '';
		if ( defined( 'RANK_MATH_VERSION' ) && in_array( 'redirections', (array) get_option( 'rank_math_modules', array() ), true ) ) {
			$owner = 'Rank Math';
		} elseif ( defined( 'WPSEO_PREMIUM_FILE' ) || defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			$owner = 'Yoast SEO Premium';
		}
		/**
		 * Who manages the site's redirects instead of this module ('' = this module).
		 *
		 * @param string $owner Name shown on the Modules screen.
		 */
		return (string) apply_filters( 'estrategia_web_redirects_owner', $owner );
	}

	/** @return string[] Names of the redirect systems active besides this one (the owner, if any, is not one: then this module is off). */
	public static function detect(): array {
		$found = array();
		if ( defined( 'REDIRECTION_VERSION' ) ) {
			$found[] = 'Redirection';
		}
		if ( class_exists( 'SRM_Redirect' ) || defined( 'SRM_VERSION' ) ) {
			$found[] = 'Safe Redirect Manager';
		}
		if ( defined( 'AIOSEO_VERSION' ) && function_exists( 'aioseo' ) && class_exists( 'AIOSEO\Plugin\Pro\Redirects\Redirects' ) ) {
			$found[] = 'All in One SEO Pro';
		}
		/**
		 * Other redirect systems active on the site.
		 *
		 * @param string[] $found Names for the warning.
		 */
		return array_values( array_unique( (array) apply_filters( 'estrategia_web_redirects_conflicts', $found ) ) );
	}
}
