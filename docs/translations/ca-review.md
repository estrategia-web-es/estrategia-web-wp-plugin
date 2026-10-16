# Catalan translation (ca): review before publishing

> **Decided by David on 16-10-2026: every recommendation in this file is accepted** («Adelante con todas tus recomendaciones»): seus, portafolis, opinions, galetes, Accepta-les totes / Rebutja-les totes, Desa la selecció, Configura, He llegit i accepto, privadesa, Escriu-nos per WhatsApp. The translation already uses them.

The plugin's Catalan translation (`languages/estrategia-web-ca.po`, 727 strings) and the parent theme's (`armazon-wp-padre/languages/ca.po`, 27 strings) were written on 16-10-2026, before the first Catalan site (Gastronòmicament, Catalan by default with es/en/de) goes live. **Nothing here is published yet.** This file is for deciding the doubtful forms first: a URL slug, once a site is live, cannot change without changing its addresses.

How to maintain this file: it is a one-off review, not a living document. Once David has decided the doubts below, apply the answers to `languages/estrategia-web-ca.po` (and the theme's `ca.po` if it applies), run `node tools/i18n.mjs`, update `tests/catalan.test.mjs` if a visitor text or a slug changed, and delete this file or mark it as decided at the top. A later wording change goes straight to the .po: this file is not kept in sync.

## Criteria used

- **Buttons and commands in the imperative, second person singular** (Softcatalà style guide, TERMCAT): «Desa», «Accepta-les totes», «Mostra el mapa», «Envia», never the infinitive. The same for checkbox labels («Mostra les fletxes»).
- **The program speaks to the user as *tu***, never *vós* («Has de marcar aquesta casella», «Escriu-nos per WhatsApp»). David's decision of 11-09-2026, applied to the whole plugin.
- **Standard Catalan** (it is software), with the terminology of Softcatalà and of WordPress in Catalan: *galetes* (cookies), *paràmetres* (settings), *extensió* (plugin), *suprimeix* (delete), *codi curt* (shortcode), *tauler* (dashboard), *mediateca* (media library), *icona*, *privadesa*.
- Quotation marks «» as in the Spanish translation. Technical values (JSON keys, `cover`/`contain`, `{{markers}}`, `%s`) untouched; `tests/translations.test.mjs` checks the placeholders.
- **URL slugs**: lowercase ASCII, hyphens, no accents or apostrophes, singular/plural as in English.

## Doubts for David (recommendation first)

1. **cookies → «galetes» or «cookies»?** Recommended: **galetes** (TERMCAT, WordPress in Catalan, the Govern balear's sites). Alternative: «cookies», more frequent on commercial sites. It appears in ~60 strings, the cookie notice among them, so decide it once for all.
2. **«Accept all» / «Reject all» → «Accepta-les totes» / «Rebutja-les totes»** or «Accepta-ho tot» / «Rebutja-ho tot»? Recommended: **Accepta-les totes / Rebutja-les totes** (they refer to the cookies, like the Spanish «Aceptar todas»). The second pair is shorter if the button is tight on mobile.
3. **«Save choices» → «Desa la selecció»**, «Desa les preferències» or «Desa l'elecció»? Recommended: **Desa la selecció** (the Spanish says «Guardar la selección»).
4. **«Configure» (the notice button that opens the categories) → «Configura»** or «Personalitza»? Recommended: **Configura**; «Personalitza» is clearer but longer.
5. **Privacy consent: «He llegit i accepto la política de privadesa»** (standard) or «He llegit i accept…» (Balearic)? Recommended: **accepto**, because the brief is standard Catalan for software. If Gastronòmicament wants a Mallorcan voice it is this sentence where it would show.
6. **privacy → «privadesa» or «privacitat»?** Recommended: **privadesa** (Softcatalà, WordPress in Catalan). «Privacitat» is also accepted and common. It is in the form consent and in the cookie notice link.
7. **Locations: «Seus», slug `seus`**, or «Locals», slug `locals`? Recommended: **seus** (the same meaning as Lavandería's «sedes»). For restaurants «locals» may read better; if so, decide it now, because it is the URL of every location (`/seus/<name>/`). A site can also fix its own slug with the `rewrite_slugs` option without changing the translation.
8. **Portfolio: «Portafolis», slug `portafolis`**, or «Portfoli», slug `portfoli`? Recommended: **portafolis** (TERMCAT's term; it is invariable: *el portafolis*). «Portfoli» is the anglicism people write, and it is not in the DIEC.
9. **Testimonials: «Opinions», slug `opinions`**, or «Testimonis», slug `testimonis`? Recommended: **opinions** (like the Spanish «opiniones»; «testimoni» is more a witness than a review).
10. **«Chat on WhatsApp» → «Escriu-nos per WhatsApp»** or «Xateja per WhatsApp»? Recommended: **Escriu-nos per WhatsApp** (like the Spanish «Escríbenos por WhatsApp»). It is the accessible name of the floating button.

All the URL slugs: clients → `clients`, client-sector → `sector`, testimonials → `opinions`, locations → `seus`, projects → `projectes`, project-category → `categoria-projecte`, portfolio → `portafolis`, portfolio-category → `categoria-portafolis`.

## What the visitor reads, side by side

Generated from the .po files on 16-10-2026 (EN = msgid; ES and CA = the translations). The admin texts are not here: they follow the same criteria and are in the .po.


### Cookie notice

| English | Castellano | Català |
|---|---|---|
| We use our own and third-party cookies for the purposes listed below. You can accept them, reject them or choose which ones to allow. Necessary cookies are always active. | Usamos cookies propias y de terceros para los fines que se indican. Puedes aceptarlas, rechazarlas o elegir cuáles permites. Las cookies necesarias están siempre activas. | Utilitzem galetes pròpies i de tercers per a les finalitats que s'indiquen. Les pots acceptar, rebutjar o triar quines permets. Les galetes necessàries sempre estan actives. |
| Privacy and cookie policy | Política de privacidad y cookies | Política de privadesa i galetes |
| Cookie categories | Categorías de cookies | Categories de galetes |
| Necessary | Necesarias | Necessàries |
| Required for the site to work, including remembering this choice. Always active. | Imprescindibles para que el sitio funcione, también para recordar esta elección. Siempre activas. | Imprescindibles perquè el lloc funcioni, també per recordar aquesta elecció. Sempre actives. |
| Analytics | Analítica | Analítica |
| Help us understand how the site is used. | Nos ayudan a entender cómo se usa el sitio. | Ens ajuden a entendre com s'utilitza el lloc. |
| Maps and third-party content | Mapas y contenido de terceros | Mapes i contingut de tercers |
| Load embedded maps and other content served by third parties, which may set their own cookies. | Cargan mapas y otro contenido servido por terceros, que pueden instalar sus propias cookies. | Carreguen mapes i altre contingut servit per tercers, que poden instal·lar les seves pròpies galetes. |
| Advertising | Publicidad | Publicitat |
| Measure campaigns and show relevant advertising. | Miden campañas y muestran publicidad relevante. | Mesuren campanyes i mostren publicitat rellevant. |
| Preferences | Preferencias | Preferències |
| Remember choices that change how the site behaves or looks, such as the region or recently viewed items. | Recuerdan elecciones que cambian cómo funciona o se ve la web, como la región o lo último que has visto. | Recorden eleccions que canvien com funciona o com es veu el web, com ara la regió o el que has vist darrerament. |
| Accept all | Aceptar todas | Accepta-les totes |
| Reject all | Rechazar todas | Rebutja-les totes |
| Configure | Configurar | Configura |
| Save choices | Guardar la selección | Desa la selecció |
| Cookie settings | Configurar cookies | Configura les galetes |
| Powered by %s | Powered by %s | Powered by %s |

### Cookie table (policy page)

| English | Castellano | Català |
|---|---|---|
| Cookie | Cookie | Galeta |
| Category | Categoría | Categoria |
| Provider | Proveedor | Proveïdor |
| Purpose | Finalidad | Finalitat |
| Duration | Duración | Durada |
| This website | Este sitio web | Aquest lloc web |
| Remembers your choice in the cookie notice. | Recuerda tu elección en el aviso de cookies. | Recorda la teva elecció a l'avís de galetes. |
| Remembers the language in which you are browsing the site. | Recuerda el idioma en el que navegas por la web. | Recorda l'idioma en què navegues pel web. |
| Checks that the browser accepts cookies. Only on the login page. | Comprueba que el navegador acepta cookies. Solo en la página de acceso. | Comprova que el navegador accepta galetes. Només a la pàgina d'accés. |
| Keeps the session of the site’s users (editors and administrators). | Mantiene la sesión de los usuarios de la web (editores y administradores). | Manté la sessió dels usuaris del web (editors i administradors). |
| Statistics: recognises a returning visitor (an anonymous identifier). Only after accepting analytics. | Estadísticas: reconoce a un visitante que vuelve (con un identificador anónimo). Solo tras aceptar la analítica. | Estadístiques: reconeix un visitant que torna (amb un identificador anònim). Només després d'acceptar l'analítica. |
| Statistics: groups the pages viewed in the same visit. | Estadísticas: agrupa las páginas vistas en una misma visita. | Estadístiques: agrupa les pàgines vistes en una mateixa visita. |
| Statistics: remembers the website the visitor came from. | Estadísticas: recuerda desde qué web llegó el visitante. | Estadístiques: recorda des de quin web va arribar el visitant. |
| Statistics: remembers that analytics cookies were accepted. | Estadísticas: recuerda que se aceptaron las cookies de analítica. | Estadístiques: recorda que es van acceptar les galetes d'analítica. |
| Remembers your name and email for the next comment, only if you ask for it. | Recuerda tu nombre y tu correo para el próximo comentario, solo si lo pides. | Recorda el teu nom i el teu correu per al pròxim comentari, només si ho demanes. |
| Session | Sesión | Sessió |
| %d second / %d seconds | %d segundo / %d segundos | %d segon / %d segons |
| %d day / %d days | %d día / %d días | %d dia / %d dies |
| %d month / %d months | %d mes / %d meses | %d mes / %d mesos |
| %d year / %d years | %d año / %d años | %d any / %d anys |

### Two-click map

| English | Castellano | Català |
|---|---|---|
| Show map | Ver mapa | Mostra el mapa |
| The map is served by Google. Loading it sends your IP address to Google, which may set its own cookies. | El mapa lo sirve Google. Al cargarlo, tu dirección IP se envía a Google, que puede instalar sus propias cookies. | El mapa el serveix Google. Quan es carrega, la teva adreça IP s'envia a Google, que pot instal·lar les seves pròpies galetes. |
| Open in Google Maps | Abrir en Google Maps | Obre a Google Maps |
| Open in Google Maps: %1$s %2$s | Abrir en Google Maps: %1$s %2$s | Obre a Google Maps: %1$s %2$s |
| Map: %s | Mapa: %s | Mapa: %s |
| Street map around %s | Plano de las calles alrededor de %s | Plànol dels carrers al voltant de %s |
| Street map of the area | Plano de las calles de la zona | Plànol dels carrers de la zona |
| Map | Mapa | Mapa |

### Forms

| English | Castellano | Català |
|---|---|---|
| Send | Enviar | Envia |
| Choose an option | Elige una opción | Tria una opció |
| I have read and accept the {privacy policy}. | He leído y acepto la {política de privacidad}. | He llegit i accepto la {política de privadesa}. |
| Leave this field empty | Deja este campo vacío | Deixa aquest camp buit |
| Thank you. Your message has been sent. | Gracias. Tu mensaje se ha enviado. | Gràcies. El teu missatge s'ha enviat. |
| The message could not be sent. Please check the form and try again. | No se ha podido enviar el mensaje. Revisa el formulario y vuelve a intentarlo. | No s'ha pogut enviar el missatge. Revisa el formulari i torna-ho a provar. |
| The message could not be sent. Please check your connection and try again. | No se ha podido enviar el mensaje. Comprueba tu conexión y vuelve a intentarlo. | No s'ha pogut enviar el missatge. Comprova la connexió i torna-ho a provar. |
| You must check this box to continue. | Tienes que marcar esta casilla para continuar. | Has de marcar aquesta casella per continuar. |
| This field is required. | Este campo es obligatorio. | Aquest camp és obligatori. |
| This text is too long. | Este texto es demasiado largo. | Aquest text és massa llarg. |
| Please enter a valid email address. | Escribe una dirección de correo válida. | Escriu una adreça de correu vàlida. |
| Please enter a valid phone number. | Escribe un número de teléfono válido. | Escriu un número de telèfon vàlid. |
| Please choose one of the options. | Elige una de las opciones. | Tria una de les opcions. |
| This form is no longer available. Please reload the page. | Este formulario ya no está disponible. Recarga la página. | Aquest formulari ja no està disponible. Torna a carregar la pàgina. |
| The form was sent too quickly. Please wait a few seconds and try again. | El formulario se ha enviado demasiado rápido. Espera unos segundos y vuelve a intentarlo. | El formulari s'ha enviat massa de pressa. Espera uns segons i torna-ho a provar. |
| The form has expired. Please reload the page and try again. | El formulario ha caducado. Recarga la página y vuelve a intentarlo. | El formulari ha caducat. Torna a carregar la pàgina i torna-ho a provar. |
| Too many messages from your connection. Please try again later. | Demasiados mensajes desde tu conexión. Vuelve a intentarlo más tarde. | Massa missatges des de la teva connexió. Torna-ho a provar més tard. |
| We have received your message | Hemos recibido tu mensaje | Hem rebut el teu missatge |
| Hello {{name}}, ↵  ↵ Thank you for writing to us. We have received your message and will reply soon. ↵  ↵ {{all_fields}} ↵  ↵ {{site_name}} | Hola, {{name}}: ↵  ↵ Gracias por escribirnos. Hemos recibido tu mensaje y te responderemos pronto. ↵  ↵ {{all_fields}} ↵  ↵ {{site_name}} | Hola, {{name}}: ↵  ↵ Gràcies per escriure'ns. Hem rebut el teu missatge i et respondrem aviat. ↵  ↵ {{all_fields}} ↵  ↵ {{site_name}} |

### WhatsApp, carousel, language switcher, links

| English | Castellano | Català |
|---|---|---|
| Chat on WhatsApp | Escríbenos por WhatsApp | Escriu-nos per WhatsApp |
| Carousel | Carrusel | Carrusel |
| Previous slide | Diapositiva anterior | Diapositiva anterior |
| Next slide | Diapositiva siguiente | Diapositiva següent |
| Choose slide | Elegir diapositiva | Tria la diapositiva |
| Go to slide %d | Ir a la diapositiva %d | Vés a la diapositiva %d |
| Pause carousel | Pausar el carrusel | Pausa el carrusel |
| Play carousel | Reanudar el carrusel | Reprèn el carrusel |
| Language: | Idioma: | Idioma: |
| (opens in a new tab) | (se abre en una pestaña nueva) | (s'obre en una pestanya nova) |
| %1$s of %2$s | %1$s de %2$s | %1$s de %2$s |
| Comments are closed. | Los comentarios están cerrados. | Els comentaris estan tancats. |

### URL slugs (become part of the address)

| English | Castellano | Català |
|---|---|---|
| clients | clientes | clients |
| client-sector | sector | sector |
| testimonials | opiniones | opinions |
| locations | sedes | seus |
| projects | proyectos | projectes |
| project-category | categoria-proyecto | categoria-projecte |
| portfolio | portfolio | portafolis |
| portfolio-category | categoria-portfolio | categoria-portafolis |

### Parent theme (armazon-wp-padre, `languages/ca.po`)

| English | Castellano | Català |
|---|---|---|
| Page not found | Página no encontrada | No s'ha trobat la pàgina |
| The page you are looking for does not exist or has moved. Try searching for it. | La página que buscas no existe o ha cambiado de sitio. Prueba a buscarla. | La pàgina que cerques no existeix o ha canviat de lloc. Prova de cercar-la. |
| Nothing was found here. Try a different search. | No se ha encontrado nada. Prueba con otra búsqueda. | No s'ha trobat res. Prova una altra cerca. |
| No results | Sin resultados | Sense resultats |
