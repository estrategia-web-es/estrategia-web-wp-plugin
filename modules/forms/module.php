<?php
/**
 * Module Name: Forms
 * Description: Block-based forms with several email notifications made from templates, a log of everything sent and WPML support.
 * Default: on
 * Requires:
 *
 * Lo que era el plugin «Estrategia Web Forms» hasta la 1.0.0 (repo
 * armazon-wp-formularios, archivado el 14-10-2026). Su documentación entera
 * sigue en modules/forms/README.md y su historia en modules/forms/CHANGELOG.md
 * (de aquí en adelante, en el CHANGELOG.md de la raíz).
 *
 * Nombres públicos de la 1.0.0, que no cambian: bloques
 * estrategia-web-forms/…, rutas REST estrategia-web-forms/v1/…, tablas
 * {prefijo}estrategia_web_forms_submissions y …_definitions, opciones
 * estrategia_web_forms_*, el contexto de cadenas de WPML
 * «estrategia-web-forms» y el menú «Formularios».
 *
 * Constantes: ESTRATEGIA_WEB_FORMS_DIR y _URL apuntan a ESTA carpeta;
 * ESTRATEGIA_WEB_FORMS_VERSION es la del plugin. La versión de la BASE DE
 * DATOS es otra cosa: Migrations::LATEST, y solo sube con una migración.
 *
 * Es el ejemplo completo de módulo: bloques (blocks/), pruebas de los tres
 * niveles (tests/, con su lib.mjs y su mu-plugin de pruebas tests/wp/),
 * desinstalación (uninstall.php) y lo que tiene que existir antes de usarlo
 * (las tablas) hecho idempotente en cada carga, porque encender el módulo
 * en la pantalla NO pasa por el gancho de activación.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_FORMS_VERSION', ESTRATEGIA_WEB_VERSION );
define( 'ESTRATEGIA_WEB_FORMS_DIR', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_FORMS_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-markers.php';
require_once __DIR__ . '/includes/class-legacy-names.php';
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
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-cron.php';
require_once __DIR__ . '/includes/admin/class-submissions-table.php';
require_once __DIR__ . '/includes/admin/class-admin.php';

// Idempotentes en cada carga: así funciona también al encender el módulo.
add_action( 'plugins_loaded', array( Migrations::class, 'maybe_run' ) );
add_action( 'init', array( Cron::class, 'schedule' ) );

add_action( 'init', array( Blocks::class, 'register' ) );
Legacy_Names::boot(); // Los bloques armazon-forms/… como alias (hasta 2.0.0).
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
// Al desactivar el plugin se quita la purga. Al APAGAR solo el módulo no
// (no está cargado para hacerlo): el evento sigue programado, sin nadie que
// lo escuche, y no hace nada; al encenderlo, schedule() ve que ya está.
add_action( 'estrategia_web_deactivate', array( Cron::class, 'unschedule' ) );

if ( is_admin() ) {
	Admin::boot();
}
