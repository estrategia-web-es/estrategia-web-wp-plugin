<?php
/**
 * The module's two tables and everything that touches them.
 *
 *   {prefix}estrategia_web_redirects      one row per rule
 *   {prefix}estrategia_web_redirects_404  the 404 log (if enabled)
 *
 * They are created IDEMPOTENTLY ON EVERY LOAD (maybe_install on
 * plugins_loaded), because enabling the module from the screen does not go
 * through plugin activation (the trap in includes/class-modules.php).
 *
 * How to maintain:
 * - Who and when: whoever changes a table's shape, in the same commit as the
 *   code that uses it: edit schema() and bump DB_VERSION. dbDelta adds
 *   columns and indexes; it does not drop or rename (that is done by hand,
 *   here).
 * - Edge case: source_hash is the md5 of the normalised KEY (Rules::key), not
 *   of the raw source. If the normalisation criterion ever changes, the
 *   stored keys go stale: they must be recomputed (rehash()) in the same
 *   DB_VERSION bump.
 * - The trap: dbDelta is fussy (two spaces after PRIMARY KEY, one column per
 *   line, no backticks). If it doesn't like something, it doesn't warn: it
 *   creates nothing. And in Playground the database is SQLite (WordPress's
 *   translator): queries here are limited to what it translates well (no
 *   ON DUPLICATE KEY UPDATE: UPDATE and, if nothing was touched, INSERT).
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Store {

	public const DB_VERSION     = 1;
	public const DB_VERSION_OPT = 'estrategia_web_redirects_db_version';

	/** Cap on the 404 log: a burst of bots doesn't fill the database. Once reached, only existing rows are counted. */
	public const LOG_MAX_ROWS = 2000;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'estrategia_web_redirects';
	}

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'estrategia_web_redirects_404';
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::DB_VERSION_OPT, 0 ) >= self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$rules   = self::table();
		$log     = self::log_table();
		dbDelta(
			"CREATE TABLE {$rules} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source varchar(2048) NOT NULL DEFAULT '',
source_hash char(32) NOT NULL DEFAULT '',
target varchar(2048) NOT NULL DEFAULT '',
type smallint(5) unsigned NOT NULL DEFAULT 301,
active tinyint(1) unsigned NOT NULL DEFAULT 1,
note varchar(500) NOT NULL DEFAULT '',
hits bigint(20) unsigned NOT NULL DEFAULT 0,
last_hit datetime NULL DEFAULT NULL,
created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
PRIMARY KEY  (id),
UNIQUE KEY source_hash (source_hash)
) {$charset};
CREATE TABLE {$log} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
path varchar(2048) NOT NULL DEFAULT '',
path_hash char(32) NOT NULL DEFAULT '',
hits bigint(20) unsigned NOT NULL DEFAULT 0,
first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
PRIMARY KEY  (id),
UNIQUE KEY path_hash (path_hash),
KEY last_seen (last_seen)
) {$charset};"
		);
		// Autoloaded ON PURPOSE (forms doesn't do this): it is read on every
		// request, and without autoload it is one more query on every page of the site.
		update_option( self::DB_VERSION_OPT, self::DB_VERSION, true );
	}

	/** Install path («/blog» if WordPress lives in a subfolder; '' otherwise). */
	public static function base(): string {
		return untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );
	}

	/** The site's domain, to tell whether an absolute target is internal. */
	public static function home(): string {
		return (string) get_option( 'home' );
	}

	/**
	 * The active rule matching a request, or null. ONE query on the unique
	 * index (source_hash IN (…), at most MAX_PARAMS+3 keys).
	 *
	 * @return array{id: int, source: string, target: string, type: int}|null
	 */
	public static function match( string $request_uri ): ?array {
		global $wpdb;
		$keys   = Rules::candidates( $request_uri, self::base() );
		$hashes = array_map( array( Rules::class, 'hash' ), $keys );
		$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, source_hash, target, type FROM ' . self::table() . " WHERE active = 1 AND source_hash IN ($in)", $hashes ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return null;
		}
		$by_hash = array_column( $rows, null, 'source_hash' );
		foreach ( $hashes as $hash ) { // candidates() order: most to least specific.
			if ( isset( $by_hash[ $hash ] ) ) {
				$row = $by_hash[ $hash ];
				return array(
					'id'     => (int) $row['id'],
					'source' => (string) $row['source'],
					'target' => (string) $row['target'],
					'type'   => (int) $row['type'],
				);
			}
		}
		return null;
	}

	public static function hit( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1, last_hit = %s WHERE id = %d', current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<string, mixed>|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/**
	 * All rules by key, for validation (loops, duplicates). There are few
	 * (hundreds): they are fetched whole instead of following the chain with
	 * one query per hop.
	 *
	 * @return array<string, array{id: int, key: string, source: string, target: string, type: int, active: bool, note: string}>
	 */
	public static function all_by_key( int $except_id = 0 ): array {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results( 'SELECT id, source, target, type, active, note FROM ' . self::table() . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			if ( (int) $row['id'] === $except_id ) {
				continue;
			}
			$key         = Rules::key( (string) $row['source'], self::base() );
			$out[ $key ] = array(
				'id'     => (int) $row['id'],
				'key'    => $key,
				'source' => (string) $row['source'],
				'target' => (string) $row['target'],
				'type'   => (int) $row['type'],
				'active' => (bool) $row['active'],
				'note'   => (string) $row['note'],
			);
		}
		return $out;
	}

	/**
	 * Saves an ALREADY VALIDATED rule (Rules::validate). With $id it updates.
	 *
	 * @param array{source: string, key: string, target: string, type: int, note: string, active: bool} $rule
	 * @return int The id.
	 */
	public static function save( array $rule, int $id = 0 ): int {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array(
			'source'      => $rule['source'],
			'source_hash' => Rules::hash( $rule['key'] ),
			'target'      => $rule['target'],
			'type'        => $rule['type'],
			'active'      => $rule['active'] ? 1 : 0,
			'note'        => mb_substr( $rule['note'], 0, 500 ),
			'updated'     => $now,
		);
		if ( $id ) {
			$wpdb->update( self::table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			return $id;
		}
		$data['created'] = $now;
		$wpdb->insert( self::table(), $data ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/** @param int[] $ids */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB
	}

	/** @param int[] $ids */
	public static function set_active( array $ids, bool $active ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET active = %d WHERE id IN (' . implode( ',', $ids ) . ')', $active ? 1 : 0 ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * List for the screen and the CLI.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: int} [ rows, total ]
	 */
	public static function query( string $search = '', int $per_page = 0, int $page = 1, string $orderby = 'source', string $order = 'asc' ): array {
		global $wpdb;
		$where = '1=1';
		$args  = array();
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
			$args  = array( $like, $like, $like );
		}
		$orderby = in_array( $orderby, array( 'source', 'target', 'type', 'hits', 'last_hit', 'active', 'id' ), true ) ? $orderby : 'source';
		$order   = 'desc' === strtolower( $order ) ? 'DESC' : 'ASC';
		$sql     = 'SELECT * FROM ' . self::table() . " WHERE $where ORDER BY $orderby $order, id ASC";
		$count   = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE $where";
		if ( $per_page > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, ( $page - 1 ) * $per_page ) );
		}
		$rows  = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count, $args ) ) : $wpdb->get_var( $count ) ); // phpcs:ignore WordPress.DB
		return array( (array) $rows, $total );
	}

	// ------------------------------------------------------------------
	// 404 log. Only the PATH (and the p= of old URLs): no IP, browser,
	// referer or the rest of the query, which may contain an email address.
	// ------------------------------------------------------------------

	public static function log_404( string $request_uri ): void {
		global $wpdb;
		list( $path, $params ) = Rules::split( $request_uri, self::base() );
		$key  = isset( $params['p'] ) ? $path . '?p=' . $params['p'] : $path;
		$key  = mb_substr( $key, 0, 2048 );
		$hash = Rules::hash( $key );
		$now  = current_time( 'mysql', true );
		$t    = self::log_table();
		$done = $wpdb->query( $wpdb->prepare( "UPDATE $t SET hits = hits + 1, last_seen = %s WHERE path_hash = %s", $now, $hash ) ); // phpcs:ignore WordPress.DB
		if ( $done ) {
			return;
		}
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ) >= self::LOG_MAX_ROWS ) { // phpcs:ignore WordPress.DB
			return;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (path, path_hash, hits, first_seen, last_seen) VALUES (%s, %s, 1, %s, %s)", $key, $hash, $now, $now ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array{0: array<int, array<string, mixed>>, 1: int} */
	public static function log_query( string $search = '', int $per_page = 50, int $page = 1, string $orderby = 'hits', string $order = 'desc' ): array {
		global $wpdb;
		$t       = self::log_table();
		$where   = '' !== $search ? $wpdb->prepare( 'path LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' ) : '1=1';
		$orderby = in_array( $orderby, array( 'path', 'hits', 'last_seen', 'first_seen' ), true ) ? $orderby : 'hits';
		$order   = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $where ORDER BY $orderby $order, id ASC LIMIT %d OFFSET %d", $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" ); // phpcs:ignore WordPress.DB
		return array( (array) $rows, $total );
	}

	/** Deletes from the log whatever hasn't been seen in $days days. With 0, everything. */
	public static function log_purge( int $days ): int {
		global $wpdb;
		$t = self::log_table();
		if ( $days <= 0 ) {
			return (int) $wpdb->query( "DELETE FROM $t" ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE last_seen < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}

	/** @param int[] $ids */
	public static function log_delete( array $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		return $ids ? (int) $wpdb->query( 'DELETE FROM ' . self::log_table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' ) : 0; // phpcs:ignore WordPress.DB
	}

	/** When a rule is created from the log, that path is removed from it. */
	public static function log_forget( string $key ): void {
		global $wpdb;
		$wpdb->delete( self::log_table(), array( 'path_hash' => Rules::hash( $key ) ) ); // phpcs:ignore WordPress.DB
	}
}
