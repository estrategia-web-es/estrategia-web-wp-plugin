<?php
/**
 * Numbered migrations of the plugin's own tables (Armazón, row 14).
 *
 * Each migration is a file includes/migrations/NNNN-name.php that RETURNS a
 * function (\wpdb $wpdb): string. The string is what it did («created 2
 * tables», «filled 340 rows, 0 failures»), and it is written to the option
 * estrategia_web_forms_migrations_log with the date: a migration that ends
 * silently cannot be told apart from one that did not run.
 *
 * They are applied once and in order, on activation and on every load if
 * the stored version (estrategia_web_forms_db_version) is lower than LATEST.
 *
 * How to maintain:
 * - Who and when: whoever changes the shape of a table, in the same commit
 *   as the code that uses it. Add the next NNNN file and bump LATEST.
 * - Never edit a migration that has already been released: sites that
 *   applied it do not run it again. Whatever needs fixing goes in a new one.
 * - Edge case: adding a column with a default value IS a migration (dbDelta
 *   adds it, but the version has to go up for it to run).
 * - The trap: dbDelta is picky about the CREATE TABLE format (two spaces
 *   after PRIMARY KEY, one column per line, no backticks). If you do not
 *   follow it, there is no error: it simply does not alter anything.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Migrations {

	public const LATEST      = 1;
	public const VERSION_OPT = 'estrategia_web_forms_db_version';
	public const LOG_OPT     = 'estrategia_web_forms_migrations_log';

	public static function submissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'estrategia_web_forms_submissions';
	}

	public static function definitions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'estrategia_web_forms_definitions';
	}

	public static function maybe_run(): void {
		$current = (int) get_option( self::VERSION_OPT, 0 );
		if ( $current >= self::LATEST ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$files = glob( ESTRATEGIA_WEB_FORMS_DIR . 'includes/migrations/[0-9][0-9][0-9][0-9]-*.php' );
		sort( $files );
		$log = get_option( self::LOG_OPT, array() );
		$log = is_array( $log ) ? $log : array();

		foreach ( $files as $file ) {
			$number = (int) substr( basename( $file ), 0, 4 );
			if ( $number <= $current || $number > self::LATEST ) {
				continue;
			}
			$migration = require $file;
			$summary   = is_callable( $migration ) ? (string) $migration( $wpdb ) : 'not callable';
			$log[]     = array(
				'version' => $number,
				'file'    => basename( $file ),
				'at'      => gmdate( 'c' ),
				'summary' => $summary,
			);
			update_option( self::VERSION_OPT, $number, false );
			update_option( self::LOG_OPT, $log, false );
			$current = $number;
		}
	}
}
