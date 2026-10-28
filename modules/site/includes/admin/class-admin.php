<?php
/**
 * «Estrategia Web › Company details» screen (manage_options), the first item
 * of the menu: the top-level item and this submenu are registered by the core
 * (includes/admin/class-menu.php), which calls render(). It saves through
 * options.php with the Settings API: the sanitising is the same as through
 * REST (Settings::sanitize), so nothing is validated here.
 *
 * 1.7.0: ONLY the Company and Contact sections, plus what other modules hang
 * on the estrategia_web_site_settings_sections hook (today local-business's
 * «Type of business», which is company data). What left, and where it is:
 * - Social networks: their own page (social module). The screen used to link
 *   to it; the menu does now.
 * - Content types: their own page (content-types module). Same.
 * - Media (allow_svg): Settings › Media, section media_section() below,
 *   registered on the Settings page's registry. SAME option and key.
 * - Matomo: Settings › Analytics (matomo module). SAME option and keys.
 * The option is still ONE, saved from three places: each one sends only its
 * keys and Settings::sanitize() merges, so saving here does not touch
 * allow_svg or matomo_* (tests/settings-page.test.mjs, level 2).
 *
 * How to maintain:
 * - A new detail: its row in render() with the name `estrategia_web_site[key]`.
 *   Checkboxes carry a hidden field in front so that «unchecked» arrives
 *   (Settings::sanitize merges with what is saved: whatever does not arrive
 *   does not change).
 * - enabled_types is NOT on the screen since 17-10-2026: the content types are
 *   switched on and off in «Estrategia Web → Content types» (content-types
 *   module); the key stays in the option, writable over REST, as a switch
 *   (modules/content-types/includes/class-site-types.php). The screen does
 *   not send it, and sanitize() keeps what is stored.
 * - The trap: the settings_fields() group has to be the same as the first
 *   argument of register_setting ('estrategia_web_site'), or options.php
 *   answers «the option is not in the allowed list».
 */

namespace EstrategiaWeb\Site;

