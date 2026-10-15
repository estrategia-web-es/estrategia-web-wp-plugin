<?php
/**
 * The «Estrategia Web → Modules» screen: one switch per module, with its
 * description and, if it cannot be loaded, what it is missing.
 *
 * The list comes from disk (Modules::available()): nothing needs to be added
 * here for a new module to show up. It saves the estrategia_web_modules
 * option through options.php (Settings API), so it requires manage_options
 * and carries its nonce with no code of its own.
 *
 * How to maintain:
 * - A settings screen OF A MODULE does not go here: it goes in its folder, as
 *   a submenu of this menu (add_submenu_page( Modules_Page::MENU, … )) or
 *   wherever it already was (the «Company details» screen and the forms one
 *   have had their own menu since before and were not moved so as not to
 *   change anyone's site).
 * - Edge case: with ALL switches off the browser does not send the field and
 *   options.php would not save anything (the previous value would remain).
 *   That is why there is an empty hidden field: an array with '' arrives and
 *   sanitize() turns it into array().
 * - The trap: the state shown is what the site WANTS (saved or default), not
 *   what was loaded in this request. Right after saving they match; if they
 *   do not, one of them is missing a requirement, and that is what the
 *   «Status» column says.
 */

namespace EstrategiaWeb\Admin;

use EstrategiaWeb\Modules;

defined( 'ABSPATH' ) || exit;

final class Modules_Page {

	/** Slug of the «Estrategia Web» menu (and of its first page, Modules). */
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
			? _x( $available[ $slug ]['name'], 'module name', 'estrategia-web' ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- comes from the module header; tools/i18n.mjs extracts it.
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
										echo esc_html( _x( $module['description'], 'module description', 'estrategia-web' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- see $name.
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
