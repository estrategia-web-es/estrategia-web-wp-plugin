=== Estrategia Web ===
Contributors: estrategiaweb
Tags: cookie consent, contact form, redirects, schema, gdpr
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Site essentials as modules you switch on and off: cookie consent, block forms, content types, redirects, schema.org, maps, WhatsApp and more.

== Description ==

Most small business sites need the same dozen things besides their design. Estrategia Web puts them in one plugin, each as a module you can switch on or off in **Estrategia Web → Modules**. A module that is off loads no code at all.

* **Cookie consent**: your own notice (Accept / Reject / Settings) with categories, a "Cookie settings" link block, a cookie table block for the cookie policy and a consent API for PHP and JavaScript.
* **Forms**: block-based forms with several email notifications from templates, a log of every submission, CSV export and spam protection without third-party services (honeypot, minimum time, rate limit).
* **Content types**: build your own post types, taxonomies and fields from the panel, or start from a template (clients, testimonials, locations, projects, portfolio), with Block Bindings.
* **Company details**: write your company name, address or phone once and use them anywhere with Block Bindings or a shortcode.
* **Local business schema**: schema.org Organization and LocalBusiness data, merged into Rank Math's or Yoast's graph when they are active.
* **Redirects**: 301, 302 and 410 redirects with hit counters, CSV import and export and an optional 404 log. It steps aside when your SEO plugin manages redirects.
* **Blocks**: carousel, two-click map, WhatsApp button, social links and a language switcher for WPML.
* **Analytics behind consent**: Google Analytics 4, Google Ads and Google Tag Manager with Consent Mode v2, and Matomo without cookies. Nothing loads until you fill in an ID.
* **Media**: WebP image sizes, phone photo uploads scaled down to 2560 px, sanitised SVG uploads and correctly sized featured images.
* **Small things**: external links in a new tab with an accessible label, and turning comments off everywhere.

Translations: Spanish and Catalan included. WPML is supported throughout.

= Source code =

Everything is in the plugin as it runs, unminified sources included. The minified `view.min.js` files are built from the `view.js` next to them with esbuild. Development, tests and build tools: https://github.com/estrategia-web-es/estrategia-web-wp-plugin

== External services ==

The plugin does not contact any service on its own. The following ones are only used when you switch the module on AND fill in its settings, and only from the visitor's browser.

= Google Maps (Map block, Locations) =

The Map block shows a static image from your media library. Only when the visitor clicks "Show map", or has accepted the maps category in the cookie notice, the browser loads the Google Maps embed (google.com/maps/embed) with the address you entered. Google receives the visitor's IP address and browser data.
Terms: https://policies.google.com/terms and https://maps.google.com/help/terms_maps/ · Privacy: https://policies.google.com/privacy

= Google Analytics 4, Google Ads and Google Tag Manager (Google tags module) =

Off by default. When you enter a measurement ID, the browser loads Google's script from googletagmanager.com. In the default mode it loads only after the visitor accepts the statistics or marketing category; in the "advanced" mode, which you must choose, it loads with consent denied and Google receives cookieless pings. Google receives the visitor's IP address, the pages visited and, if you set a conversion label, that a form was sent (never its content).
Terms: https://policies.google.com/terms and https://marketingplatform.google.com/about/analytics/terms/ · Privacy: https://policies.google.com/privacy

= Matomo (Matomo module) =

Only when you enter the URL of your own Matomo server: the browser loads matomo.js from that server and sends it the pages visited. It works without cookies by default. The data goes to the server you configure, not to us.
Matomo's privacy information: https://matomo.org/privacy-policy/

= WhatsApp (WhatsApp button) =

The button is a plain link to wa.me with the number and message you set. Nothing is sent until the visitor clicks it.
Terms: https://www.whatsapp.com/legal/terms-of-service · Privacy: https://www.whatsapp.com/legal/privacy-policy

== Frequently Asked Questions ==

= Does it need a particular theme? =

No. The Estrategia Web theme uses its blocks, but the plugin does not depend on it.

= What happens to my content if I switch a module off? =

Nothing is deleted. The module stops loading, and switching it on again restores it as it was.

= Does deleting the plugin delete its data? =

Its settings, yes. Your data (form submissions, redirects, company details) is kept unless you ask for it in wp-config.php before deleting, with `define( 'ESTRATEGIA_WEB_FORMS_REMOVE_DATA', true );`, `define( 'ESTRATEGIA_WEB_REDIRECTS_REMOVE_DATA', true );` or `define( 'ESTRATEGIA_WEB_SITE_REMOVE_DATA', true );`.

== Credits ==

The cookie list includes an extract of the Open Cookie Database (https://github.com/jkwakman/Open-Cookie-Database), © Jan Kwakman and contributors, Apache License 2.0. Details in modules/consent/data/NOTICE.

== Changelog ==

= 2.2.0 =
* New module: Banners, by zone and dates, with view and click statistics and a migration from AdRotate. Off by default.
* New module: FAQ structured data, one schema.org FAQPage from the page's FAQ accordions. Off by default.

= 2.0.1 =
* English placeholders in form templates, with their migration.
* Same look across all admin screens; notices link to where they are fixed.
* Redirects step aside when Rank Math's Redirections module or Yoast SEO Premium manage them.
