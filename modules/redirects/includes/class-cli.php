<?php
/**
 * Redirecciones desde WP-CLI. Pensado para desplegar: el CSV vive en el repo
 * de la web y el desplegador lo importa (idempotente: ver Importer).
 *
 *   wp estrategia-web redirects import fichero.csv [--dry-run]
 *   wp estrategia-web redirects list [--search=<texto>] [--format=table|csv|json|count]
 *   wp estrategia-web redirects export [<fichero>]
 *
 * Solo existe con el módulo encendido (un módulo apagado no carga nada). Si
 * `wp estrategia-web redirects` dice que no existe, es eso: encenderlo con
 * `wp option update estrategia_web_modules '[…,"redirects"]' --format=json`
 * o en la pantalla de Módulos.
 *
 * Sale con error (código 1) si alguna fila del CSV no entra: un despliegue
 * no puede dar por buena una importación a medias sin que nadie lo lea.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Importa reglas de un CSV (origen,destino,tipo,motivo…). Actualiza las que ya existen.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Ruta del CSV.
	 *
	 * [--dry-run]
	 * : Dice qué haría sin escribir nada.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "No se puede leer $file" );
		}
		$dry    = ! empty( $assoc['dry-run'] );
		$report = Importer::import( (string) file_get_contents( $file ), $dry );
		foreach ( $report['errors'] as $e ) {
			\WP_CLI::warning( sprintf( 'línea %d (%s): %s', $e['line'], $e['source'], $e['message'] ) );
		}
		$summary = sprintf( '%s%d nuevas, %d actualizadas, %d sin cambios, %d con error.', $dry ? '[--dry-run] ' : '', $report['created'], $report['updated'], $report['unchanged'], count( $report['errors'] ) );
		if ( $report['errors'] ) {
			\WP_CLI::error( $summary );
		}
		\WP_CLI::success( $summary );
	}

	/**
	 * Lista las reglas.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Filtra por origen, destino o nota.
	 *
	 * [--format=<format>]
	 * : table, csv, json o count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function list_( array $args, array $assoc ): void {
		list( $rows ) = Store::query( (string) ( $assoc['search'] ?? '' ) );
		$fields       = array( 'id', 'source', 'target', 'type', 'active', 'hits', 'last_hit', 'note' );
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, $fields );
	}

	/**
	 * Exporta las reglas a CSV (a la salida, o a un fichero).
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Fichero de salida. Sin él, a la salida estándar.
	 *
	 * @param string[] $args
	 */
	public function export( array $args ): void {
		$csv = Importer::export();
		if ( isset( $args[0] ) ) {
			file_put_contents( $args[0], $csv );
			\WP_CLI::success( 'Exportado a ' . $args[0] );
			return;
		}
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV a la consola.
	}
}
