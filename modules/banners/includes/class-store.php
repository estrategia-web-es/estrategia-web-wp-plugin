<?php
/**
 * The statistics table: one row per banner and day, with views and clicks.
 *
 *   {prefix}estrategia_web_banners_stats  (banner_id, day, views, clicks)
 *
 * The day is the SITE's day (wp_date), not UTC: «how many on Thursday» is
 * asked in local time. Created idempotently on every load (maybe_install on
 * plugins_loaded), like redirects: enabling the module does not go through
 * plugin activation.
 *
 * How to maintain:
 * - A change of shape: schema below and DB_VERSION + 1, in the same commit.
 * - The trap (shared with redirects): dbDelta is fussy (two spaces after
 *   PRIMARY KEY, one column per line) and says nothing when it dislikes
 *   something; and in Playground the database is SQLite, so no ON DUPLICATE
 *   KEY UPDATE: UPDATE, and INSERT if nothing was touched.
 */

namespace EstrategiaWeb\Banners;

defined( 'ABSPATH' ) || exit;

final class Store {

	public const DB_VERSION     = 1;
	public const DB_VERSION_OPT = 'estrategia_web_banners_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'estrategia_web_banners_stats';
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_VERSION_OPT, 0 ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
banner_id bigint(20) unsigned NOT NULL,
day date NOT NULL,
views bigint(20) unsigned NOT NULL DEFAULT 0,
clicks bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (banner_id,day),
KEY day (day)
) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_VERSION_OPT, self::DB_VERSION, true );
	}

	/**
	 * Adds views and clicks to a banner's day (today by default).
	 */
	public static function add( int $banner_id, int $views, int $clicks, string $day = '' ): void {
		global $wpdb;
		$day     = '' === $day ? wp_date( 'Y-m-d' ) : $day;
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET views = views + %d, clicks = clicks + %d WHERE banner_id = %d AND day = %s', $views, $clicks, $banner_id, $day ) ); // phpcs:ignore WordPress.DB
		if ( ! $updated ) {
			$wpdb->insert( self::table(), array( 'banner_id' => $banner_id, 'day' => $day, 'views' => $views, 'clicks' => $clicks ), array( '%d', '%s', '%d', '%d' ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Deletes every row of a banner (the migration replaces its history whole).
	 */
	public static function forget( int $banner_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'banner_id' => $banner_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Totals per banner.
	 *
	 * @param int[] $ids
	 * @return array<int, array{views: int, clicks: int}>
	 */
	public static function totals( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT banner_id, SUM(views) AS views, SUM(clicks) AS clicks FROM ' . self::table() . " WHERE banner_id IN ($in) GROUP BY banner_id", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['banner_id'] ] = array( 'views' => (int) $row['views'], 'clicks' => (int) $row['clicks'] );
		}
		return $out;
	}

	/**
	 * A banner's days, newest first.
	 *
	 * @return array<int, array{day: string, views: int, clicks: int}>
	 */
	public static function days( int $banner_id, int $limit = 60 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, views, clicks FROM ' . self::table() . ' WHERE banner_id = %d ORDER BY day DESC LIMIT %d', $banner_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( static fn( array $r ): array => array( 'day' => (string) $r['day'], 'views' => (int) $r['views'], 'clicks' => (int) $r['clicks'] ), $rows ?: array() );
	}
}
