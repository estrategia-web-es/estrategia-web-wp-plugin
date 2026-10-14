<?php
/**
 * Las dos tablas del módulo y todo lo que las toca.
 *
 *   {prefijo}estrategia_web_redirects      una fila por regla
 *   {prefijo}estrategia_web_redirects_404  el registro de 404 (si se enciende)
 *
 * Se crean IDEMPOTENTES EN CADA CARGA (maybe_install en plugins_loaded),
 * porque encender el módulo en la pantalla no pasa por la activación del
 * plugin (la trampa de includes/class-modules.php).
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien cambie la forma de una tabla, en el mismo commit
 *   que el código que la usa: se toca schema() y se sube DB_VERSION. dbDelta
 *   añade columnas e índices; no borra ni renombra (eso, a mano y aquí).
 * - Caso frontera: source_hash es md5 de la CLAVE normalizada
 *   (Rules::key), no del origen tal cual. Si un día cambia el criterio de
 *   normalizar, las claves guardadas quedan viejas: hay que recalcularlas
 *   (rehash()) en la misma subida de DB_VERSION.
 * - La trampa: dbDelta es quisquilloso (dos espacios tras PRIMARY KEY, una
 *   columna por línea, sin comillas invertidas). Si no le gusta, no avisa:
 *   no crea nada. Y en Playground la base es SQLite (el traductor de
 *   WordPress): las consultas de aquí se limitan a lo que traduce bien
 *   (nada de ON DUPLICATE KEY UPDATE: UPDATE y, si no tocó nada, INSERT).
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Store {

	public const DB_VERSION     = 1;
	public const DB_VERSION_OPT = 'estrategia_web_redirects_db_version';

	/** Tope del registro de 404: una ráfaga de bots no llena la base. Al llegar, solo se cuentan las que ya están. */
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
		update_option( self::DB_VERSION_OPT, self::DB_VERSION, false );
	}

	/** Ruta de la instalación («/blog» si WordPress vive en una subcarpeta; '' si no). */
	public static function base(): string {
		return untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );
	}

	/** El dominio de la web, para saber si un destino absoluto es de casa. */
	public static function home(): string {
		return (string) get_option( 'home' );
	}

	/**
	 * La regla activa que casa con una petición, o null. UNA consulta por el
	 * índice único (source_hash IN (…), como mucho MAX_PARAMS+3 claves).
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
		foreach ( $hashes as $hash ) { // El orden de candidates(): de más concreta a menos.
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
	 * Todas las reglas por clave, para validar (bucles, duplicados). Son pocas
	 * (cientos): se traen enteras en vez de seguir la cadena con una consulta
	 * por salto.
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
	 * Guarda una regla YA VALIDADA (Rules::validate). Con $id actualiza.
	 *
	 * @param array{source: string, key: string, target: string, type: int, note: string, active: bool} $rule
	 * @return int El id.
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
	 * Lista para la pantalla y el CLI.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: int} [ filas, total ]
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
	// Registro de 404. Solo la RUTA (y el p= de las URL viejas): nada de IP,
	// navegador, referer ni el resto de la query, que puede llevar un correo.
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

	/** Borra del registro lo que no se ha visto en $days días. Con 0, todo. */
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

	/** Al crear una regla desde el registro, esa ruta sale de él. */
	public static function log_forget( string $key ): void {
		global $wpdb;
		$wpdb->delete( self::log_table(), array( 'path_hash' => Rules::hash( $key ) ) ); // phpcs:ignore WordPress.DB
	}
}
