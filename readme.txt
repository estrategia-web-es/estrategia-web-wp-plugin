=== EstrategiaWeb Toolkit ===
Contributors: estrategiaweb
Tags: cookie consent, contact form, redirects, schema, gdpr
Requires at least: 7.1
Tested up to: 7.2
Requires PHP: 8.1
Stable tag: 2.18.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Site essentials as modules you switch on and off: cookie consent, block forms, content types, redirects, schema.org, maps, WhatsApp and more.

== Description ==

Most small business sites need the same dozen things besides their design. EstrategiaWeb Toolkit puts them in one plugin, each as a module you can switch on or off in **Estrategia Web → Modules**. A module that is off loads no code at all.

* **Cookie consent**: your own notice (Accept / Reject / Settings) with categories, a "Cookie settings" link block, a cookie table block for the cookie policy and a consent API for PHP and JavaScript.
* **Forms**: block-based forms with several email notifications from templates, a log of every submission, CSV export and spam protection without third-party services (honeypot, minimum time, rate limit).
* **Content types**: build your own post types, taxonomies and fields from the panel, or start from a template (clients, testimonials, locations, projects, portfolio), with Block Bindings.
* **Company details**: write your company name, address or phone once and use them anywhere with Block Bindings or a shortcode.
* **Local business schema**: schema.org Organization and LocalBusiness data, merged into Rank Math's or Yoast's graph when they are active.
* **Redirects**: 301, 302 and 410 redirects with hit counters, CSV import and export and an optional 404 log. It steps aside when your SEO plugin manages redirects.
* **llms.txt**: the text AI assistants read to describe your site, written in the panel (with a draft from your company details and pages) and served at /llms.txt, one per language with WPML.
* **Blocks**: carousel, two-click map, WhatsApp button, social links and a language switcher for WPML.
* **Analytics behind consent**: Google Analytics 4, Google Ads and Google Tag Manager with Consent Mode v2, and Matomo without cookies. Nothing loads until you fill in an ID.
* **Media and performance**: WebP image sizes, phone photo uploads scaled down to 2560 px, sanitised SVG uploads, correctly sized featured images, and the cached page of a post emptied when its fields change outside the editor (SiteGround, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache).
* **Newsletter**: choose the week's posts, preview the email and copy its HTML, or create the campaign as a draft in Mailchimp (never sent from WordPress).
* **Small things**: external links in a new tab with an accessible label, and turning comments off everywhere.

Translations: Spanish, Catalan and German (informal and formal) included. WPML is supported throughout.

= Source code =

Everything is in the plugin as it runs, unminified sources included. The minified `view.min.js` files are built from the `view.js` next to them with esbuild. Development, tests and build tools: https://github.com/estrategia-web-es/estrategia-web-wp-plugin

== External services ==

The plugin does not contact any service on its own. The following ones are only used when you switch the module on AND fill in its settings, and only from the visitor's browser, except Mailchimp, which the server calls when an editor asks for it.

= Mailchimp (Newsletter module) =

Off by default. Only when you save a Mailchimp API key and an editor clicks "Create the draft in Mailchimp", the server calls the Mailchimp Marketing API (<datacenter>.api.mailchimp.com) to read the last campaign sent (its audience, segment and sender) and to create a new draft campaign with the posts chosen (their titles, excerpts, links and image URLs). Nothing about visitors is sent, and no campaign is ever sent from WordPress.
Terms: https://mailchimp.com/legal/terms/ · Privacy: https://www.intuit.com/privacy/statement/

= Google Maps (Map block, Locations) =

The Map block shows a static image from your media library. Only when the visitor clicks "Show map", or has accepted the maps category in the cookie notice, the browser loads the Google Maps embed (google.com/maps/embed) with the address you entered. Google receives the visitor's IP address and browser data.
Terms: https://policies.google.com/terms and https://maps.google.com/help/terms_maps/ · Privacy: https://policies.google.com/privacy

= Google Analytics 4, Google Ads and Google Tag Manager (Google tags module) =

