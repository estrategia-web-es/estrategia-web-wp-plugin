# Armazón Site — datos de la empresa y tipos de contenido de las webs de WordPress

Los **datos de la empresa** (nombre, teléfono, WhatsApp, dirección, redes…) en
un solo sitio y enlazables desde cualquier bloque con **Block Bindings**; los
**tipos de contenido habituales** de una web corporativa (clientes, opiniones,
sedes, proyectos, portfolio), **cada uno activable**; y **subida de SVG
saneada**. Lo usan todas las webs hechas con el tema padre del Armazón; es
compartido y versionado, como el plugin hermano
[armazon-wp-formularios](E:/Repositories/armazon-wp-formularios/README.md).
El marco está en
[armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md](E:/Repositories/armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md).

- Slug y text domain: `armazon-site` · WordPress **7.1+** · PHP **8.1+** · GPLv2+ · versión 0.5.2
- Desde 0.5.0 lleva también **el aviso de cookies** (vino del tema padre
  0.6.0, que en su 0.7.0 ya no lo trae): ver «6. Aviso de cookies».
- Sin dependencias, sin `node_modules`, sin paso de compilación.

Por qué un plugin y no el tema padre: los datos y el contenido **no pueden
desaparecer al cambiar de tema**. Lo que se ve (cómo se pinta una ficha de
cliente) es del tema; lo que se guarda, de aquí.

---

## El contrato

Es lo que leen el tema padre, los temas hijos y la carga de contenido. **Estos
nombres no cambian en una versión menor** (ver «Versiones»).

### 1. Datos de la empresa — opción `armazon_site`

Pantalla **Datos de la empresa** (menú propio, capacidad `manage_options`).
Una sola opción (array), registrada con la Settings API y expuesta en REST con
su esquema: `GET/POST /wp/v2/settings` → clave `armazon_site`.

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

**Se guarda fusionando**: un POST con `{"armazon_site": {"phone": "…"}}` cambia
el teléfono y deja lo demás. Así la carga de contenido rellena por partes.

```bash
# REST (como administrador; Playground o una web con contraseña de aplicación)
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"armazon_site":{"phone":"971 12 34 56","enabled_types":["client","location"]}}'

# WP-CLI: pasa por el mismo saneado (sanitize_option), así que también fusiona.
# (Deducido del código, no probado: las pruebas van por REST.)
wp option update armazon_site '{"phone":"971 12 34 56"}' --format=json
```

En PHP, un tema lee un dato con `\Armazon\Site\Company::value( 'phone' )`
(no con `get_option`, que se salta el filtro y las derivadas).

### 2. Fuente de Block Bindings `armazon-site/company`

`args.key` = cualquier clave de arriba (menos `enabled_types`, `allow_svg` y `rewrite_slugs`),
`social.<red>` con punto, y cuatro **derivadas**:

