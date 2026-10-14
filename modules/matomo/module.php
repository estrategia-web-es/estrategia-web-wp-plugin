<?php
/**
 * Module Name: Matomo
 * Description: Matomo tracking code, cookieless or hybrid (no cookies until the visitor accepts analytics in the cookie notice).
 * Default: on
 * Requires: site
 *
 * Qué hace cada modo y sus trampas: cabecera de includes/class-matomo.php.
 *
 * Por qué es un módulo desde la 1.1.0 (antes vivía en «site»): para poder
 * apagarlo por separado y para que la analítica de Google (google-tags)
 * sea su igual y no un añadido. Lo que NO se movió, a propósito:
 * - La OPCIÓN. Sigue dentro de estrategia_web_site (matomo_url,
 *   matomo_site_id, matomo_mode), con su esquema y su saneado en
 *   modules/site/includes/class-settings.php. Así la opción guardada de cada
 *   web sigue valiendo SIN MIGRACIÓN, y /wp/v2/settings no cambia. Por eso
 *   «Requires: site»: Settings es de site.
 * - El id del <script> (estrategia-web-site-matomo) y los filtros
 *   (estrategia_web_site_matomo_track): hay pruebas de las webs que los miran.
 * Los campos se pintan en la pantalla «Datos de la empresa» (la de site), en
 * el gancho estrategia_web_site_settings_sections: con este módulo apagado no
 * salen, y lo guardado se queda.
 *
 * Encendido por defecto, a sabiendas: llega a webs que YA tenían Matomo dentro de site
 * (Lavandería: híbrido, id 87). Con «on», al desplegar se enciende solo (caso
 * frontera de includes/class-modules.php: slug nuevo para la web) y la web
 * sigue midiendo igual. Con «off» se quedaría sin medir sin avisar. Sin URL
 * ni id configurados no imprime nada, así que en una web nueva no hace nada.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque Matomo; las pruebas son modules/matomo/tests/
 *   (matomo.test.mjs nivel 2, matomo-browser.test.mjs nivel 3).
 * - Criterio: lo que es de Matomo va aquí; un campo nuevo de Matomo se guarda
 *   en Settings (site) para no partir la opción, y se pinta aquí.
 * - Caso frontera: site apagado. Este módulo no carga (Requires) y la web deja
 *   de medir; lo guardado sigue en la opción.
 * - La trampa: el modo híbrido CARGA matomo.js antes del consentimiento (mide
 *   sin cookies, requireCookieConsent). Una prueba que exija «ninguna petición
 *   a terceros antes de aceptar» lo verá como fallo: tiene que excluir el host
 *   de Matomo en modo híbrido y mirar las COOKIES (_pk_*, mtm_*), no las
 *   peticiones. Pasó con tests/consent-maps.test.mjs de Lavandería.
 */

namespace EstrategiaWeb\Matomo;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-matomo.php';
require_once __DIR__ . '/includes/class-admin-fields.php';

Matomo::boot();
add_action( 'estrategia_web_site_settings_sections', array( Admin_Fields::class, 'render' ), 10, 2 );