Off by default. When you enter a measurement ID, the browser loads Google's script from googletagmanager.com. In the default mode it loads only after the visitor accepts the statistics or marketing category; in the "advanced" mode, which you must choose, it loads with consent denied and Google receives cookieless pings. Google receives the visitor's IP address, the pages visited and, if you set a conversion label, that a form was sent (never its content).
Terms: https://policies.google.com/terms and https://marketingplatform.google.com/about/analytics/terms/us/· Privacy: https://policies.google.com/privacy

= Matomo (Matomo module) =

Only when you enter the URL of your own Matomo server: the browser loads matomo.js from that server and sends it the pages visited. It works without cookies by default. The data goes to the server you configure, not to us.
Matomo's privacy information: https://matomo.org/privacy-policy/

= WhatsApp (WhatsApp button) =

The button is a plain link to wa.me with the number and message you set. Nothing is sent until the visitor clicks it.
Terms: https://www.whatsapp.com/legal/terms-of-service · Privacy: https://www.whatsapp.com/legal/privacy-policy

== Frequently Asked Questions ==

= What can the code of a code banner contain? =

What ad networks paste: scripts (from a file or inline), <ins>, iframes and linked images. Only a user with the unfiltered_html capability can write it, and it is filtered when it is shown. Inline JavaScript that contains & or < does not survive the filter; the banner's screen says so, and the fix is to load that script from a file.

= Does it need a particular theme? =

No. The Estrategia Web theme uses its blocks, but the plugin does not depend on it.

= What happens to my content if I switch a module off? =

Nothing is deleted. The module stops loading, and switching it on again restores it as it was.

= Does deleting the plugin delete its data? =

Its settings, yes. Your data (form submissions, redirects, company details) is kept unless you ask for it in wp-config.php before deleting, with `define( 'ESTRATEGIA_WEB_FORMS_REMOVE_DATA', true );`, `define( 'ESTRATEGIA_WEB_REDIRECTS_REMOVE_DATA', true );` or `define( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA', true );`.

== Credits ==

