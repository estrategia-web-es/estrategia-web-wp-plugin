# Cambios

Una entrada por versión, la más nueva arriba. Se escribe en el mismo commit
que sube el número (README, «Versiones»). Si una versión obliga a tocar algo
en las webs, la entrada lo dice primero, con qué cambiar.

Desde la 1.1.0 este es el CHANGELOG del plugin único **Estrategia Web**. La
historia de los formularios hasta su 1.0.0 está en
[modules/forms/CHANGELOG.md](modules/forms/CHANGELOG.md), y la de los cuatro
bloques, en el README del tema (armazon-wp-padre, «Versiones»).

## Sin publicar

### Rama modulo/contenido (14-10-2026)

No obliga a tocar las webs: todo añade; nada cambia de nombre ni se borra.

- **Módulo nuevo `social`** (Default: on, necesita `site`): lista libre y
  ordenada de redes en **Estrategia Web → Redes sociales** (opción
  `estrategia_web_social_links`: nombre, enlace, icono de una colección de
  17 —Simple Icons, CC0; Bootstrap Icons, MIT— o un SVG de la mediateca,
  saneado al pintarlo) y bloque `estrategia-web/social-links` (solo iconos o
  icono y nombre, tres tamaños, color del texto). Mientras la lista no se
  guarda, salen las redes de «Datos de la empresa». Con WPML, nombre y
  enlace como textos de administración (sin probar con WPML).
- **`site`: «Tipo de negocio» del cliente** (`estrategia_web_client_business_type`),
  traducible, enlazable con core/post-meta.
- **`site`: dónde se editan los campos de sede, cliente y opinión**: panel
  en la barra lateral del editor (sede, opinión) y meta box en la pantalla
  clásica (cliente). Abierto al entrar.
- **`site`: WPML** (`wpml-config.xml`, `<custom-fields>`): teléfono, correo,
  enlaces y mapa de la sede, web y ajuste de imagen del cliente, autor,
  valoración y cliente de la opinión se COPIAN a las traducciones; dirección,
  horario, tipo de negocio y cargo se TRADUCEN.
- **`site`: el embed del mapa de la sede** admite pegar el `<iframe>` entero
  (se guarda su `src`), como ya admitía el bloque del mapa.
- Pruebas: `tests/run.mjs` acepta `AS_PORT` (otro puerto que el 9404).
- **Módulo `redirects`** (Default: off): redirecciones 301/302/410 editables
  en **Estrategia Web → Redirecciones**, con contador y última visita,
  importar/exportar CSV (el de Lavandería, 83 reglas, entra entero),
  validación de bucles y duplicados, registro de 404 opcional (solo la ruta,
  sin IP) con «Crear redirección», aviso si Rank Math u otro sistema de
  redirecciones está activo y `wp estrategia-web redirects import|list|export`.
  Solo mira su tabla en los 404 (0 consultas en una página que existe).
  Tablas nuevas `{prefijo}estrategia_web_redirects` y `…_404`; opciones
  `estrategia_web_redirects_settings` y `…_db_version`. **No cambia nada en
  las webs**: nace apagado. Cómo se mantiene, en
  `modules/redirects/module.php`.
- Pruebas: `EW_PORT` elige el puerto del Playground de `tests/run.mjs`
  (9404 por defecto).
### Cookies con detector, Google y Matomo aparte (rama modulo/cookies)

**Obliga a tocar las webs: no.** Quien ya contestó el aviso no lo vuelve a
ver (mismos slugs, misma revisión) y Matomo sigue midiendo igual.

- **Aviso de cookies, categorías de la AEPD**: necesarias, **preferencias**
  (nueva, `preferences`), analítica y publicidad, más mapas. Los slugs de
  siempre (`statistics`, `marketing`) se quedan y se ROTULAN «Analítica» y
  «Publicidad» (antes «Estadísticas» y «Marketing»); se aceptan los alias
  `analytics`, `advertising` y `ads` en PHP y JS. Al enseñarlas, preferencias
  va primera y publicidad antes que mapas (no toca la revisión).
- **Reabrir el panel desde cualquier enlace**: `#estrategia-web-consent`, la
  clase `estrategia-web-consent-open` o `data-estrategia-web-consent-open`
  (para el menú del pie), además del bloque, el shortcode y `.open()`.
