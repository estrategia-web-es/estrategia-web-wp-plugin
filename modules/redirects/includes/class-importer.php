<?php
/**
 * Saving rules: one from the screen or many from a CSV. Both go through
 * Rules::validate with ALL the other rules in view (duplicates and loops),
 * so whatever can't get in through the screen can't get in through the CSV
 * either.
 *
 * Importing is IDEMPOTENT: a source that already has a rule is updated
 * (target, type, note, active) and keeps its counter; importing the same
 * file twice leaves the same result («unchanged»). That is why it works for
 * deploying: `wp estrategia-web redirects import file.csv` on every deploy
 * duplicates nothing. What it does NOT do is delete rules that aren't in the
 * file: the file adds and corrects, it doesn't replace.
 *
 * Everything is computed in memory first and only written afterwards: with
 * --dry-run (or $dry_run) the report is exactly what would happen, without
 * touching the database. A bad row doesn't stop the others; it shows up in
 * the report with its line.
 *
 * Edge case: two rows of the same file with the same source. The second is
 * an error (duplicate) even if it says the same thing: it doesn't happen in
 * Lavandería's file, and if it does, someone copied one row too many.
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
	 * Saves a rule from the screen.
	 *
	 * @param array{source: string, target: string, type: mixed, note?: string, active?: bool} $input
	 * @return array{0: int, 1: string} [ id (0 if not saved), error code ]
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

	/** CSV with all rules, in the format import() reads. */
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

	/** The text for an error code from Rules::validate. */
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
