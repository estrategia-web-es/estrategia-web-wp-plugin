<?php
/**
 * The submissions log: reading and writing the plugin's own table.
 *
 * Why a table and not a private post type (CPT):
 * - Filtering by form, sorting by date, exporting and purging by age are ONE
 *   SQL query; with a CPT the data would go to postmeta (one row per field)
 *   and every filter would be a JOIN.
 * - WPML treats post types as translatable content: a submissions CPT would
 *   show up in its translation management and would have to be excluded by
 *   hand on every site. A table of our own is invisible to it.
 * - A submission is not content: it has no revisions, no author, no public
 *   link. A CPT brings all that and it has to be switched off piece by piece.
 * The price: we maintain the table's shape ourselves (Migrations).
 *
 * How to maintain: each row carries format_version. If the shape of data or
 * notifications changes, bump FORMAT, add a migration that converts the old
 * rows, and decode() keeps understanding both until the migration has run
 * on every site.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Submissions {

	public const FORMAT = 1;

	public static function insert( array $row ): int {
		global $wpdb;
		$wpdb->insert(
			Migrations::submissions_table(),
			array(
				'form_id'         => (string) $row['form_id'],
				'form_title'      => (string) $row['form_title'],
				'definition_hash' => (string) $row['definition_hash'],
				'post_id'         => (int) $row['post_id'],
				'page_url'        => substr( (string) $row['page_url'], 0, 2048 ),
				'lang'            => (string) $row['lang'],
				'created_at'      => current_time( 'mysql', true ),
				'status'          => (string) $row['status'],
				'data'            => (string) wp_json_encode( $row['data'] ),
				'notifications'   => (string) wp_json_encode( $row['notifications'] ),
				'ip'              => (string) $row['ip'],
				'user_agent'      => substr( (string) $row['user_agent'], 0, 255 ),
				'format_version'  => self::FORMAT,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function update_notifications( int $id, string $status, array $notifications ): void {
		global $wpdb;
		$wpdb->update(
			Migrations::submissions_table(),
			array(
				'status'        => $status,
				'notifications' => (string) wp_json_encode( $notifications ),
			),
			array( 'id' => $id )
		);
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Migrations::submissions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decode( $row ) : null;
	}

	/** The notification states a submission can be in (the «status» column), in the order the list's views show them. */
	public const STATUSES = array( 'failed', 'partial', 'pending', 'sent' );

	/** What the list sorts by (2.8.0): column => SQL. «form» is the title shown, or the id when the form had no title. */
	public const ORDER_BY = array(
		'date' => 'created_at',
		'form' => "COALESCE(NULLIF(form_title, ''), form_id)",
	);

	/**
	 * @param string $status  '' or one of STATUSES (2.8.0, the list's views).
	 * @param string $orderby A key of ORDER_BY; anything else is the date.
	 * @param string $order   'asc' or 'desc' (the default: newest first).
	 * @return array{rows: array<int, array>, total: int}
	 */
	public static function query( string $form_id = '', int $per_page = 20, int $page = 1, string $status = '', string $orderby = 'date', string $order = 'desc' ): array {
		global $wpdb;
		$table  = Migrations::submissions_table();
		$where  = '1=1';
		$params = array();
		if ( '' !== $form_id ) {
			$where   .= ' AND form_id = %s';
			$params[] = $form_id;
		}
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Both come from the two lists above, never from the request: safe to write into the SQL.
		$by    = self::ORDER_BY[ $orderby ] ?? self::ORDER_BY['date'];
		$dir   = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';
		$limit = $per_page > 0 ? $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, ( $page - 1 ) * $per_page ) ) : '';
		$sql   = "SELECT * FROM $table WHERE $where ORDER BY $by $dir, id $dir" . $limit;
		$rows   = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => array_map( array( self::class, 'decode' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * How many submissions there are in each notification state (2.8.0, the list's views), of one form or all.
	 *
	 * @return array<string, int> status => count, only the states that have any.
	 */
	public static function count_by_status( string $form_id = '' ): array {
		global $wpdb;
		$table = Migrations::submissions_table();
		$rows  = '' !== $form_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM $table WHERE form_id = %s GROUP BY status", $form_id ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** @return array<string, string> form_id => most recent title. */
	public static function forms(): array {
		global $wpdb;
		$table = Migrations::submissions_table();
		$rows  = $wpdb->get_results( "SELECT form_id, form_title FROM $table ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$forms = array();
		foreach ( (array) $rows as $row ) {
			$forms[ $row['form_id'] ] = '' !== $row['form_title'] ? $row['form_title'] : $row['form_id'];
		}
		return $forms;
	}

	/** @param int[] $ids */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$table        = Migrations::submissions_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Deletes the submissions older than $days days. Returns how many. */
	public static function purge_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$table  = Migrations::submissions_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function decode( array $row ): array {
		$row['data']          = json_decode( (string) $row['data'], true ) ?: array();
		$row['notifications'] = json_decode( (string) $row['notifications'], true ) ?: array();
		$row['id']            = (int) $row['id'];
		return $row;
	}
}
