<?php
/**
 * Ajustes del módulo (opción estrategia_web_redirects_settings) y la purga
 * diaria del registro de 404.
 *
 * - log_404 (apagado de serie): apuntar las rutas que dan 404 sin regla.
 *   Apagado porque es una escritura por 404 y solo hace falta mientras se
 *   revisa una migración.
 * - log_days (90): lo que no se ve en ese tiempo sale del registro.
 *
 * La purga es un cron diario programado de forma idempotente en init (como
 * forms): encender el módulo no pasa por la activación del plugin. Al
 * desactivar el PLUGIN se quita; al apagar solo el MÓDULO se queda
 * programado sin nadie que lo escuche y no hace nada.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'estrategia_web_redirects_settings';
	public const CRON   = 'estrategia_web_redirects_purge';

	/** @return array{log_404: bool, log_days: int} */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'log_404'  => ! empty( $saved['log_404'] ),
			'log_days' => max( 1, (int) ( $saved['log_days'] ?? 90 ) ),
		);
	}

	public static function save( bool $log_404, int $log_days ): void {
		update_option(
			self::OPTION,
			array(
				'log_404'  => $log_404,
				'log_days' => min( 3650, max( 1, $log_days ) ),
			),
			true // Se lee en cada 404: que venga con las autocargadas.
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function purge(): void {
		Store::log_purge( self::get()['log_days'] );
	}
}
