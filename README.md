# Estrategia Web — el plugin de las webs de WordPress, por módulos

**Todo lo que no es diseño** en una web de Estrategia Web, en un solo plugin
con **módulos que se encienden y se apagan** en **Estrategia Web → Módulos**.
El diseño (plantillas, partes, patrones, `theme.json`) es del tema
[Estrategia Web](E:/Repositories/armazon-wp-padre/README.md), que desde su
1.1.0 no registra ningún bloque (requisito de WordPress.org).

Decisión de David del 14-10-2026: **un tema + un plugin**. Hasta la 1.0.0
(que nunca se publicó) eran tres piezas: el plugin «Estrategia Web Site»
(este repo), el plugin «Estrategia Web Forms»
([armazon-wp-formularios](E:/Repositories/armazon-wp-formularios/README.md),
archivado) y cuatro bloques registrados por el tema. **Los nombres públicos
de la 1.0.0 no cambian** (bloques, opciones, metas, tablas, rutas REST,
filtros): cambió dónde vive el código. El marco general está en
[armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md](E:/Repositories/armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md).

- Slug, carpeta y text domain: `estrategia-web` (`estrategia-web/estrategia-web.php`) · WordPress **7.1+** · PHP **8.1+** · GPLv2+ · versión **1.1.0**
- El repo se sigue llamando `armazon-wp-sitio`; la carpeta del plugin en una web es `estrategia-web/`.
- Sin dependencias, sin `node_modules`, sin paso de compilación.

Por qué un plugin y no el tema padre: lo que se guarda y lo que funciona **no
puede desaparecer al cambiar de tema**. Lo que se ve es del tema; lo que se
guarda y lo que hace, de aquí.

## Módulos

| Módulo (carpeta) | Qué hace | Por defecto |
|---|---|---|
| `site` | datos de la empresa (Block Bindings, `[estrategia_web_company]`), tipos de contenido (clientes, opiniones, sedes, proyectos, portfolio) y SVG saneado: secciones 1 a 4 de abajo | encendido |
| `matomo` | código de Matomo, sin cookies o híbrido (sin publicar: antes dentro de `site`; necesita `site`, guarda en su opción): sección 5 | encendido |
| `consent` | el aviso de cookies y su API; (sin publicar) registro de cookies con detector y tabla para la política: secciones 6 y 6 bis | encendido |
| `google-tags` | (sin publicar) GA4, Google Ads y Tag Manager con Consent Mode v2; necesita `consent`: sección 6 ter | **apagado** |
| `webp` | tamaños de imagen en WebP: sección 7 | encendido |
| `external-links` | enlaces a otro dominio en pestaña nueva: sección 8 | encendido |
| `wpml-front-page` | con WPML, `/en/` enseña la portada traducida | encendido |
| `forms` | formularios por bloques, avisos por correo y registro de envíos: [modules/forms/README.md](modules/forms/README.md) | encendido |
| `carousel` | bloque `estrategia-web/carousel` | encendido |
| `map` | bloque `estrategia-web/map` (mapa en dos clics) | encendido |
| `whatsapp` | bloque `estrategia-web/whatsapp` y sus dos opciones | encendido |
| `language-switcher` | bloque `estrategia-web/language-switcher` (WPML) | encendido |

**Encendidos por defecto los que ya usaba Lavandería** (también `matomo`, que
salió de `site`): una web que actualiza no cambia. Lo nuevo que cambia lo que
pide la web (`google-tags`) nace apagado. Un módulo apagado **no carga nada** (ni su PHP,
ni sus bloques, ni sus assets) y **no borra nada** de lo que guardó.

**Cómo se añade un módulo:** una carpeta `modules/<slug>/` con su
`module.php`, que lleva una cabecera (nombre, descripción, si va encendido
por defecto, de qué depende). Se descubre solo: no hay lista central. Todo lo
que hay que saber —campos, el caso frontera de un módulo nuevo en una web
que ya existía, las trampas— está en la cabecera de
[includes/class-modules.php](includes/class-modules.php). El ejemplo mínimo
es [modules/webp/module.php](modules/webp/module.php); el completo,
[modules/forms/module.php](modules/forms/module.php).

    estrategia-web.php          entrada: constantes y arranque del núcleo
    includes/                   el núcleo, siempre cargado:
      class-modules.php           el cargador (CÓMO SE AÑADE UN MÓDULO)
      class-plugin.php            traducciones y ganchos estrategia_web_activate / _deactivate
      class-blocks.php            etiquetas de autoRegister, traducciones del editor, alias armazon/…
      class-armazon-migration.php el migrador de «Armazón» (para todas las piezas)
      admin/class-modules-page.php  la pantalla «Estrategia Web → Módulos»
    modules/<slug>/             module.php (cabecera + arranque), includes/, blocks/, assets/, tests/, uninstall.php
    languages/                  estrategia-web.pot · estrategia-web-es_ES.po · .l10n.php (uno para todo)
    tests/                      corredor, lib.mjs, blueprint y las pruebas del núcleo
    tools/i18n.mjs              extrae (también las cabeceras de módulo), fusiona y compila
    tools/open-cookie-database.mjs  regenera el extracto de la Open Cookie Database (módulo consent)
    wpml-config.xml             WPML solo lee el de la raíz: aquí van los de todos los módulos
    uninstall.php               llama al uninstall.php de cada módulo

---

## El contrato

Es lo que leen el tema padre, los temas hijos y la carga de contenido. **Estos
nombres no cambian en una versión menor** (ver «Versiones»).

### 1. Datos de la empresa — opción `estrategia_web_site`

Pantalla **Datos de la empresa** (menú propio, capacidad `manage_options`).
Una sola opción (array), registrada con la Settings API y expuesta en REST con
su esquema: `GET/POST /wp/v2/settings` → clave `estrategia_web_site`.

| Clave | Tipo | Saneado |
|---|---|---|
| `company_name` | texto | sin HTML |
| `legal_name` | texto | sin HTML |
| `tax_id` | texto | sin HTML |
| `registry` | texto (0.4.0): la inscripción registral **tal como la dice el aviso legal**, sin «Inscrita en» («Registro Mercantil de Mallorca, tomo 1234, folio 56, hoja PM-7890, inscripción 1.ª»). Una sola clave a propósito: el aviso la escribe como frase y no toda empresa está en el Mercantil | sin HTML |
| `email` | texto | correo válido; si no, **se queda el anterior** |
| `phone` | texto | tal como se escribe («971 12 34 56») |
| `phone_secondary` | texto | ídem |
| `whatsapp` | texto | se guarda **solo dígitos con prefijo** («+34 600…» → `34600…`); inválido → se queda el anterior |
| `whatsapp_message` | texto | sin HTML |
| `address` | texto varias líneas | sin HTML, conserva saltos |
| `schedule` | texto varias líneas | ídem |
| `map_url` | URL | http(s); inválida → se queda la anterior |
| `social` | objeto: `facebook`, `instagram`, `linkedin`, `x`, `youtube`, `tiktok` | URL http(s) cada una |
| `enabled_types` | array ⊂ `client`, `testimonial`, `location`, `project`, `portfolio` | otro valor → **400** entero |
| `allow_svg` | booleano | |
| `rewrite_slugs` | objeto `nombre → slug` con los nombres `client`, `client_sector`, `testimonial`, `location`, `project`, `project_category`, `portfolio`, `portfolio_category` (0.2.0) | `sanitize_title` («Mi Cliente!» → `mi-cliente`); `''` = el de siempre; otro nombre → **400** entero. Se fusiona **por clave**. Al cambiar, se rehacen las reglas de URL |

