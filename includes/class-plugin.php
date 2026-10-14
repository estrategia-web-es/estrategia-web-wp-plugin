<?php
/**
 * El núcleo: lo que se carga SIEMPRE, esté encendido el módulo que esté.
 * Traducciones, el cargador de módulos, las ayudas comunes de bloques y la
 * pantalla «Estrategia Web → Módulos». Nada de un módulo concreto vive aquí.
 *
 * Ganchos que ofrece a los módulos:
 * - estrategia_web_activate / estrategia_web_deactivate: al activar o
 *   desactivar el PLUGIN entero en «Plugins». NO saltan al encender o apagar
 *   un módulo en la pantalla (ver la trampa en class-modules.php).
 * - estrategia_web_modules_loaded( $slugs ): justo después de cargar los
 *   módulos, antes de plugins_loaded.
 *
 * La trampa del orden: un módulo se carga mientras WordPress carga los
 * plugins, ANTES que el tema. Las traducciones se cargan en init con
 * prioridad 1 porque las etiquetas de tipos y bloques salen de gettext y se
 * registran en init (10).
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
	 * Un solo dominio, estrategia-web, para todo el plugin. Las traducciones
	 * van DENTRO del plugin (languages/): no está en wordpress.org y nadie
	 * más las va a servir.
	 *
	 * LA TRAMPA (medida el 14-10-2026, tests/page-loads.test.mjs del tema en
	 * rojo: «404 sin traducir»): el tema usa el MISMO dominio, y desde
	 * WordPress 6.7 load_plugin_textdomain() y load_theme_textdomain() no
	 * cargan nada: solo apuntan UNA carpeta por dominio para la carga «justo
	 * a tiempo». La del plugin (init) pisaba la del tema (after_setup_theme)
	 * y los textos del tema salían en inglés. Por eso aquí:
	 * 1. NO se llama a load_plugin_textdomain(): la carpeta apuntada sigue
	 *    siendo la del tema.
	 * 2. get_translations_for_domain() fuerza esa carga justo a tiempo (la
	 *    del tema, si la hay).
	 * 3. load_textdomain() carga el fichero del plugin YA, y WordPress 6.5+
	 *    lo junta con lo cargado (varios ficheros por dominio).
	 * Y se repite en change_locale: al cambiar de idioma (un correo en el
	 * idioma del visitante, WPML) WordPress descarga el dominio y lo vuelve a
	 * cargar solo desde la carpeta apuntada, sin el plugin.
	 */
	public static function load_textdomain(): void {
		get_translations_for_domain( 'estrategia-web' );
		load_textdomain( 'estrategia-web', ESTRATEGIA_WEB_DIR . 'languages/estrategia-web-' . determine_locale() . '.mo' );
	}

	public static function activate(): void {
		self::load_textdomain();
		/** Al activar el plugin entero. Los módulos encendidos ya están cargados. */
		do_action( 'estrategia_web_activate' );
	}

	public static function deactivate(): void {
		/** Al desactivar el plugin entero. */
		do_action( 'estrategia_web_deactivate' );
	}
}
