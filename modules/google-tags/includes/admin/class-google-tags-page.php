<?php
/**
 * Google tags in «Estrategia Web › Settings › Analytics» (1.7.0; up to 1.6.1
 * it was its own screen, «Estrategia Web → Google», page=estrategia-web-google,
 * which now redirects here: Menu::LEGACY in includes/admin/class-menu.php at
 * the root). The three ids, the form conversion label and the mode.
 *
 * It is a section of the Settings page's registry
 * (includes/admin/class-settings-page.php) declaring the option
 * estrategia_web_google_tags: the page wraps it in the Analytics tab's form
 * (with Matomo's section, if that module is on) and puts the option in the
 * tab's settings group, so it saves through options.php: sanitization is
 * Google_Tags::sanitize, the same as over REST, and it MERGES with what is
 * saved.
 *
 * How to maintain: a new field gets its row in render_section() with the
 * name estrategia_web_google_tags[key] (the rest is in the header of
 * class-google-tags.php). The pitfall: no <form> or settings_fields() here
 * any more — the page prints them once for the whole tab; a second form
 * inside would be nested and the browser would drop it.
 */

namespace EstrategiaWeb\GoogleTags;

use EstrategiaWeb\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

final class Google_Tags_Page {

	/** The slug of the old screen (1.6.1), redirected to the Analytics tab. */
	public const PAGE = 'estrategia-web-google';

	public static function boot(): void {
		add_action( Settings_Page::HOOK, array( self::class, 'register' ) );
	}

	/** On Settings_Page::HOOK: the Analytics tab and this section. */
	public static function register(): void {
		Settings_Page::add_tab( 'analytics', __( 'Analytics', 'estrategia-web' ), 20 );
		Settings_Page::add_section(
			'analytics',
			array(
				'id'       => 'google-tags',
				'priority' => 10,
				'options'  => array( Google_Tags::OPTION ),
				'render'   => array( self::class, 'render_section' ),
			)
		);
	}

	public static function render_section(): void {
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
		<h2><?php esc_html_e( 'Google tags', 'estrategia-web' ); ?></h2>
		<p><?php esc_html_e( 'An empty field loads nothing. Whatever is filled in waits for the cookie notice: Analytics needs «Analytics», Ads needs «Advertising», and Tag Manager asks for both. Logged-in editors and administrators are not measured.', 'estrategia-web' ); ?></p>
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
		<?php
	}
}
