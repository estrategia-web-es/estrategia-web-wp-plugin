<?php
/**
 * The core: what is ALWAYS loaded, whichever modules are on.
 * Translations, the module loader, the shared block helpers and the
 * «Estrategia Web → Modules» screen. Nothing specific to a module lives here.
 *
 * Hooks it offers to modules:
 * - estrategia_web_activate / estrategia_web_deactivate: when the WHOLE
 *   plugin is activated or deactivated in «Plugins». They do NOT fire when a
 *   module is turned on or off on the screen (see the trap in class-modules.php).
 * - estrategia_web_modules_loaded( $slugs ): right after the modules are
 *   loaded, before plugins_loaded.
 *
 * The ordering trap: a module is loaded while WordPress loads the plugins,
 * BEFORE the theme. Translations are loaded on init with priority 1 because
 * the labels of post types and blocks come from gettext and are registered
 * on init (10).
 *
 * Languages (1.4.0): the plugin's texts follow the language of the REQUEST
 * (locale(): WPML's on the front end), and its URL slugs follow the language
 * of the SITE (site_locale(), url_slug()). Neither trusts WPLANG to agree
 * with WPML. The why, with the measurements, in load_textdomain() and
 * url_slug(); the test, tests/wplang.test.mjs.
 */

namespace EstrategiaWeb;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Text domain of the plugin (and of the parent theme: see load_textdomain()). */
	public const DOMAIN = 'estrategia-web';

	/** @var array<string,string> Locale of the plugin file loaded under each locale key of the translation controller. */
	private static array $loaded = array();

	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ), 1 );
		add_action( 'change_locale', array( self::class, 'load_textdomain' ) );
		// After WPML String Translation (10), which swaps the loaded domains on a switch.
		add_action( 'wpml_language_has_switched', array( self::class, 'load_textdomain' ), 20, 0 );
		add_filter( 'gettext_with_context_' . self::DOMAIN, array( self::class, 'url_slug' ), 10, 3 );
		Modules::load();
		Blocks::boot();
		if ( is_admin() ) {
			Admin\Modules_Page::boot();
		}
	}

	/**
	 * A single domain, estrategia-web, for the whole plugin. The translations
	 * ship INSIDE the plugin (languages/): it is not on wordpress.org and
	 * nobody else is going to serve them.
	 *
	 * THE TRAP (measured on 14-10-2026, the theme's tests/page-loads.test.mjs
	 * in red: «404 untranslated»): the theme uses the SAME domain, and since
	 * WordPress 6.7 load_plugin_textdomain() and load_theme_textdomain() load
	 * nothing: they only register ONE folder per domain for the «just in time»
	 * loading. The plugin's (init) overwrote the theme's (after_setup_theme)
	 * and the theme's texts came out in English. Hence, here:
	 * 1. load_plugin_textdomain() is NOT called: the registered folder stays
	 *    the theme's.
	 * 2. get_translations_for_domain() forces that just-in-time load (the
	 *    theme's, if there is one).
	 * 3. load_textdomain() loads the plugin's file RIGHT NOW, and WordPress
	 *    6.5+ merges it with what is already loaded (several files per domain).
	 * And it is repeated on change_locale: when the language changes (an email
	 * in the visitor's language, WPML) WordPress unloads the domain and loads
	 * it again only from the registered folder, without the plugin.
	 *
	 * THE SECOND TRAP (measured in Lavandería production on 15-10-2026):
	 * WHICH file is not determine_locale(), it is locale(). With WPLANG empty
	 * and WPML's default language Spanish, determine_locale() was still en_US
	 * on /contacto/ at plugins_loaded, after_setup_theme, init and wp_loaded,
	 * while WPML already knew the language («es», <html lang="es-ES">). The
	 * plugin loaded estrategia-web-en_US (it does not exist) and every plugin
	 * text came out in English on the Spanish site. WPML fixes the locale
	 * later and without change_locale (reproduced locally, removing WPML's
	 * `locale` filter: between init 2 and init 1000 WPML String Translation
	 * moves the locale and the translation controller from en_US to es_ES,
	 * with no hook). Hence:
	 * 4. The file is the one of locale() (WPML's language on the front end).
	 * 5. It is loaded under TWO keys of the translation controller: the
	 *    locale WordPress looks up in NOW (the controller's, right or wrong:
	 *    a Spanish file under «en_US» is what makes the plugin speak Spanish
	 *    in that request) and locale() itself (where WPML will move the
	 *    controller later, without telling anyone). Since a key can later
	 *    hold another language (a WPML switch to «en» sets the controller to
	 *    en_US), the previous file under that key is unloaded first.
	 * 6. It is repeated on wpml_language_has_switched (priority 20, after
	 *    WPML String Translation, which swaps the loaded domains for the new
	 *    language at 10): the language switcher, menus and emails switch
	 *    language in the middle of a page several times.
	 * Edge case: on a site where WordPress and WPML agree (the usual one, and
	 * Lavandería since WPLANG = es_ES), the file and the key are the same
	 * locale and this is what it always was.
	 */
	public static function load_textdomain(): void {
		get_translations_for_domain( self::DOMAIN );
		$controller = class_exists( 'WP_Translation_Controller' ) ? \WP_Translation_Controller::get_instance() : null;
		$key        = $controller ? $controller->get_locale() : determine_locale();
		$locale     = self::locale();
		foreach ( array_unique( array( $key, $locale ) ) as $k ) {
			if ( isset( self::$loaded[ $k ] ) && self::$loaded[ $k ] !== $locale && $controller ) {
				$controller->unload_file( self::l10n_file( self::$loaded[ $k ] ), self::DOMAIN, $k );
			}
			self::$loaded[ $k ] = $locale;
			load_textdomain( self::DOMAIN, ESTRATEGIA_WEB_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo', $k );
		}
	}

	/**
	 * The language of THIS request, as a locale. On the front end with WPML,
	 * the locale of WPML's current language (the one of the URL, or the one
	 * WPML switched to); everywhere else, determine_locale(). The admin and
	 * the editor's REST calls (_locale=user) speak the user's language, not
	 * the content's: WPML does the same with the WordPress locale there.
	 */
	public static function locale(): string {
		$editor = isset( $_GET['_locale'] ) && 'user' === $_GET['_locale']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_admin() && ! $editor ) {
			$wpml = self::wpml_locale( apply_filters( 'wpml_current_language', null ) );
			if ( null !== $wpml ) {
				return $wpml;
			}
		}
		return determine_locale();
	}

	/**
	 * The language of the SITE, as a locale: WPML's default language, or
	 * get_locale() without WPML (WPLANG; not the user's). The same in every
	 * request, whatever the URL or the user. Used for URL slugs (url_slug()).
	 */
	public static function site_locale(): string {
		return self::wpml_locale( apply_filters( 'wpml_default_language', null ) ) ?? get_locale();
	}

	/**
	 * URL slugs (_x( 'clients', 'URL slug' )) are ALWAYS translated into the
	 * site language, not into the request's.
	 *
	 * THE CRITERION: a URL slug is a route, and routes are global. The
	 * rewrite rules are generated ONCE (in whatever request flushes them) and
	 * stored in the database; the permalinks are built in every request with
	 * the slug registered in THAT request. If the slug followed the request's
	 * language, a request in another language would build links that the
	 * stored rules do not know. Measured in local Lavandería with real WPML
	 * on 15-10-2026: /en/ registered `locations`, the rules said `sedes`, and
	 * every English location permalink (/en/locations/son-oms-en/) was a 404.
	 * And whoever flushes the rules from /en/ (or from an admin with the user
	 * language in English) breaks the Spanish ones. So:
	 * - The slug is the site language's: Lavandería (Spanish by default)
	 *   /sedes/ and /en/sedes/…; Gastronòmicament (Catalan by default, four
	 *   languages) the Catalan one in all four, as soon as the plugin ships a
	 *   ca translation (until then, the English msgid: `locations`, in all
	 *   four too, which is consistent; adding it changes their URLs, see
	 *   tools/i18n.mjs).
	 * - Slugs per language (/en/locations/) are NOT the plugin's job: that is
	 *   WPML's slug translation («URL slug: eweb_location» strings, see
	 *   class-prefix-migration.php), which rewrites on top of the registered
	 *   slug and expects that slug to be the default language's.
	 * - A fixed slug (rewrite_slugs option, the filter) still wins: it is
	 *   applied after this, in the module.
	 */
	public static function url_slug( $translation, $text, $context ) {
		if ( 'URL slug' !== $context || ! class_exists( 'WP_Translation_Controller' ) ) {
			return $translation;
		}
		$site       = self::site_locale();
		$controller = \WP_Translation_Controller::get_instance();
		if ( $site === ( self::$loaded[ $controller->get_locale() ] ?? null ) ) {
			return $translation; // What is being looked up now is already the site language's file.
		}
		// Under a key of its own, not under a real locale: the request's texts
		// are under the controller's locale, which may be this same one with
		// another language's file in it (see load_textdomain(), 5).
		$key = $site . '#url-slugs';
		$controller->load_file( self::l10n_file( $site ), self::DOMAIN, $key );
		$translated = $controller->translate( $text, $context, self::DOMAIN, $key );
		return false === $translated ? $text : $translated;
	}

	/** The locale of a WPML language code, or null without WPML (or without that language). */
	private static function wpml_locale( $code ): ?string {
		if ( ! is_string( $code ) || '' === $code || 'all' === $code ) {
			return null;
		}
		global $sitepress;
		$locale = is_object( $sitepress ) && method_exists( $sitepress, 'get_locale' ) ? $sitepress->get_locale( $code ) : null;
		return is_string( $locale ) && '' !== $locale ? $locale : null;
	}

	/**
	 * The file that really exists. load_textdomain() is given the .mo and
	 * reads the .l10n.php next to it; the controller is given this one directly.
	 */
	private static function l10n_file( string $locale ): string {
		return ESTRATEGIA_WEB_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.l10n.php';
	}

	public static function activate(): void {
		self::load_textdomain();
		/** When the whole plugin is activated. The enabled modules are already loaded. */
		do_action( 'estrategia_web_activate' );
	}

	public static function deactivate(): void {
		/** When the whole plugin is deactivated. */
		do_action( 'estrategia_web_deactivate' );
	}
}
