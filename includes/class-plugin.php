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
 */

namespace EstrategiaWeb;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ), 1 );
		add_action( 'change_locale', array( self::class, 'load_textdomain' ) );
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
	 */
	public static function load_textdomain(): void {
		get_translations_for_domain( 'estrategia-web' );
		load_textdomain( 'estrategia-web', ESTRATEGIA_WEB_DIR . 'languages/estrategia-web-' . determine_locale() . '.mo' );
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
