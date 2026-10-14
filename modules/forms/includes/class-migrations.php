<?php
/**
 * Migraciones numeradas de las tablas propias (Armazón, fila 14).
 *
 * Cada migración es un fichero includes/migrations/NNNN-nombre.php que
 * DEVUELVE una función (\wpdb $wpdb): string. La cadena es lo que hizo
 * («creó 2 tablas», «rellenó 340 filas, 0 fallos»), y queda escrita en la
 * opción estrategia_web_forms_migrations_log con la fecha: una migración que acaba
 * en silencio no se distingue de una que no corrió.
 *
 * Se aplican una vez y en orden, al activar y en cada carga si la versión
 * guardada (estrategia_web_forms_db_version) es menor que LATEST.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien cambie la forma de una tabla, en el mismo commit que
 *   el código que la usa. Se añade el fichero NNNN siguiente y se sube LATEST.
 * - Nunca se edita una migración ya publicada: las webs que la aplicaron no
 *   la vuelven a correr. Lo que haya que corregir va en una nueva.
 * - Caso frontera: añadir una columna con valor por defecto ES una migración
 *   (dbDelta la añade, pero la versión tiene que subir para que corra).
 * - La trampa: dbDelta es quisquilloso con el formato del CREATE TABLE (dos
 *   espacios tras PRIMARY KEY, una columna por línea, sin comillas
 *   invertidas). Si no lo respetas, no da error: simplemente no altera nada.
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
