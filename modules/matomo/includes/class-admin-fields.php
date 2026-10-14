<?php
/**
 * Los campos de Matomo en la pantalla «Datos de la empresa» (la del módulo
 * site, que los guarda en su opción). Se pintan en el gancho
 * estrategia_web_site_settings_sections que esa pantalla lanza antes del
 * botón Guardar; el saneado es el de Settings (site), así que aquí no se
 * valida nada. Por qué la opción no se movió: modules/matomo/module.php.
 *
 * Cómo se mantiene: un campo nuevo de Matomo, su fila aquí con el name
 * estrategia_web_site[clave] y su clave en Settings (esquema + saneado).
 */

namespace EstrategiaWeb\Matomo;

defined( 'ABSPATH' ) || exit;

final class Admin_Fields {

	/**
	 * @param array    $data Settings::all().
	 * @param callable $name Clave → name del campo (estrategia_web_site[clave]).
	 */
	public static function render( array $data, callable $name ): void {
		?>
		<?php // 0.3.0. Qué hace cada modo y por qué: cabecera de modules/matomo/includes/class-matomo.php. ?>
		<h2><?php esc_html_e( 'Statistics (Matomo)', 'estrategia-web' ); ?></h2>
		<p><?php esc_html_e( 'Without the Matomo address or the site ID nothing is added to the site. Logged-in editors and administrators are not counted.', 'estrategia-web' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="estrategia-web-site-matomo_url"><?php esc_html_e( 'Matomo address', 'estrategia-web' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="estrategia-web-site-matomo_url" name="<?php echo esc_attr( $name( 'matomo_url' ) ); ?>" value="<?php echo esc_attr( (string) $data['matomo_url'] ); ?>">
					<p class="description"><?php esc_html_e( 'Example: https://stats.example.com/', 'estrategia-web' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="estrategia-web-site-matomo_site_id"><?php esc_html_e( 'Site ID', 'estrategia-web' ); ?></label></th>
				<td><input type="number" min="0" step="1" class="small-text" id="estrategia-web-site-matomo_site_id" name="<?php echo esc_attr( $name( 'matomo_site_id' ) ); ?>" value="<?php echo esc_attr( (string) ( $data['matomo_site_id'] ?: '' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cookies', 'estrategia-web' ); ?></th>
				<td>
					<fieldset>
						<label style="display:block;margin:.4em 0">
							<input type="radio" name="<?php echo esc_attr( $name( 'matomo_mode' ) ); ?>" value="cookieless" <?php checked( 'cookieless', $data['matomo_mode'] ); ?>>
							<?php esc_html_e( 'Without cookies (recommended): nothing to ask the visitor.', 'estrategia-web' ); ?>
						</label>
						<label style="display:block;margin:.4em 0">
							<input type="radio" name="<?php echo esc_attr( $name( 'matomo_mode' ) ); ?>" value="hybrid" <?php checked( 'hybrid', $data['matomo_mode'] ); ?>>
							<?php esc_html_e( 'Hybrid: without cookies until the visitor accepts «Analytics» in the cookie notice.', 'estrategia-web' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}
}
