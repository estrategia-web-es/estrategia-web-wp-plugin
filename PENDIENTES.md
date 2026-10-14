# Pendientes — armazon-wp-sitio

Un solo sitio (Armazón, fila 25). Lo terminado **sale** de aquí: al historial
de git, con el commit que lo cierra.

---

## Hoja de ruta Estrategia Web — lo que tienen que llevar TODAS las webs (12-10-2026)

Lista de David, para que las webs nuevas y las ya hechas lleven lo mismo. Va
aquí porque casi todo acaba en este plugin. **Cómo se mantiene**: cuando algo
se termina Y se publica en las webs, sale de esta lista con el commit que lo
cierra; si se termina pero no se publica, pasa a «Hecho y sin desplegar».

**Estructura decidida** (David, 14-10-2026): un tema que solo es diseño + un
plugin «Estrategia Web» con módulos activables. Lo de «A añadir» se hace como
**módulo nuevo** (una carpeta en `modules/`, ver la cabecera de
`includes/class-modules.php`) o dentro del módulo al que pertenece (las
cookies, en `consent`).

### Hecho y sin desplegar (en local, commiteado)

- **Un tema + un plugin con módulos** (plugin 1.1.0, tema 1.1.0,
  14-10-2026): este repo es ahora el plugin `estrategia-web`, con Site, Forms
  (repo archivado) y los cuatro bloques del tema como módulos; pantalla
  «Estrategia Web → Módulos»; un dominio de traducción. Lavandería en local
  ya lo usa. **Falta adaptar el migrador** (abajo, «Migrador al plugin único»).
- **Fase 1: nombre Estrategia Web** en los tres repos (tema 1.0.0, este plugin
  1.0.0, formularios 1.0.0): carpetas, prefijos, bloques, opciones, tablas,
  cookie; alias de los nombres viejos hasta 2.0.0; «Powered by» apagado por
  defecto (WordPress.org, guideline 10). Migrador: Lavandería
  `despliegue/migrar-a-estrategia-web.mjs` (--seco contra el servidor, limpio).
- **Desplegador que no pisa el contenido** (Lavandería): publicar = código; el
  contenido solo con `--contenido`, con huella por entrada y sin tocar lo
  editado en el panel ni las páginas inglesas de WPML.
- **bajar-contenido.mjs** (Lavandería): trae al repo lo editado en el servidor.

### A añadir (todavía sin hacer)

- **Subidas de imágenes** — hoy es un MU-plugin aparte en Lavandería
  (`wp-content/mu-plugins/estrategia-web-subidas.php`, 12-10-2026): límite de
  20 MB, umbral de serie de 2560 px y borrar el original gigante tras reducir.
  Pasa a opción de este plugin (límite y umbral configurables) y el MU-plugin
  se borra al publicarlo. Probado en el servidor: una foto de 4056×3040 y
  4,2 MB quedó en 2560×1919 WebP (947 KB) sin original en el disco.
- **Cookies como módulo o plugin aparte, con detector automático**: el
  navegador del visitante que acepta manda solo los NOMBRES de las cookies; el
  panel las lista y propone categoría con la Open Cookie Database. Categoría
  «publicidad» y Consent Mode v2 de Google.
- **Analítica**: Google Analytics 4, Google Ads (conversiones) y Tag Manager,
  como alternativa a Matomo, siempre detrás del consentimiento.
- **Redirecciones editables en el panel**: origen, destino, tipo (301, 302,
  410), importar CSV (el de Lavandería está en
  `docs/redirecciones/redirecciones-2026-10-07.csv`), contador. Ojo: las
  variantes `%3F` y `?p=ID` de las URL viejas.
- **Redes sociales personalizadas**: lista libre (nombre, enlace, icono de una
  colección o SVG subido) y un bloque para pintarlas. David revisará antes cómo
  quiere esto.
- **«Tipo de negocio» del cliente**: campo traducible aparte del título (caso:
  «Portic Villas (agencia alquiler vacacional)» salía igual en inglés).
- **Recuadro «Datos de la sede»** (ver la sección siguiente) y que cambie los
  dos idiomas a la vez.

---

## Migrador al plugin único
**Fecha:** 14-10-2026 · **Vence:** antes de publicar nada en Lavandería

Lo hace la «parte 3» (otro agente). Lo que cambió la 1.1.0 y el migrador
tiene que saber:
- `includes/class-armazon-migration.php`, paso 7 (`active_plugins`): hoy
  pasa `armazon-site/armazon-site.php` y `armazon-forms/armazon-forms.php` a
  `estrategia-web-site/…` y `estrategia-web-forms/…`. Tiene que dejar UNO:
  `estrategia-web/estrategia-web.php` (quitar los dos viejos de la lista, sin
  llamar a sus ganchos de desactivación), y comprobar en el paso previo que
  existe `plugins/estrategia-web`, no las dos carpetas de la 1.0.0.
- El cron de la purga de formularios conserva su nombre
  (`estrategia_web_forms_purge`): el paso que quita el evento viejo
  (`armazon_forms_purge`) sigue valiendo; el nuevo lo programa el módulo
  forms solo, en init.
- La opción `estrategia_web_modules` NO hace falta escribirla: sin ella
  están todos los módulos encendidos, que es lo que usa Lavandería. Si se
  escribe, que sea con `wp option update` (no con SQL), para que se apunte
  `estrategia_web_modules_known`.