| Clave | Ejemplo |
|---|---|
| `phone_href` | `tel:+34971123456` (prefijo 34 si no lleva; filtro `armazon_site_default_country_code`) |
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
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"armazon-site/company","args":{"key":"phone"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

Un botón de WhatsApp (el texto del botón es fijo; la url, enlazada):

```html
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"armazon-site/company","args":{"key":"whatsapp_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Escríbenos por WhatsApp</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
```

Botón «Llamar» con texto y url enlazados a la vez (el `text` del botón no
tiene prueba propia; el `url` sí):

```html
<!-- wp:button {"metadata":{"bindings":{"text":{"source":"armazon-site/company","args":{"key":"phone"}},"url":{"source":"armazon-site/company","args":{"key":"phone_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"></a></div>
<!-- /wp:button -->
```

### 2 bis. Un dato dentro de una frase: `[armazon_company key="…"]` (0.4.0)

Un binding sustituye el párrafo **entero** (WP 7.1 no tiene bindings en
línea), así que para el **aviso legal** (LSSI, art. 10) hay un shortcode.
Mismas claves que la fuente (`Company::value()`: también `social.<red>` y las
derivadas), texto escapado y **en una línea**: dirección y horario salen
unidos con «, ». Una clave inexistente o vacía no pinta nada.

```html
<!-- wp:paragraph -->
<p>Titular: [armazon_company key="legal_name"], con NIF [armazon_company key="tax_id"] y domicilio en [armazon_company key="address"]. Teléfono: [armazon_company key="phone"]. Correo: [armazon_company key="email"]. Inscrita en el [armazon_company key="registry"].</p>
<!-- /wp:paragraph -->
```

- En el **editor se ve el shortcode** tal cual; el valor sale en la web. Un
  dato solo en su párrafo, mejor con binding (que sí se ve en el editor).
- Funciona en el contenido y en las plantillas de bloques; **no** en un
  atributo de bloque ni en un `href` (para enlaces, el binding del botón).
- `[[armazon_company …]]` con dos corchetes es el **escape** de WordPress:
  sale literal. El porqué de shortcode y no bloque, en la cabecera de
  [includes/class-shortcode.php](includes/class-shortcode.php).

### 2 ter. Fuente de Block Bindings `armazon-site/location` (0.2.0)

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
| `location_address`, `location_schedule` | varias líneas (salen con `<br>`) |
| `location_phone`, `location_email`, `location_map_url`, `location_map_embed`, `location_static_map`, `location_static_map_id` (0.4.0) | tal cual |
| `location_phone_href` | `tel:+34971112233`, de `location_phone` (mismo prefijo que la empresa) |
| `location_email_href` | `mailto:…`, de `location_email` |

Mismo tratamiento que la de la empresa: texto escapado con `<br>`, y en `url`
solo `http`, `https`, `tel` y `mailto`. **Una sede inexistente, en borrador o
privada sale vacía** (en el editor, `[Nombre del dato]`). En el editor enseña
el valor de las sedes publicadas al abrirlo; una sede creada después sale
entre corchetes hasta recargar.

Botón con el teléfono de la sede «maioris» (texto y url):

```html
<!-- wp:button {"metadata":{"bindings":{"text":{"source":"armazon-site/location","args":{"slug":"maioris","key":"location_phone"}},"url":{"source":"armazon-site/location","args":{"slug":"maioris","key":"location_phone_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"></a></div>
<!-- /wp:button -->
```

La dirección de cada sede dentro de una Consulta (`"postType":"location"`):

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"armazon-site/location","args":{"key":"location_address"}}}}} -->
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
| `client` | Clientes | `/clientes/` | título = nombre, imagen destacada = logo/foto, extracto, orden manual | `client_url` (URL), `client_image_fit` (`cover` por defecto o `contain`; otro valor → `cover`; 0.2.0) | `client_sector` (jerárquica, slug `/sector/`) |
| `testimonial` | Opiniones | `/opiniones/` | título (interno), contenido = la opinión, imagen = foto del autor, orden | `testimonial_author`, `testimonial_role` (texto), `testimonial_rating` (entero 0-5; **0 = sin valoración**), `testimonial_client` (id de un `client`, o 0) | — |
| `location` | Sedes | `/sedes/` | título = sede, contenido, imagen = mapa estático, extracto, orden | `location_address`, `location_schedule` (varias líneas), `location_phone`, `location_email`, `location_map_url` (enlace a Google Maps), `location_map_embed` (URL de embed para el bloque `armazon/map` del padre), `location_static_map` (0.4.0: imagen del mapa estático servida por **este** sitio: ruta del tema `assets/maps/sede.webp`, ruta del sitio `/wp-content/…` o URL del mismo dominio, acabada en webp/png/jpg/gif/avif/svg; cualquier otra → `''`), `location_static_map_id` (0.4.0: entero, id de un adjunto de **imagen**; si no, 0) | — |
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
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"testimonial_author"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

El mapa de cada sede con `armazon/map` del tema padre, dentro de una Consulta
de `location` (0.4.0: el mapa estático, de `location_static_map`):

```html
<!-- wp:armazon/map {"metadata":{"bindings":{"address":{"source":"core/post-meta","args":{"key":"location_address"}},"embedUrl":{"source":"core/post-meta","args":{"key":"location_map_embed"}},"mapsUrl":{"source":"core/post-meta","args":{"key":"location_map_url"}},"staticMapUrl":{"source":"core/post-meta","args":{"key":"location_static_map"}}}}} /-->
```