**Se guarda fusionando**: un POST con `{"estrategia_web_site": {"phone": "…"}}` cambia
el teléfono y deja lo demás. Así la carga de contenido rellena por partes.

```bash
# REST (como administrador; Playground o una web con contraseña de aplicación)
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"estrategia_web_site":{"phone":"971 12 34 56","enabled_types":["client","location"]}}'

# WP-CLI: pasa por el mismo saneado (sanitize_option), así que también fusiona.
# (Deducido del código, no probado: las pruebas van por REST.)
wp option update estrategia_web_site '{"phone":"971 12 34 56"}' --format=json
```

En PHP, un tema lee un dato con `\EstrategiaWeb\Site\Company::value( 'phone' )`
(no con `get_option`, que se salta el filtro y las derivadas).

### 2. Fuente de Block Bindings `estrategia-web-site/company`

`args.key` = cualquier clave de arriba (menos `enabled_types`, `allow_svg` y `rewrite_slugs`),
`social.<red>` con punto, y cuatro **derivadas**:

| Clave | Ejemplo |
|---|---|
| `phone_href` | `tel:+34971123456` (prefijo 34 si no lleva; filtro `estrategia_web_site_default_country_code`) |
| `phone_secondary_href` | ídem, de `phone_secondary` (0.2.0) |
| `email_href` | `mailto:hola@empresa.com` |
| `whatsapp_href` | `https://wa.me/34600111222?text=Hola%2C%20quer%C3%ADa…` |

Sirve para el **contenido** de párrafos y encabezados (texto escapado; las
varias líneas salen con `<br>`) y la **url** de botones y enlaces. En el
editor el bloque **enseña el valor** (o `[Nombre del dato]` si está vacío) y
la fuente sale en el panel «Atributos» con la lista de datos; no se puede
editar desde el bloque.

Un párrafo con el teléfono:

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"phone"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

Un botón de WhatsApp (el texto del botón es fijo; la url, enlazada):

```html
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"estrategia-web-site/company","args":{"key":"whatsapp_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Escríbenos por WhatsApp</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
```

Botón «Llamar» con texto y url enlazados a la vez (el `text` del botón no
tiene prueba propia; el `url` sí):

```html
<!-- wp:button {"metadata":{"bindings":{"text":{"source":"estrategia-web-site/company","args":{"key":"phone"}},"url":{"source":"estrategia-web-site/company","args":{"key":"phone_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"></a></div>
<!-- /wp:button -->
```

### 2 bis. Un dato dentro de una frase: `[estrategia_web_company key="…"]` (0.4.0)

Un binding sustituye el párrafo **entero** (WP 7.1 no tiene bindings en
línea), así que para el **aviso legal** (LSSI, art. 10) hay un shortcode.
Mismas claves que la fuente (`Company::value()`: también `social.<red>` y las
derivadas), texto escapado y **en una línea**: dirección y horario salen
unidos con «, ». Una clave inexistente o vacía no pinta nada.

```html
<!-- wp:paragraph -->
<p>Titular: [estrategia_web_company key="legal_name"], con NIF [estrategia_web_company key="tax_id"] y domicilio en [estrategia_web_company key="address"]. Teléfono: [estrategia_web_company key="phone"]. Correo: [estrategia_web_company key="email"]. Inscrita en el [estrategia_web_company key="registry"].</p>
<!-- /wp:paragraph -->
```

- En el **editor se ve el shortcode** tal cual; el valor sale en la web. Un
  dato solo en su párrafo, mejor con binding (que sí se ve en el editor).
- Funciona en el contenido y en las plantillas de bloques; **no** en un
  atributo de bloque ni en un `href` (para enlaces, el binding del botón).
- `[[estrategia_web_company …]]` con dos corchetes es el **escape** de WordPress:
  sale literal. El porqué de shortcode y no bloque, en la cabecera de
  [modules/site/includes/class-shortcode.php](modules/site/includes/class-shortcode.php).

### 2 ter. Fuente de Block Bindings `estrategia-web-site/location` (0.2.0)

Los datos de **una sede** (tipo `location`, que tiene que estar activado).
Dos formas de decir cuál, por los `args`:

| args | Qué sede |
|---|---|
| `{"slug": "maioris", "key": "…"}` | esa, desde **cualquier** página. El identificador es el **slug** de la sede (su «enlace permanente»), no el título |
| `{"key": "…"}` | la del **contexto**: dentro de una Consulta de sedes, la de cada fila (`postId`). Fuera de una sede, vacío |

Claves:

| Clave | Qué |
|---|---|
| `title` | el nombre de la sede |
| `estrategia_web_location_address`, `estrategia_web_location_schedule` | varias líneas (salen con `<br>`) |
| `estrategia_web_location_phone`, `estrategia_web_location_email`, `estrategia_web_location_map_url`, `estrategia_web_location_map_embed`, `estrategia_web_location_static_map`, `estrategia_web_location_static_map_id` (0.4.0) | tal cual |
| `estrategia_web_location_phone_href` | `tel:+34971112233`, de `estrategia_web_location_phone` (mismo prefijo que la empresa) |
| `estrategia_web_location_email_href` | `mailto:…`, de `estrategia_web_location_email` |

Mismo tratamiento que la de la empresa: texto escapado con `<br>`, y en `url`
solo `http`, `https`, `tel` y `mailto`. **Una sede inexistente, en borrador o
privada sale vacía** (en el editor, `[Nombre del dato]`). En el editor enseña
el valor de las sedes publicadas al abrirlo; una sede creada después sale
entre corchetes hasta recargar.

Botón con el teléfono de la sede «maioris» (texto y url):

```html
<!-- wp:button {"metadata":{"bindings":{"text":{"source":"estrategia-web-site/location","args":{"slug":"maioris","key":"estrategia_web_location_phone"}},"url":{"source":"estrategia-web-site/location","args":{"slug":"maioris","key":"estrategia_web_location_phone_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"></a></div>
<!-- /wp:button -->
```

La dirección de cada sede dentro de una Consulta (`"postType":"location"`):

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/location","args":{"key":"estrategia_web_location_address"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

### 3. Tipos de contenido

Cada uno se registra **solo si está en `enabled_types`**. Desactivarlo **no
borra nada**: sus entradas siguen en la base de datos y vuelven tal cual al
reactivarlo. Todos `public`, `show_in_rest` (ruta REST = nombre del tipo:
`/wp/v2/client`…), con archivo, icono y etiquetas en castellano en es_ES.

