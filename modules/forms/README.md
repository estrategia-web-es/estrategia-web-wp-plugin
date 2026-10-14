# Módulo `forms` — los formularios de las webs de WordPress

> **Desde el 14-10-2026 (plugin Estrategia Web 1.1.0) esto es un MÓDULO del
> plugin único**, no un plugin aparte (el repo armazon-wp-formularios quedó
> archivado). Lo que cambia respecto a lo que cuenta este README, escrito
> cuando era plugin:
> - Se instala con el plugin `estrategia-web` y se enciende o apaga en
>   «Estrategia Web → Módulos». Su arranque es [module.php](module.php).
> - **Dominio de traducción: `estrategia-web`** (no `estrategia-web-forms`), en
>   `languages/` de la raíz del plugin; ya no hay JSON del editor.
> - `wpml-config.xml` está en la raíz del plugin (WPML solo lee ese).
> - Pruebas: [tests/](tests/), corridas por el `tests/run.mjs` de la raíz del
>   plugin (`node tests/run.mjs forms/`), en el puerto **9404** (antes 9402).
> - Lo demás —bloques, rutas REST, tablas, opciones, menú «Formularios»— es
>   igual, con los mismos nombres.

Formularios **por bloques**, con **varios avisos por correo** hechos con
plantillas, **registro de todo lo enviado** y **compatibles con WPML**. Lo usan
todas las webs hechas con el tema padre del Armazón; es compartido y
versionado. El porqué de que sea propio (Contact Form 7 no gusta; Formello está
abandonado, sin WPML) está en
[armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md](E:/Repositories/armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md).

- Slug y text domain: `estrategia-web-forms` · WordPress **7.1+** · PHP **8.1+** · GPLv2+
- Sin dependencias, sin `node_modules`, sin paso de compilación.

## Qué hace

1. **Bloques**: «Formulario» (contenedor) y dentro «Campo de texto», «Campo de
   correo», «Campo de teléfono», «Campo de mensaje», «Campo desplegable»,
   «Casilla» (también el consentimiento RGPD), «Botón de enviar» y «Aviso por
   correo». Cada campo tiene etiqueta, nombre (clave), obligatorio, texto de
   ejemplo y ancho (completo / medio). Se pueden meter en grupos y columnas: el
   formulario se maqueta como cualquier bloque.
2. **Avisos**: cada bloque «Aviso por correo» es un correo que sale con cada
   envío — al equipo, acuse al remitente, una copia a otro sitio… — con
   destinatarios, responder-a, asunto y cuerpo, todos con marcadores.
3. **Registro**: cada envío se guarda con formulario, página, fecha, idioma,
   datos, resultado de cada aviso (enviado / fallo y motivo), IP truncada y
   navegador. Pantalla **Formularios → Envíos**: lista, filtro por formulario,
   ficha, exportar CSV y borrar con confirmación. Retención configurable con
   purga diaria.
4. **Seguridad**: envío por un endpoint REST propio con token firmado,
   validación en servidor por tipo de campo, honeypot, tiempo mínimo de
   relleno y límite de envíos por IP.
5. **Funciona sin JavaScript** (POST normal y redirección) y **con JavaScript**
   (Interactivity API, sin recargar, mensajes en una zona `aria-live`).

## Configurar un formulario

1. En el editor, añade el bloque **Formulario**. Llega con una plantilla:
   nombre, correo, mensaje, consentimiento, botón y dos avisos (a la web y
   acuse al remitente). Cambia, quita o añade lo que haga falta.
2. En el panel lateral del formulario: el **nombre en el registro** y, si
   hace falta, los mensajes de éxito y error propios.
3. Cada campo: su **nombre (clave)** es el que se usa en los marcadores
   (`{{email}}`) y en el registro. Se genera solo; cámbialo antes de publicar,
   no después (el registro agrupa por él).