- El dominio de traducción `estrategia-web-site` / `estrategia-web-forms` no
  está guardado en la base de datos; nada que migrar. El contexto de cadenas
  de WPML de los formularios sigue siendo `estrategia-web-forms`.
- El desplegador de Lavandería sube hoy `plugins/estrategia-web-site` y
  `plugins/estrategia-web-forms` desde dos repos: tiene que subir
  `plugins/estrategia-web` desde este (sin `tests/`, `modules/*/tests/` ni
  `tools/`) y el tema sin `blocks/`.

## Pruebas de los bloques: del tema al plugin
**Fecha:** 14-10-2026 · **Vence:** —

Las de carrusel, mapa y WhatsApp siguen en `armazon-wp-padre/tests/`, que
monta este plugin con solo los módulos de bloques encendidos. Funcionan,
pero quien toque `modules/carousel/` tiene que saber que su prueba está en
otro repo (lo dice su `module.php`). Moverlas pide traer su siembra
(`tests/fixtures/setup/setup.php` del tema) y medirlas sin el CSS del tema,
o con un tema de prueba propio. Se hace la próxima vez que se toque uno.

## Formularios: probarlo con WPML de verdad
**Fecha:** 01-10-2026 · **Vence:** antes de la primera web con WPML

(Venía de armazon-wp-formularios, archivado.) `class-language.php` y
`wpml-config.xml` no se han ejecutado nunca con WPML en las pruebas del
plugin (Playground no lo tiene). Lavandería sí tiene WPML en local
(`tests/fixtures/wpml/`): traducir una página con formulario, enviar en el
segundo idioma, mirar que el acuse sale traducido, que el registro dice ese
idioma y que en Traducción de cadenas aparecen los textos por defecto; y que
en la página traducida el desplegable con `defaultOption` llega con la misma
opción marcada.

## Formularios: nivel 4
**Fecha:** 01-10-2026 · **Vence:** —

(Venía de armazon-wp-formularios.) Nadie ha mirado con ojos de persona el
editor de formularios (la rejilla de medio ancho, la tarjeta de los avisos,
el panel lateral). Y desde la 1.1.0, tampoco la pantalla «Estrategia Web →
Módulos».

---

## Recuadro «Datos de la sede» en la pantalla de edición de la sede
**Fecha:** 07-10-2026 · **Vence:** después de la fase 1 (el cambio de nombre)

David quiso cambiar el teléfono de la sede Maioris y no encontró dónde: el
editor de bloques esconde las metas de la sede (teléfono, dirección,
horario, enlace y embed del mapa, mapa estático), y el panel «Campos
personalizados» no sale con los tipos del plugin. Se cambió por WP-CLI.
Hace falta un recuadro «Datos de la sede» en la pantalla de edición de la
sede (barra lateral del documento o meta box) con esos campos, que guarde
en las metas registradas (`estrategia_web_location_…`). Lo mismo, si se
quiere, para cliente (ajuste de imagen) y opinión (autor, valoración).

## Tipos y taxonomías sin prefijo, antes de WordPress.org
**Fecha:** 07-10-2026 · **Vence:** antes de mandarlo a WordPress.org

En la 1.0.0 todo ganó el prefijo `estrategia_web_` / `estrategia-web-`
menos los tipos (`client`, `testimonial`, `location`, `project`,
`portfolio`) y sus taxonomías (`client_sector`…). Son nombres genéricos que
otro plugin puede usar, y la revisión de WordPress.org suele pedir prefijo.
No se cambiaron porque cambiar un tipo cambia su ruta REST, las Consultas
guardadas en las páginas (`"postType":"client"`) y sus filas en
`wp_posts`: necesita su propio paso en el migrador y una versión mayor
(2.0.0). Lo decide David.

## Remoto del repo (fila 24)
**Fecha:** 02-10-2026 · **Vence:** antes de instalarlo en la primera web

Nació sin remoto: se creó en una sesión sin permiso para crear repos en
GitHub. No es un «ninguno» decidido como el de `atlas`: el preferente es
GitHub privado, y un plugin que van a usar varias webs necesita un sitio del
que descargarlo. Lo decide David (el mismo caso que armazon-wp-formularios).

## WPML: qué se traduce de aquí
**Fecha:** 02-10-2026 · **Vence:** antes de la primera web con WPML

No hay `wpml-config.xml`. Los tipos y taxonomías no declaran si son
traducibles, y los datos de la empresa (horario, mensaje de WhatsApp) no se
registran como cadenas. En una web con WPML hay que decidir: tipos
traducibles sí (clientes y opiniones probablemente no), y `schedule`,
`address` y `whatsapp_message` como cadenas. Y los slugs: con WPML, los
traduce WPML, no gettext.

## Orden manual en una Consulta
**Fecha:** 02-10-2026 · **Vence:** —

Los tipos llevan `page-attributes` (orden manual) pero ninguna prueba mira una
Consulta con `"orderBy":"menu_order"`. El README pone `title` en el ejemplo
por eso. Una prueba de nivel 2 en types.test.mjs lo cierra.

## Nivel 4: mirarlo
**Fecha:** 02-10-2026 · **Vence:** —

Las pruebas comprueban que funciona, no que se vea bien. Nadie ha mirado aún
con ojos de persona la pantalla «Datos de la empresa», el panel «Atributos»
de un bloque con la fuente, ni un SVG en la mediateca.