The cookie list includes an extract of the Open Cookie Database (https://github.com/jkwakman/Open-Cookie-Database), © Jan Kwakman and contributors, Apache License 2.0. Details in modules/consent/data/NOTICE.

== Changelog ==

= 2.18.0 =
* Security headers module: it also removes WPML's version from the public HTML (the generator meta tag).

= 2.17.0 =
* New «Security headers» module, off by default: adds the browser security headers a site is missing (HSTS over https, framing only from the site itself, a referrer policy, no camera, microphone or location) and hides the PHP version, without repeating any header another plugin already sends.

= 2.16.2 =
* Compatible with WordPress 7.2. Its new HTML filter broke the attributes of several blocks (the language switcher stopped opening, and the carousel, map, social links, WhatsApp button, banner zone and form fields could lose values with quotes); they now print correctly on 7.1 and 7.2.
* Forms: the help text link of a field (aria-describedby) and autocomplete on dropdowns and text areas are kept.

= 2.16.1 =
* Local business: on the front page, a location without a page of its own now links to the page that talks about it (or to the home page), not to an address that does not exist.

= 2.16.0 =
* Cookie notice: on phones it is compact (smaller text, the three buttons in one row) and no longer covers half the screen.
* Cookie notice: a theme with a fixed bar at the bottom can lift the notice above it with the --estrategia-web-consent-bottom CSS variable.

= 2.15.0 =
* Content types: a field can be marked «Private». Only people who can edit the post see it; it is left out of what visitors get through the REST API and is never shown on the site. Forms can still use it in their notices, for example to send a copy to a family's email.
* Content types: a text field can suggest, as you type, what it already says in other posts (a place, a relationship), and anything else can still be typed.
* External links: links to documents (PDF, Word, Excel, ZIP…), also the site's own, open in a new tab; a link marked «download» still just saves the file.

= 2.14.0 =
* Content types: a «Repeating list» field type (rows with their own text, date, time, number, choice, link, email or phone fields), shown as a list, and a «Time» field type.
* Forms: a form inside a post can use that post's title, link and fields in its notices ({{post_title}}, {{post_url}}, {{post_field:key}}), and notices have Cc and Bcc.
* Content types: text saved from the fields box under the editor keeps its backslashes.
* Content types: a list bound to a paragraph no longer leaves two empty paragraphs around it.

= 2.13.0 =
* New «Cache purge on field changes» module, on by default: when a published post's fields are changed outside the editor (WP-CLI, a script, a scheduled task), that post's cached page is emptied in SiteGround, WP Rocket, LiteSpeed, W3 Total Cache or WP Super Cache, whichever is installed. Only that page, once per request, and never because of a visitor.

= 2.12.1 =
* Every request value is sanitized as soon as it is read and everything printed is escaped where it is printed, also in the File field and dossier mode.

= 2.12.0 =
* Forms: every field can have a help text, shown in small print under it and read with it by screen readers. The placeholder stays for an example.
* Forms: the «Send me the link» box of a dossier form is styled like the form above it.

= 2.11.0 =
* Forms: a File field (PDF, JPG, PNG, checked by their content). Files are stored outside the web root and only the site team can download them.
* Forms: dossier mode, per form. The sender can save half-way, gets a private link by email to come back and complete it, and can ask for a new link. The team sees what is missing, can ask the client for more and can revoke the link.
* Forms: the retention counts a dossier from its last change, and deleting a submission deletes its files.

= 2.10.4 =
* Every request value is sanitized as soon as it is read, with no exceptions.
* The code banner screen notes that the code is filtered when it is saved and when it is shown.

= 2.10.3 =
* The code of a code banner is also filtered when it is saved.
* Form input is read and sanitized one field at a time.

= 2.10.2 =
* The code of a code banner is filtered when it is shown, and the banner's screen lists anything the filter would remove.
* The banner list's styles are added through WordPress's style functions.

= 2.10.1 =
* Everything the plugin prints is escaped at the point of output, including block markup, form controls, icons and admin screens.
* The plugin's Author URI now points to its GitHub organisation.

= 2.10.0 =
* Renamed to EstrategiaWeb Toolkit (text domain estrategiaweb-toolkit). Settings, content and blocks are unchanged.
* New module: llms.txt, written in Settings and served at /llms.txt with the right charset; one per language with WPML. Off by default.
* German translation reviewed by a native speaker, and a formal German (de_DE_formal) for sites whose visitors expect «Sie».
* The admin menu moves below Settings.
* Code review fixes: styles and structured data printed through WordPress functions, sanitised request input, translatable module names.

= 2.9.0 =
* Map block: new latitude and longitude attributes (bindable). With no embed URL, the map is built from them, still in two clicks, so locations stored as coordinates get a map without pasting one embed each.

= 2.8.0 =
* Content types: each type's list of entries has a dropdown per taxonomy, and several dropdowns add up.
* Form submissions: views by notification state with their count, and sorting by date and by form.
* Redirects: views by type (301, 302, 410) and inactive with their count; sorting by last hit puts the rules that never fired last; the 404 log sorts by first seen.

= 2.7.0 =
* New Newsletter module (off by default): choose the week's posts, preview the email and copy its code, or create the campaign as a draft in Mailchimp. It never sends.

= 2.6.0 =
* Banners: the state (showing, scheduled, expired, off) is stored and kept current when a banner is saved, when its dates arrive and once a day.
* Banners list: sort by state, views and clicks.

= 2.5.0 =
* Banners list: views by state with their count (showing, scheduled, expired), the zone dropdown, sorting by dates and the state as a coloured label.

= 2.4.0 =
* German translation (de_DE).
* Cookie table: the texts the administrator writes are registered in WPML in the site's default language, not as English.

= 2.3.0 =
* Forms: once sent, the fields are hidden and the success message is a box that cannot be missed, focused and scrolled into view.

= 2.2.0 =
* New module: Banners, by zone and dates, with view and click statistics and a migration from AdRotate. Off by default.
* New module: FAQ structured data, one schema.org FAQPage from the page's FAQ accordions. Off by default.

= 2.0.1 =
* English placeholders in form templates, with their migration.
* Same look across all admin screens; notices link to where they are fixed.
* Redirects step aside when Rank Math's Redirections module or Yoast SEO Premium manage them.