- **Registro de cookies con detector** (módulo `consent`): al ACEPTAR, el
  navegador manda los NOMBRES de sus cookies (nunca valores) a
  `POST /wp-json/estrategia-web/v1/cookies/seen`, como mucho cuando cambia su
  lista o cada 7 días; sin nonce (caché de página) y con límites de ritmo por
  IP (sin guardarla) y por hora. Pantalla **Estrategia Web → Cookies**:
  pendientes con propuesta (base propia + extracto de la **Open Cookie
  Database**, Apache 2.0, `modules/consent/data/`, regenerable con
  `tools/open-cookie-database.mjs`), confirmar/cambiar/ignorar, añadir a mano
  las de terceros, comodines (`_ga_*`) y categorías extra para el aviso.
  `wp-wpml_current_language` va como necesaria (WPML, 1 día).
- **Tabla de cookies para la política**: bloque `estrategia-web/cookie-table`
  y shortcode `[estrategia_web_cookie_table]`, solo lo confirmado, por
  categoría; textos fijos por `.po`, los del administrador por WPML String
  Translation (contexto `estrategia-web-cookies`).
- **Módulo nuevo `google-tags`** (Default: off, Requires: consent): GA4, Google
  Ads (+ conversión de envío de formulario) y Tag Manager con **Consent Mode
  v2** (`default` denied antes que nada, `update` al elegir). Modo básico por
  defecto: nada de Google hasta aceptar Analítica o Publicidad; modo
  avanzado opcional. Pantalla **Estrategia Web → Google**.
- **Matomo es su propio módulo** (`matomo`, Default: on, Requires: site), sin
  migrar la opción (`estrategia_web_site`, claves `matomo_*`). Gancho nuevo
  `estrategia_web_site_settings_sections` en «Datos de la empresa».
- **forms** lanza `estrategia-web:form-sent` al enviar bien con JS (lo usa la
  conversión de Google Ads).
- Pruebas: `EW_PORT` elige el puerto del WordPress de pruebas.

## 1.1.0 — 14-10-2026

**Un tema + un plugin** (decisión de David, 14-10-2026). **Obliga a tocar las
webs**: el plugin cambia de carpeta y sustituye a dos. Qué hacer en una web
que venga de la 1.0.0 o de Armazón: desactivar `estrategia-web-site` y
`estrategia-web-forms` (o `armazon-site` y `armazon-forms`), subir este en
`plugins/estrategia-web/` y activarlo, y el tema padre a la 1.1.0 a la vez
(lo hará el migrador de Lavandería, que es la única web).

- **Un solo plugin, «Estrategia Web»** (`estrategia-web/estrategia-web.php`),
  con lo que eran el plugin Estrategia Web Site, el plugin Estrategia Web
  Forms (repo archivado) y los bloques carrusel, mapa, WhatsApp y selector de
  idioma, que registraba el tema (WordPress.org no deja que un tema registre
  bloques).
- **Módulos activables** en **Estrategia Web → Módulos**: `site`, `consent`,
  `webp`, `external-links`, `wpml-front-page`, `forms`, `carousel`, `map`,
  `whatsapp`, `language-switcher`. **Todos encendidos por defecto**: una web
  que actualiza no cambia. Un módulo apagado no carga nada y no borra nada.
  Opciones nuevas: `estrategia_web_modules` (los encendidos) y
  `estrategia_web_modules_known` (los que había al guardar; ver el caso
  frontera en `includes/class-modules.php`).
- **Un módulo nuevo es una carpeta** `modules/<slug>/` con un `module.php` de
  cabecera: se descubre solo, sin lista central. Cómo, en la cabecera de
  `includes/class-modules.php`.
- **Nombres públicos sin cambios**: bloques `estrategia-web/…`,
  `estrategia-web-site/…` y `estrategia-web-forms/…`, opciones y metas
  `estrategia_web_…`, tablas, rutas REST, filtros, shortcodes y alias
  `armazon…`. Los alias `armazon/carousel`… que daba el tema los da ahora el
  núcleo, solo para los bloques cuyo módulo esté encendido.