| Tipo | Menú | Slug (es_ES) | Usa | Campos (`register_post_meta`, `single`, `show_in_rest`) | Taxonomía |
|---|---|---|---|---|---|
| `client` | Clientes | `/clientes/` | título = nombre, imagen destacada = logo/foto, extracto, orden manual | `client_url` (URL), `estrategia_web_client_image_fit` (`cover` por defecto o `contain`; otro valor → `cover`; 0.2.0) | `client_sector` (jerárquica, slug `/sector/`) |
| `testimonial` | Opiniones | `/opiniones/` | título (interno), contenido = la opinión, imagen = foto del autor, orden | `estrategia_web_testimonial_author`, `testimonial_role` (texto), `estrategia_web_testimonial_rating` (entero 0-5; **0 = sin valoración**), `testimonial_client` (id de un `client`, o 0) | — |
| `location` | Sedes | `/sedes/` | título = sede, contenido, imagen = mapa estático, extracto, orden | `estrategia_web_location_address`, `estrategia_web_location_schedule` (varias líneas), `estrategia_web_location_phone`, `estrategia_web_location_email`, `estrategia_web_location_map_url` (enlace a Google Maps), `estrategia_web_location_map_embed` (URL de embed para el bloque `estrategia-web/map` del padre), `estrategia_web_location_static_map` (0.4.0: imagen del mapa estático servida por **este** sitio: ruta del tema `assets/maps/sede.webp`, ruta del sitio `/wp-content/…` o URL del mismo dominio, acabada en webp/png/jpg/gif/avif/svg; cualquier otra → `''`), `estrategia_web_location_static_map_id` (0.4.0: entero, id de un adjunto de **imagen**; si no, 0) | — |
| `project` | Proyectos | `/proyectos/` | título, contenido, imagen, extracto, orden, revisiones | — | `project_category` (`/categoria-proyecto/`) |
| `portfolio` | Portfolio | `/portfolio/` | ídem | — | `portfolio_category` (`/categoria-portfolio/`) |

Todos los tipos llevan `custom-fields`, así que los campos se pintan con la
fuente **`core/post-meta`** dentro de una Consulta. Una Consulta de clientes
de un sector (id 3) con logo y nombre:

```html
<!-- wp:query {"queryId":1,"query":{"perPage":12,"pages":0,"offset":0,"postType":"client","order":"asc","orderBy":"title","inherit":false,"taxQuery":{"client_sector":[3]}}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-featured-image /-->
<!-- wp:post-title /-->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
```

(El orden manual —`page-attributes`, `"orderBy":"menu_order"`— está en los
tipos pero aún no tiene prueba en una Consulta: ver PENDIENTES.)

Un campo dentro de la Consulta (el autor de una opinión):

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"estrategia_web_testimonial_author"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

El mapa de cada sede con `estrategia-web/map` del tema padre, dentro de una Consulta
de `location` (0.4.0: el mapa estático, de `estrategia_web_location_static_map`):

```html
<!-- wp:estrategia-web/map {"metadata":{"bindings":{"address":{"source":"core/post-meta","args":{"key":"estrategia_web_location_address"}},"embedUrl":{"source":"core/post-meta","args":{"key":"estrategia_web_location_map_embed"}},"mapsUrl":{"source":"core/post-meta","args":{"key":"estrategia_web_location_map_url"}},"staticMapUrl":{"source":"core/post-meta","args":{"key":"estrategia_web_location_static_map"}}}}} /-->
```

`estrategia_web_location_static_map_id` se guarda y valida, pero **hoy no se puede enlazar**
a `staticMapId`: el padre solo declara enlazables sus atributos de texto
(`inc/blocks.php`) y `staticMapId` es un número. Para la mediateca, la URL
del adjunto en `estrategia_web_location_static_map` (mismo dominio) sirve igual. El tema
vuelve a comprobar la URL al pintar (`estrategia_web_map_static_url`).

**Slugs públicos por web: `rewrite_slugs`** en la opción (0.2.0). Las rutas
son del plugin, no del tema: una web con una **página** `/clientes/` (que el
archivo del tipo pisaría) mueve el archivo así, por REST o en su carga de
contenido:

```bash
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"estrategia_web_site":{"rewrite_slugs":{"client":"cliente"}}}'
```

Orden: el slug traducido ← el de `rewrite_slugs` si no está vacío ← el filtro
`estrategia_web_site_rewrite_slug( $slug, $tipo_o_taxonomia )`, que **sigue
mandando** (recibe ya el de la opción). Un cambio por el filtro no rehace las
reglas solo; uno por la opción, sí.

**Clases que el plugin pone en una entrada** (filtro `post_class`: salen en
el `<li>` de una Consulta y en la ficha). Describen el dato; **el plugin no
pone CSS**, el aspecto es del tema:

| Clase | Cuándo | Para qué (en el tema) |
|---|---|---|
| `has-image-fit-contain` | un `client` con `estrategia_web_client_image_fit` = `contain` (0.2.0) | la imagen es un **logo**: que no se recorte, p. ej. `.has-image-fit-contain .wp-block-post-featured-image img { object-fit: contain; }` |

Solo en la web: la vista previa de la Consulta en el editor la pinta JS y no
pasa por `post_class` (deducido del núcleo, no probado).

### 4. SVG

Con `allow_svg` activado, **solo** quien tiene `unfiltered_html` o
`manage_options` puede subir `.svg` (no `.svgz`). Siempre se **sanea en
servidor** por lista blanca de elementos y atributos
([modules/site/includes/class-svg-sanitizer.php](modules/site/includes/class-svg-sanitizer.php)): fuera
`<script>`, atributos `on*`, `<foreignObject>`, enlaces `javascript:`, URL
externas, `@import`, entidades (XXE) y comentarios; se quedan degradados,
máscaras, filtros, texto y `<style>` con `url(#id)`. Las medidas (width/height
o viewBox) salen en la mediateca y en `media_details`, y la imagen destacada
funciona con un SVG. **Sin dependencias**: el porqué, en la cabecera de esa
clase. Un tema no debe añadir `svg` a `upload_mimes` por su cuenta (el tema
hijo viejo de Lavandería lo hacía, sin sanear).

### 5. Matomo (0.3.0)

Tres claves más en la opción `estrategia_web_site` (misma fusión, mismo REST), y
una sección **Estadísticas (Matomo)** en la pantalla:

| Clave | Tipo | Saneado |
|---|---|---|
| `matomo_url` | URL | http(s); se guarda **acabada en `/`** y sin el `matomo.php`/`matomo.js` que se pega a veces; inválida → se queda la anterior |
| `matomo_site_id` | entero ≥ 0 | `0` = sin configurar; negativo → **400** |
| `matomo_mode` | `cookieless` (por defecto) o `hybrid` | otro valor → **400** |

```bash
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"estrategia_web_site":{"matomo_url":"https://stats.ejemplo.com/","matomo_site_id":3}}'
```

