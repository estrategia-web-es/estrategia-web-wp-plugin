<?php
/**
 * Pantalla «Datos de la empresa» (menú propio, manage_options). Guarda por
 * options.php con la Settings API: el saneado es el mismo que por REST
 * (Settings::sanitize), así que aquí no se valida nada.
 *
 * Cómo se mantiene:
 * - Un dato nuevo: su fila en render() con el name `estrategia_web_site[clave]`.
 *   Las casillas llevan un campo oculto delante para que «desmarcado» llegue
 *   (Settings::sanitize fusiona con lo guardado: lo que no llega, no cambia).
 * - Caso frontera: enabled_types vacío. Llega como [''] gracias al oculto, y
 *   sanitize lo deja en []. Sin el oculto no llegaría y no se podría
 *   desactivar el último tipo.
 * - La trampa: el grupo de settings_fields() tiene que ser el mismo que el
 *   primer argumento de register_setting ('estrategia_web_site'), o options.php
 *   contesta «la opción no está en la lista permitida».
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = 'estrategia-web-site';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Company details', 'estrategia-web' ),
			__( 'Company details', 'estrategia-web' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render' ),
			'dashicons-building',
			3
		);
	}

	private static function type_labels(): array {
		return array(
			'client'      => __( 'Clients', 'estrategia-web' ),
			'testimonial' => __( 'Testimonials', 'estrategia-web' ),
			'location'    => __( 'Locations', 'estrategia-web' ),
			'project'     => __( 'Projects', 'estrategia-web' ),
			'portfolio'   => __( 'Portfolio', 'estrategia-web' ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$data   = Settings::all();
		$labels = Company::labels();
		$name   = static fn( string $key ): string => Settings::OPTION . '[' . $key . ']';

		$text = static function ( string $key, string $type = 'text', string $help = '' ) use ( $data, $labels, $name ): void {
			printf(
				'<tr><th scope="row"><label for="estrategia-web-site-%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="estrategia-web-site-%1$s" name="%4$s" value="%5$s">%6$s</td></tr>',
				esc_attr( $key ),
				esc_html( $labels[ $key ] ),
				esc_attr( $type ),
				esc_attr( $name( $key ) ),
				esc_attr( (string) $data[ $key ] ),
				$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		};
		$area = static function ( string $key ) use ( $data, $labels, $name ): void {
			printf(
				'<tr><th scope="row"><label for="estrategia-web-site-%1$s">%2$s</label></th><td><textarea class="large-text" rows="3" id="estrategia-web-site-%1$s" name="%3$s">%4$s</textarea></td></tr>',
				esc_attr( $key ),
				esc_html( $labels[ $key ] ),
				esc_attr( $name( $key ) ),
				esc_textarea( (string) $data[ $key ] )
			);
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Company details', 'estrategia-web' ); ?></h1>
			<p><?php esc_html_e( 'These details appear wherever a block is linked to them (Block Bindings, source «Company details»). Change them here and they change across the whole site.', 'estrategia-web' ); ?></p>
			<?php settings_errors( Settings::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'estrategia_web_site' ); ?>

				<h2><?php esc_html_e( 'Company', 'estrategia-web' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$text( 'company_name' );
					$text( 'legal_name' );
					$text( 'tax_id' );
					$text( 'registry', 'text', __( 'As it appears in the legal notice, without «Registered in». Example: Registro Mercantil de Mallorca, tomo 1234, folio 56, hoja PM-7890, inscripción 1.ª', 'estrategia-web' ) );
					$area( 'address' );
					$area( 'schedule' );
					$text( 'map_url', 'url' );
					?>
				</table>

				<h2><?php esc_html_e( 'Contact', 'estrategia-web' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$text( 'email', 'email' );
					$text( 'phone', 'tel' );
					$text( 'phone_secondary', 'tel' );
					$text( 'whatsapp', 'tel', __( 'International format, digits only, without +. Example: 34600000000.', 'estrategia-web' ) );
					$text( 'whatsapp_message', 'text', __( 'Text that appears already written when the chat opens.', 'estrategia-web' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Social networks', 'estrategia-web' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					foreach ( Settings::SOCIAL as $network ) {
						printf(
							'<tr><th scope="row"><label for="estrategia-web-site-social-%1$s">%2$s</label></th><td><input type="url" class="regular-text" id="estrategia-web-site-social-%1$s" name="%3$s" value="%4$s"></td></tr>',
							esc_attr( $network ),
							esc_html( $labels[ 'social.' . $network ] ),
							esc_attr( Settings::OPTION . '[social][' . $network . ']' ),
							esc_attr( (string) $data['social'][ $network ] )
						);
					}
					?>
				</table>

				<h2><?php esc_html_e( 'Content types', 'estrategia-web' ); ?></h2>
				<p><?php esc_html_e( 'Only the checked types appear in the menu and on the site. Unchecking a type does not delete its content: it comes back when it is checked again.', 'estrategia-web' ); ?></p>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[enabled_types][]' ); ?>" value="">
				<fieldset>
					<?php foreach ( self::type_labels() as $type => $label ) : ?>
						<label style="display:block;margin:.4em 0">
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[enabled_types][]' ); ?>" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $data['enabled_types'], true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'Media', 'estrategia-web' ); ?></h2>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[allow_svg]' ); ?>" value="0">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[allow_svg]' ); ?>" value="1" <?php checked( (bool) $data['allow_svg'] ); ?>>
					<?php esc_html_e( 'Allow SVG uploads (administrators only; files are sanitized)', 'estrategia-web' ); ?>
				</label>

				<?php
				/**
				 * Secciones de OTROS módulos que guardan en esta misma opción
				 * (1.1.0: Matomo, modules/matomo/includes/class-admin-fields.php).
				 * Van dentro del formulario, antes de Guardar; sus campos se
				 * llaman estrategia_web_site[clave] y los sanea Settings.
				 *
				 * @param array    $data Settings::all().
				 * @param callable $name Clave → name del campo.
				 */
				do_action( 'estrategia_web_site_settings_sections', $data, $name );
				?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
