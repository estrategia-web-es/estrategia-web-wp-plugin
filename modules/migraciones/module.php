<?php
namespace EstrategiaWeb\Migraciones;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Content migrations
 * Description: Runs the content migrations the site's child theme ships in its migraciones/ folder, once each, when the theme's version changes or from WP-CLI.
 * Default: off
 * Requires:
 * Category: Content
 *
 * Why (David, 26-11-2026): deploying a site becomes copying its child theme
 * (PHP, JS, CSS and migrations); the content changes by itself on update. It
 * lives in the plugin and not in the parent theme because WordPress.org's
 * rules do not let a theme create or change content («plugin territory»).
 * Pattern taken from deliciousbrains/wp-migrations (GPL-2.0+); here without
 * Composer.
 *
 * A MIGRATION is a PHP file in migraciones/ of the ACTIVE theme (the child),
 * named with its date: 2026-11-26-testimonio-luis-de-la-serna.php. They are
 * applied in name order. The file RETURNS an object that extends
 * Migracion (see includes/class-migracion.php, with the helpers: post by
 * slug, media with alt text, meta, menu order):
 *
 *     return new class extends \EstrategiaWeb\Migraciones\Migracion {
 *         public function aplicar(): string { ... return 'what it did'; }
 *         public function deshacer(): string { ... }   // optional
 *     };
 *
 * Each one is applied ONCE: it is written in the option
 * estrategia_web_migraciones (date, result, what it did). If one fails
 * (exception or false), it stops there and the rest do not run; a failed one
 * is tried again next time (it is not «applied»).
 *
 * When: when the theme's Version (style.css) differs from the last one seen
 * (option estrategia_web_migraciones_version), or with WP-CLI:
 *
 *     wp estrategia-web migraciones estado
 *     wp estrategia-web migraciones aplicar [--seco]
 *
 * A lock (add_option is atomic) stops two simultaneous visits from running
 * the same migration twice; it expires after 5 minutes. The version is
 * written ONLY if the run ended well: if the lock is held or a migration
 * fails, it is not, and the next load tries again (the ones already applied
 * are not repeated: they are recorded one by one).
 *
 * Log: every run leaves a line in the PHP error log and fires the action
 * estrategia_web_migracion( $name, $result, $detail ). (There is no shared
 * log screen in the plugin yet.)
 *
 * Why Default: off: it WRITES content. A site switches it on when its child
 * theme starts shipping migrations.
 *
 * How to maintain: Juan. Test: tests/pure.test.mjs (level 1).
 */

require_once __DIR__ . '/includes/class-migracion.php';
require_once __DIR__ . '/includes/class-runner.php';

add_action( 'init', array( Runner::class, 'on_init' ), 20 );

add_action(
	'plugins_loaded',
	static function () {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/includes/class-cli.php';
			\WP_CLI::add_command( 'estrategia-web migraciones', Cli::class );
		}
	},
	0
);