- **Sin URL o sin id no se imprime nada.** Con las dos, el código de
  seguimiento va en línea en el `<head>` (`<script id="estrategia-web-site-matomo">`).
- **No se mide** en el escritorio, el editor, las vistas previas ni a quien
  tiene sesión y puede editar (`edit_posts`). Filtro
  `estrategia_web_site_matomo_track( $track )` para cambiarlo.
- **`cookieless`** (el de la fila 16): `disableCookies`, nada que preguntar.
- **`hybrid`**: mide sin cookies (`requireCookieConsent`) y, cuando el
  visitante acepta **«statistics»** en el **aviso de cookies** (el de este
  plugin desde 0.5.0; ver la sección 6), llama a
  `rememberCookieConsentGiven`; si la retira, a `forgetCookieConsentGiven`.
  Escucha el evento `estrategia-web:consent` y mira `window.estrategiaWebConsent.get()`
  (el contrato del aviso), y **declara él mismo la categoría** con el filtro
  `estrategia_web_consent_categories` (prioridad 99): el tema no tiene que
  acordarse. Desde 0.5.0 funciona con cualquier tema.
  **La legalidad del híbrido no está verificada** (criterio de la CNIL): por
  eso no es el de por defecto.
- «Sin IP completa» se configura en el servidor de Matomo, no aquí.

El porqué de cada decisión y sus trampas, en la cabecera de
[modules/matomo/includes/class-matomo.php](modules/matomo/includes/class-matomo.php).
Desde la 1.1.0 Matomo es su propio módulo (`matomo`, encendido por defecto,
necesita `site`); la opción y su saneado siguen en `estrategia_web_site`, sin
migración.

### 6. Aviso de cookies (0.5.0)

Vino del tema padre (`armazon-wp-padre` ≤ 0.6.0, `inc/consent.php`) **sin
cambiar el contrato**: el consentimiento es funcionalidad, no presentación, y
una web que cambie de tema no puede quedarse sin aviso. La documentación
entera (decisiones, cómo se mantiene, trampas) está en la cabecera de
[modules/consent/includes/class-consent.php](modules/consent/includes/class-consent.php).

- **Sale solo si la web declara categorías** (`statistics`, `maps`,
  `marketing`) con el filtro `estrategia_web_consent_categories`; sin ninguna, no
  hay aviso, que es lo normal. Matomo híbrido declara `statistics` él solo.

      add_filter( 'estrategia_web_consent_categories', fn( $c ) => array_merge( (array) $c, array( 'maps' ) ) );

- **La cookie es la misma** (`estrategia_web_consent`, `"<revisión>.<cat~cat>.<unix>"`,
  la misma revisión): quien ya eligió con el aviso del padre no lo vuelve a ver.
- **PHP:** `estrategia_web_consent_has( 'maps' )`, `estrategia_web_consent_categories()` y
  las demás `estrategia_web_consent_*` de siempre
  ([modules/consent/includes/consent-functions.php](modules/consent/includes/consent-functions.php)), y
  `\EstrategiaWeb\Site\Consent`. Filtros: `estrategia_web_consent_categories`,
  `estrategia_web_consent_revision`, `estrategia_web_consent_max_age`, `estrategia_web_consent_text`.
- **JS:** `window.estrategiaWebConsent` (`categories`, `has()`, `get()`, `open()`,
  `grant()`), el evento `estrategia-web:consent` en `document` con
  `detail.accepted`, **`estrategia-web:consent-ready`** (nuevo en 0.5.0: una vez al
  arrancar, también sin elección, con `accepted: null`; lo usa Matomo), el
  store de Interactivity `estrategia-web/consent`, y
  `<script type="text/plain" data-estrategia-web-consent="statistics">` para lo que
  solo se ejecuta con consentimiento. WP Consent API si está, sin depender.
- **Enlace «Configurar cookies»:** bloque **`estrategia-web-site/consent`** (atributo
  `label`), o `[estrategia_web_consent_settings label="…"]`. El nombre viejo
  **`estrategia-web/consent` se sigue registrando como alias** oculto del
  insertador, con el mismo HTML (clase `wp-block-estrategia-web-consent`): el
  contenido y las partes ya guardados con él siguen funcionando. Quitar el
  alias es versión mayor.
- **Estilos:** hoja base neutra en el plugin
  ([modules/consent/blocks/consent/style.css](modules/consent/blocks/consent/style.css)) que lee los tokens de
  `theme.json` (`base`, `contrast`, `border`, espaciado, tamaños) con valores
  de reserva. El tema afina con sus tokens o con CSS sobre `.estrategia-web-consent`.
- **Convivencia:** con un tema padre viejo que aún trae su aviso, el plugin
  se aparta (no hay dos avisos ni funciones redeclaradas). Con el padre 0.7.0
  y este plugin ≤ 0.4.0 no hay aviso: se actualizan los dos a la vez.

**«Powered by»**: al pie del aviso, en letra pequeña y alineado al final,
**«Powered by Estrategia Web»** enlazando a `https://estrategiaweb.es/`, en
pestaña nueva, `rel="noopener"` y **sin `nofollow`** (cuenta como enlace).
Texto traducible (`Powered by %s`; en es_ES se deja en inglés). Se cambia o
se quita con el **filtro** `estrategia_web_site_consent_powered_by` (no con una
opción: es la firma de quien hace la web, no un dato que el cliente toque, y
una opción sería otra clave del contrato):

    // Otro texto o URL (lo que no se pase, se queda):
    add_filter( 'estrategia_web_site_consent_powered_by', fn( $p ) => array_merge( $p, array( 'text' => 'Hecha por X', 'url' => 'https://x.es/' ) ) );
    // Quitarlo:
    add_filter( 'estrategia_web_site_consent_powered_by', '__return_false' );

`url` vacía (o que no sea http/https) pinta el texto sin enlace; `text`
vacío no pinta la línea. Su color es el del texto del aviso, sin opacidad:
contraste AA medido en las pruebas (11,05:1 en Lavandería). Un tema que la
atenúe tiene que volver a medirlo.

**Orden de foco (0.5.1):** el aviso se pinta al **principio del `body`**
(`wp_body_open`; en `wp_footer` si el tema no lo llama), así que mientras
está abierto el primer Tab cae en él. No roba el foco al cargar, no lo
atrapa (Tab sale, Mayús+Tab vuelve; `aria-modal="false"`) y **Escape no lo
cierra la primera vez**: hay que elegir. Reabierto, Escape sí. El hueco
entre el texto y los botones es de 24 px y resiste el
`:root :where(p) { margin: 0 }` de un `theme.json`. En las transiciones
entre páginas del tema padre (0.7.5) se queda quieto
(`view-transition-name: estrategia-web-consent`).

**Categorías (sin publicar, rama `modulo/cookies`)**: necesarias, preferencias
(`preferences`, nueva), analítica (`statistics`) y publicidad (`marketing`),
más mapas (`maps`). Los slugs no cambian (la revisión del aviso es su hash);
se aceptan los alias `analytics`, `advertising` y `ads`. Las declara el código
con el filtro y, además, el administrador en **Estrategia Web → Cookies**
(opción `estrategia_web_cookies_settings`, clave `ask`). El panel se reabre
también desde cualquier enlace a `#estrategia-web-consent` o un elemento con
la clase `estrategia-web-consent-open` (útil en el menú del pie).

