# Pendientes — armazon-wp-sitio

Un solo sitio (Armazón, fila 25). Lo terminado **sale** de aquí: al historial
de git, con el commit que lo cierra.

---

## Hoja de ruta Estrategia Web — lo que tienen que llevar TODAS las webs (12-10-2026)

Lista de David, para que las webs nuevas y las ya hechas lleven lo mismo. Va
aquí porque casi todo acaba en este plugin. **Cómo se mantiene**: cuando algo
se termina Y se publica en las webs, sale de esta lista con el commit que lo
cierra; si se termina pero no se publica, pasa a «Hecho y sin desplegar».

**Decisión pendiente antes de nada** (David, 07-10-2026, «no lo abordamos
todavía»): ¿un solo tema (solo diseño) + un solo plugin «Estrategia Web» con
módulos activables, o como está (tema + plugin de sitio + plugin de
formularios)? Lo de abajo se hace en la estructura que se elija; por eso nada
se publica hasta decidirlo.

### Hecho y sin desplegar (en local, commiteado)

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
- **Bloques fuera del tema** (carrusel, mapa, WhatsApp, selector de idioma) si
  el tema va a WordPress.org: un tema allí no puede registrar bloques.

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