4. Cada **aviso**, en su panel lateral:
   - **Para**: direcciones separadas por comas o un marcador (`{{email}}`).
     Vacío = el correo del administrador de la web.
   - **Responder a**: una dirección o un marcador. Opcional.
   - **Asunto** y **Mensaje**: texto con marcadores. Mensaje vacío =
     `{{todos_los_campos}}`.
5. La **casilla de consentimiento** es siempre obligatoria. Lo que va entre
   llaves en su etiqueta es el enlace: «He leído y acepto la {política de
   privacidad}». Sin URL propia enlaza a la página de privacidad de *Ajustes →
   Privacidad* (con WPML, la traducida).

**Formularios → Ajustes** tiene solo lo común: retención, antispam y los
textos por defecto.

### Preseleccionar una opción del desplegable según la página

El mismo formulario en varias páginas, y en cada una el desplegable llega ya
con la suya (presupuesto en hostelería → «Lavandería para hoteles…»). La
opción se indica por su **posición**: 1 es la primera, 0 ninguna.

- **Formulario normal** (o patrón NO sincronizado, que se copia en cada
  página): panel del campo → **Opción preseleccionada**. Queda en el bloque:
  `<!-- wp:estrategia-web-forms/select {"name":"servicio","options":"…","defaultOption":2} /-->`.
- **Bloque sincronizado** (el mismo formulario en todas las páginas): sus
  bloques de dentro son los mismos en todas, así que la opción se fija en el
  bloque del patrón de CADA página, en su atributo `content`, con la clave
  del **nombre del campo**. En el editor de código de la página:
  `<!-- wp:block {"ref":123,"content":{"servicio":{"defaultOption":1}}} /-->`.
  No hay control en el panel para esto: WordPress solo deja anular por página
  los bloques del núcleo.
- **Desde un enlace**: `?af_servicio=2` o
  `?af_servicio=lavanderia-para-barcos-y-yates` (el texto de la opción como
  slug). Gana a lo de la página. `af_` delante a propósito: un campo llamado
  `name`, `p` o `s` chocaría con WordPress. Un valor que no es opción se
  ignora y nunca llega al HTML.

Si el visitante ya eligió (vuelta de un envío sin JS con errores), manda lo
que eligió. Con WPML, la posición vale en todos los idiomas porque las
opciones se traducen sin cambiar el orden; el enlace con slug lleva el texto
de su idioma. El orden de las fuentes, el porqué de la posición y las
trampas, en [includes/class-preselect.php](includes/class-preselect.php).

### Marcadores

| Marcador | Qué pone |
|---|---|
| `{{nombre_del_campo}}` | lo que escribió el visitante en ese campo (una casilla: Sí / No) |
| `{{todos_los_campos}}` | todos los campos con su etiqueta; tabla en HTML, líneas en texto |
| `{{fecha}}` | fecha y hora del envío, con el formato de la web |
| `{{url_pagina}}` | la página desde la que se envió |
| `{{nombre_sitio}}` | el nombre de la web |

Un campo opcional vacío se sustituye por nada. Un marcador **que no existe**
(`{{emial}}`) se deja tal cual, a propósito: la errata se ve en el correo.

### Plantilla de los correos

Cada aviso sale en HTML (sencillo: tablas y estilos en línea) con la versión
en texto como alternativa. La plantilla es
[templates/email.php](templates/email.php); una web la sustituye **sin tocar
el plugin** copiándola a `<tema>/estrategia-web-forms/email.php` (hijo o padre).

## Cómo lo usa un tema hijo

Nada que programar: se instala el plugin y se usan los bloques. Para vestirlo:

- **El botón** es `.wp-element-button`: hereda `styles.elements.button` del
  `theme.json`.