### 6 bis. Registro de cookies, detector y tabla de la política (sin publicar)

Todo en el módulo `consent`; cada pieza documenta lo suyo en su cabecera:
[class-cookie-registry.php](modules/consent/includes/class-cookie-registry.php)
(qué se guarda y de dónde salen las propuestas),
[class-cookie-rest.php](modules/consent/includes/class-cookie-rest.php)
(el detector y sus límites),
[class-cookie-table.php](modules/consent/includes/class-cookie-table.php)
(la tabla y su traducción) y
[admin/class-cookies-page.php](modules/consent/includes/admin/class-cookies-page.php).

- **Detector**: al ACEPTAR alguna categoría, el navegador manda los NOMBRES de
  sus cookies (nunca valores) a `POST /wp-json/estrategia-web/v1/cookies/seen`,
  solo si su lista cambió o cada 7 días. Sin nonce (la caché de página lo
  caducaría) y con límites: 1 informe / 10 min por IP (hash con sal del día,
  no se guarda), 100 / hora para toda la web, 60 nombres, 300 filas.
- **Propuestas**: primero nuestra lista (la cookie del aviso, WordPress, WPML
  —`wp-wpml_current_language` necesaria, 1 día—, Polylang, Matomo) y luego el
  extracto de la [Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database)
  (Apache 2.0) en [modules/consent/data/](modules/consent/data/NOTICE),
  regenerable con `node tools/open-cookie-database.mjs` (`--seco`, `--csv`).
  Comodines: `_ga_*` recoge `_ga_ABC123`.
- **Pantalla Estrategia Web → Cookies**: pendientes / confirmadas /
  ignoradas, categoría, proveedor, finalidad y duración editables, añadir a
  mano las de terceros, y aviso si hay cookies confirmadas en una categoría que
  el aviso no pregunta. Administración también por REST
  (`GET/POST/DELETE /wp-json/estrategia-web/v1/cookies`, `manage_options`).
- **Tabla para la política**: bloque **`estrategia-web/cookie-table`**
  (atributos `category` y `headingLevel`) o
  `[estrategia_web_cookie_table category="" heading_level="3"]`. Solo lo
  confirmado. Duraciones como «2 years» se traducen solas; proveedor,
  finalidad y duración libre, por WPML String Translation (contexto
  `estrategia-web-cookies`).

### 6 ter. Etiquetas de Google — módulo `google-tags` (sin publicar)

Apagado por defecto. GA4 (`G-…`), Google Ads (`AW-…`, con conversión opcional
de envío de formulario) y Tag Manager (`GTM-…`) en **Estrategia Web → Google**
(opción `estrategia_web_google_tags`, también por `/wp/v2/settings`). Campo
vacío = no se carga nada. Consent Mode v2: `default` todo denied antes de
cualquier etiqueta y `update` al elegir (Analítica → `analytics_storage`;
Publicidad → `ad_storage`, `ad_user_data`, `ad_personalization`). **Modo
básico** (por defecto): nada de Google hasta aceptar Analítica o Publicidad;
**modo avanzado**: Google se carga ya, denegado (pings sin cookies antes de
consentir: decisión del cliente). Todo, en la cabecera de
[class-google-tags.php](modules/google-tags/includes/class-google-tags.php).

### 7. WebP (0.5.1)

Los JPEG y PNG que se suben se guardan **en WebP**: el filtro
`image_editor_output_format` de WordPress, solo si el editor de imágenes del
servidor sabe escribirlo (`wp_image_editor_supports`). Medido: WordPress
convierte **también el fichero principal** (`foto.jpg` → la mediateca sirve
`foto.webp`; el `.jpg` queda como `original_image`). Apagar, en el tema:

    add_filter( 'estrategia_web_site_webp', '__return_false' );

**Lo ya subido no cambia solo**, y **regenerar sin tocar el contenido lo
empeora**: `wp media regenerate` pasa el meta a `.webp`, pero las URL
guardadas en las páginas no cambian. La del fichero principal (`foto.jpg`,
la que pone la carga de contenido de Lavandería en todas) sigue sirviendo,
**pero se queda sin `srcset`**: el JPEG entero a cualquier ancho. La de un
tamaño intermedio (`foto-1024x683.jpg`) apunta a un fichero borrado. Después
de regenerar hay que volver a escribir en el contenido la URL actual de la
mediateca. El detalle, en la cabecera de
[modules/webp/includes/class-webp.php](modules/webp/includes/class-webp.php).

**Calidad (0.5.4): 75**, no el 82 que WordPress pone a todo
(`wp_editor_set_quality`, solo cuando lo que se guarda es `image/webp`: un
JPEG que siga siendo JPEG no cambia). Es la de `cwebp` por defecto; en fotos
pesa ~20 % menos y no se distingue. Cambiarla, en el tema:

    add_filter( 'estrategia_web_site_webp_quality', fn() => 82 );   // 0: la de WordPress

**Igual que el formato, solo vale para lo que se genere desde entonces.** Lo
ya subido sigue a 82 hasta regenerarlo (en Lavandería, el paso «webp» de su
desplegador, que lo rehace sin tocar el contenido y vacía la caché).

### 8. Enlaces externos en pestaña nueva (0.5.2)

Todo `<a href>` del **frontal** cuyo host no es el de `home_url()` sale con
`target="_blank"`, `noopener` añadido a `rel` (sin quitar `nofollow`,
`sponsored`…) y un `aria-label` con su texto y **«(se abre en una pestaña
nueva)»** (el mismo texto traducible que el «Powered by»; si ya tenía
`aria-label`, el aviso va al final). Caso real: «Hablar con un asesor»
(wa.me) en /hosteleria/ de Lavandería; también Google Maps, redes y
cualquier enlace del contenido a otro dominio.

- **Un filtro sobre la página entera**, `wp_template_enhancement_output_buffer`
  del núcleo (6.9+), con `WP_HTML_Tag_Processor`: pilla bloques, plantillas,
  shortcodes y el aviso de cookies. El escritorio, el editor, REST y los
  feeds no pasan por ahí.
- **Internos:** el mismo host, con o sin `www.`, y sus subdominios
  (`tienda.x.es` desde `x.es`).
- **No se tocan:** relativos, anclas, `mailto:`, `tel:`, `sms:`,
  `javascript:` (todo lo que no sea http/https) y **los que ya traen
  `target`**, también `_self`: es la forma de dejar uno en la misma pestaña.
  Por eso el «Powered by» no se duplica.
- **Apagar** (encendido por defecto), en el tema:

      add_filter( 'estrategia_web_site_external_links', '__return_false' );

