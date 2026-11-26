<?php
namespace EstrategiaWeb\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Reads several checks share (gather() only; judge() never calls this).
 *
 * How to maintain:
 * - Who and when: a read goes here when a SECOND check needs it; a read only
 *   one check uses stays in that check's file, next to its criterion.
 * - Criterion: every query is capped (LIMIT) and reads published content
 *   only. The Site Health screen must open in the same time on a site with
 *   ten pages and on one with ten thousand.
 * - Edge case, by name: pages_with() matches the block's comment
 *   («<!-- wp:estrategia-web/cookie-table»), so a block inside a SYNCED
 *   pattern is not found on the page that uses the pattern. The pattern
 *   itself (wp_block) is searched too, so «there is one» stays true.
 * - The trap: post_content LIKE with esc_like(); without it the «_» of a
 *   block name is a wildcard and «%» in a slug breaks the pattern.
 */
final class Probe {

	/** Published pages, posts and synced patterns whose content contains $needle (capped). @return \WP_Post[] */
	public static function pages_with( string $needle, int $limit = 20 ): array {
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a LIKE over post_content has no API; capped, only on the Site Health screen.
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post','wp_block') AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
				'%' . $wpdb->esc_like( $needle ) . '%',
				$limit
			)
		);
		return array_values( array_filter( array_map( 'get_post', array_map( 'intval', $ids ) ) ) );
	}

	/** Whether a module of this plugin is loaded in this request. */
	public static function module_on( string $slug ): bool {
		return class_exists( \EstrategiaWeb\Modules::class ) && \EstrategiaWeb\Modules::is_active( $slug );
	}

	/** The active plugins' main files, site and network. @return string[] */
	public static function active_plugins(): array {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_values( array_unique( array_map( 'strval', $plugins ) ) );
	}

	/** The site's language is English (en, en_US, en_GB…). */
	public static function english_site(): bool {
		return str_starts_with( (string) get_locale(), 'en' );
	}
}
