<?php
/**
 * The test WordPress's ENVIRONMENT (28-10-2026): what makes two runs of the battery see the
 * same WordPress. Loaded first by the mu-plugin loader of tests/blueprint.json; it exists only
 * there, never in a real site.
 *
 * 1. OFFLINE. No HTTP request leaves the machine: anything not addressed to 127.0.0.1 or
 *    localhost gets a WP_Error at once. Measured with the trace below on one full run (5+1
 *    WordPress): every shard called api.wordpress.org (version, plugin, theme and pattern
 *    checks, serve-happy, browse-happy), rpc.pingomatic.com (pings on publish) and, in two of
 *    the six, downloaded core's es_ES language pack in the MIDDLE of the run (translations
 *    auto-update through cron) — so the files dealt after that download saw core in Spanish
 *    and the ones before in English (forms-list went red that way). The WPML one called
 *    wpml.org, cdn.wpml.org, tp.wpml.org and cloudfront. 7-16 s of waiting per shard, with
 *    whatever latency the internet had that day. Requests to the WordPress itself (WPML's
 *    REST status check) still go through: that is the code under test.
 * 2. NO WP-CRON. DISABLE_WP_CRON: a fresh site has a dozen events due, so requests spawned
 *    wp-cron.php loopbacks (2-7 per shard, each a full WordPress request of 1-8 s on a busy
 *    machine) at moments nobody chose. Nothing in this plugin schedules events (checked:
 *    no wp_schedule_* in the code).
 * 3. PHP'S ERRORS to a file per shard (tests/run.mjs mounts estrategia-web-test-logs/ and
 *    prints what was logged while a file was red): a «critical error» 500 says nothing alone.
 * 4. TRACE, opt-in (EW_TRACE=1 node tests/run.mjs): every request's milliseconds and URL and
 *    every outbound HTTP call, to estrategia-web-test-logs/trace.log. It is how points 1 and 2
 *    were measured; off by default because appending a file on every request costs time.
 *
 * How to maintain: a test that needs the internet is wrong (it tests someone else's server);
 * a test that needs cron calls the hook itself (do_action) in its own route. If a new
 * WordPress or WPML version starts a new kind of background request, the trace shows it.
 * The trap: DISABLE_WP_CRON is read on wp_loaded, so it must be defined here (an mu-plugin
 * loads before that), never in a plugin's init.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( false !== $pre || in_array( $host, array( '127.0.0.1', 'localhost' ), true ) ) {
			return $pre;
		}
		return new WP_Error( 'estrategia_web_test_offline', 'The test WordPress is offline (tests/wp/estrategia-web-test-env.php): ' . $url );
	},
	PHP_INT_MAX,
	3
);

$estrategia_web_test_logs = WP_CONTENT_DIR . '/estrategia-web-test-logs';
if ( is_dir( $estrategia_web_test_logs ) ) {
	ini_set( 'log_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet
	ini_set( 'error_log', $estrategia_web_test_logs . '/php-errors.log' ); // phpcs:ignore WordPress.PHP.IniSet

	if ( file_exists( $estrategia_web_test_logs . '/trace.on' ) ) {
		$estrategia_web_test_trace = static function ( string $line ) use ( $estrategia_web_test_logs ): void {
			file_put_contents( $estrategia_web_test_logs . '/trace.log', $line . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		};
		// BEG when the request reaches the mu-plugins, REQ when it ends, with the same id: a BEG
		// with no REQ is a request that never finished (a hung worker).
		$estrategia_web_test_id = substr( md5( uniqid( '', true ) ), 0, 8 );
		$estrategia_web_test_trace( sprintf( 'BEG %s %.3f %s %s', $estrategia_web_test_id, microtime( true ), $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		register_shutdown_function(
			static function () use ( $estrategia_web_test_trace, $estrategia_web_test_id ): void {
				$ms = (int) round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$estrategia_web_test_trace( sprintf( 'REQ %d %s %s %s', $ms, $_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '', $estrategia_web_test_id ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		);
		add_action(
			'http_api_debug',
			static function ( $res, $ctx, $cls, $args, $url ) use ( $estrategia_web_test_trace ): void {
				$estrategia_web_test_trace( 'HTTP ' . ( is_wp_error( $res ) ? $res->get_error_code() : wp_remote_retrieve_response_code( $res ) ) . ' ' . $url );
			},
			10,
			5
		);
	}
}