- **Los campos** heredan tipografía y color; el borde, el radio, el relleno,
  el hueco y el color de error son variables: `--estrategia-web-forms-border`,
  `--estrategia-web-forms-radius`, `--estrategia-web-forms-padding`, `--estrategia-web-forms-gap`,
  `--estrategia-web-forms-error`, en el CSS del hijo sobre `.estrategia-web-forms`.
- Las clases `.estrategia-web-forms__*` son estables entre versiones menores.
- **La flecha del desplegable** es la nativa del navegador: el plugin no la
  quita. Un tema que quiera su propio chevron pone `appearance: none` y su
  flecha juntos, y pinta el fondo con `background-color`, no con el atajo
  `background`, que borra la imagen de la flecha (fue la «caja lisa» de la
  revisión de Lavandería, 03-10-2026). Por qué no lo pone el plugin, en la
  cabecera de [assets/style.css](assets/style.css).
- Un patrón del hijo puede llevar un formulario entero (bloques con sus
  atributos). Copias de un mismo patrón comparten identificador y se agrupan
  en el registro; si deben ir aparte, se cambia en el editor.

Ganchos para lo raro: `estrategia_web_forms_email` (retocar un aviso),
`estrategia_web_forms_submitted` y `estrategia_web_forms_rejected` (acciones),
`estrategia_web_forms_capability` (quién ve los envíos, por defecto
`manage_options`), `estrategia_web_forms_client_ip` (IP tras un proxy de confianza),
`estrategia_web_forms_csv_separator` (`;` por defecto) y `estrategia_web_forms_settings`.

## Decisiones, con su porqué

### Por qué los avisos viven en el bloque y no en una pantalla aparte

- **Se traducen con la página.** Con WPML, la página traducida lleva sus
  avisos traducidos, así que el acuse sale en el idioma del visitante sin
  hacer nada más. En una pantalla de administración habría que registrar cada
  texto como cadena suelta.
- **Se ve cuántos avisos tiene cada formulario** al editarlo.
- **No llegan al navegador del visitante**: los bloques de aviso no pintan
  nada, y la configuración de `post_content` solo la ve quien puede editar.

Y lo que lo hace seguro: al pintar el formulario, el servidor guarda una
**foto de su definición** (campos y avisos) en una tabla y en el HTML solo
pone su hash. Al recibir el envío valida y avisa con la foto. El visitante no
puede añadir campos, quitar un obligatorio ni cambiar un destinatario: solo
señalar una definición que el servidor pintó. Funciona igual si el formulario
está en una página, un patrón sincronizado o una parte de plantilla. Detalle y
trampas en la cabecera de
[includes/class-definitions.php](includes/class-definitions.php).

Cada aviso es un **bloque** y no una lista en un atributo para que WPML lo
traduzca con claves planas (`subject`, `body`) en
[wpml-config.xml](wpml-config.xml), sin depender de cómo trate los atributos
anidados.

### Por qué tabla propia y no un tipo de entrada privado

Filtrar, exportar y purgar por fecha son una consulta; con un CPT los datos
irían a `postmeta`, y **WPML trataría los envíos como contenido traducible**.
Dos tablas con migraciones numeradas (Armazón, fila 14):
[includes/class-migrations.php](includes/class-migrations.php).

### Por qué el editor no se compila

[assets/editor.js](assets/editor.js) es JavaScript moderno escrito con
`wp.element.createElement` en vez de JSX, y lee los globales `wp.*` que el
editor ya trae. Sin `@wordpress/scripts`, sin `node_modules`, sin `build/`:

- el plugin se instala copiando la carpeta, y lo que hay en git es lo que corre;
- no hay una versión de herramientas que envejezca (`@wordpress/scripts`
  arrastra cientos de paquetes que habría que mantener al día en un plugin de
  ~400 líneas de editor);
- el precio es escribir `h('div', …)` en vez de `<div>`, que en estos bloques
  (formularios de paneles laterales) es poco.

