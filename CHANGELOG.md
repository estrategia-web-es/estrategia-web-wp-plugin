# Cambios

Una entrada por versión, la más nueva arriba. Se escribe en el mismo commit
que sube el número (README, «Versiones»). Si una versión obliga a tocar algo
en las webs, la entrada lo dice primero, con qué cambiar.

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