use EstrategiaWeb\Admin\Menu;
use EstrategiaWeb\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = Menu::PARENT; // 'estrategia-web-site', the same URL as up to 1.6.1.

	public static function boot(): void {
		add_action( Settings_Page::HOOK, array( self::class, 'settings_sections' ) );
	}

	/** Settings › Media: the SVG switch (the uploads module adds its own section to the same tab). */
	public static function settings_sections(): void {
		Settings_Page::add_tab( 'media', __( 'Media', 'estrategia-web' ), 30 );
		Settings_Page::add_section(
			'media',
			array(
				'id'       => 'svg',
				'priority' => 10,
				'options'  => array( Settings::OPTION ),
				'render'   => array( self::class, 'media_section' ),
			)
		);
	}

	/** The SVG row. A hidden 0 in front so that «unchecked» arrives (sanitize() merges). */
	public static function media_section(): void {
		$data = Settings::all();
		?>
		<?php \EstrategiaWeb\Admin\Admin_UI::box_open( __( 'SVG images', 'estrategia-web' ), 'ew-box-svg' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'SVG uploads', 'estrategia-web' ); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[allow_svg]' ); ?>" value="0">
					<label>
						<input type="checkbox" id="estrategia-web-site-allow_svg" name="<?php echo esc_attr( Settings::OPTION . '[allow_svg]' ); ?>" value="1" <?php checked( (bool) $data['allow_svg'] ); ?>>
						<?php esc_html_e( 'Allow SVG uploads (administrators only; files are sanitized)', 'estrategia-web' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
		\EstrategiaWeb\Admin\Admin_UI::box_close();
	}

	/**
	 * «Opening hours» (2.0.0): day by day — the «hours» field type, one line
	 * per day as on a location's meta box — and, under it, the sentence the
	 * site shows (Company::value( 'schedule' ), derived). A site WITH
	 * locations does not get the field: each location has its own hours, and
	 * two places to write them is what 2.0.0 removed. A site that still has
	 * the old free text sees it, read-only, until its migration converts it.
	 */
	private static function hours_row( array $data, string $label ): void {
		$type = Type_Names::name( 'location' );
		if ( post_type_exists( $type ) && (int) ( wp_count_posts( $type )->publish ?? 0 ) > 0 ) {
			printf( '<tr><th scope="row">%s</th><td><p class="description">%s</p></td></tr>', esc_html( $label ), esc_html__( 'This site has locations: the opening hours go in each location.', 'estrategia-web' ) );
			return;
		}
		$hours = Settings::hours_class();
		$days  = array( 'mo' => 1, 'tu' => 2, 'we' => 3, 'th' => 4, 'fr' => 5, 'sa' => 6, 'su' => 0 );
		global $wp_locale;
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><fieldset id="estrategia-web-site-hours"><legend class="screen-reader-text">' . esc_html( $label ) . '</legend>';
		foreach ( $days as $day => $n ) {
			printf(
				'<p><label for="estrategia-web-site-hours-%1$s" style="display:inline-block;min-width:8em">%2$s</label> <input type="text" class="regular-text" id="estrategia-web-site-hours-%1$s" name="%3$s[hours][%1$s]" value="%4$s" placeholder="09:00-14:00, 16:00-20:00"></p>',
				esc_attr( $day ),
				esc_html( $wp_locale ? $wp_locale->get_weekday( $n ) : $day ),
				esc_attr( Settings::OPTION ),
				esc_attr( $hours::to_line( (string) $data['hours'], $day ) )
			);
		}
		echo '<p class="description">' . esc_html__( 'One line per day, e.g. 08:00-14:00, 16:00-20:00. Empty: closed.', 'estrategia-web' ) . '</p>';
		$shown = Company::value( 'schedule' );
		if ( '' !== $shown ) {
			echo '<p><strong>' . esc_html__( 'Shown on the site (as of the last save):', 'estrategia-web' ) . '</strong><br>' . nl2br( esc_html( $shown ) ) . '</p>';
		}
		echo '</fieldset></td></tr>';
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
		<div class="wrap ew-screen estrategia-web-company">
			<?php \EstrategiaWeb\Admin\Admin_UI::header( __( 'Company details', 'estrategia-web' ), __( 'These details appear wherever a block is linked to them (Block Bindings, source «Company details»). Change them here and they change across the whole site.', 'estrategia-web' ) ); ?>
			<?php settings_errors( Settings::OPTION ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'estrategia_web_site' ); ?>

				<?php \EstrategiaWeb\Admin\Admin_UI::box_open( __( 'Company', 'estrategia-web' ), 'ew-box-company' ); ?>
				<table class="form-table" role="presentation">
					<?php
					$text( 'company_name' );
					$text( 'legal_name' );
					$text( 'tax_id' );
					$text( 'registry', 'text', __( 'As it appears in the legal notice, without «Registered in». Example: Registro Mercantil de Mallorca, tomo 1234, folio 56, hoja PM-7890, inscripción 1.ª', 'estrategia-web' ) );
					$area( 'address' );
					self::hours_row( $data, $labels['schedule'] );
					$text( 'map_url', 'url' );
					?>
				</table>
				<?php \EstrategiaWeb\Admin\Admin_UI::box_close(); ?>

				<?php \EstrategiaWeb\Admin\Admin_UI::box_open( __( 'Contact', 'estrategia-web' ), 'ew-box-contact' ); ?>
				<table class="form-table" role="presentation">
					<?php
					$text( 'email', 'email' );
					$text( 'phone', 'tel' );
					$text( 'phone_secondary', 'tel' );
					$text( 'whatsapp', 'tel', __( 'International format, digits only, without +. Example: 34600000000.', 'estrategia-web' ) );
					$text( 'whatsapp_message', 'text', __( 'Text that appears already written when the chat opens.', 'estrategia-web' ) );
					?>
				</table>
				<?php \EstrategiaWeb\Admin\Admin_UI::box_close(); ?>

				<?php
				/**
				 * Sections of OTHER modules that belong to the COMPANY details
				 * (today local-business's «Type of business», its own option in
				 * this settings group). They go inside the form, before Save, each
				 * in its own box (Admin_UI::box_open/close, since 2.0.1). A
				 * site SETTING (Matomo since 1.7.0) does not go here: it is a
				 * section of the Settings page (includes/admin/class-settings-page.php).
				 * Fields named estrategia_web_site[key] are sanitised by Settings.
				 *
				 * @param array    $data Settings::all().
				 * @param callable $name Key → field name.
				 */
				do_action( 'estrategia_web_site_settings_sections', $data, $name );
				?>

				<?php \EstrategiaWeb\Admin\Admin_UI::save(); ?>
			</form>
		</div>
		<?php
	}
}
