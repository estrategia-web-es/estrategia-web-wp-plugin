<?php
/**
 * Redirects from WP-CLI. Meant for deployment: the CSV lives in the site's
 * repo and the deployer imports it (idempotent: see Importer).
 *
 *   wp estrategia-web redirects import file.csv [--dry-run]
 *   wp estrategia-web redirects list [--search=<text>] [--format=table|csv|json|count]
 *   wp estrategia-web redirects export [<file>]
 *
 * It only exists with the module enabled (a disabled module loads nothing).
 * If `wp estrategia-web redirects` says it doesn't exist, that's why: enable
 * it with `wp option update estrategia_web_modules '[…,"redirects"]' --format=json`
 * or on the Modules screen.
 *
 * Exits with an error (code 1) if any CSV row fails: a deployment cannot
 * accept a half-done import without anyone reading it.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Imports rules from a CSV (origen,destino,tipo,motivo…). Updates the ones that already exist.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV.
	 *
	 * [--dry-run]
	 * : Reports what it would do without writing anything.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "Cannot read $file" );
		}
		$dry    = ! empty( $assoc['dry-run'] );
		$report = Importer::import( (string) file_get_contents( $file ), $dry );
		foreach ( $report['errors'] as $e ) {
			\WP_CLI::warning( sprintf( 'line %d (%s): %s', $e['line'], $e['source'], $e['message'] ) );
		}
		$summary = sprintf( '%s%d new, %d updated, %d unchanged, %d with errors.', $dry ? '[--dry-run] ' : '', $report['created'], $report['updated'], $report['unchanged'], count( $report['errors'] ) );
		if ( $report['errors'] ) {
			\WP_CLI::error( $summary );
		}
		\WP_CLI::success( $summary );
	}

	/**
	 * Lists the rules.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Filters by source, target or note.
	 *
	 * [--format=<format>]
	 * : table, csv, json or count.
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
	 * Exports the rules to CSV (to standard output, or to a file).
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Output file. Without it, standard output.
	 *
	 * @param string[] $args
	 */
	public function export( array $args ): void {
		$csv = Importer::export();
		if ( isset( $args[0] ) ) {
			file_put_contents( $args[0], $csv );
			\WP_CLI::success( 'Exported to ' . $args[0] );
			return;
		}
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV to the console.
	}
}
