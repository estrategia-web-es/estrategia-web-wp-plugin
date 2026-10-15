# Alta de una web nueva con Estrategia Web

La guía para montar una web de cliente con las dos piezas comunes —el **tema
padre** `estrategia-web` y el **plugin** `estrategia-web`— y un **tema hijo**
propio. Escrita el 15-10-2026 a partir de lo que hay en Lavandería Mallorca,
la única web hecha así: **la prueba de que esto escala es que la próxima web
salga siguiendo esta guía sin preguntar**. Donde hoy hay que preguntar a
alguien, pone **HUECO**.

**El caso real con el que se prueba: rehacer https://gastronomicament.cat**
(Grup Gastronòmicament, desde 2006). Lo que se sabe a 15-10-2026, de leer la
portada y de David: revista gastronómica de Baleares, **en catalán** con
castellano, inglés y alemán (WPML probable); es **WordPress** (confirmado) en
**Raiola Networks** con cPanel, **sin SSH**, varios GB (sobre todo fotos);
menú Restaurants, Receptes, Alimentació (con buscador), Vinícola, Actualitat
(Fires i rutes, Concursos i premis, Cursos i tallers, Reportatges i
novetats…; unas 133 páginas de artículos) y Contacte; boletín
(«Subscriu-m'hi»), buscador, comentarios en los artículos y redes (Facebook,
X, Instagram, correo); **sin sedes ni direcciones**. Cada sección dice qué
le toca a esta web con «**Gastronòmicament:**». Lo que pide y aún no tenemos,
con una propuesta cada cosa, está en la sección 8.

Las tres piezas y qué lleva cada una:

| Pieza | Repo | Qué lleva | Qué NO lleva |
|---|---|---|---|
| Tema padre `estrategia-web` | `armazon-wp-padre` | plantillas, partes, patrones genéricos, `theme.json` base | ningún bloque (desde su 1.1.0), ningún dato |
| Plugin `estrategia-web` | `armazon-wp-sitio` (este) | todo lo que se guarda o funciona: datos de la empresa, tipos, bloques, formularios, cookies, analítica, redirecciones, subidas… en **módulos** | diseño |
| Tema hijo `<cliente>` | el repo de la web | la marca: tokens, estilos de sección, patrones con su aspecto, partes | contenido (ni un teléfono ni un nombre de cliente: lo comprueba `theme-has-no-content.test.mjs` en Lavandería) |

---

## 1. Qué se instala y en qué orden

1. **WordPress 7.1+**, PHP 8.1+, en el idioma PRINCIPAL de la web (Ajustes ›
   Generales): castellano en Lavandería; **catalán** en Gastronòmicament (ver
   8.4: el plugin hoy solo trae `es_ES`).
2. **El plugin `estrategia-web`** (carpeta `wp-content/plugins/estrategia-web/`,
   sin `tests/`, `modules/*/tests/` ni `tools/`) y activarlo. Va antes que
   el tema: el padre pinta bloques que registra el plugin
   (`estrategia-web/map`, `/carousel`…).
3. **El tema padre `estrategia-web`** (carpeta `wp-content/themes/estrategia-web/`,
   sin `blocks/` si aún la trae, sin `tests/`). No se activa solo.
4. **El tema hijo** (carpeta `wp-content/themes/<cliente>/`) y se activa
   ESTE. Si ya había un tema con la misma carpeta, ver la trampa de
   `switch_theme` en la cabecera de `lavanderia-mallorca/despliegue/desplegar.mjs`.
5. **WPML** (si la web es bilingüe; sección 5) y **Rank Math** (SEO; sus
   ajustes por tipo usan los nombres registrados, `pt_eweb_client_…`).

En una web publicada esto lo hace el desplegador (sección 6); a mano solo en
la primera instalación o en un servidor sin SSH.

## 2. El tema hijo: cómo se crea

