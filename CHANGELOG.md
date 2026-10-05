# Cambios

Una entrada por versión, la más nueva arriba. Se escribe en el mismo commit
que sube el número (README, «Versiones»). Si una versión obliga a tocar algo
en las webs, la entrada lo dice primero, con qué cambiar.

## 0.5.0 — 05-10-2026

Menor para el plugin (solo añade), pero **va junto con el tema padre 0.7.0**,
que deja de traer el aviso de cookies: en cada web se suben los dos a la vez.
Con este plugin y el padre viejo, el plugin se aparta y sale el aviso del
padre como hasta ahora.

- **Aviso de cookies**, traído del tema padre con el mismo contrato: la misma
  cookie `armazon_consent` y la misma revisión (quien ya eligió no vuelve a
  ver el aviso), el filtro `armazon_consent_categories`, las funciones
  `armazon_consent_*`, `window.armazonConsent` (`categories`, `has`, `get`,
  `open`, `grant`), el evento `armazon:consent`, el store `armazon/consent`,
  los `<script type="text/plain" data-armazon-consent>`, el shortcode
  `[armazon_consent_settings]` y la WP Consent API. Por qué: es
  funcionalidad, no presentación; una web que cambie de tema no puede
  quedarse sin aviso, y Matomo ya dependía de él.
- Bloque del enlace **`armazon-site/consent`**; el nombre viejo
  `armazon/consent` se registra como alias oculto, así que lo ya guardado
  sigue pintando el enlace.
- Estilos base neutros en el plugin, que leen los tokens del tema.
- **«Powered by Estrategia Web»** al pie del aviso: enlace a
  https://estrategiaweb.es/ en pestaña nueva, `rel="noopener"`, sin
  `nofollow`. Se cambia o se quita con el filtro
  `armazon_site_consent_powered_by`.
- Matomo híbrido: su cabecera ya no habla del «aviso del tema padre». Con
  cualquier tema hay aviso y, por tanto, consentimiento.
- **Arreglo** (salió al probar Matomo con el aviso real): si el aviso
  hidrataba después de `window.load`, Matomo no se enteraba de una elección
  caducada y seguía con sus cookies (1 de cada 3 cargas). El aviso lanza
  ahora `armazon:consent-ready` una vez al arrancar (con `accepted: null` si
  no hay elección) y Matomo lo escucha.
- `tools/i18n.mjs` extrae también `blocks/` (PHP y `block.json`).
- Pruebas: `tests/consent.test.mjs` (nivel 2) y
  `tests/consent-browser.test.mjs` (nivel 3); `tests/matomo-browser.test.mjs`
  usa el aviso REAL en vez de simular el evento. La ruta de pruebas
  `consent-setup` hace de tema (categorías, «Powered by», script diferido).

## 0.4.0 — 03-10-2026

Menor: solo añade. Ninguna web tiene que tocar nada.

- Datos legales: clave `registry` en la opción `armazon_site` (la
  inscripción registral tal como la escribe el aviso legal, texto libre), en
  la pantalla, en REST (fusionada y saneada), en la fuente
  `armazon-site/company` y en `Company::value()`. Razón social, NIF y
  domicilio ya estaban (`legal_name`, `tax_id`, `address`).
- Shortcode `[armazon_company key="…"]`: un dato de la empresa dentro de una
  frase (el aviso legal), escapado y en una línea (dirección y horario
  unidos con «, »). Un binding sustituye el párrafo entero.
- Sedes: campos `location_static_map` (URL de la imagen del mapa estático,
  solo del propio sitio: ruta del tema, ruta del sitio o mismo dominio) y
  `location_static_map_id` (adjunto de imagen), para `staticMapUrl` de
  `armazon/map` del tema padre con core/post-meta en una Consulta. También
  en la fuente `armazon-site/location`.
- Pruebas: `tests/legal.test.mjs` y `tests/static-map.test.mjs` (nivel 2).

## 0.3.0 — 03-10-2026

Menor: solo añade. Ninguna web tiene que tocar nada; sin configurar Matomo,
no se imprime nada.

- Matomo: claves `matomo_url`, `matomo_site_id` y `matomo_mode`
  (`cookieless` por defecto, o `hybrid`) en la opción `armazon_site`, y su
  sección «Estadísticas (Matomo)» en la pantalla. El código de seguimiento va
  en el `<head>`; no se mide en el escritorio, vistas previas ni a quien
  puede editar (filtro `armazon_site_matomo_track`).
- Modo híbrido: sin cookies hasta que el visitante acepta «statistics» en el
  aviso del tema padre (`rememberCookieConsentGiven`; al retirarla,
  `forgetCookieConsentGiven`). Declara la categoría con
  `armazon_consent_categories`. Su legalidad no está verificada (CNIL).
- Pruebas: `tests/matomo.test.mjs` (nivel 2) y `tests/matomo-browser.test.mjs`
  (nivel 3, con el matomo.js real en `tests/fixtures/`).

## 0.2.0 — 02-10-2026

Menor: solo añade. Ninguna web tiene que tocar nada.

- Fuente `armazon-site/company`: clave derivada `phone_secondary_href`
  (`tel:` del teléfono secundario).
- Fuente nueva `armazon-site/location`: los datos de UNA sede, por slug
  (`{"slug":"maioris","key":"location_phone"}`) desde cualquier página o por
  el contexto de una Consulta (`{"key":…}`). Claves `title`, los campos
  `location_*` y las derivadas `location_phone_href` y `location_email_href`.
  También en el editor.
- Clientes que son un logo: campo `client_image_fit` (`cover` por defecto o
  `contain`) y clase `has-image-fit-contain` en la entrada (post_class) cuando
  vale `contain`. Sin CSS: lo pinta el tema.
- Slugs públicos por web: clave `rewrite_slugs` en la opción `armazon_site`
  (objeto tipo-o-taxonomía → slug, `sanitize_title`, vacío = el de siempre,
  fusión por clave). Se aplica antes del filtro `armazon_site_rewrite_slug`,
  que sigue mandando, y al cambiar se rehacen las reglas de URL. Una web que
  movía `/clientes/` con el filtro desde su tema puede pasarlo a la opción
  (no obligatorio: el filtro sigue funcionando igual).

## 0.1.0 — 02-10-2026

Primera versión.

- Datos de la empresa: opción `armazon_site` con pantalla propia, Settings API
  y esquema REST; se guarda fusionando y lo inválido no pisa lo anterior.
- Fuente de Block Bindings `armazon-site/company` (web y editor), con las
  derivadas `phone_href`, `email_href` y `whatsapp_href`.
- Tipos activables `client`, `testimonial`, `location`, `project`,
  `portfolio`, con taxonomías `client_sector`, `project_category`,
  `portfolio_category` y sus campos meta. Desactivar no borra contenido.
- Subida de SVG para administradores, saneada por lista blanca, con medidas.
- Traducción es_ES (`.po` y `.l10n.php`).
