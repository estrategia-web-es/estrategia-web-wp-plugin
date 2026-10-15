<?php
/**
 * Module settings (option estrategia_web_redirects_settings) and the daily
 * purge of the 404 log.
 *
 * - log_404 (off by default): record paths that give a 404 with no rule.
 *   Off because it is one write per 404 and is only needed while a migration
 *   is being reviewed.
 * - log_days (90): whatever isn't seen in that time leaves the log.
 *
 * The purge is a daily cron scheduled idempotently on init (like forms):
 * enabling the module doesn't go through plugin activation. Deactivating the
 * PLUGIN removes it; switching off only the MODULE leaves it scheduled with
 * nobody listening, and it does nothing.
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
			true // Read on every 404: load it with the autoloaded options.
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
