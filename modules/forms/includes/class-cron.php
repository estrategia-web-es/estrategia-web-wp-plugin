<?php
/**
 * Scheduled purge of the log according to the retention (Settings → «Keep
 * submissions for N days»; 0 = never delete).
 *
 * Runs once a day with WP-Cron. What it deletes is written to the option
 * estrategia_web_forms_last_purge (date and how many), which the settings
 * screen shows: a purge that says nothing cannot be told apart from one that
 * did not run.
 *
 * The trap: WP-Cron is not a real cron. It runs when someone visits the
 * site, so on a site without visits the purge is delayed. If a site
 * disables WP-Cron (DISABLE_WP_CRON) it must have a system cron that calls
 * wp-cron.php, or the retention is not enforced.
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
