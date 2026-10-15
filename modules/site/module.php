<?php
/**
 * Module Name: Company data and content types
 * Description: Company details with Block Bindings and the [estrategia_web_company] shortcode, the usual content types of a corporate site (clients, testimonials, locations, projects, portfolio) and sanitised SVG uploads.
 * Default: on
 * Requires:
 *
 * Lo que era el plugin «Estrategia Web Site» hasta la 1.0.0, menos lo que se
 * pudo separar sin reescribirlo (aviso de cookies, WebP, enlaces externos,
 * portada de WPML, Matomo: cada uno es su módulo). Pantalla propia: «Datos de
 * la empresa» (includes/admin/class-admin.php). Y en el editor de una sede, un
 * cliente o una opinión, el panel con sus campos (includes/class-meta-panel.php).
 *
 * Por qué SVG sigue aquí y no en su módulo: su ajuste vive en la misma opción
 * (estrategia_web_site, clase Settings) y la misma pantalla que los datos de
 * la empresa. Matomo salió en la 1.1.0 (modules/matomo) SIN partir la opción:
 * sus claves matomo_* y su saneado siguen en Settings, y sus campos los pinta
 * él en el gancho estrategia_web_site_settings_sections de la pantalla. Es el
 * camino para el próximo que salga de aquí.
 *
 * Constantes: ESTRATEGIA_WEB_SITE_DIR y _URL apuntan a ESTA carpeta (antes, a
 * la raíz del plugin); ESTRATEGIA_WEB_SITE_VERSION es la del plugin.
 *
 * Pruebas: modules/site/tests/ (nivel 1: pure.test.mjs; nivel 2 y 3, el
 * resto, con tests/lib.mjs).
 *
 * Cómo se mantiene: un asunto nuevo de datos de la empresa entra aquí; uno
 * que se pueda apagar por separado es un módulo nuevo (ver
 * includes/class-modules.php en la raíz).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_SITE_VERSION', ESTRATEGIA_WEB_VERSION ); // Globales con define(): un const aquí sería EstrategiaWeb\Site\….
define( 'ESTRATEGIA_WEB_SITE_DIR', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_SITE_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-type-names.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/class-links.php';
require_once __DIR__ . '/includes/class-svg-sanitizer.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-company.php';
require_once __DIR__ . '/includes/class-bindings.php';
require_once __DIR__ . '/includes/class-location.php';
require_once __DIR__ . '/includes/class-shortcode.php';
require_once __DIR__ . '/includes/class-content-types.php';
require_once __DIR__ . '/includes/class-svg.php';
require_once __DIR__ . '/includes/class-legacy-names.php';
require_once __DIR__ . '/includes/class-unprefixed-names.php';
require_once __DIR__ . '/includes/class-meta-panel.php';
require_once __DIR__ . '/includes/admin/class-admin.php';

// La trampa del orden: los tipos de contenido se registran en init con lo
// que diga la opción en ESA carga; las traducciones (núcleo, init 1) van antes
// porque las etiquetas y los slugs salen de gettext.
add_action( 'init', array( Settings::class, 'register' ) );
add_action( 'init', array( Content_Types::class, 'register' ) );
add_action( 'init', array( Bindings::class, 'register' ) );
add_action( 'init', array( Location::class, 'register' ) );
add_action( 'init', array( Shortcode::class, 'register' ) );
Legacy_Names::boot(); // Los nombres de antes de 1.0.0, como alias (hasta 2.0.0).
Unprefixed_Names::boot(); // Los tipos y taxonomías sin prefijo de antes de 1.2.0, como alias (hasta 2.0.0).
add_action( 'enqueue_block_editor_assets', array( Bindings::class, 'enqueue_editor' ) );
add_filter( 'post_class', array( Content_Types::class, 'post_class' ), 10, 3 );
add_action( 'update_option_' . Settings::OPTION, array( Content_Types::class, 'maybe_flush' ), 10, 2 );
add_action( 'add_option_' . Settings::OPTION, static fn( $name, $value ) => Content_Types::maybe_flush( array(), $value ), 10, 2 );
Svg::boot();
Meta_Panel::boot(); // Sin publicar: «Datos de la sede/del cliente/de la opinión» en el editor.

// Al activar el plugin: los tipos registrados y sus URL al día. Al
// desactivarlo, que sus URL dejen de existir (el contenido se queda).
add_action(
	'estrategia_web_activate',
	static function (): void {
		Content_Types::register();
		flush_rewrite_rules( false );
	}
);
add_action( 'estrategia_web_deactivate', static fn() => delete_option( 'rewrite_rules' ) );

if ( is_admin() ) {
	Admin::boot();
}