`location_static_map_id` se guarda y valida, pero **hoy no se puede enlazar**
a `staticMapId`: el padre solo declara enlazables sus atributos de texto
(`inc/blocks.php`) y `staticMapId` es un número. Para la mediateca, la URL
del adjunto en `location_static_map` (mismo dominio) sirve igual. El tema
vuelve a comprobar la URL al pintar (`armazon_map_static_url`).

**Slugs públicos por web: `rewrite_slugs`** en la opción (0.2.0). Las rutas
son del plugin, no del tema: una web con una **página** `/clientes/` (que el
archivo del tipo pisaría) mueve el archivo así, por REST o en su carga de
contenido:

```bash
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"armazon_site":{"rewrite_slugs":{"client":"cliente"}}}'
```

Orden: el slug traducido ← el de `rewrite_slugs` si no está vacío ← el filtro
`armazon_site_rewrite_slug( $slug, $tipo_o_taxonomia )`, que **sigue
mandando** (recibe ya el de la opción). Un cambio por el filtro no rehace las
reglas solo; uno por la opción, sí.

**Clases que el plugin pone en una entrada** (filtro `post_class`: salen en
el `<li>` de una Consulta y en la ficha). Describen el dato; **el plugin no
pone CSS**, el aspecto es del tema:

| Clase | Cuándo | Para qué (en el tema) |
|---|---|---|
| `has-image-fit-contain` | un `client` con `client_image_fit` = `contain` (0.2.0) | la imagen es un **logo**: que no se recorte, p. ej. `.has-image-fit-contain .wp-block-post-featured-image img { object-fit: contain; }` |

Solo en la web: la vista previa de la Consulta en el editor la pinta JS y no
pasa por `post_class` (deducido del núcleo, no probado).

### 4. SVG

Con `allow_svg` activado, **solo** quien tiene `unfiltered_html` o
`manage_options` puede subir `.svg` (no `.svgz`). Siempre se **sanea en
servidor** por lista blanca de elementos y atributos
([includes/class-svg-sanitizer.php](includes/class-svg-sanitizer.php)): fuera
`<script>`, atributos `on*`, `<foreignObject>`, enlaces `javascript:`, URL
externas, `@import`, entidades (XXE) y comentarios; se quedan degradados,
máscaras, filtros, texto y `<style>` con `url(#id)`. Las medidas (width/height
o viewBox) salen en la mediateca y en `media_details`, y la imagen destacada
funciona con un SVG. **Sin dependencias**: el porqué, en la cabecera de esa
clase. Un tema no debe añadir `svg` a `upload_mimes` por su cuenta (el tema
hijo viejo de Lavandería lo hacía, sin sanear).

### 5. Matomo (0.3.0)

Tres claves más en la opción `armazon_site` (misma fusión, mismo REST), y
una sección **Estadísticas (Matomo)** en la pantalla:

| Clave | Tipo | Saneado |
|---|---|---|
| `matomo_url` | URL | http(s); se guarda **acabada en `/`** y sin el `matomo.php`/`matomo.js` que se pega a veces; inválida → se queda la anterior |
| `matomo_site_id` | entero ≥ 0 | `0` = sin configurar; negativo → **400** |
| `matomo_mode` | `cookieless` (por defecto) o `hybrid` | otro valor → **400** |

```bash
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"armazon_site":{"matomo_url":"https://stats.ejemplo.com/","matomo_site_id":3}}'
```

- **Sin URL o sin id no se imprime nada.** Con las dos, el código de
  seguimiento va en línea en el `<head>` (`<script id="armazon-site-matomo">`).
- **No se mide** en el escritorio, el editor, las vistas previas ni a quien
  tiene sesión y puede editar (`edit_posts`). Filtro
  `armazon_site_matomo_track( $track )` para cambiarlo.