**No es un módulo ES** (`import`): los bloques del editor se registran con
scripts clásicos que corren antes de que el editor arranque, y un módulo se
ejecuta diferido. El lado público **sí** es módulo:
[blocks/form/view.js](blocks/form/view.js), con la Interactivity API, que
WordPress sirve como módulo sin compilar.

### Por qué un token propio y no `wp_create_nonce()`

Un nonce de WordPress va atado al usuario, y la API REST trata como anónima
cualquier petición con cookies sin cabecera `X-WP-Nonce`. Un editor con la
sesión abierta que probara su formulario sin JavaScript fallaría siempre. El
token es la hora de pintado firmada con la sal del sitio, ligada a la
definición; caduca a los 7 días y es también el reloj del tiempo mínimo.
Ver [includes/class-token.php](includes/class-token.php).

### WPML

Todo lo que sabe de WPML está en
[includes/class-language.php](includes/class-language.php), y solo actúa si
WPML está activo:

- Los textos de los bloques (etiquetas, opciones, mensajes, asunto y cuerpo de
  los avisos) los traduce WPML con la página, según
  [wpml-config.xml](wpml-config.xml). El destinatario no se traduce a
  propósito (que un traductor no pueda desviar los correos).
- Los textos de **Ajustes** se registran con `wpml_register_single_string` y
  se traducen con `wpml_translate_single_string` (dominio `estrategia-web-forms`).
- El idioma del envío es el de la página que vio el visitante
  (`wpml_current_language` al pintar), queda en el registro, y al enviar se
  cambia a él (`wpml_switch_language` + `switch_to_locale`) para que los textos
  fijos de los correos salgan en su idioma.
- Sin WPML, el idioma es el locale de WordPress (`es_ES`) y todo funciona igual.

### Textos y traducciones (fila 11) — y dónde se aparta

`languages/`: [estrategia-web-forms.pot](languages/estrategia-web-forms.pot),
[estrategia-web-forms-es_ES.po](languages/estrategia-web-forms-es_ES.po), su
`.l10n.php` (formato PHP de WP 6.5+) y el JSON del editor. Se generan con
`node tools/i18n.mjs` (ver su cabecera; necesita GNU gettext, que trae Git
para Windows).

**Se aparta de la fila 11 en una cosa, y lo dice:** el `msgid` es el **texto
en inglés**, no una clave simbólica. Es la convención de la plataforma —
`__( 'Send', … )`—: WordPress, Poedit, Loco Translate y la traducción de
cadenas de WPML esperan el texto de origen, y con claves una web en un idioma
sin `.po` enseñaría `form.submit` en el botón. El precio es el que avisa la
fila: cambiar el inglés deja la traducción como *fuzzy*, y el script lo cuenta.

## Pruebas (fila 7)

    node tests/run.mjs             1 → 2 → 3, para en el primer rojo
    node tests/run.mjs --nivel 1   solo el 1 (cada commit, ~0,2 s)

El corredor arranca **Playground** (WP 7.1, PHP 8.3) si no hay uno en el
puerto 9402, con el plugin montado y [tests/blueprint.json](tests/blueprint.json),
y lo mata al terminar. Los correos no salen: un mu-plugin de prueba
([tests/wp/estrategia-web-forms-test.php](tests/wp/estrategia-web-forms-test.php)) los
intercepta con `pre_wp_mail` y los guarda para que la prueba los lea.

