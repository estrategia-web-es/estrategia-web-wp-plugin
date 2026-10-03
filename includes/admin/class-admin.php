<?php
/**
 * Pantalla «Datos de la empresa» (menú propio, manage_options). Guarda por
 * options.php con la Settings API: el saneado es el mismo que por REST
 * (Settings::sanitize), así que aquí no se valida nada.
 *
 * Cómo se mantiene:
 * - Un dato nuevo: su fila en render() con el name `armazon_site[clave]`.
 *   Las casillas llevan un campo oculto delante para que «desmarcado» llegue
 *   (Settings::sanitize fusiona con lo guardado: lo que no llega, no cambia).
 * - Caso frontera: enabled_types vacío. Llega como [''] gracias al oculto, y
 *   sanitize lo deja en []. Sin el oculto no llegaría y no se podría
 *   desactivar el último tipo.
 * - La trampa: el grupo de settings_fields() tiene que ser el mismo que el
 *   primer argumento de register_setting ('armazon_site'), o options.php
 *   contesta «la opción no está en la lista permitida».
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = 'armazon-site';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Company details', 'armazon-site' ),
			__( 'Company details', 'armazon-site' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render' ),
			'dashicons-building',
			3
		);
	}

	private static function type_labels(): array {
		return array(
			'client'      => __( 'Clients', 'armazon-site' ),
			'testimonial' => __( 'Testimonials', 'armazon-site' ),
			'location'    => __( 'Locations', 'armazon-site' ),
			'project'     => __( 'Projects', 'armazon-site' ),
			'portfolio'   => __( 'Portfolio', 'armazon-site' ),
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
				'<tr><th scope="row"><label for="armazon-site-%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="armazon-site-%1$s" name="%4$s" value="%5$s">%6$s</td></tr>',
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
				'<tr><th scope="row"><label for="armazon-site-%1$s">%2$s</label></th><td><textarea class="large-text" rows="3" id="armazon-site-%1$s" name="%3$s">%4$s</textarea></td></tr>',
				esc_attr( $key ),
				esc_html( $labels[ $key ] ),
				esc_attr( $name( $key ) ),
				esc_textarea( (string) $data[ $key ] )
			);
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Company details', 'armazon-site' ); ?></h1>
			<p><?php esc_html_e( 'These details appear wherever a block is linked to them (Block Bindings, source «Company details»). Change them here and they change across the whole site.', 'armazon-site' ); ?></p>
			<?php settings_errors( Settings::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'armazon_site' ); ?>

				<h2><?php esc_html_e( 'Company', 'armazon-site' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$text( 'company_name' );
					$text( 'legal_name' );
					$text( 'tax_id' );
					$text( 'registry', 'text', __( 'As it appears in the legal notice, without «Registered in». Example: Registro Mercantil de Mallorca, tomo 1234, folio 56, hoja PM-7890, inscripción 1.ª', 'armazon-site' ) );
					$area( 'address' );
					$area( 'schedule' );
					$text( 'map_url', 'url' );
					?>
				</table>

				<h2><?php esc_html_e( 'Contact', 'armazon-site' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$text( 'email', 'email' );
					$text( 'phone', 'tel' );
					$text( 'phone_secondary', 'tel' );
					$text( 'whatsapp', 'tel', __( 'International format, digits only, without +. Example: 34600000000.', 'armazon-site' ) );
					$text( 'whatsapp_message', 'text', __( 'Text that appears already written when the chat opens.', 'armazon-site' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Social networks', 'armazon-site' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					foreach ( Settings::SOCIAL as $network ) {
						printf(
							'<tr><th scope="row"><label for="armazon-site-social-%1$s">%2$s</label></th><td><input type="url" class="regular-text" id="armazon-site-social-%1$s" name="%3$s" value="%4$s"></td></tr>',
							esc_attr( $network ),
							esc_html( $labels[ 'social.' . $network ] ),
							esc_attr( Settings::OPTION . '[social][' . $network . ']' ),
							esc_attr( (string) $data['social'][ $network ] )
						);
					}
					?>
				</table>

				<h2><?php esc_html_e( 'Content types', 'armazon-site' ); ?></h2>
				<p><?php esc_html_e( 'Only the checked types appear in the menu and on the site. Unchecking a type does not delete its content: it comes back when it is checked again.', 'armazon-site' ); ?></p>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[enabled_types][]' ); ?>" value="">
				<fieldset>
					<?php foreach ( self::type_labels() as $type => $label ) : ?>
						<label style="display:block;margin:.4em 0">
							<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[enabled_types][]' ); ?>" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $data['enabled_types'], true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'Media', 'armazon-site' ); ?></h2>
				<input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[allow_svg]' ); ?>" value="0">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[allow_svg]' ); ?>" value="1" <?php checked( (bool) $data['allow_svg'] ); ?>>
					<?php esc_html_e( 'Allow SVG uploads (administrators only; files are sanitized)', 'armazon-site' ); ?>
				</label>

				<?php // 0.3.0. Qué hace cada modo y por qué: cabecera de includes/class-matomo.php. ?>
				<h2><?php esc_html_e( 'Statistics (Matomo)', 'armazon-site' ); ?></h2>
				<p><?php esc_html_e( 'Without the Matomo address or the site ID nothing is added to the site. Logged-in editors and administrators are not counted.', 'armazon-site' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="armazon-site-matomo_url"><?php esc_html_e( 'Matomo address', 'armazon-site' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="armazon-site-matomo_url" name="<?php echo esc_attr( $name( 'matomo_url' ) ); ?>" value="<?php echo esc_attr( (string) $data['matomo_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Example: https://stats.example.com/', 'armazon-site' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="armazon-site-matomo_site_id"><?php esc_html_e( 'Site ID', 'armazon-site' ); ?></label></th>
						<td><input type="number" min="0" step="1" class="small-text" id="armazon-site-matomo_site_id" name="<?php echo esc_attr( $name( 'matomo_site_id' ) ); ?>" value="<?php echo esc_attr( (string) ( $data['matomo_site_id'] ?: '' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cookies', 'armazon-site' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin:.4em 0">
									<input type="radio" name="<?php echo esc_attr( $name( 'matomo_mode' ) ); ?>" value="cookieless" <?php checked( 'cookieless', $data['matomo_mode'] ); ?>>
									<?php esc_html_e( 'Without cookies (recommended): nothing to ask the visitor.', 'armazon-site' ); ?>
								</label>
								<label style="display:block;margin:.4em 0">
									<input type="radio" name="<?php echo esc_attr( $name( 'matomo_mode' ) ); ?>" value="hybrid" <?php checked( 'hybrid', $data['matomo_mode'] ); ?>>
									<?php esc_html_e( 'Hybrid: without cookies until the visitor accepts «Statistics» in the cookie notice of the theme.', 'armazon-site' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
