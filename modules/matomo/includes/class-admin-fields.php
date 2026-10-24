<?php
/**
 * The Matomo fields, in «Estrategia Web › Settings › Analytics» (1.7.0; up
 * to 1.6.1 they were on the «Company details» screen). They are stored in
 * the site module's option (estrategia_web_site, keys matomo_*), and the
 * sanitization is Settings' (site), so nothing is validated here. Why the
 * option was not moved: modules/matomo/module.php.
 *
 * It is a section of the Settings page's registry
 * (includes/admin/class-settings-page.php at the root) that declares the
 * option estrategia_web_site: the page puts it in the Analytics tab's form,
 * next to Google tags' section if that module is on, and options.php saves
 * only the keys sent here (Settings::sanitize merges: the company details
 * saved from their own screen are not touched).
 *
 * How to maintain: a new Matomo field gets its row here with the name
 * estrategia_web_site[key] and its key in Settings (schema + sanitization).
 * The trap: this section must ALWAYS print all three fields when it is
 * registered (see the trap of the Settings page): otherwise the option
 * would be sent to options.php without them — harmless today because
 * sanitize() merges, but it is the rule the page relies on.
 */

namespace EstrategiaWeb\Matomo;

use EstrategiaWeb\Admin\Settings_Page;
use EstrategiaWeb\Site\Settings;

defined( 'ABSPATH' ) || exit;

final class Admin_Fields {

	/** On Settings_Page::HOOK: the Analytics tab and this section. */
	public static function register(): void {
		Settings_Page::add_tab( 'analytics', __( 'Analytics', 'estrategia-web' ), 20 );
		Settings_Page::add_section(
			'analytics',
			array(
				'id'       => 'matomo',
				'priority' => 20, // After Google tags (10).
				'options'  => array( Settings::OPTION ),
				'render'   => array( self::class, 'render' ),
			)
		);
	}

	public static function render(): void {
		$data = Settings::all();
		$name = static fn( string $key ): string => Settings::OPTION . '[' . $key . ']';
		?>
		<?php // 0.3.0. What each mode does and why: header of modules/matomo/includes/class-matomo.php. ?>
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