- **Caso frontera:** el enlace a la **web vieja del cliente en otro dominio
  cuenta como externo**: decide el host de `home_url()`, no de quién es la
  web. En el servidor de pruebas (`*.sg-host.com`) también los enlaces al
  dominio definitivo. Si una web tiene de verdad dos dominios propios,
  `target="_self"` en esos enlaces.

Decisiones, trampa (corre dentro del búfer de salida: un fallo no rompe la
página pero deja los enlaces sin tocar, en silencio) y cómo se mantiene, en
la cabecera de [modules/external-links/includes/class-external-links.php](modules/external-links/includes/class-external-links.php).

### Glosario (código en inglés, dominio en castellano)

`client` cliente · `testimonial` opinión · `location` sede · `project`
proyecto · `portfolio` portfolio · `sector` sector del cliente · `company`
empresa · `binding` enlace de un bloque a un dato · `consent` aviso de
cookies / consentimiento · `powered by` la firma al pie del aviso ·
`external link` enlace a otro dominio.

---

## Pruebas (fila 7)

    node tests/run.mjs             1 → 2 → 3, para en el primer rojo
    node tests/run.mjs --nivel 1   solo el 1 (cada commit, ~1 s)
    node tests/run.mjs webp        el 1 y, de los demás, solo los que empiecen por «webp»
    node tests/run.mjs forms/      el 1 y, de los demás, los del módulo forms

Al desarrollar, **el nivel 1 siempre y, de los niveles 2 y 3, solo la prueba
nueva** (fila 7, «Cuándo corre la batería»). La tanda entera, cuando la pida
David o antes de llevar el plugin a una web en producción.

**Dónde están (1.1.0):** las del núcleo en `tests/` (el cargador de módulos:
[tests/modules.test.mjs](tests/modules.test.mjs), nivel 1, y
[tests/modules-page.test.mjs](tests/modules-page.test.mjs), nivel 2) y las de
cada módulo en `modules/<slug>/tests/`. El corredor las descubre en los dos
sitios. Las de la tabla de abajo se mudaron de `tests/` a su módulo sin
cambiar nada más que la ruta del `lib.mjs`; las de formularios están en
[modules/forms/README.md](modules/forms/README.md). **Las de los cuatro
bloques que vinieron del tema siguen en el repo del tema**
(`armazon-wp-padre/tests/`), que monta este plugin: miden los bloques con el
CSS del tema.

El corredor arranca **Playground** (WP 7.1, PHP 8.3) en el **puerto 9404** si
no hay uno, con el plugin montado como `plugins/estrategia-web` y
[tests/blueprint.json](tests/blueprint.json), y lo mata al terminar con
`taskkill /T /F`. Todos los módulos comparten ese WordPress, con un mu-plugin
de pruebas por módulo que lo necesite. Las pruebas hablan con la **API
REST de verdad** como administrador y como autor (contraseñas de aplicación
que crea [tests/wp/estrategia-web-site-test.php](tests/wp/estrategia-web-site-test.php)).
La trampa de compartirlo: ese mu-plugin pone el sitio en `es_ES` y las de
formularios esperan inglés; su `reset()` lo marca y vuelve a `en_US` (ver
`modules/forms/tests/wp/estrategia-web-forms-test.php`).

| Fichero (en `modules/<módulo>/tests/`) | Nivel | Qué prueba |
|---|---|---|
| [site/pure](modules/site/tests/pure.test.mjs) | 1 | tel:/mailto:/wa.me; SVG hostil saneado, SVG inaceptables, medidas; (0.5.2) qué enlace es externo (otro host, www, subdominio, `//`, relativos, anclas, otros esquemas) y `rel` con `noopener` sin perder lo que traía; (0.5.4) la calidad del WebP: 75 solo para `image/webp`, el filtro, 0 y el tope |
| [external-links/external-links](modules/external-links/tests/external-links.test.mjs) | 2 | enlaces externos (0.5.2) en la página servida: el botón de wa.me con `_blank`, `noopener` y `aria-label`; internos, www, relativo, ancla, tel, mailto intactos; con `target` intacto; con `rel` y con `aria-label`, añadidos; enlace-imagen con su alt; «Powered by» sin duplicar; REST sin tocar; el filtro lo apaga. Vista en rojo sin el `boot()` |
| [site/settings](modules/site/tests/settings.test.mjs) | 2 | guardar y leer por REST, fusión, saneado (correo/URL/WhatsApp inválidos no se guardan), esquema, `rewrite_slugs` (saneado, fusión por clave, 400), autor sin permiso |
| [site/bindings](modules/site/tests/bindings.test.mjs) | 2 | párrafo con el teléfono, botón con wa.me, tel: (también `phone_secondary_href`), mailto:, `<br>`, escapado, `social.<red>` |
| [site/location](modules/site/tests/location.test.mjs) | 2 | fuente de sedes: por slug (botón texto+url, título, dirección, mailto, mapa), inexistente/borrador/fuera de contexto vacíos, por contexto en una Consulta |
| [site/types](modules/site/tests/types.test.mjs) | 2 | tipo inexistente sin activar; activar/desactivar sin perder contenido; slug `/clientes/`; meta saneado; Consulta por `client_sector` con título e imagen; `estrategia_web_client_image_fit` y la clase `has-image-fit-contain`; `rewrite_slugs` mueve el archivo a `/cliente/` y deja libre la página `/clientes/` |
| [site/legal](modules/site/tests/legal.test.mjs) | 2 | datos legales (0.4.0): `registry` saneado y fusionado; frase legal con `[estrategia_web_company]` (razón social, NIF, domicilio en una línea, teléfono, correo, registro, escapado); clave inexistente vacía; `registry` por binding |
| [site/static-map](modules/site/tests/static-map.test.mjs) | 2 | mapa estático de una sede (0.4.0): `estrategia_web_location_static_map` acepta ruta del tema, del sitio y URL del mismo dominio, y rechaza otro dominio, `//`, `javascript:`, `..`, no-imagen; `estrategia_web_location_static_map_id` solo adjuntos de imagen; en una Consulta, core/post-meta da a cada sede el suyo (párrafo e imagen); también por la fuente de sedes |
| [site/svg](modules/site/tests/svg.test.mjs) | 2 | SVG con `<script>` subido saneado (multipart y sideload), medidas, autor no puede, `allow_svg` apagado |
| [matomo/matomo](modules/matomo/tests/matomo.test.mjs) | 2 | Matomo (0.3.0): sin URL o sin id no se imprime; por defecto `disableCookies`; híbrido con `requireCookieConsent` y la categoría «statistics» declarada; saneado (URL, id, 400 del modo); sus claves no son datos enlazables |
| [matomo/matomo-browser](modules/matomo/tests/matomo-browser.test.mjs) | 3 | con el `matomo.js` real ([modules/matomo/tests/fixtures/matomo.js](modules/matomo/tests/fixtures/matomo.js), 5.4.0, BSD-3) servido por una ruta falsa y, desde 0.5.0, con el **aviso de cookies real** (sus botones y su cookie, no un evento simulado): sin configuración no se pide; sin cookies mide, no deja cookies y no hay aviso; híbrido sin cookies hasta «Aceptar todas» (solo «mapas» no vale), `mtm_cookie_consent` y `_pk_id` al aceptar, borradas al «Rechazar todas»; aviso ya contestado al cargar; elección caducada olvida; a un administrador no se le mide |
| [consent/consent](modules/consent/tests/consent.test.mjs) | 2 | aviso de cookies (0.5.0): sin categorías no sale; con ellas, en orden estable y con los tres botones; revisión y cookie iguales a las del padre; Matomo híbrido lo hace salir; «Powered by» (texto, `href`, `_blank`, `rel="noopener"` sin `nofollow`, después de los botones); el filtro cambia texto y URL, una URL vacía o no http(s) deja el texto sin enlace, `false` lo quita; enlace por bloque nuevo, alias viejo y shortcode |
| [consent/consent-browser](modules/consent/tests/consent-browser.test.mjs) | 3 | aviso de cookies (0.5.0): sale; aceptar y rechazar pesan igual; rechazar guarda la cookie y persiste; el enlace (nuevo y alias) lo reabre con el foco dentro y Escape lo cierra; contrato JS (evento, `has`, `get`, `categories`, `grant`, `open`) y scripts diferidos; una cookie del aviso del padre sigue valiendo; «Powered by» visible, más pequeño que los botones y con contraste AA medido; (0.5.1) abierto es lo primero al tabular, sin trampa y Escape no lo cierra sin elegir; ≥ 16 px entre el texto y los botones a 402 y 1512 con el `:root :where(p)` de Lavandería |
| [consent/cookies-pure](modules/consent/tests/cookies-pure.test.mjs) | 1 | registro de cookies sin WordPress: exacto antes que patrón y prefijo más largo; `wp-wpml_current_language` necesaria; GA con `_ga_*`; `_pk_id.*` de Matomo y no de Piwik; nombres sin valores ni HTML; tope de filas; alias y orden de las categorías; atribución del extracto |
| [consent/cookies](modules/consent/tests/cookies.test.mjs) | 2 | detector: solo nombres, 202, 429 por cliente y por hora, 400 con más de 60, 403 con otro origen o apagado; ruta de administración por permisos; tabla de la política (solo confirmadas, por categoría, en castellano, bloque y shortcode, vacía no pinta); «Preferencias» añadida por el administrador y alias al declarar sin cambiar la revisión de siempre |
| [consent/cookies-browser](modules/consent/tests/cookies-browser.test.mjs) | 3 | al aceptar el navegador manda solo nombres (mira el cuerpo de la petición) y no repite; rechazando no manda; reabrir desde un enlace y una clase; la pantalla Cookies confirma y la tabla lo enseña |
| [google-tags/google-tags](modules/google-tags/tests/google-tags.test.mjs) | 2 | apagado por defecto; vacío no imprime; `default` denied primero y sin `<script src>` de Google; categorías por etiqueta; conversión solo con su cuenta; ids inválidos 400; sin `consent` no carga |
| [google-tags/google-tags-browser](modules/google-tags/tests/google-tags-browser.test.mjs) | 3 | básico: nada a Google sin aceptar, rechazando o con solo mapas; al aceptar gtag.js y solo `analytics_storage`; retirar borra `_ga*`; Ads y conversión de formulario; avanzado carga antes |
| [webp/webp](modules/webp/tests/webp.test.mjs) | 2 | WebP (0.5.1): el editor escribe WebP; JPEG y PNG subidos generan sus tamaños (y el principal) en WebP; el filtro a `false` lo apaga; la trampa de regenerar lo ya subido (la `.jpg` del contenido sin `srcset`, la intermedia rota) y su arreglo |
| [site/editor](modules/site/tests/editor.test.mjs) | 3 | el editor enseña el valor enlazado y lista los datos (empresa y sede por slug); la pantalla de ajustes guarda, avisa y desactiva el último tipo |

