<?php
/**
 * Pantalla «Estrategia Web → Google» (manage_options): los tres ids, la
 * etiqueta de conversión de formularios y el modo. Guarda por options.php
 * (Settings API): el saneado es Google_Tags::sanitize, el mismo que por REST.
 *
 * Cómo se mantiene: un campo nuevo, su fila en render() con el name
 * estrategia_web_google_tags[clave] (y lo demás en la cabecera de
 * class-google-tags.php). La trampa: el grupo de settings_fields() tiene que
 * ser el de register_setting ('estrategia_web_google_tags'), o options.php
 * contesta «la opción no está en la lista permitida».
 */

namespace EstrategiaWeb\GoogleTags;

use EstrategiaWeb\Admin\Modules_Page;

defined( 'ABSPATH' ) || exit;

final class Google_Tags_Page {

	public const PAGE = 'estrategia-web-google';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 ); // Después del menú «Estrategia Web» (10).
	}

	public static function menu(): void {
		add_submenu_page( Modules_Page::MENU, __( 'Google tags', 'estrategia-web' ), __( 'Google', 'estrategia-web' ), 'manage_options', self::PAGE, array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = Google_Tags::settings();
		$name = static fn( string $key ): string => Google_Tags::OPTION . '[' . $key . ']';
		$row  = static function ( string $key, string $label, string $placeholder, string $help ) use ( $s, $name ): void {
			printf(
				'<tr><th scope="row"><label for="estrategia-web-google-%1$s">%2$s</label></th><td><input type="text" class="regular-text code" id="estrategia-web-google-%1$s" name="%3$s" value="%4$s" placeholder="%5$s"><p class="description">%6$s</p></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name( $key ) ),
				esc_attr( $s[ $key ] ),
				esc_attr( $placeholder ),
				esc_html( $help )
			);
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Google tags', 'estrategia-web' ); ?></h1>
			<p><?php esc_html_e( 'An empty field loads nothing. Whatever is filled in waits for the cookie notice: Analytics needs «Analytics», Ads needs «Advertising», and Tag Manager asks for both. Logged-in editors and administrators are not measured.', 'estrategia-web' ); ?></p>
			<?php settings_errors( Google_Tags::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'estrategia_web_google_tags' ); ?>
				<table class="form-table" role="presentation">
					<?php
					$row( 'ga4', __( 'Google Analytics 4', 'estrategia-web' ), 'G-XXXXXXXXXX', __( 'Measurement ID of the GA4 web stream.', 'estrategia-web' ) );
					$row( 'ads', __( 'Google Ads', 'estrategia-web' ), 'AW-123456789', __( 'Conversion ID of the Google Ads account.', 'estrategia-web' ) );
					$row( 'ads_form_label', __( 'Conversion: form sent', 'estrategia-web' ), 'AW-123456789/AbCdEfGhIj', __( 'Optional. Conversion ID and label («send_to» of the conversion). Counted each time a form of the site is sent correctly, if the visitor accepted «Advertising».', 'estrategia-web' ) );
					$row( 'gtm', __( 'Google Tag Manager', 'estrategia-web' ), 'GTM-XXXXXXX', __( 'Container ID. The tags inside the container follow Consent Mode.', 'estrategia-web' ) );
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Loading', 'estrategia-web' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin:.4em 0">
									<input type="radio" name="<?php echo esc_attr( $name( 'mode' ) ); ?>" value="basic" <?php checked( 'basic', $s['mode'] ); ?>>
									<?php esc_html_e( 'Basic (recommended): nothing is requested from Google until the visitor accepts.', 'estrategia-web' ); ?>
								</label>
								<label style="display:block;margin:.4em 0">
									<input type="radio" name="<?php echo esc_attr( $name( 'mode' ) ); ?>" value="advanced" <?php checked( 'advanced', $s['mode'] ); ?>>
									<?php esc_html_e( 'Advanced: Google loads from the start with consent denied and sends cookieless pings. Better measurement, but data reaches Google before the visitor decides: decide it with the client.', 'estrategia-web' ); ?>
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
