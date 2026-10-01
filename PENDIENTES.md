# Pendientes — armazon-wp-sitio

Un solo sitio (Armazón, fila 25). Lo terminado **sale** de aquí: al historial
de git, con el commit que lo cierra.

---

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
