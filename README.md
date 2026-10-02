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

- Slug y text domain: `armazon-site` · WordPress **7.1+** · PHP **8.1+** · GPLv2+ · versión 0.2.0
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

### 2 bis. Fuente de Block Bindings `armazon-site/location` (0.2.0)

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
| `location_phone`, `location_email`, `location_map_url`, `location_map_embed` | tal cual |
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
| `location` | Sedes | `/sedes/` | título = sede, contenido, imagen = mapa estático, extracto, orden | `location_address`, `location_schedule` (varias líneas), `location_phone`, `location_email`, `location_map_url` (enlace a Google Maps), `location_map_embed` (URL de embed para el bloque `armazon/map` del padre) | — |
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

### Glosario (código en inglés, dominio en castellano)

`client` cliente · `testimonial` opinión · `location` sede · `project`
proyecto · `portfolio` portfolio · `sector` sector del cliente · `company`
empresa · `binding` enlace de un bloque a un dato.

---

## Pruebas (fila 7)

    node tests/run.mjs             1 → 2 → 3, para en el primer rojo (~2 min)
    node tests/run.mjs --nivel 1   solo el 1 (cada commit, ~0,2 s)

El corredor arranca **Playground** (WP 7.1, PHP 8.3) en el **puerto 9404** si
no hay uno, con el plugin montado y [tests/blueprint.json](tests/blueprint.json),
y lo mata al terminar con `taskkill /T /F`. Las pruebas hablan con la **API
REST de verdad** como administrador y como autor (contraseñas de aplicación
que crea [tests/wp/armazon-site-test.php](tests/wp/armazon-site-test.php)).

| Fichero | Nivel | Qué prueba |
|---|---|---|
| [tests/pure.test.mjs](tests/pure.test.mjs) | 1 | tel:/mailto:/wa.me; SVG hostil saneado, SVG inaceptables, medidas |
| [tests/settings.test.mjs](tests/settings.test.mjs) | 2 | guardar y leer por REST, fusión, saneado (correo/URL/WhatsApp inválidos no se guardan), esquema, `rewrite_slugs` (saneado, fusión por clave, 400), autor sin permiso |
| [tests/bindings.test.mjs](tests/bindings.test.mjs) | 2 | párrafo con el teléfono, botón con wa.me, tel: (también `phone_secondary_href`), mailto:, `<br>`, escapado, `social.<red>` |
| [tests/location.test.mjs](tests/location.test.mjs) | 2 | fuente de sedes: por slug (botón texto+url, título, dirección, mailto, mapa), inexistente/borrador/fuera de contexto vacíos, por contexto en una Consulta |
| [tests/types.test.mjs](tests/types.test.mjs) | 2 | tipo inexistente sin activar; activar/desactivar sin perder contenido; slug `/clientes/`; meta saneado; Consulta por `client_sector` con título e imagen; `client_image_fit` y la clase `has-image-fit-contain`; `rewrite_slugs` mueve el archivo a `/cliente/` y deja libre la página `/clientes/` |
| [tests/svg.test.mjs](tests/svg.test.mjs) | 2 | SVG con `<script>` subido saneado (multipart y sideload), medidas, autor no puede, `allow_svg` apagado |
| [tests/editor.test.mjs](tests/editor.test.mjs) | 3 | el editor enseña el valor enlazado y lista los datos (empresa y sede por slug); la pantalla de ajustes guarda, avisa y desactiva el último tipo |

**Todas se han visto fallar** (02-10-2026): tres tandas de mutantes, diez en
total, cada uno pillado por su prueba. Las de 0.2.0 (marcadas «(0.2.0)» en su
nombre) se vieron fallar ANTES de escribir el código, y la de `rewrite_slugs`
en types, además, con un mutante de `maybe_flush`.

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
