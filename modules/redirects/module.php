<?php
/**
 * Module Name: Redirects
 * Description: Editable 301, 302 and 410 redirects with hit counters, CSV import and export, and an optional 404 log.
 * Default: off
 * Requires:
 *
 * Redirecciones editables en «Estrategia Web → Redirecciones»: origen,
 * destino, tipo (301, 302, 410), activa, contador y última visita. Importar
 * y exportar CSV (el formato del de Lavandería,
 * docs/redirecciones/redirecciones-2026-10-07.csv de aquel repo), registro
 * opcional de 404 con «Crear redirección», y WP-CLI para desplegar:
 *
 *     wp estrategia-web redirects import fichero.csv [--dry-run]
 *     wp estrategia-web redirects list | export
 *
 * POR QUÉ NACE APAGADO (Default: off): cambia lo que ve el visitante, y en
 * Lavandería, la única web hoy, las redirecciones las lleva Rank Math porque
 * la empresa de SEO trabaja con él. Encenderlo allí serían dos sistemas a la
 * vez; la pantalla lo avisa si pasa (includes/class-conflicts.php).
 *
 * Dónde está cada cosa:
 *   includes/class-rules.php     lo puro: normalizar, casar, leer el CSV,
 *                                validar (bucles, duplicados). EL CRITERIO
 *                                PARA CASAR está en su cabecera.
 *   includes/class-runtime.php   cuándo se mira la tabla (solo en los 404) y
 *                                lo que se mide.
 *   includes/class-store.php     las dos tablas.
 *   includes/class-importer.php  guardar una o muchas; importar es idempotente.
 *   includes/class-cli.php       WP-CLI.
 *   includes/admin/              la pantalla.
 *
 * CÓMO SE MANTIENE
 * - Quién y cuándo: quien migre una web con URL viejas. El CSV de reglas vive
 *   en el repo DE LA WEB (no aquí) y se importa al desplegar con WP-CLI o
 *   desde la pantalla; importar dos veces no duplica. Lo que se edite en el
 *   panel después se baja con «Exportar» (o `wp … export`) al repo de la web.
 * - Criterio de qué entra como regla: una URL que existió y que alguien puede
 *   tener enlazada o en Google. 301 si hay página equivalente; 410 si el
 *   contenido ya no existe a propósito (negocio vendido, idioma quitado); 302
 *   solo para algo temporal. Una errata que nunca se publicó no es una regla.
 * - Caso frontera con nombre: /?p=24298 → / en Lavandería. Funciona porque
 *   en la web nueva el post 24298 NO existe y la petición va a 404. Si algún
 *   día un post nuevo tuviera ese ID, WordPress lo enseñaría y la regla no
 *   actuaría: las reglas solo actúan en los 404 (class-runtime.php). Es a
 *   propósito: una regla vieja nunca tapa una página viva.
 * - La trampa que mordió al montarlo: las URL viejas exportadas a estático
 *   llevan el «?» codificado dentro de la ruta (/index.html%3Fp=6981.html).
 *   Partir por «?» y DESPUÉS decodificar las deja como ruta; decodificar y
 *   después partir, como query. Se parte dos veces para que las dos formas
 *   den la misma clave (cabecera de class-rules.php). Y engaña: con
 *   /index.html%3Fp=6981.html la clave sin partir coincide por casualidad
 *   con la partida, así que la prueba obvia sale verde aunque el código
 *   esté mal; la delatan /en/%3Fp=1 (barra antes del ?) y %3Fpage_id=.
 *   Lo prueba el nivel 1, con el CSV real.
 * - Una tabla cambia de forma: Store::DB_VERSION (cabecera de class-store.php).
 * - Otro plugin de redirecciones que detectar: class-conflicts.php.
 *
 * Datos personales: ninguno. El registro de 404 guarda la ruta y un
 * contador; ni IP, ni navegador, ni la query (salvo p=, que es un ID).
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-rules.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-conflicts.php';
require_once __DIR__ . '/includes/class-runtime.php';

// Idempotentes en cada carga: encender el módulo no pasa por la activación.
add_action( 'plugins_loaded', array( Store::class, 'maybe_install' ) );
add_action( 'init', array( Settings::class, 'schedule' ) );
add_action( Settings::CRON, array( Settings::class, 'purge' ) );
add_action( 'estrategia_web_deactivate', array( Settings::class, 'unschedule' ) );

Runtime::boot();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-tables.php';
	require_once __DIR__ . '/includes/admin/class-page.php';
	Admin\Page::boot();
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	require_once __DIR__ . '/includes/class-cli.php';
	\WP_CLI::add_command( 'estrategia-web redirects', Cli::class );
}
