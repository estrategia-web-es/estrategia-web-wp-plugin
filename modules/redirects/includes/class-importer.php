<?php
/**
 * Guardar reglas: una desde la pantalla o muchas desde un CSV. Las dos pasan
 * por Rules::validate con TODAS las demás reglas delante (duplicados y
 * bucles), así que lo que no entra por la pantalla tampoco entra por el CSV.
 *
 * Importar es IDEMPOTENTE: un origen que ya tiene regla se actualiza
 * (destino, tipo, nota, activa) y conserva su contador; importar dos veces
 * el mismo fichero deja lo mismo («sin cambios»). Por eso sirve para
 * desplegar: `wp estrategia-web redirects import fichero.csv` en cada
 * despliegue no duplica nada. Lo que NO hace es borrar las reglas que no
 * están en el fichero: el fichero añade y corrige, no sustituye.
 *
 * Todo se calcula primero en memoria y solo después se escribe: con
 * --dry-run (o $dry_run) el informe es exactamente el que saldría, sin tocar
 * la base. Una fila mala no para las demás; sale en el informe con su línea.
 *
 * Caso frontera: dos filas del mismo fichero con el mismo origen. La segunda
 * es un error (duplicate) aunque diga lo mismo: en el fichero de Lavandería
 * no pasa, y si pasa es que alguien copió una fila de más.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Importer {

	/**
	 * @return array{created: int, updated: int, unchanged: int, errors: array<int, array{line: int, source: string, code: string, message: string}>}
	 */
	public static function import( string $csv, bool $dry_run = false ): array {
		$parsed = Rules::parse_csv( $csv );
		$all    = Store::all_by_key();
		$report = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'errors'    => array(),
		);
		$ops    = array();
		$in_csv = array();
		foreach ( $parsed['rows'] as $row ) {
			$key    = Rules::key( $row['source'], Store::base() );
			$others = $all;
			$own    = $others[ $key ] ?? null;
			unset( $others[ $key ] );
			list( $rule, $code ) = isset( $in_csv[ $key ] )
				? array( null, 'duplicate' )
				: Rules::validate( $row, $others, Store::home(), Store::base() );
			if ( ! $rule ) {
				$report['errors'][] = array(
					'line'    => $row['line'],
					'source'  => $row['source'],
					'code'    => $code,
					'message' => self::message( $code ),
				);
				continue;
			}
			$in_csv[ $key ] = true;
			if ( $own && $own['target'] === $rule['target'] && $own['type'] === $rule['type'] && $own['note'] === $rule['note'] && $own['active'] === $rule['active'] && $own['source'] === $rule['source'] ) {
				++$report['unchanged'];
				continue;
			}
			++$report[ $own ? 'updated' : 'created' ];
			$ops[]       = array( $rule, $own ? $own['id'] : 0 );
			$all[ $key ] = array(
				'id'     => $own ? $own['id'] : 0,
				'key'    => $key,
				'source' => $rule['source'],
				'target' => $rule['target'],
				'type'   => $rule['type'],
				'active' => $rule['active'],
				'note'   => $rule['note'],
			);
		}
		if ( ! $dry_run ) {
			foreach ( $ops as list( $rule, $id ) ) {
				Store::save( $rule, $id );
			}
		}
		return $report;
	}

	/**
	 * Guarda una regla desde la pantalla.
	 *
	 * @param array{source: string, target: string, type: mixed, note?: string, active?: bool} $input
	 * @return array{0: int, 1: string} [ id (0 si no se guardó), código de error ]
	 */
	public static function save_one( array $input, int $id = 0 ): array {
		list( $rule, $code ) = Rules::validate( $input, Store::all_by_key( $id ), Store::home(), Store::base() );
		if ( ! $rule ) {
			return array( 0, $code );
		}
		$id = Store::save( $rule, $id );
		Store::log_forget( $rule['key'] );
		return array( $id, '' );
	}

	/** CSV con todas las reglas, en el formato que lee import(). */
	public static function export(): string {
		list( $rows ) = Store::query( '', 0, 1, 'id', 'asc' );
		$fh           = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, array( 'origen', 'destino', 'tipo', 'motivo', 'activa', 'visitas', 'ultima_visita' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv(
				$fh,
				array(
					Rules::cell( (string) $row['source'] ),
					Rules::cell( (string) $row['target'] ),
					(int) $row['type'],
					Rules::cell( (string) $row['note'] ),
					$row['active'] ? '1' : '0',
					(int) $row['hits'],
					(string) $row['last_hit'],
				),
				',',
				'"',
				''
			);
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/** El texto de un código de error de Rules::validate. */
	public static function message( string $code ): string {
		switch ( $code ) {
			case 'empty_source':
				return __( 'The source is empty.', 'estrategia-web' );
			case 'foreign_source':
				return __( 'The source is an address of another website. Write the path only (/old-page/).', 'estrategia-web' );
			case 'home_source':
				return __( 'The home page itself cannot be redirected.', 'estrategia-web' );
			case 'bad_type':
				return __( 'The type must be 301, 302 or 410.', 'estrategia-web' );
			case 'empty_target':
				return __( 'A 301 or 302 redirect needs a destination.', 'estrategia-web' );
			case 'bad_target':
				return __( 'The destination must be a path starting with / or a full http(s) address, without spaces.', 'estrategia-web' );
			case 'same_target':
				return __( 'The destination is the same as the source.', 'estrategia-web' );
			case 'duplicate':
				return __( 'There is already a rule for this source.', 'estrategia-web' );
			case 'loop':
				return __( 'This rule would create a redirect loop with other rules.', 'estrategia-web' );
		}
		return $code;
	}
}
