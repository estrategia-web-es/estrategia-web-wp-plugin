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
		Modules::load();
		Blocks::boot();
		if ( is_admin() ) {
			Admin\Modules_Page::boot();
		}
	}

	/**
	 * Un solo dominio, estrategia-web, para todo el plugin (el mismo que el
	 * tema: WordPress 6.5+ junta los dos ficheros en el mismo dominio). Las
	 * traducciones van DENTRO del plugin (languages/): no está en
	 * wordpress.org y nadie más las va a servir.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'estrategia-web', false, dirname( plugin_basename( ESTRATEGIA_WEB_FILE ) ) . '/languages' );
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