**Todas se han visto fallar** (02-10-2026): tres tandas de mutantes, diez en
total, cada uno pillado por su prueba. Las de 0.2.0 (marcadas «(0.2.0)» en su
nombre) se vieron fallar ANTES de escribir el código, y la de `rewrite_slugs`
en types, además, con un mutante de `maybe_flush`. Las de Matomo (0.3.0,
03-10-2026) con cinco mutantes: imprimir sin id, quitar `disableCookies`,
cambiar `remember` por `forget`, no olvidar con la elección caducada y medir
a los administradores; cada uno pillado. Las de 0.4.0 (03-10-2026) se vieron
en rojo antes del código (shortcode y campos sin existir), y con dos
mutantes: `registry` fuera de DEFAULTS y el saneado del mapa estático que lo
deja pasar todo. Las de 0.5.0 (05-10-2026), con siete mutantes, cada uno
pillado: `rel` con `nofollow` (también por la prueba de Lavandería), otra
revisión, el filtro del «Powered by» sin aplicar, el alias sin registrar,
sin el evento `estrategia-web:consent`, sin `estrategia-web:consent-ready` y el «Powered
by» en `#aaa`. Y al pasar Matomo al aviso real salió un fallo de verdad que
la prueba simulada escondía (ver CHANGELOG, 0.5.0).

## Textos y traducciones (fila 11)

**Un solo dominio para todo el plugin: `estrategia-web`** (desde la 1.1.0;
antes, `estrategia-web-site` y `estrategia-web-forms`; el tema usa el mismo
y WordPress junta los dos ficheros). `languages/`:
[estrategia-web.pot](languages/estrategia-web.pot),
[estrategia-web-es_ES.po](languages/estrategia-web-es_ES.po) y su `.l10n.php`.
Se generan con `node tools/i18n.mjs` (ver su cabecera), que también saca el
nombre y la descripción de cada `module.php`. El `msgid` es el **texto en
inglés** (convención de WordPress). No hay JSON del editor: el núcleo pasa
las traducciones a `wp.i18n` en línea (`includes/class-blocks.php`).

## Instalar en una web

Copiar la carpeta a `wp-content/plugins/estrategia-web/` **sin `tests/`, sin
`modules/*/tests/` ni `tools/`**, activar, y en **Estrategia Web → Módulos**
apagar lo que esa web no use (por defecto, todo encendido). Después, en
**Datos de la empresa**, rellenar los datos y marcar los tipos. Los
`tests/wp/` abren rutas REST sin permisos: solo se cargan con el mu-plugin
del blueprint y sus constantes `ESTRATEGIA_WEB_*_TESTING` (doble llave), pero
no tienen por qué estar en el servidor.

**No convive con los plugins de la 1.0.0** (`estrategia-web-site`,
`estrategia-web-forms`) **ni con un tema padre que aún registre los bloques**
(el 1.0.0): registrarían lo mismo dos veces. Una web que venga de ellos los
desactiva al activar este (lo hace el migrador).

**Secretos (fila 21): no hay.** Al **borrar** el plugin, el
[uninstall.php](uninstall.php) de la raíz llama al de cada módulo: no se
borran datos del cliente salvo que la web defina
`ESTRATEGIA_WEB_SITE_REMOVE_DATA` o `ESTRATEGIA_WEB_FORMS_REMOVE_DATA`; el
contenido de los tipos no se borra nunca.

