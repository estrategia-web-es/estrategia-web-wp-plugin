<?php
/**
 * Module Name: Google tags
 * Description: Google Analytics 4, Google Ads (with an optional conversion for form submissions) and Google Tag Manager behind the cookie notice, with Consent Mode v2. An empty field loads nothing.
 * Default: off
 * Requires: consent
 *
 * Qué hace, entero, en la cabecera de includes/class-google-tags.php. En
 * corto: tres campos (G-…, AW-…, GTM-…) en «Estrategia Web → Google»; campo
 * vacío = no se carga nada de eso. En el <head>, ANTES de cualquier otra
 * cosa, Consent Mode v2 con todo «denied»; y los scripts de Google NO se
 * piden hasta que el visitante acepta Analítica o Publicidad en el aviso
 * (modo básico, el de por defecto).
 *
 * Por qué Default: off: cambia lo que pide la web (Google) y declara
 * categorías en el aviso, que hacen que vuelva a salir a todos. Eso se decide
 * web a web encendiéndolo, nunca al desplegar (caso frontera de
 * includes/class-modules.php).
 *
 * Por qué Requires: consent: sin aviso no hay consentimiento y, en modo
 * básico, nunca se cargaría nada; con el módulo encendido y el aviso apagado
 * la pantalla de Módulos dice qué le falta en vez de fingir que mide. Habla
 * con el aviso SOLO por su API JS (window.estrategiaWebConsent y los eventos),
 * como Matomo, no llamando a sus clases.
 *
 * Opción: estrategia_web_google_tags (también por /wp/v2/settings).
 * Pantalla: includes/admin/class-google-tags-page.php.
 * Pruebas: modules/google-tags/tests/ (nivel 2 lo que se imprime; nivel 3
 * qué pide el navegador antes y después de aceptar, con Google interceptado).
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque la analítica. Una etiqueta nueva de Google
 *   (p. ej. Floodlight) es un campo más con su validación en Google_Tags::FIELDS
 *   y su línea en script(); otra marca (Meta, LinkedIn) es OTRO módulo con el
 *   mismo patrón.
 * - Criterio de categorías: analytics_storage ← Analítica (statistics);
 *   ad_storage, ad_user_data y ad_personalization ← Publicidad (marketing);
 *   functionality_storage y personalization_storage ← Preferencias;
 *   security_storage, siempre concedido.
 * - Caso frontera: solo Tag Manager. GTM puede llevar cualquier cosa dentro,
 *   así que declara Analítica Y Publicidad, y se carga con cualquiera de las
 *   dos; lo que haga cada etiqueta dentro de GTM lo decide Consent Mode.
 * - La trampa: «cargar Google con todo denied» (modo avanzado) también manda
 *   datos a Google antes de consentir (pings sin cookies, con IP). Por eso no
 *   es el de por defecto. Ver la cabecera de la clase.
 */

namespace EstrategiaWeb\GoogleTags;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-google-tags.php';

Google_Tags::boot();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-google-tags-page.php';
	Google_Tags_Page::boot();
}