- **`cookieless`** (el de la fila 16): `disableCookies`, nada que preguntar.
- **`hybrid`**: mide sin cookies (`requireCookieConsent`) y, cuando el
  visitante acepta **«statistics»** en el **aviso de cookies** (el de este
  plugin desde 0.5.0; ver la sección 6), llama a
  `rememberCookieConsentGiven`; si la retira, a `forgetCookieConsentGiven`.
  Escucha el evento `armazon:consent` y mira `window.armazonConsent.get()`
  (el contrato del aviso), y **declara él mismo la categoría** con el filtro
  `armazon_consent_categories` (prioridad 99): el tema no tiene que
  acordarse. Desde 0.5.0 funciona con cualquier tema.
  **La legalidad del híbrido no está verificada** (criterio de la CNIL): por
  eso no es el de por defecto.
- «Sin IP completa» se configura en el servidor de Matomo, no aquí.

El porqué de cada decisión y sus trampas, en la cabecera de
[includes/class-matomo.php](includes/class-matomo.php).

### 6. Aviso de cookies (0.5.0)

Vino del tema padre (`armazon-wp-padre` ≤ 0.6.0, `inc/consent.php`) **sin
cambiar el contrato**: el consentimiento es funcionalidad, no presentación, y
una web que cambie de tema no puede quedarse sin aviso. La documentación
entera (decisiones, cómo se mantiene, trampas) está en la cabecera de
[includes/class-consent.php](includes/class-consent.php).

- **Sale solo si la web declara categorías** (`statistics`, `maps`,
  `marketing`) con el filtro `armazon_consent_categories`; sin ninguna, no
  hay aviso, que es lo normal. Matomo híbrido declara `statistics` él solo.

      add_filter( 'armazon_consent_categories', fn( $c ) => array_merge( (array) $c, array( 'maps' ) ) );

- **La cookie es la misma** (`armazon_consent`, `"<revisión>.<cat~cat>.<unix>"`,
  la misma revisión): quien ya eligió con el aviso del padre no lo vuelve a ver.
- **PHP:** `armazon_consent_has( 'maps' )`, `armazon_consent_categories()` y
  las demás `armazon_consent_*` de siempre
  ([includes/consent-functions.php](includes/consent-functions.php)), y
  `\Armazon\Site\Consent`. Filtros: `armazon_consent_categories`,
  `armazon_consent_revision`, `armazon_consent_max_age`, `armazon_consent_text`.
- **JS:** `window.armazonConsent` (`categories`, `has()`, `get()`, `open()`,
  `grant()`), el evento `armazon:consent` en `document` con
  `detail.accepted`, **`armazon:consent-ready`** (nuevo en 0.5.0: una vez al
  arrancar, también sin elección, con `accepted: null`; lo usa Matomo), el
  store de Interactivity `armazon/consent`, y
  `<script type="text/plain" data-armazon-consent="statistics">` para lo que
  solo se ejecuta con consentimiento. WP Consent API si está, sin depender.
- **Enlace «Configurar cookies»:** bloque **`armazon-site/consent`** (atributo
  `label`), o `[armazon_consent_settings label="…"]`. El nombre viejo
  **`armazon/consent` se sigue registrando como alias** oculto del
  insertador, con el mismo HTML (clase `wp-block-armazon-consent`): el
  contenido y las partes ya guardados con él siguen funcionando. Quitar el
  alias es versión mayor.
- **Estilos:** hoja base neutra en el plugin
  ([blocks/consent/style.css](blocks/consent/style.css)) que lee los tokens de
  `theme.json` (`base`, `contrast`, `border`, espaciado, tamaños) con valores
  de reserva. El tema afina con sus tokens o con CSS sobre `.armazon-consent`.
- **Convivencia:** con un tema padre viejo que aún trae su aviso, el plugin
  se aparta (no hay dos avisos ni funciones redeclaradas). Con el padre 0.7.0
  y este plugin ≤ 0.4.0 no hay aviso: se actualizan los dos a la vez.

