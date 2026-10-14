# Cambios

Una entrada por versión, la más nueva arriba. Se escribe en el mismo commit
que sube el número (README, «Versiones»). Si una versión obliga a tocar algo
en las webs, la entrada lo dice primero, con qué cambiar.

## 1.0.0 — 07-10-2026

**MAYOR: cambio de nombre. Obliga a tocar las webs:** pasar el migrador
(plugin Estrategia Web Site 1.0.0) y cambiar en el tema hijo las clases y
propiedades `armazon-forms…` por `estrategia-web-forms…`.

- **«Armazón Forms» pasa a «Estrategia Web Forms»**: carpeta y fichero
  `estrategia-web-forms/estrategia-web-forms.php`, text domain, namespace
  `EstrategiaWeb\Forms`, funciones, constantes, filtros, opciones, tablas,
  bloques, ruta REST, contexto de WPML, handles y clases (tabla en el README,
  «Nombres»). Autor, Plugin URI y Author URI: Estrategia Web,
  https://estrategiaweb.es/.
- **Alias** de los nueve bloques `armazon-forms/…` hasta la 2.0.0
  (`includes/class-legacy-names.php`). La ruta REST vieja no tiene alias
  (solo la llamaba el JS del propio bloque).
- Las tablas, las opciones y las cadenas las pasa el migrador de Estrategia
  Web Site: los envíos se conservan (RENAME TABLE).
- README: «Si cambias de proveedor».

## 0.2.2 — 06-10-2026

No obliga a tocar ninguna web: correo y teléfono siguen llevando `email` y
`tel`. Para que un campo de texto lo lleve, hay que ponérselo.

- **Atributo `autocomplete`** en los campos de texto, correo, teléfono,
  área de texto y desplegable (WCAG 1.3.5). Se pinta tal cual; vacío, el
  correo lleva `email` y el teléfono `tel`, como antes. Un texto sin él no
  lo lleva: no se adivina por el nombre del campo. Valores: los de la
  especificación HTML (`name`, `organization`, `given-name`, `work tel`,
  `off`…); lo que no sean palabras en minúscula, cifras y guiones se
  descarta. Control nuevo en el editor («Autocompletar»).
- De la auditoría de Lavandería (06-10-2026): en Contacto, nombre y empresa
  no lo llevaban. Allí: `"autocomplete":"name"` y
  `"autocomplete":"organization"` en sus bloques.
- Prueba: `tests/autocomplete.test.mjs` (nivel 2), vista fallar antes.

## 0.2.1 — 06-10-2026

No obliga a tocar ninguna web: `{texto}` enlaza a la privacidad como antes.

- Consentimiento: **varios enlaces en la misma frase** con `{texto|url}`
  («la {protección de datos|/proteccion/} y {Sus datos seguros|/sus-datos/}»).
  Antes solo se enlazaba la primera llave, y a la página de privacidad del
  sitio. Una url que esc_url() deja vacía (javascript:…) queda como texto.
  Prueba: tests/consent-links.test.mjs (nivel 2).

## 0.2.0 — 03-10-2026

No obliga a tocar ninguna web: sin `defaultOption` el desplegable sale como
antes.

- Desplegable: **opción preseleccionada** según la página. Atributo
  `defaultOption` (posición, 1 = la primera, 0 = ninguna) con su control en
  el panel; en un bloque sincronizado, por página con
  `"content":{"<campo>":{"defaultOption":N}}` en el `core/block`; y desde un
  enlace con `?af_<campo>=` (posición o slug de la opción). Solo se marca
  una opción que existe. Al reordenar las opciones en el editor, la
  preselección sigue a su texto.
- WPML: `defaultOption` no se traduce; vale en todos los idiomas porque es
  una posición.
- La flecha del desplegable sigue siendo la nativa: decidido y documentado
  que el chevron propio es del tema (README, «Cómo lo usa un tema hijo»).
- Traducción es_ES de los tres textos nuevos del editor.

## 0.1.0 — 01-10-2026

Primera versión.

- Bloques: formulario, texto, correo, teléfono, mensaje, desplegable, casilla
  (con consentimiento RGPD), botón y aviso por correo.
- Varios avisos por formulario con plantilla HTML + texto y marcadores
  `{{campo}}`, `{{todos_los_campos}}`, `{{fecha}}`, `{{url_pagina}}`,
  `{{nombre_sitio}}`.
- Registro de envíos (tabla propia, migración 0001), pantalla de envíos con
  filtro, ficha, CSV y borrado con confirmación; retención con purga diaria.
- Endpoint REST con token firmado, honeypot, tiempo mínimo y límite por IP.
  Con y sin JavaScript (Interactivity API).
- WPML: `wpml-config.xml` y cadenas de ajustes; idioma del envío registrado.
- Traducción es_ES (`.po`, `.l10n.php` y JSON del editor).