- **Un solo dominio de traducción: `estrategia-web`** (antes
  `estrategia-web-site` y `estrategia-web-forms`). Las traducciones del editor
  ya no van en JSON con el md5 de la ruta: el núcleo se las pasa a `wp.i18n`
  en línea, para todos los bloques del plugin.
- **Funciones del carrusel en un espacio de nombres**:
  `estrategia_web_carousel_images_*()` del tema pasan a
  `EstrategiaWeb\Carousel\images_*()`. Los filtros
  (`estrategia_web_carousel_eager_slides`, `…_image_sizes`) no cambian.
  Ningún hijo las llamaba.
- **Ganchos para los módulos**: `estrategia_web_activate`,
  `estrategia_web_deactivate` y `estrategia_web_modules_loaded`.
- **Pruebas por módulo** en `modules/<slug>/tests/`, con un solo corredor y
  un solo WordPress de pruebas (puerto 9404). Nuevas:
  `tests/modules.test.mjs` (nivel 1) y `tests/modules-page.test.mjs` (nivel 2).
- **El migrador** (`includes/class-armazon-migration.php`) no cambia en esta
  versión: su paso 7 todavía activa los dos plugins de la 1.0.0. Adaptarlo
  es la parte siguiente (ver PENDIENTES.md).

## 1.0.0 — 07-10-2026

**MAYOR: cambio de nombre. Obliga a tocar las webs:** pasar el migrador
(abajo) y actualizar a la vez el tema padre a Estrategia Web 1.0.0.

- **«Armazón Site» pasa a «Estrategia Web Site»**: carpeta y fichero
  `estrategia-web-site/estrategia-web-site.php`, text domain, namespace
  `EstrategiaWeb\Site`, funciones, constantes, filtros, opción, metas, fuentes
  de bindings, bloque, cookie, API JS, evento, shortcodes, handles y clases
  (tabla en el README, «Nombres»). Autor, Plugin URI y Author URI: Estrategia
  Web, https://estrategiaweb.es/.
- **Metas con prefijo**: las de los tipos (`location_…`, `client_image_fit`,
  `testimonial_…`) pasan a `estrategia_web_…`, también como claves de los
  bindings `core/post-meta` y de la fuente `…/location`.
- **«Powered by Estrategia Web» APAGADO por defecto** (guideline 10 de
  WordPress.org); se enciende con `add_filter(
  'estrategia_web_site_consent_powered_by', '__return_true' )`.
- **Cookie del consentimiento**: `estrategia_web_consent`, mismo formato y
  revisión. La vieja se lee si falta la nueva y el navegador la pasa a la
  nueva: nadie vuelve a ver el aviso por el cambio.
- **Alias** de los nombres viejos hasta la 2.0.0
  (`includes/class-legacy-names.php`); sustituye al alias `armazon/consent`
  que había desde la 0.5.0.
- **El migrador** (`includes/class-armazon-migration.php`, comando WP-CLI
  `estrategia-web-site migrate-armazon [--dry-run]`): contenido, metas,
  opciones, tablas de formularios, cadenas de WPML, tema y plugins activos.
  Probado en local sobre una web cargada con los nombres viejos.
- README: «Si cambias de proveedor».

## 0.5.4 — 06-10-2026

Parche: no obliga a tocar ninguna web. Cambia el peso de los WebP que se
generen desde que esté activo.

- **WebP a calidad 75** (`includes/class-webp.php`, `Webp::quality`): filtro
  `wp_editor_set_quality` de WordPress, solo cuando el formato que se guarda
  es `image/webp` (JPEG y PNG que sigan siéndolo, intactos). WordPress usa 82
  para todo. Por qué: el PageSpeed de Lavandería (móvil, 06-10-2026) daba
  ~371 KB en «mejorar la entrega de imágenes», parte por compresión. Ajuste:
  `armazon_site_webp_quality` (0 o menos, la de WordPress; tope 100).
- **La trampa:** lo ya subido sigue a 82 hasta regenerarlo, y regenerar a
  secas tiene la trampa de 0.5.1. En Lavandería lo hace el paso «webp» de
  `despliegue/desplegar.mjs` (marca `_lm_webp_calidad` por adjunto).
- Prueba: `tests/pure.test.mjs` (nivel 1), con `apply_filters` de mentira en
  `tests/php/pure.php`. No hay prueba de nivel 2 que mire el peso real: la
  calidad que llega al editor es la que dice el filtro de WordPress, y eso es
  lo que se prueba.