**«Powered by»**: al pie del aviso, en letra pequeña y alineado al final,
**«Powered by Estrategia Web»** enlazando a `https://estrategiaweb.es/`, en
pestaña nueva, `rel="noopener"` y **sin `nofollow`** (cuenta como enlace).
Texto traducible (`Powered by %s`; en es_ES se deja en inglés). Se cambia o
se quita con el **filtro** `armazon_site_consent_powered_by` (no con una
opción: es la firma de quien hace la web, no un dato que el cliente toque, y
una opción sería otra clave del contrato):

    // Otro texto o URL (lo que no se pase, se queda):
    add_filter( 'armazon_site_consent_powered_by', fn( $p ) => array_merge( $p, array( 'text' => 'Hecha por X', 'url' => 'https://x.es/' ) ) );
    // Quitarlo:
    add_filter( 'armazon_site_consent_powered_by', '__return_false' );

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
(`view-transition-name: armazon-consent`).

### 7. WebP (0.5.1)

Los JPEG y PNG que se suben se guardan **en WebP**: el filtro
`image_editor_output_format` de WordPress, solo si el editor de imágenes del
servidor sabe escribirlo (`wp_image_editor_supports`). Medido: WordPress
convierte **también el fichero principal** (`foto.jpg` → la mediateca sirve
`foto.webp`; el `.jpg` queda como `original_image`). Apagar, en el tema:

    add_filter( 'armazon_site_webp', '__return_false' );

**Lo ya subido no cambia solo**, y **regenerar sin tocar el contenido lo
empeora**: `wp media regenerate` pasa el meta a `.webp`, pero las URL
guardadas en las páginas no cambian. La del fichero principal (`foto.jpg`,
la que pone la carga de contenido de Lavandería en todas) sigue sirviendo,
**pero se queda sin `srcset`**: el JPEG entero a cualquier ancho. La de un
tamaño intermedio (`foto-1024x683.jpg`) apunta a un fichero borrado. Después
de regenerar hay que volver a escribir en el contenido la URL actual de la
mediateca. El detalle, en la cabecera de
[includes/class-webp.php](includes/class-webp.php).

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

      add_filter( 'armazon_site_external_links', '__return_false' );

- **Caso frontera:** el enlace a la **web vieja del cliente en otro dominio
  cuenta como externo**: decide el host de `home_url()`, no de quién es la
  web. En el servidor de pruebas (`*.sg-host.com`) también los enlaces al
  dominio definitivo. Si una web tiene de verdad dos dominios propios,
  `target="_self"` en esos enlaces.

Decisiones, trampa (corre dentro del búfer de salida: un fallo no rompe la
página pero deja los enlaces sin tocar, en silencio) y cómo se mantiene, en
la cabecera de [includes/class-external-links.php](includes/class-external-links.php).

### Glosario (código en inglés, dominio en castellano)

`client` cliente · `testimonial` opinión · `location` sede · `project`
proyecto · `portfolio` portfolio · `sector` sector del cliente · `company`
empresa · `binding` enlace de un bloque a un dato · `consent` aviso de
cookies / consentimiento · `powered by` la firma al pie del aviso ·
`external link` enlace a otro dominio.

---

## Pruebas (fila 7)

    node tests/run.mjs             1 → 2 → 3, para en el primer rojo (~2 min)
    node tests/run.mjs --nivel 1   solo el 1 (cada commit, ~0,2 s)
    node tests/run.mjs webp        el 1 y, de los demás, solo webp.test.mjs

Al desarrollar, **el nivel 1 siempre y, de los niveles 2 y 3, solo la prueba
nueva** (fila 7, «Cuándo corre la batería»). La tanda entera, cuando la pida
David o antes de llevar el plugin a una web en producción.

El corredor arranca **Playground** (WP 7.1, PHP 8.3) en el **puerto 9404** si
no hay uno, con el plugin montado y [tests/blueprint.json](tests/blueprint.json),
y lo mata al terminar con `taskkill /T /F`. Las pruebas hablan con la **API
REST de verdad** como administrador y como autor (contraseñas de aplicación
que crea [tests/wp/armazon-site-test.php](tests/wp/armazon-site-test.php)).

