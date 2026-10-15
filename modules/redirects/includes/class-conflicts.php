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
 * - Named edge case: Rank Math on Lavandería. The SEO agency uses it with its
 *   redirects module, which is why this module ships disabled (Default: off)
 *   and stays disabled there. Rank Math evaluates its rules on the «wp» hook,
 *   before this one (template_redirect): if both have a rule for the same
 *   thing, Rank Math wins.
 * - The trap: Rank Math stores its enabled modules in the rank_math_modules
 *   option (list of slugs); the redirects one is «redirections», with -ions.
 *   With Rank Math installed but that module off, there is no conflict.
 * - Filter estrategia_web_redirects_conflicts so a site can add its own.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Conflicts {

	/** @return string[] Names of the redirect systems active besides this one. */
	public static function detect(): array {
		$found = array();
		if ( defined( 'RANK_MATH_VERSION' ) && in_array( 'redirections', (array) get_option( 'rank_math_modules', array() ), true ) ) {
			$found[] = 'Rank Math';
		}
		if ( defined( 'REDIRECTION_VERSION' ) ) {
			$found[] = 'Redirection';
		}
		if ( defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			$found[] = 'Yoast SEO Premium';
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