| Fichero | Nivel | Qué prueba |
|---|---|---|
| [tests/pure.test.mjs](tests/pure.test.mjs) | 1 | marcadores, IP truncada, token, preselección (con el PHP de la máquina); el CSS base no quita la flecha del desplegable |
| [tests/preselect.test.mjs](tests/preselect.test.mjs) | 2 | opción preseleccionada por atributo, por URL (slug y posición, valor ajeno ignorado), por página en un bloque sincronizado, y la vuelta sin JS |
| [tests/server.test.mjs](tests/server.test.mjs) | 2 | HTML público sin destinatarios, obligatorio vacío en servidor, correo y opción inválidos, honeypot, tiempo mínimo, token falso, límite por IP, envío completo con N avisos y marcadores, sin JS (303), purga |
| [tests/autocomplete.test.mjs](tests/autocomplete.test.mjs) | 2 | `autocomplete` (0.2.2): el del bloque se pinta (name, organization, en texto, desplegable y área de texto); correo y teléfono con email y tel por defecto y cambiables; un texto sin él no lo lleva; un valor con comillas no se cuela. Vista fallar antes del código |
| [tests/browser.test.mjs](tests/browser.test.mjs) | 3 | con JS sin recargar y `aria-live`, validación nativa, error de servidor saltándose el navegador, sin JS de verdad |
| [tests/admin.test.mjs](tests/admin.test.mjs) | 3 | lista, filtro, ficha, CSV (e inyección en CSV), nonce, borrar con confirmación, bloques en el editor; la preselección sigue a su texto al reordenar opciones, y abrir y guardar una página no borra el `content` de su bloque sincronizado |
| [tests/i18n.test.mjs](tests/i18n.test.mjs) | 3 | es_ES: textos por defecto, errores, idioma registrado, correo y editor |

Todas se han visto fallar: se rompió a propósito el código que cubre cada una
(tres tandas de mutantes, 01-10-2026; las de la preselección, 03-10-2026) y
se comprobó que se ponía en rojo. La de «abrir y guardar no borra el
`content`» no se puede ver en rojo rompiendo el plugin: vigila a WordPress,
y se pondrá roja el día que el editor empiece a tirarlo.

## Instalar en una web

Copiar la carpeta a `wp-content/plugins/estrategia-web-forms/` **sin `tests/` ni
`tools/`** y activar. `tests/wp/` abre rutas REST sin permisos: no se carga
si no la carga el `blueprint` de pruebas (doble llave: un mu-plugin que no
existe en una web y la constante `ESTRATEGIA_WEB_FORMS_TESTING`), pero no tiene por
qué estar en el servidor.

**Secretos (fila 21): no hay.** El token se firma con `wp_salt('nonce')`, que
vive en el `wp-config.php` de cada web. El envío de correo es cosa de la web
(`wp_mail` y su SMTP): el plugin no guarda credenciales.

Al **borrar** el plugin no se borra el registro, salvo que la web defina
`ESTRATEGIA_WEB_FORMS_REMOVE_DATA` a `true` ([uninstall.php](uninstall.php)).

## Nombres: de «Armazón Forms» a «Estrategia Web Forms» (1.0.0)

Hasta la 0.2.2 el plugin se llamó **Armazón Forms** (carpeta
`armazon-forms`). En la 1.0.0 (07-10-2026) pasó a **Estrategia Web Forms**
para poder publicarse en WordPress.org (prefijos únicos de 4 o más
caracteres). Las entradas 0.x del CHANGELOG son del nombre viejo; el repo
sigue en `E:\Repositories\armazon-wp-formularios`.

| Qué | Antes | Ahora |
|---|---|---|
| Carpeta, fichero, text domain | `armazon-forms/armazon-forms.php` | `estrategia-web-forms/estrategia-web-forms.php` |
| Namespace | `Armazon\Forms` | `EstrategiaWeb\Forms` |
| Funciones, constantes, filtros, opciones | `armazon_forms_…`, `ARMAZON_FORMS_…` | `estrategia_web_forms_…`, `ESTRATEGIA_WEB_FORMS_…` |
| Tablas | `{prefijo}armazon_forms_submissions`, `…_definitions` | `{prefijo}estrategia_web_forms_…` |
| Bloques | `armazon-forms/form`, `/text`… | `estrategia-web-forms/…` |
| Ruta REST | `armazon-forms/v1` | `estrategia-web-forms/v1` |
| Clases CSS y propiedades | `.armazon-forms__…`, `--armazon-forms-…` | `.estrategia-web-forms__…`, `--estrategia-web-forms-…` |
| Contexto de WPML | `armazon-forms` | `estrategia-web-forms` |