| Fichero | Nivel | Qué prueba |
|---|---|---|
| [tests/pure.test.mjs](tests/pure.test.mjs) | 1 | tel:/mailto:/wa.me; SVG hostil saneado, SVG inaceptables, medidas; (0.5.2) qué enlace es externo (otro host, www, subdominio, `//`, relativos, anclas, otros esquemas) y `rel` con `noopener` sin perder lo que traía |
| [tests/external-links.test.mjs](tests/external-links.test.mjs) | 2 | enlaces externos (0.5.2) en la página servida: el botón de wa.me con `_blank`, `noopener` y `aria-label`; internos, www, relativo, ancla, tel, mailto intactos; con `target` intacto; con `rel` y con `aria-label`, añadidos; enlace-imagen con su alt; «Powered by» sin duplicar; REST sin tocar; el filtro lo apaga. Vista en rojo sin el `boot()` |
| [tests/settings.test.mjs](tests/settings.test.mjs) | 2 | guardar y leer por REST, fusión, saneado (correo/URL/WhatsApp inválidos no se guardan), esquema, `rewrite_slugs` (saneado, fusión por clave, 400), autor sin permiso |
| [tests/bindings.test.mjs](tests/bindings.test.mjs) | 2 | párrafo con el teléfono, botón con wa.me, tel: (también `phone_secondary_href`), mailto:, `<br>`, escapado, `social.<red>` |
| [tests/location.test.mjs](tests/location.test.mjs) | 2 | fuente de sedes: por slug (botón texto+url, título, dirección, mailto, mapa), inexistente/borrador/fuera de contexto vacíos, por contexto en una Consulta |
| [tests/types.test.mjs](tests/types.test.mjs) | 2 | tipo inexistente sin activar; activar/desactivar sin perder contenido; slug `/clientes/`; meta saneado; Consulta por `client_sector` con título e imagen; `client_image_fit` y la clase `has-image-fit-contain`; `rewrite_slugs` mueve el archivo a `/cliente/` y deja libre la página `/clientes/` |
| [tests/legal.test.mjs](tests/legal.test.mjs) | 2 | datos legales (0.4.0): `registry` saneado y fusionado; frase legal con `[armazon_company]` (razón social, NIF, domicilio en una línea, teléfono, correo, registro, escapado); clave inexistente vacía; `registry` por binding |
| [tests/static-map.test.mjs](tests/static-map.test.mjs) | 2 | mapa estático de una sede (0.4.0): `location_static_map` acepta ruta del tema, del sitio y URL del mismo dominio, y rechaza otro dominio, `//`, `javascript:`, `..`, no-imagen; `location_static_map_id` solo adjuntos de imagen; en una Consulta, core/post-meta da a cada sede el suyo (párrafo e imagen); también por la fuente de sedes |
| [tests/svg.test.mjs](tests/svg.test.mjs) | 2 | SVG con `<script>` subido saneado (multipart y sideload), medidas, autor no puede, `allow_svg` apagado |
| [tests/matomo.test.mjs](tests/matomo.test.mjs) | 2 | Matomo (0.3.0): sin URL o sin id no se imprime; por defecto `disableCookies`; híbrido con `requireCookieConsent` y la categoría «statistics» declarada; saneado (URL, id, 400 del modo); sus claves no son datos enlazables |
| [tests/matomo-browser.test.mjs](tests/matomo-browser.test.mjs) | 3 | con el `matomo.js` real ([tests/fixtures/matomo.js](tests/fixtures/matomo.js), 5.4.0, BSD-3) servido por una ruta falsa y, desde 0.5.0, con el **aviso de cookies real** (sus botones y su cookie, no un evento simulado): sin configuración no se pide; sin cookies mide, no deja cookies y no hay aviso; híbrido sin cookies hasta «Aceptar todas» (solo «mapas» no vale), `mtm_cookie_consent` y `_pk_id` al aceptar, borradas al «Rechazar todas»; aviso ya contestado al cargar; elección caducada olvida; a un administrador no se le mide |
| [tests/consent.test.mjs](tests/consent.test.mjs) | 2 | aviso de cookies (0.5.0): sin categorías no sale; con ellas, en orden estable y con los tres botones; revisión y cookie iguales a las del padre; Matomo híbrido lo hace salir; «Powered by» (texto, `href`, `_blank`, `rel="noopener"` sin `nofollow`, después de los botones); el filtro cambia texto y URL, una URL vacía o no http(s) deja el texto sin enlace, `false` lo quita; enlace por bloque nuevo, alias viejo y shortcode |
| [tests/consent-browser.test.mjs](tests/consent-browser.test.mjs) | 3 | aviso de cookies (0.5.0): sale; aceptar y rechazar pesan igual; rechazar guarda la cookie y persiste; el enlace (nuevo y alias) lo reabre con el foco dentro y Escape lo cierra; contrato JS (evento, `has`, `get`, `categories`, `grant`, `open`) y scripts diferidos; una cookie del aviso del padre sigue valiendo; «Powered by» visible, más pequeño que los botones y con contraste AA medido; (0.5.1) abierto es lo primero al tabular, sin trampa y Escape no lo cierra sin elegir; ≥ 16 px entre el texto y los botones a 402 y 1512 con el `:root :where(p)` de Lavandería |
| [tests/webp.test.mjs](tests/webp.test.mjs) | 2 | WebP (0.5.1): el editor escribe WebP; JPEG y PNG subidos generan sus tamaños (y el principal) en WebP; el filtro a `false` lo apaga; la trampa de regenerar lo ya subido (la `.jpg` del contenido sin `srcset`, la intermedia rota) y su arreglo |
| [tests/editor.test.mjs](tests/editor.test.mjs) | 3 | el editor enseña el valor enlazado y lista los datos (empresa y sede por slug); la pantalla de ajustes guarda, avisa y desactiva el último tipo |

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
sin el evento `armazon:consent`, sin `armazon:consent-ready` y el «Powered
by» en `#aaa`. Y al pasar Matomo al aviso real salió un fallo de verdad que
la prueba simulada escondía (ver CHANGELOG, 0.5.0).

