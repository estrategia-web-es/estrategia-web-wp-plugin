# Pendientes — armazon-wp-sitio

Un solo sitio (Armazón, fila 25). Lo terminado **sale** de aquí: al historial
de git, con el commit que lo cierra.

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
