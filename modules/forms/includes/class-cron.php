<?php
/**
 * Purga programada del registro según la retención (Ajustes → «Conservar
 * envíos durante N días»; 0 = no borrar nunca).
 *
 * Corre una vez al día con WP-Cron. Lo que borra queda escrito en la opción
 * estrategia_web_forms_last_purge (fecha y cuántos), que la pantalla de ajustes
 * enseña: una purga que no dice nada no se distingue de una que no corrió.
 *
 * La trampa: WP-Cron no es un cron de verdad. Corre cuando alguien visita la
 * web, así que en una web sin visitas la purga se retrasa. Si una web
 * desactiva WP-Cron (DISABLE_WP_CRON) tiene que tener un cron del sistema que
 * llame a wp-cron.php, o la retención no se cumple.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public const HOOK = 'estrategia_web_forms_purge';

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function purge(): int {
		$deleted = Submissions::purge_older_than( (int) Settings::get( 'retention_days' ) );
		update_option(
			'estrategia_web_forms_last_purge',
			array(
				'at'      => gmdate( 'c' ),
				'deleted' => $deleted,
			),
			false
		);
		return $deleted;
	}
}