## Textos y traducciones (fila 11)

`languages/`: [armazon-site.pot](languages/armazon-site.pot),
[armazon-site-es_ES.po](languages/armazon-site-es_ES.po) y su `.l10n.php`. Se
generan con `node tools/i18n.mjs` (ver su cabecera). Igual que formularios, el
`msgid` es el **texto en inglés** (convención de WordPress). No hay JSON del
editor: `assets/editor.js` no lleva textos propios.

## Instalar en una web

Copiar la carpeta a `wp-content/plugins/armazon-site/` **sin `tests/` ni
`tools/`**, activar, y en **Datos de la empresa** rellenar los datos y marcar
los tipos que use esa web. `tests/wp/` abre rutas REST sin permisos: solo se
carga con el mu-plugin del blueprint y la constante `ARMAZON_SITE_TESTING`
(doble llave), pero no tiene por qué estar en el servidor.

**Secretos (fila 21): no hay.** Al **borrar** el plugin no se borra nada,
salvo la opción si la web define `ARMAZON_SITE_REMOVE_DATA`; el contenido de
los tipos no se borra nunca ([uninstall.php](uninstall.php)).

## Versiones

`MAYOR.MENOR.PARCHE`, con su nota en [CHANGELOG.md](CHANGELOG.md).

- **Una versión menor o de parche nunca obliga a tocar una web**: ni sus
  bloques enlazados, ni sus Consultas, ni su tema.
- **Desactivar un tipo no borra su contenido**, y una versión nueva tampoco.
- Es **versión mayor**, con nota de qué cambiar en cada web: renombrar o
  quitar una clave de `armazon_site`, una clave de la fuente, un tipo, una
  taxonomía, un campo meta, o **cambiar la traducción de un slug** (cambia
  las URL publicadas).
- Añadir (una clave, un tipo, un campo) es menor.

## Cómo se mantiene

- **Quién y cuándo:** quien toque el plugin, en cada versión: número en
  `armazon-site.php` y `ARMAZON_SITE_VERSION`, nota en el CHANGELOG,
  `node tools/i18n.mjs` si cambió un texto, y `node tests/run.mjs` entero
  antes de llevarlo a ninguna web.
- **Qué entra:** lo que tendría **cualquier** web corporativa (un dato de
  contacto, un tipo de ficha repetida). Lo de una sola web va a su tema hijo.
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
