<?php
/**
 * Module Name: Cookie notice
 * Description: Own cookie consent notice (Accept / Reject / Settings with declarable categories), the «Cookie settings» link block and the consent API for PHP and JavaScript, plus a cookie list fed by a detector and a cookie table block for the cookie policy.
 * Default: on
 * Requires:
 *
 * Era parte de «Estrategia Web Site» (desde su 0.5.0; antes, del tema).
 * Todo lo que hace y cómo se mantiene, en la cabecera de
 * includes/class-consent.php. Bloque: estrategia-web-site/consent (el nombre
 * de la 1.0.0, que no cambia). Pruebas: modules/consent/tests/.
 *
 * Quién depende de él sin declararlo: Matomo en modo híbrido (módulo site)
 * escucha el evento «estrategia-web:consent» en el navegador. Con este
 * módulo apagado, ese evento no llega nunca y Matomo se queda sin cookies,
 * que es lo seguro. Y la API PHP (estrategia_web_consent_has()) deja de
 * existir: quien la llame, function_exists() antes.
 *
 * 1.1.0: además del aviso, el REGISTRO de cookies con su DETECTOR (los
 * navegadores que aceptan mandan los nombres de las cookies que ven), la
 * pantalla «Estrategia Web → Cookies» y la TABLA para la política de cookies
 * (bloque estrategia-web/cookie-table y shortcode). Se quedan aquí y no en
 * un módulo aparte porque son el mismo aviso: las categorías que pregunta, y
 * lo que hay que contar en la política, salen de lo mismo. Las etiquetas de
 * Google (Consent Mode v2) sí son módulo aparte (google-tags), como Matomo:
 * se enteran del consentimiento por la API JS, sin llamar a estas clases.
 *
 * Datos de terceros: data/open-cookie-database.json, extracto de la Open
 * Cookie Database (Apache 2.0; atribución en el JSON y en data/NOTICE),
 * regenerado con tools/open-cookie-database.mjs.
 *
 * Cómo se mantiene: lo que tenga que ver con QUÉ cookies hay y QUÉ se
 * pregunta va aquí; una etiqueta o un servicio que ESPERA al consentimiento
 * (Matomo, Google, un chat) es su propio módulo y habla por la API JS. Cada
 * pieza documenta lo suyo en su cabecera (includes/class-*.php).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_CONSENT_DIR', __DIR__ . '/' );

require_once __DIR__ . '/includes/class-consent.php';
require_once __DIR__ . '/includes/class-cookie-registry.php';
require_once __DIR__ . '/includes/class-cookie-table.php';
require_once __DIR__ . '/includes/class-cookie-rest.php';

Consent::boot(); // Arranca en after_setup_theme: ver su cabecera («Convivencia»).
