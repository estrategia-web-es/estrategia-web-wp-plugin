<?php
namespace EstrategiaWeb\Migraciones;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Content migrations
 * Description: Runs the content migrations the site's child theme ships in its migraciones/ folder, once each, only from WP-CLI (the deploy runs it); the dashboard warns of pending ones.
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
 * When (decision by David, 27-11-2026): ONLY the deploy runs them. aplicar.sh,
 * right after copying the child theme, runs
 *
 *     wp estrategia migrar [--seco]
 *     wp estrategia estado
 *
 * If a migration fails the command exits with an error and the deploy goes
 * red. NEVER on a visit: nothing on init or any request hook, so a migration
 * does not run half-way with traffic and no visitor can trigger one. The
 * command can be repeated with no effect: what is applied is not applied again.
 *
 * If the plugin is deployed but the command was not run, administrators see a
 * notice on the dashboard (admin_notices) listing the pending ones. It only
 * warns; it does not run anything.
 *
 * A lock (add_option is atomic) stops two simultaneous runs from applying the
 * same migration twice; it expires after 5 minutes.
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

add_action( 'admin_notices', array( Runner::class, 'notice' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			require_once __DIR__ . '/includes/class-cli.php';
			\WP_CLI::add_command( 'estrategia', Cli::class );
		}
	},
	0
);
