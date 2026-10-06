<?php
/**
 * Cableado: qué clase atiende cada gancho de WordPress. Si buscas dónde
 * empieza algo, empieza aquí.
 *
 * La trampa del orden: los tipos de contenido se registran en `init` con lo
 * que diga la opción en ESA carga, y las traducciones tienen que estar antes
 * (prioridad 1) porque las etiquetas y los slugs salen de gettext.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ), 1 );
		add_action( 'init', array( Settings::class, 'register' ) );
		add_action( 'init', array( Content_Types::class, 'register' ) );
		add_action( 'init', array( Bindings::class, 'register' ) );
		add_action( 'init', array( Location::class, 'register' ) );
		add_action( 'init', array( Shortcode::class, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( Bindings::class, 'enqueue_editor' ) );
		add_filter( 'post_class', array( Content_Types::class, 'post_class' ), 10, 3 );
		add_action( 'update_option_' . Settings::OPTION, array( Content_Types::class, 'maybe_flush' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, static fn( $name, $value ) => Content_Types::maybe_flush( array(), $value ), 10, 2 );
		Svg::boot();
		Matomo::boot();
		Consent::boot(); // El aviso de cookies (0.5.0). Arranca en after_setup_theme: ver su cabecera.
		Webp::boot(); // Tamaños de imagen en WebP (0.5.1).
		External_Links::boot(); // Enlaces a otro dominio en pestaña nueva (0.5.2).

		if ( is_admin() ) {
			Admin::boot();
		}
	}

	/**
	 * Las traducciones van DENTRO del plugin (languages/): no está en
	 * wordpress.org y nadie más las va a servir. WordPress 6.5+ carga el
	 * .l10n.php si existe.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'armazon-site', false, dirname( plugin_basename( ARMAZON_SITE_FILE ) ) . '/languages' );
	}

	public static function activate(): void {
		self::load_textdomain();
		Content_Types::register();
		flush_rewrite_rules( false );
	}

	public static function deactivate(): void {
		// Los tipos ya no se registrarán: que sus URL dejen de existir. El contenido se queda.
		delete_option( 'rewrite_rules' );
	}
}