Se copia la estructura del de Lavandería (`lavanderia-mallorca/theme/`) y se
vacía de marca. Lo **genérico** (se copia tal cual y se cambia el prefijo):

    style.css            cabecera (Template: estrategia-web) y solo reglas de base
    functions.php        solo ganchos; prefijo <cliente>_ en todas las funciones
    inc/*.php            ayudas de patrones (una por asunto; se cargan con glob)
    templates/           front-page, page, page-full, page-hero (las del padre ya sirven)
    parts/               header, footer, navigation-overlay
    languages/           su .po/.l10n.php
    (y en la raíz del repo de la web, fuera del tema: tools/juntar-css.mjs,
    que junta assets/css/*.css en assets/css/dist/: la web carga una hoja)

Lo **de la marca** (se hace de nuevo para cada cliente):

    theme.json           colores, letras, radios, sombras, huecos
    styles/block/*.json  un estilo por TIPO de sección (no por página)
    assets/css/*.css     el CSS que theme.json no sabe decir, con el nombre del estilo
    assets/fonts, deco, iconos
    patterns/*.php       secciones con su aspecto y texto de ejemplo neutro

Reglas que ya están escritas y no se repiten aquí: `lavanderia-mallorca/theme/README.md`
(qué va en el tema y qué no; nombres de hojas; patrones). Las que importan
para el plugin:

- **Los tipos del plugin en un patrón**, siempre con su nombre registrado y
  pedido al plugin, nunca a fuego:
  `"postType":"<?php echo esc_attr( function_exists( 'estrategia_web_type_name' ) ? estrategia_web_type_name( 'client' ) : 'client' ); ?>"`.
- **Los datos de la empresa** van por Block Bindings
  (`estrategia-web-site/company`, `estrategia-web-site/location`) o por
  `[estrategia_web_company key="…"]`; nunca escritos en el tema.
- **Las categorías del aviso de cookies** que pida la web (`maps` si hay
  mapas) las declara el hijo con el filtro `estrategia_web_consent_categories`
  (ver `lavanderia-mallorca/theme/functions.php`).

**HUECO — no hay plantilla de tema hijo.** Hoy se copia Lavandería y se
borra a mano lo suyo; no hay un `armazon-wp-hijo` vacío con la estructura.
Propuesta (sin hacer): sacar de Lavandería un repo plantilla con lo genérico
de arriba y `tools/juntar-css.mjs`, como `armazon-template-tool` para las
herramientas (Armazón, fila 23: el generador vive en el repo plantilla).

## 3. Qué módulos encender

En **Estrategia Web › Módulos**. Por defecto vienen encendidos los que usa
Lavandería; apagar un módulo no borra nada de lo que guardó.

| Caso | Módulos |
|---|---|
| Toda web | `site` (datos de la empresa y tipos), `consent` (aviso de cookies), `webp`, `uploads`, `external-links`, `featured-images` |
| Con formulario de contacto o presupuesto | `forms` |
| Con sedes y mapa | `site` (tipo `location`) + `map` |
| Con portada en carrusel | `carousel` |
| Con WhatsApp | `whatsapp` |
| Con redes sociales en el pie | `social` |
| Bilingüe con WPML | `language-switcher`, `wpml-front-page` |
| Analítica sin cookies (Matomo) | `matomo` |
| Google Analytics, Ads o Tag Manager | `google-tags` (apagado por defecto; necesita `consent`) |
| Web que sustituye a otra (URL viejas) | `redirects` (apagado por defecto; si Rank Math ya lleva redirecciones, una sola de las dos) |
| Sin comentarios | `comments-off` (apagado por defecto) |

La lista completa y qué hace cada uno: [README](../README.md), «Módulos».

**Gastronòmicament:** `site` (datos de la empresa; **ningún** tipo de los
nuestros: no tiene clientes ni sedes; sus tipos son otros, 8.1), `consent`,
`webp`, `uploads` (y su recorte a 2560 px y WebP pesa en la importación, 8.2),
`external-links`, `featured-images`, `forms` (Contacte), `social`,
`language-switcher` y `wpml-front-page` (cuatro idiomas, 8.4), `redirects`
**encendido** (las URL viejas, 8.2), `matomo` o `google-tags` según lo que
mida hoy. `comments-off` **apagado**: tiene comentarios (8.5). Sin `map` ni
`whatsapp` mientras no se pidan.

## 4. Qué se configura en el panel

En este orden (lo de después usa lo de antes):

1. **Estrategia Web › Datos de la empresa**: nombre comercial y legal, CIF,
   inscripción registral, teléfonos, correo, dirección, horario, mapa,
   WhatsApp; **qué tipos de contenido usa la web** (clientes, opiniones,
   sedes, proyectos, portfolio); SVG sí/no; Matomo (URL, id del sitio,
   modo). Si la web tiene una **página** `/clientes/` y el tipo clientes, el
   archivo del tipo la pisaría: fijar otro slug en `rewrite_slugs` (README,
   sección 3).
2. **Sedes** (si la web las tiene): una por sede, con teléfono, correo,
   dirección, horario, enlace e *embed* de Google Maps y la imagen estática
   del mapa (panel «Datos de la sede» del editor).
3. **Clientes y opiniones**: título, foto o logo (y «Logo» en el ajuste de
   imagen si lo es), sector; la opinión con autor y valoración.
4. **Estrategia Web › Redes sociales**: la lista (nombre, enlace, icono).
5. **Estrategia Web › Cookies**: revisar lo que el detector encuentra y
   completar lo que no ve (las de terceros dentro de un iframe). La tabla de
   la política sale del bloque `estrategia-web/cookie-table`.
6. **Google** (si `google-tags`): GA4, Ads, Tag Manager.
7. **Estrategia Web › Formularios › Ajustes**: remitente y destinatarios.
8. **Ajustes › Medios › Subidas de imágenes**: tamaño máximo, umbral de
   píxeles y si se borra el original.
9. **Estrategia Web › Redirecciones** (si `redirects`): importar el CSV de
   las URL viejas.
10. **Rank Math**: títulos y sitemap por tipo (salen como `eweb_client`…).

**HUECO — los datos los da el cliente.** No hay un formulario de alta que
el cliente rellene: hoy David los pide por correo. Lista mínima que hay que
pedirle: los campos del punto 1, sedes, logo en vectorial (o se vectoriza,
avisándole: redibuja la marca), fotos, textos legales (aviso legal,
privacidad, cookies) y si habrá inglés.

**HUECO — los textos legales.** Lavandería los tiene en su semilla; no hay
plantilla común con los huecos marcados.

## 5. WPML: sí o no

- **No** (monolingüe): no se instala; los módulos `language-switcher` y
  `wpml-front-page` no hacen nada sin él.
- **Sí**: idioma por defecto castellano, segundo idioma por directorio
  (`/en/`). Los tipos `eweb_location`, `eweb_client`, `eweb_testimonial` y la
  taxonomía `eweb_client_sector` traducibles «mostrando el original si falta»
  (modo 2); las páginas, traducibles (modo 1). Qué campos de una sede se
  copian y cuáles se traducen lo dice `wpml-config.xml` del plugin. **La
  trampa** (medida en Lavandería): WPML no lee el `wpml-config.xml` de un
  plugin activado sin sus ganchos hasta que se abre una pantalla suya; el
  desplegador lo fuerza (`WPML_Config::load_config_run`).
- **HUECO — la licencia de WPML** es de pago y por web: quién la compra y a
  nombre de quién, no está escrito.

## 6. La semilla y la carga de contenido

La **semilla** es el contenido de arranque en ficheros (`content/semilla/`
del repo de la web): páginas en HTML de bloques, clientes, sedes, menús,
datos de la empresa, fotos. La **carga** (`cargar.mjs`) la sube por la API
REST a cualquier WordPress (Playground local o el servidor por SSH),
idempotente, y desde el 07-10-2026 **no pisa lo editado en el panel**
(huellas). En producción el contenido es del cliente: la semilla sirve para
**arrancar** y para el WordPress local, no para sincronizar.

Qué de Lavandería se reutiliza, y qué está atado a ella:

| Script | Reutilizable | Atado a Lavandería |
|---|---|---|
| `content/semilla/cargar.mjs` | el motor entero: fases, marcadores `{{media:…}}`, huellas, puente SSH, WPML | las fases leen `clientes.json`, `sedes.json`, `paginas.json` con su forma; el idioma `en` fijo; los modos de WPML |
| `content/semilla/idiomas.php`, `puente.php`, `tipos.mjs` | **sí, tal cual** | — |
| `despliegue/desplegar.mjs` | copia, paquetes desde HEAD, subida, migradores, comprobación, registro del OK | constantes al principio (`DEST`, `URL_BASE`, `WP`, `CHILD`, `DESACTIVAR`, `MODULOS_ENCENDER`, `MU_SUBIDAS`, las páginas que comprueba) y el pasado de esa web (tema hijo de spectra-one, MU-plugin de subidas) |
| `despliegue/bajar-contenido.mjs` (+ `.php`) | el mecanismo (bajar y «desrellenar» marcadores) | la forma de la semilla de Lavandería |
| `despliegue/prefijo-tipos.mjs`, `migrar-a-estrategia-web.mjs` | sí (con `--destino`/`--wp-path`) | solo los valores por defecto |

**Propuesta (sin hacer, lo decide David): sacar a un repo común
`armazon-wp-carga`** —o como herramienta con nombre, Armazón fila 27— el
motor de la carga (`cargar.mjs` sin sus fases de Lavandería, `idiomas.php`,
`puente.php`, `tipos.mjs`) y el esqueleto del desplegador con sus
constantes en un `web.json` por web. Cada web se quedaría solo con su
`content/semilla/` y ese `web.json`. Mientras no se haga, **una web nueva
copia los scripts de Lavandería y cambia las constantes**, y un arreglo en
uno hay que llevarlo a mano al otro (es lo que la propuesta evita).

**HUECO — sin plantilla de semilla.** Las páginas de una web nueva se
escriben o se diseñan desde cero (en Lavandería salieron del kit de diseño);
no hay una semilla mínima (inicio, contacto, legales) para empezar.

## 7. Lista de comprobación antes de publicar

- [ ] Plugin, padre e hijo en las versiones de `main`, empaquetados desde
      el HEAD (no desde el árbol de trabajo).
- [ ] Copia de la base de datos y de `wp-content` (el desplegador la hace;
      a mano, antes de nada).
- [ ] **Migradores en seco leídos**: el de Armazón (solo webs viejas) y el
      del prefijo de los tipos (`--seco`), sin avisos sin explicar.
- [ ] Módulos: los de la sección 3, y ninguno de más.
- [ ] Datos de la empresa completos; tipos marcados; slugs que no pisen
      páginas.
- [ ] Aviso de cookies: categorías declaradas (`maps` si hay mapas), nada
      de terceros antes de aceptar (el desplegador lo mide con Playwright),
      tabla de la política con todas las cookies.
- [ ] Páginas legales enlazadas en el pie y en el aviso.
- [ ] Accesibilidad **medida**: `node E:/Repositories/atlas/bin/kit-accesibilidad.mjs <url> --contraste`
      (cero fallos no es «conforme»: decirlo así).
- [ ] Imágenes en WebP y sin rotas (`comprobar-imagenes.mjs`).
- [ ] Con WPML: `/en/` responde, el selector de idioma sale, los campos
      «copy» de las sedes llegan a la traducción.
- [ ] Rank Math: sitemap con los tipos que se ven; títulos por tipo.
- [ ] Formularios: un envío de prueba llega al destinatario.
- [ ] Batería entera del plugin, del padre y del hijo en verde.
- [ ] El OK de David, literal, para `desplegar.mjs --ok "<frase>"`.

## 8. Lo que pide Gastronòmicament y aún no tenemos

Cinco huecos, cada uno con una **propuesta** (sin hacer: se decide y se
hace cuando toque). El criterio para decidir dónde va cada cosa es el del
Armazón: si la próxima web lo haría igual sin saber de qué va, es del plugin;
si no, es de la web.

### 8.1 Tipos de contenido PROPIOS (restaurante, receta, producto, bodega)

Los nuestros (clientes, opiniones, sedes…) son de toda web corporativa; un
«restaurante» con su cocina y su precio es de esta web. **Propuesta: un
plugin propio de la web** (`gastronomicament-tipos`, en el repo de la web,
junto al tema hijo), **no el tema hijo** (lo que se guarda no puede
desaparecer al cambiar de tema: README, primera sección) **ni el plugin
común** (no lo haría igual la siguiente web). Y para que no se invente cada
vez, el plugin común le da **el molde**:

- Declarar un tipo con la misma forma que `Content_Types::definitions()`
  (etiquetas, slug traducible, supports, taxonomía, METAS con su saneado) por
  un filtro, p. ej. `estrategia_web_content_types`, y que el plugin común
  haga el resto: registrar, `rewrite_slugs`, el panel de campos
  (`Meta_Panel`), la copia WPML de los campos «copy» y su prueba.
- **Prefijo propio** de la web en sus nombres (`gm_restaurant`, metas
  `gastronomicament_…`): el `eweb_` es solo de los tipos del plugin común.
- Integración, igual que los nuestros: Consultas por `"postType"`, campos por
  `core/post-meta`, `wpml-config.xml` propio en la raíz de ESE plugin (WPML lo
  lee de cada plugin activo), y las reglas de esta guía (huellas, semilla)
  no cambian.
- **HUECO**: el filtro-molde no existe; hoy un tipo propio se registra a mano
  con `register_post_type` y no tiene panel de campos ni copia WPML.

### 8.2 IMPORTAR lo que ya existe (miles de entradas, medios, URL viejas)

La semilla y `cargar.mjs` son para **crear** una web desde ficheros; aquí
hay que **traer** una web viva. **Procedimiento por defecto para un hosting
sin SSH** (es el caso de Raiola):

1. **Base de datos**: copia por cPanel (Copia de seguridad › base de datos) o
   phpMyAdmin (Exportar). Se importa en LOCAL y es la vía PRINCIPAL: conserva
   lo que la API REST no da, sobre todo las **relaciones de traducción de
   WPML** (`icl_translations`), los ajustes y las metas que no están en REST.
2. **Fotos** (`wp-content/uploads`, varios GB): por **FTP con réplica
   incremental** (`lftp -e "mirror --only-newer --parallel=4 …"`), con una
   cuenta FTP de cPanel **limitada a `wp-content/uploads`**. **No un zip
   único**: con GB se corta, y no se puede reanudar.
3. La web vieja **sigue publicando** mientras se rehace: el día del cambio,
   una **resincronización final** de solo lo nuevo (otro `mirror
   --only-newer` y las entradas nuevas de la base de datos, por fecha).
4. Al importar, el módulo `uploads` (2560 px y WebP) **reduce el peso**:
   **medir antes y después** (GB de `uploads`, peso medio por foto) y
   apuntarlo, que es un argumento para el cliente.
5. **URL viejas → `redirects`**: si cambian (estructura de enlaces,
   categorías), se exportan viejas y nuevas y se importa el CSV en
   Estrategia Web › Redirecciones (`wp estrategia-web redirects import`).
6. La **API REST** (con una contraseña de aplicación de un usuario de solo
   lectura, guardada fuera del repo) queda para **comprobar en vivo**:
   cuántas entradas y medios hay hoy, qué tipos y taxonomías propios están
   registrados, y si devuelve idioma y traducciones. **La trampa**: la REST
   del núcleo NO trae las relaciones de WPML; según la versión y la
   configuración de WPML se exponen o no. Por eso no es la vía de importación.

**Lo primero con el acceso delante**: si el plan tiene SSH (si lo tiene,
`wp db export` y `rsync` sustituyen a los pasos 1 y 2), la versión de WPML,
si la REST da `lang`/traducciones, los tipos y taxonomías propios
registrados (de un plugin o del tema: 8.1), y el número de entradas y de
medios. **HUECO**: no hay importador; la propuesta es un script en el repo de
la web, idempotente y con `--seco`, que pase de la copia local a la
estructura nueva (tipos propios, bloques) y escriba el CSV de redirecciones.

### 8.3 Boletín («Subscriu-m'hi»)

**No tenemos nada.** Propuesta: **no** guardar suscriptores en WordPress
(datos personales, bajas, envíos: es un servicio entero); un **formulario del
módulo `forms`** que mande el alta a un servicio de boletines (Brevo, Mailchimp,
MailerLite…) por su API, con el consentimiento de la casilla y doble
confirmación del propio servicio. Encaja como un aviso más de `forms` («al
enviar, alta en la lista X»). **HUECO**: qué servicio usa hoy, cuántos
suscriptores tiene y si se migran (los datos son suyos y de sus lectores:
eso lo decide el cliente con su RGPD).

### 8.4 Cuatro idiomas y catalán como principal

- **WPML con cuatro idiomas** (ca, es, en, de): solo se ha probado con dos.
  Lo que hay que mirar: el selector (`language-switcher`) con cuatro, la
  portada traducida (`wpml-front-page`), la carga (`cargar.mjs` tiene el
  inglés fijo: `LANG = 'en'`) y los slugs traducidos (la trampa de /en/:
  las reglas de URL se rehacen con el idioma de la petición; README y
  `Prefix_Migration::run`).
- **Catalán como idioma principal**: los textos del plugin, del padre y del
  hijo existen en `es_ES`; **falta `ca`**. Propuesta: `ca.po` en los tres con
  `tools/i18n.mjs` (fusiona y compila), en **imperativo de tú** para los
  botones (la regla de David para interfaces en catalán), y revisarlo con el
  léxico de David antes de publicar.
- Los slugs de los tipos y de las páginas se traducen con gettext: en una web
  publicada, **fijarlos** (`rewrite_slugs`) antes de cambiar o añadir idiomas.
- **HUECO**: quién traduce al alemán y quién revisa el catalán.

### 8.5 Comentarios

Lavandería los apaga (`comments-off`). Aquí hay comentarios en los artículos:
el módulo se deja **apagado** y la web usa los comentarios de WordPress.
Propuesta: moderación previa (Ajustes › Comentarios), sin enlaces, con el
aviso de privacidad en el formulario. **HUECO**: la plantilla `single.html`
del tema padre **no lleva bloque de comentarios** (medido el 15-10-2026):
o se añade en el padre (lo haría igual la siguiente web con comentarios) o
en el hijo de esta web. **HUECO**: cuánto spam recibe hoy y si usa
algo (Akismet u otro) — se mira con la copia de la base de datos (8.2).

## Cómo se mantiene esta guía

- **Quién y cuándo**: quien dé de alta una web. Cada paso que haya tenido
  que preguntar se escribe aquí en el mismo commit, y un **HUECO** que se
  cierra se quita (con el commit que lo cierra).
- **Criterio**: entra lo que se haría igual en la próxima web sin saber de
  qué va (Armazón: «la estructura viaja, el contenido no»). Lo de una web
  concreta va al repo de esa web.
- **Caso frontera, con nombre**: los slugs de los tipos (`/clientes/`). Son
  del plugin y se fijan por web en `rewrite_slugs`; NO en el tema hijo.
- **La trampa**: esta guía se escribió con UNA web hecha. Lo que dice
  «genérico» es una hipótesis hasta la segunda; si algo de aquí no valió,
  se corrige aquí, no solo en la web nueva.