Los bloques viejos siguen pintando durante la 1.x
([includes/class-legacy-names.php](includes/class-legacy-names.php)); se
quitan en la **2.0.0**. Las tablas, las opciones y las cadenas de WPML las
pasa **el migrador**, que vive en el plugin Estrategia Web Site
(`includes/class-armazon-migration.php`): los envíos se renombran con
`RENAME TABLE`, no se copian ni se pierden.

## Si cambias de proveedor

- **Licencia:** GPL-2.0-or-later. Es tuyo para usarlo, cambiarlo y
  llevártelo: no depende de ningún servicio nuestro (el correo sale por el
  `wp_mail` de la web).
- **Si desactivas el plugin**, el formulario deja de verse: sus bloques
  son dinámicos y sin el plugin no pintan nada (queda, si lo hay, el texto
  que rodee al formulario en la página). Nadie puede enviar nada.
- **El registro de envíos no se borra** al desactivar ni al borrar el plugin
  (salvo `ESTRATEGIA_WEB_FORMS_REMOVE_DATA`). Está en dos tablas propias de
  la base de datos.
- **Exportar, ANTES de desactivarlo:** Formularios › Envíos › «Exportar CSV»
  por formulario. Con el plugin ya quitado: `wp db export --tables=<prefijo>estrategia_web_forms_submissions,<prefijo>estrategia_web_forms_definitions`
  (los campos de cada envío van en JSON en la columna `data`).

## Versiones

`MAYOR.MENOR.PARCHE`, con su nota en [CHANGELOG.md](CHANGELOG.md).

- **Una versión menor o de parche nunca obliga a tocar una web**: ni sus
  bloques, ni su tema hijo, ni su plantilla de correo. Es la misma regla del
  tema padre (condición 2 de la decisión).
- Lo que rompa algo de una web —renombrar un atributo, una clase
  `.estrategia-web-forms__*`, un marcador, la variable de una plantilla de correo— es
  **versión mayor**, con nota de qué cambiar en cada web.
- Una web puede quedarse en una versión vieja: nadie está obligado a
  actualizar el mismo día.
- La forma de la base de datos va aparte (`Migrations::LATEST`); sube cuando
  hay migración, y la migración corre sola al cargar.

## Cómo se mantiene

- **Quién y cuándo:** quien toque el plugin, en cada versión: número en
  `estrategia-web-forms.php` y `ESTRATEGIA_WEB_FORMS_VERSION`, nota en el CHANGELOG,
  `node tools/i18n.mjs` si cambió un texto, y `node tests/run.mjs` entero antes
  de llevarlo a ninguna web.
- **Qué entra aquí y qué no:** entra lo que vale para **cualquier** formulario
  de **cualquier** web (un tipo de campo, un marcador, una regla antispam). Lo
  que necesita una sola web va en su tema hijo, con los ganchos de arriba.
- **El caso frontera:** «el cliente quiere el aviso con su logo». Eso no es
  una opción del plugin: es la plantilla `estrategia-web-forms/email.php` del hijo.
  Si **todas** las webs acaban queriendo logo, entonces sí entra aquí.
- **La trampa:** WPML no está en las pruebas (es de pago). Toda versión que
  toque [includes/class-language.php](includes/class-language.php) o
  [wpml-config.xml](wpml-config.xml) se prueba a mano en una web con WPML
  antes de llevarla a las demás: un formulario traducido, un envío en el
  segundo idioma y mirar que el acuse sale en ese idioma.
- **Cada fichero dice cómo se mantiene en su cabecera.** Si cambias uno,
  revisa que su cabecera siga siendo verdad.

Pendiente: [PENDIENTES.md](PENDIENTES.md).