## 0.5.3 — 06-10-2026

Parche: no obliga a tocar ninguna web. Solo actúa con WPML.

- **La portada traducida** (`includes/class-wpml-front-page.php`): en la parte
  pública, `page_on_front` y `page_for_posts` se traducen al idioma de la
  petición con `wpml_object_id`. En Lavandería, con WPML 5.0.2 en el servidor,
  /en/ redirigía a /en/home/ porque WordPress seguía viendo la portada
  castellana; en local WPML sí lo hacía. Se apaga con
  `armazon_site_wpml_front_page`. La trampa: por WP-CLI no se puede comprobar
  (WPML no carga su parte pública); hay que pedir /en/.

## 0.5.2 — 06-10-2026

Parche: no obliga a tocar ninguna web. Cambia cómo se abren los enlaces a
otro dominio en todas las que lo actualicen.

- **Enlaces externos en pestaña nueva** (`includes/class-external-links.php`):
  todo `<a href>` del frontal a otro host sale con `target="_blank"`,
  `noopener` añadido a `rel` (sin quitar lo que traía) y `aria-label` con
  «(se abre en una pestaña nueva)» al final. Un solo filtro sobre la página
  entera (`wp_template_enhancement_output_buffer`, WP 6.9+) con
  `WP_HTML_Tag_Processor`. Internos: el mismo host, con o sin `www.`, y sus
  subdominios. No se tocan relativos, anclas, `mailto:`, `tel:`, `sms:`,
  `javascript:` ni los que ya traen `target` (el «Powered by» no se
  duplica). Se apaga con `add_filter( 'armazon_site_external_links',
  '__return_false' )`. Lo pidió David por «Hablar con un asesor» (wa.me) en
  /hosteleria/ de Lavandería.
  **Caso frontera:** la web vieja del cliente en otro dominio cuenta como
  externa.
- **Pruebas:** `node tests/run.mjs <nombre>` corre el nivel 1 y, de los
  demás, solo esa prueba (fila 7, «Cuándo corre la batería»).

## 0.5.1 — 06-10-2026

Parche: no obliga a tocar ninguna web. **Pero el WebP pide un paso aparte
para lo ya subido** (abajo, la trampa).

- **WebP** (`includes/class-webp.php`): los JPEG y PNG subidos se guardan en
  WebP, también el fichero principal (el `.jpg` queda como
  `original_image`). Solo si el servidor sabe escribir WebP. Se apaga con
  `add_filter( 'armazon_site_webp', '__return_false' )`.
  **La trampa:** lo ya subido no cambia solo, y al regenerar
  (`wp media regenerate`) las URL guardadas en el contenido no cambian: la
  `.jpg` principal sigue sirviendo pero **sin `srcset`** (peor que antes) y
  la de un tamaño intermedio se rompe. Después de regenerar, volver a
  escribir en el contenido la URL actual de la mediateca.
- **Aviso de cookies, orden de foco:** se pinta al principio del `body`
  (`wp_body_open`), así que abierto es lo primero al tabular (WCAG 2.4.3;
  antes, unos 30-35 tabuladores). Sin trampa de foco; Escape sigue sin
  cerrarlo la primera vez.
- **Aviso de cookies, el texto ya no queda pegado a los botones:** los
  márgenes del título, el texto y el «Powered by» van con dos clases. Con
  una empataban con `:root :where(p) { margin: 0 }` del `theme.json` del
  tema, que se carga después y ganaba (0,6 px en Lavandería). Hueco texto →
  botones de 24 px.
- Aviso: el enlace del texto, subrayado (WCAG 1.4.1); el «Powered by» con
  24 px de diana (2.5.8); y `view-transition-name: armazon-consent` sin
  animación, para que no salte en las transiciones del tema padre 0.7.5.
- Pruebas: `tests/webp.test.mjs` (nivel 2, nueva) y dos más en
  `tests/consent-browser.test.mjs` (nivel 3). Vistas fallar antes del
  arreglo: los tamaños salían en `.jpg`, el primer Tab iba a «Saltar al
  contenido» y el hueco era de 0,6 px.

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
