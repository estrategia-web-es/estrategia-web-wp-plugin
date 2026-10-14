<?php
/**
 * Module Name: Cookie notice
 * Description: Own cookie consent notice (Accept / Reject / Settings with declarable categories), the «Cookie settings» link block and the consent API for PHP and JavaScript.
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
 * Cómo se mantiene: el detector automático de cookies y Consent Mode v2
 * (hoja de ruta, PENDIENTES.md) se construyen AQUÍ, no en un módulo aparte:
 * es el mismo aviso.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_CONSENT_DIR', __DIR__ . '/' );

require_once __DIR__ . '/includes/class-consent.php';

Consent::boot(); // Arranca en after_setup_theme: ver su cabecera («Convivencia»).
