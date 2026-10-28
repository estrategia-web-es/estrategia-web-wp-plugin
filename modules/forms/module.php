<?php
/**
 * Module Name: Forms
 * Description: Block-based forms with several email notifications made from templates, a log of everything sent and WPML support.
 * Default: on
 * Requires:
 * Category: Contact
 *
 * What used to be the «Estrategia Web Forms» plugin up to 1.0.0 (repo
 * armazon-wp-formularios, archived on 14-10-2026). Its full documentation
 * is still in modules/forms/README.md and its history in
 * modules/forms/CHANGELOG.md (from now on, in the root CHANGELOG.md).
 *
 * Public names from 1.0.0, which do not change: blocks
 * estrategia-web-forms/…, REST routes estrategia-web-forms/v1/…, tables
 * {prefix}estrategia_web_forms_submissions and …_definitions, options
 * estrategia_web_forms_*, the WPML string context «estrategia-web-forms»
 * and the «Formularios» menu. Since 2.0.0 also: the post type eweb_form
 * (REST base eweb-forms), the reference block estrategia-web-forms/form-ref,
 * the meta _eweb_forms_migration_backup and the WP-CLI command
 * «estrategia-web migrate-forms».
 *
 * Constants: ESTRATEGIA_WEB_FORMS_DIR and _URL point to THIS folder;
 * ESTRATEGIA_WEB_FORMS_VERSION is the plugin's. The DATABASE version is
 * something else: Migrations::LATEST, and it only goes up with a migration.
 *
 * This is the complete example of a module: blocks (blocks/), tests at all
 * three levels (tests/, with its lib.mjs and its test mu-plugin tests/wp/),
 * uninstall (uninstall.php) and what must exist before using it (the
 * tables) made idempotent on every load, because switching the module on
 * in the screen does NOT go through the activation hook.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_FORMS_VERSION', ESTRATEGIA_WEB_VERSION );
define( 'ESTRATEGIA_WEB_FORMS_DIR', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_FORMS_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-markers.php';
require_once __DIR__ . '/includes/class-ip.php';
require_once __DIR__ . '/includes/class-token.php';
require_once __DIR__ . '/includes/class-preselect.php';
require_once __DIR__ . '/includes/class-language.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-migrations.php';
require_once __DIR__ . '/includes/class-definitions.php';
require_once __DIR__ . '/includes/class-submissions.php';
require_once __DIR__ . '/includes/class-validator.php';
require_once __DIR__ . '/includes/class-notifier.php';
require_once __DIR__ . '/includes/class-blocks.php';
require_once __DIR__ . '/includes/class-form-post-type.php'; // 2.0.0: the form as a post of its own (eweb_form).
require_once __DIR__ . '/includes/class-form-migration.php'; // 2.0.0: wp estrategia-web migrate-forms.
require_once __DIR__ . '/includes/class-marker-migration.php'; // 2.0.1: wp estrategia-web migrate-form-markers.
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-cron.php';
require_once __DIR__ . '/includes/admin/class-submissions-table.php';
require_once __DIR__ . '/includes/admin/class-admin.php';
require_once __DIR__ . '/includes/admin/class-forms-list.php'; // 1.7.0: Forms → Forms, the list of the site's forms.

// Idempotent on every load: that way it also works when the module is switched on.
add_action( 'plugins_loaded', array( Migrations::class, 'maybe_run' ) );
add_action( 'init', array( Cron::class, 'schedule' ) );

add_action( 'init', array( Blocks::class, 'register' ) );
Form_Post_Type::boot();
add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
add_action( Cron::HOOK, array( Cron::class, 'purge' ) );
add_action( 'update_option_' . Settings::OPTION, array( Settings::class, 'register_strings' ) );
add_action( 'add_option_' . Settings::OPTION, array( Settings::class, 'register_strings' ) );

add_action(
	'estrategia_web_activate',
	static function (): void {
		Migrations::maybe_run();
		Cron::schedule();
	}
);
// Deactivating the plugin removes the purge. Switching OFF just the module
// does not (it is not loaded to do it): the event stays scheduled, with
// nobody listening, and does nothing; when switched on, schedule() sees it
// is already there.
add_action( 'estrategia_web_deactivate', array( Cron::class, 'unschedule' ) );

if ( is_admin() ) {
	Admin::boot();
}
