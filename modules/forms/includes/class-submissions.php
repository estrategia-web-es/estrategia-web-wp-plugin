<?php
/**
 * El registro de envíos: lectura y escritura de la tabla propia.
 *
 * Por qué tabla y no un tipo de entrada privado (CPT):
 * - Filtrar por formulario, ordenar por fecha, exportar y purgar por
 *   antigüedad son UNA consulta SQL; con un CPT los datos irían a postmeta
 *   (una fila por campo) y cada filtro sería un JOIN.
 * - WPML trata los tipos de entrada como contenido traducible: un CPT de
 *   envíos aparecería en su gestión de traducciones y habría que excluirlo
 *   a mano en cada web. Una tabla propia no la ve.
 * - Un envío no es contenido: no tiene revisiones, ni autor, ni enlace
 *   público. Un CPT trae todo eso y hay que irlo apagando.
 * El precio: la forma de la tabla la mantenemos nosotros (Migrations).
 *
 * Cómo se mantiene: cada fila lleva format_version. Si cambia la forma de
 * data o notifications, sube FORMAT, se añade una migración que convierta las
 * viejas, y decode() sigue entendiendo las dos mientras la migración no haya
 * corrido en todas las webs.
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

	/**
	 * @return array{rows: array<int, array>, total: int}
	 */
	public static function query( string $form_id = '', int $per_page = 20, int $page = 1 ): array {
		global $wpdb;
		$table  = Migrations::submissions_table();
		$where  = '1=1';
		$params = array();
		if ( '' !== $form_id ) {
			$where   .= ' AND form_id = %s';
			$params[] = $form_id;
		}
		$count_sql = "SELECT COUNT(*) FROM $table WHERE $where";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$limit  = $per_page > 0 ? $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, max( 0, ( $page - 1 ) * $per_page ) ) : '';
		$sql    = "SELECT * FROM $table WHERE $where ORDER BY created_at DESC, id DESC" . $limit;
		$rows   = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => array_map( array( self::class, 'decode' ), (array) $rows ),
			'total' => $total,
		);
	}

	/** @return array<string, string> form_id => título más reciente. */
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

	/** Borra los envíos anteriores a hace $days días. Devuelve cuántos. */
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
