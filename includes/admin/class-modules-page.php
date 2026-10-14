<?php
/**
 * La pantalla «Estrategia Web → Módulos»: un interruptor por módulo, con su
 * descripción y, si no se puede cargar, qué le falta.
 *
 * La lista sale del disco (Modules::available()): no hay nada que añadir
 * aquí para que salga un módulo nuevo. Guarda la opción estrategia_web_modules
 * por options.php (Settings API), así que pide manage_options y lleva su
 * nonce sin código propio.
 *
 * Cómo se mantiene:
 * - Una pantalla de ajustes DE UN MÓDULO no va aquí: va en su carpeta, como
 *   submenú de este menú (add_submenu_page( Modules_Page::MENU, … )) o donde
 *   ya estuviera (la de «Datos de la empresa» y la de formularios tienen su
 *   menú propio desde antes y no se han movido para no cambiarle la web a
 *   nadie).
 * - Caso frontera: con TODOS los interruptores apagados el navegador no
 *   manda el campo y options.php no guardaría nada (se quedaría lo de antes).
 *   Por eso va un campo oculto vacío: llega un array con '' y sanitize() lo
 *   deja en array().
 * - La trampa: el estado que se pinta es lo que la web QUIERE (guardado o
 *   por defecto), no lo cargado en esta petición. Justo después de guardar
 *   coinciden; si no coinciden es que a uno le falta un requisito, y eso es
 *   lo que dice la columna «Estado».
 */

namespace EstrategiaWeb\Admin;

use EstrategiaWeb\Modules;

defined( 'ABSPATH' ) || exit;

final class Modules_Page {

	/** Slug del menú «Estrategia Web» (y de su primera página, Módulos). */
	public const MENU = 'estrategia-web';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	public static function register_setting(): void {
		register_setting(
			self::MENU,
			Modules::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Modules::class, 'sanitize' ),
				'default'           => null,
				'show_in_rest'      => false,
			)
		);
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Estrategia Web', 'estrategia-web' ),
			__( 'Estrategia Web', 'estrategia-web' ),
			'manage_options',
			self::MENU,
			array( self::class, 'render' ),
			'dashicons-screenoptions',
			81
		);
		add_submenu_page( self::MENU, __( 'Modules', 'estrategia-web' ), __( 'Modules', 'estrategia-web' ), 'manage_options', self::MENU, array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$available = Modules::available();
		$wanted    = Modules::wanted( $available, get_option( Modules::OPTION, null ), get_option( Modules::KNOWN_OPTION, null ) );
		list( , $blocked ) = Modules::resolve( $available, $wanted );
		$name = static fn( string $slug ): string => isset( $available[ $slug ] )
			? _x( $available[ $slug ]['name'], 'module name', 'estrategia-web' ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- sale de la cabecera del módulo; tools/i18n.mjs la extrae.
			: $slug;
		?>
		<div class="wrap estrategia-web-modules">
			<h1><?php esc_html_e( 'Modules', 'estrategia-web' ); ?></h1>
			<p><?php esc_html_e( 'Each module adds one feature to the site. A module that is off loads nothing, and turning it off does not delete anything it saved: turning it on again brings it back as it was.', 'estrategia-web' ); ?></p>
			<style>
				.estrategia-web-modules td, .estrategia-web-modules th { vertical-align: middle; }
				.estrategia-web-modules .column-switch { width: 4.5em; }
				.estrategia-web-modules input[role="switch"] { appearance: none; width: 2.5em; height: 1.4em; border-radius: 1em; background: #8c8f94; position: relative; cursor: pointer; margin: 0; border: 0; }
				.estrategia-web-modules input[role="switch"]::before { content: ""; position: absolute; top: .2em; left: .2em; width: 1em; height: 1em; border-radius: 50%; background: #fff; transition: transform .15s; margin: 0; }
				.estrategia-web-modules input[role="switch"]:checked { background: #2271b1; }
				.estrategia-web-modules input[role="switch"]:checked::before { transform: translateX(1.1em); }
				.estrategia-web-modules input[role="switch"]:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
				.estrategia-web-modules .is-blocked { color: #b32d2e; }
			</style>
			<form method="post" action="options.php">
				<?php settings_fields( self::MENU ); ?>
				<input type="hidden" name="<?php echo esc_attr( Modules::OPTION ); ?>[]" value="">
				<table class="widefat striped">
					<thead>
						<tr>
							<th class="column-switch"><?php esc_html_e( 'On', 'estrategia-web' ); ?></th>
							<th><?php esc_html_e( 'Module', 'estrategia-web' ); ?></th>
							<th><?php esc_html_e( 'Status', 'estrategia-web' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $available as $slug => $module ) : ?>
						<?php
						$id      = 'estrategia-web-module-' . $slug;
						$is_on   = in_array( $slug, $wanted, true );
						$missing = $blocked[ $slug ] ?? array();
						?>
						<tr>
							<td class="column-switch">
								<input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Modules::OPTION ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-description" <?php checked( $is_on ); ?>>
							</td>
							<td>
								<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $name( $slug ) ); ?></strong></label>
								<p class="description" id="<?php echo esc_attr( $id ); ?>-description">
									<?php
									if ( '' !== $module['description'] ) {
										echo esc_html( _x( $module['description'], 'module description', 'estrategia-web' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- ver $name.
									}
									if ( $module['requires'] ) {
										echo ' ';
										/* translators: %s: names of other modules, comma separated. */
										echo esc_html( sprintf( __( 'Needs: %s.', 'estrategia-web' ), implode( ', ', array_map( $name, $module['requires'] ) ) ) );
									}
									?>
								</p>
							</td>
							<td>
								<?php if ( $is_on && $missing ) : ?>
									<span class="is-blocked">
									<?php
									/* translators: %s: names of the modules that are missing. */
									echo esc_html( sprintf( __( 'Not loaded: turn on %s.', 'estrategia-web' ), implode( ', ', array_map( $name, $missing ) ) ) );
									?>
									</span>
								<?php elseif ( $is_on ) : ?>
									<?php esc_html_e( 'On', 'estrategia-web' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Off', 'estrategia-web' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