## Nombres: de «Armazón Site» a «Estrategia Web Site» (1.0.0)

Hasta la 0.5.4 el plugin se llamó **Armazón Site** (carpeta `armazon-site`).
En la 1.0.0 (07-10-2026) pasó a **Estrategia Web Site** para poder
publicarse en WordPress.org (prefijos únicos de 4 o más caracteres). Las
entradas 0.x del CHANGELOG son del nombre viejo; el repo sigue en
`E:\Repositories\armazon-wp-sitio`.

| Qué | Antes | Ahora |
|---|---|---|
| Carpeta, fichero, text domain | `armazon-site/armazon-site.php` | `estrategia-web-site/estrategia-web-site.php` |
| Namespace | `Armazon\Site` | `EstrategiaWeb\Site` |
| Funciones, constantes, filtros | `armazon_…`, `ARMAZON_SITE_…` | `estrategia_web_…`, `ESTRATEGIA_WEB_SITE_…` |
| Opción | `armazon_site` | `estrategia_web_site` |
| Metas de los tipos | `location_static_map`, `client_image_fit`… | `estrategia_web_location_static_map`, `estrategia_web_client_image_fit`… |
| Fuentes de bindings | `armazon-site/company`, `/location` | `estrategia-web-site/company`, `/location` |
| Bloque | `armazon-site/consent` (y `armazon/consent`) | `estrategia-web-site/consent` |
| Cookie | `armazon_consent` | `estrategia_web_consent` (mismo formato) |
| API JS y evento | `window.armazonConsent`, `armazon:consent` | `window.estrategiaWebConsent`, `estrategia-web:consent` |
| Shortcodes | `[armazon_company]`, `[armazon_consent_settings]` | `[estrategia_web_company]`, `[estrategia_web_consent_settings]` |
| Clases CSS | `.armazon-consent__…` | `.estrategia-web-consent__…` |

Los **tipos** (`client`, `testimonial`, `location`, `project`, `portfolio`) y
sus **taxonomías** NO cambian: cambiarlos cambia las URL y la API REST de
cada web. Está en [PENDIENTES.md](PENDIENTES.md) para decidir antes de
mandarlo a WordPress.org.

**Los nombres viejos siguen funcionando** durante la 1.x
([modules/site/includes/class-legacy-names.php](modules/site/includes/class-legacy-names.php)): bloques,
fuentes de bindings, shortcodes y la opción (si falta la nueva); la cookie
vieja se lee y se pasa a la nueva sin volver a preguntar
([modules/consent/includes/class-consent.php](modules/consent/includes/class-consent.php)). Se quitan en la
**2.0.0**.

**EL MIGRADOR** ([includes/class-armazon-migration.php](includes/class-armazon-migration.php))
pasa una web de los nombres viejos a los nuevos EN SITIO: contenido (bloques
y traducciones de WPML), metas, opciones, las tablas del plugin de
formularios, cadenas de WPML, tema y plugins activos. Idempotente, con seco,
y no corre solo: `wp estrategia-web-site migrate-armazon --dry-run`, o
incluido desde `wp eval-file` como hace Lavandería
(`despliegue/migrar-a-estrategia-web.mjs`). Antes, copia de la base de datos.

## Si cambias de proveedor

- **Licencia:** GPL-2.0-or-later. Es tuyo para usarlo, cambiarlo y
  llevártelo: no depende de ningún servicio nuestro.
- **Si desactivas el plugin**, nada se borra (ni al borrarlo, salvo
  `ESTRATEGIA_WEB_SITE_REMOVE_DATA`), pero deja de verse:
  - los **datos de la empresa** que salían por Block Bindings: cada bloque
    enseña el texto que tenga guardado (en los patrones suele ir vacío o un
    texto de ejemplo); conviene escribirlos a mano antes;
  - los **shortcodes** `[estrategia_web_company …]`: salen tal cual, como texto;
  - los **tipos** (clientes, opiniones, sedes…): siguen en la base de datos,
    pero sin el plugin no se ven en el escritorio ni en las Consultas;
  - el **aviso de cookies** y su enlace: no salen. La web necesita otro aviso
    si carga algo que lo pida (Matomo híbrido, mapas);
  - los **SVG** ya subidos siguen en la mediateca; subir más, no.
- **Exportar, ANTES de desactivarlo:** Herramientas › Exportar con el
  plugin activo se lleva también clientes, opiniones, sedes, proyectos y
  portfolio (son tipos exportables) con sus metas. Los datos de la empresa
  son la opción `estrategia_web_site` (`wp option get estrategia_web_site
  --format=json`).

## Versiones

`MAYOR.MENOR.PARCHE`, con su nota en [CHANGELOG.md](CHANGELOG.md).

- **Una versión menor o de parche nunca obliga a tocar una web**: ni sus
  bloques enlazados, ni sus Consultas, ni su tema.
- **Desactivar un tipo no borra su contenido**, y una versión nueva tampoco.
- Es **versión mayor**, con nota de qué cambiar en cada web: renombrar o
  quitar una clave de `estrategia_web_site`, una clave de la fuente, un tipo, una
  taxonomía, un campo meta, o **cambiar la traducción de un slug** (cambia
  las URL publicadas).
- Añadir (una clave, un tipo, un campo) es menor.

## Cómo se mantiene

- **Quién y cuándo:** quien toque el plugin, en cada versión: número en
  `estrategia-web.php` y `ESTRATEGIA_WEB_VERSION` (los módulos no llevan
  versión propia), nota en el CHANGELOG, `node tools/i18n.mjs` si cambió un
  texto, y `node tests/run.mjs` entero antes de llevarlo a ninguna web.
- **Qué entra:** lo que tendría **cualquier** web corporativa (un dato de
  contacto, un tipo de ficha repetida, un bloque). Lo de una sola web va a su
  tema hijo. Lo que se pueda querer apagar por separado es un **módulo
  nuevo**, no una opción más dentro de otro (ver
  [includes/class-modules.php](includes/class-modules.php)).
- **El caso frontera:** «servicios». No es un tipo, a propósito: en estas webs
  un servicio es una página con diseño propio. Si varias webs acaban pidiendo
  fichas de servicio, entra como tipo nuevo (versión menor).
- **La trampa:** el slug público sale de gettext, así que **cambiar el idioma
  de una web cambia sus URL** (`clientes` ↔ `clients`). En una web publicada
  que vaya a cambiar de idioma, fijar antes el slug en `rewrite_slugs`. Y la
  otra: activar o desactivar un tipo, o cambiar `rewrite_slugs`, regenera las
  reglas de URL en la siguiente carga; una caché de página puede servir un
  404 viejo un rato.
- **Cada fichero dice cómo se mantiene en su cabecera.** Si cambias uno,
  revisa que su cabecera siga siendo verdad.

Pendiente: [PENDIENTES.md](PENDIENTES.md).
