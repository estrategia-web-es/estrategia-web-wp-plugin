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
  (repo archivado) y los cuatro bloques del tema como módulos (los bloques
  ya no viven en el tema); pantalla «Estrategia Web → Módulos»; un dominio
  de traducción. Lavandería en local ya lo usa.
- **Migrador al plugin único** (14-10-2026): `class-armazon-migration.php`
  deja activo solo `estrategia-web/estrategia-web.php` (salen armazon-site,
  armazon-forms y los dos de la 1.0.0). El desplegador de Lavandería sube un
  plugin (sin `tests/`, `modules/*/tests/` ni `tools/`) y el tema sin
  `blocks/`, aparta el MU-plugin de subidas, enciende `comments-off` una vez
  y tiene los pasos opcionales `medios` y `limpieza`. Ensayado en local con
  las versiones de producción; ver su cabecera.
- **Subidas de imágenes** (módulo `uploads`, Default: on): 20 MB, umbral de
  2560 px y borrar el original gigante, configurables en Ajustes → Medios.
  Sustituye al MU-plugin de Lavandería, que el desplegador aparta al publicar.
- **Cookies con detector y analítica** (fusionado el 14-10-2026): categorías
  de la AEPD, detector de nombres, Open Cookie Database, pantalla Cookies,
  tabla de la política; módulos `google-tags` (Consent Mode v2, Default: off)
  y `matomo` (Default: on, la opción sigue en `estrategia_web_site`). Lo que
  quedó fuera:
  - la conversión de Google Ads de un formulario enviado SIN JavaScript (la
    redirección con `af_result`) no se cuenta;
  - la tabla de la política no lleva las cookies de terceros que nadie añade
    a mano (las de google.com del iframe del mapa): el detector no las ve.
- **Redes sociales personalizadas** (módulo `social`, Default: on): lista
  libre (nombre, enlace, icono de la colección o SVG) y el bloque
  `estrategia-web/social-links`. David revisará cómo la quiere.
- **«Tipo de negocio» del cliente** (`estrategia_web_client_business_type`,
  traducible). En Lavandería falta pasar a ese campo lo que hoy va entre
  paréntesis en el título (a mano en el panel, tras publicar).
- **Recuadro «Datos de la sede»** (y de cliente y opinión): `Meta_Panel` del
  módulo `site`, con `wpml-config.xml` para copiar o traducir cada campo.
- **Otros módulos nuevos**: `comments-off` (Default: off; Lavandería lo
  enciende al desplegar), `featured-images` (tamaño `estrategia-web-small`,
  200 px: `wp media regenerate --only-missing` en las webs que ya tienen
  fotos), recorte vertical del carrusel en el móvil (lo pide el tema con
  `estrategia_web_carousel_mobile_crop`).
- **Fase 1: nombre Estrategia Web** en los tres repos (tema 1.0.0, este plugin
  1.0.0, formularios 1.0.0): carpetas, prefijos, bloques, opciones, tablas,
  cookie; alias de los nombres viejos hasta 2.0.0; «Powered by» apagado por
  defecto (WordPress.org, guideline 10). Migrador: Lavandería
  `despliegue/migrar-a-estrategia-web.mjs` (--seco contra el servidor, limpio).
- **Desplegador que no pisa el contenido** (Lavandería): publicar = código; el
  contenido solo con `--contenido`, con huella por entrada y sin tocar lo
  editado en el panel ni las páginas inglesas de WPML.
- **bajar-contenido.mjs** (Lavandería): trae al repo lo editado en el servidor.
- **Redirecciones editables en el panel** (módulo `redirects`, Default: off,
  14-10-2026): CSV de Lavandería entero, `%3F` y `?p=`/`?page_id=`, solo en
  los 404, registro de 404, aviso de Rank Math, WP-CLI. En Lavandería se
  queda APAGADO (las lleva Rank Math).
- **Tipos y taxonomías con prefijo `eweb_`** (15-10-2026, rama
  prefijo-tipos, versión propuesta 1.2.0): `Type_Names`, alias
  `Unprefixed_Names` hasta 2.0.0, migrador `Prefix_Migration` (y en
  Lavandería `despliegue/prefijo-tipos.mjs`, que el desplegador pasa una
  vez). Tabla de nombres públicos en el README y guía
  [docs/web-nueva.md](docs/web-nueva.md).

### A añadir (todavía sin hacer)

(Nada de la lista de David del 12-10-2026: todo está en «Hecho y sin
desplegar». Lo nuevo que pida entra aquí.)

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


## Tres metas sin prefijo, antes de WordPress.org
**Fecha:** 15-10-2026 · **Vence:** antes de mandarlo a WordPress.org

Los tipos ganaron prefijo en la 1.2.0 (`eweb_client`…; rama prefijo-tipos).
Quedan tres METAS de antes de la 1.0.0 sin prefijo: `client_url`,
`testimonial_role` y `testimonial_client` (las demás son
`estrategia_web_…`). Mismo problema que tenían los tipos: otro plugin puede
usar `client_url`. Arreglo: un paso más en `Prefix_Migration` (metas de los
tipos `eweb_*`), `wpml-config.xml`, `Meta_Panel::WPML_*`, los bindings
`core/post-meta` del contenido y las pruebas. No se hizo con los tipos
porque el encargo del 15-10-2026 decía «metas no cambian». Lo decide David.

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
