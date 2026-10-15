<?php
/**
 * «Company details» screen (own menu, manage_options). It saves through
 * options.php with the Settings API: the sanitising is the same as through
 * REST (Settings::sanitize), so nothing is validated here.
 *
 * How to maintain:
 * - A new detail: its row in render() with the name `estrategia_web_site[key]`.
 *   Checkboxes carry a hidden field in front so that «unchecked» arrives
 *   (Settings::sanitize merges with what is saved: whatever does not arrive
 *   does not change).
 * - Edge case: empty enabled_types. It arrives as [''] thanks to the hidden
 *   field, and sanitize turns it into []. Without the hidden field it would not
 *   arrive and the last type could not be disabled.
 * - The trap: the settings_fields() group has to be the same as the first
 *   argument of register_setting ('estrategia_web_site'), or options.php
 *   answers «the option is not in the allowed list».
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

				<?php
				// The social networks no longer go here (15-10-2026): they are the social module's list.
				// The screen does not send `social` and sanitize() keeps what is saved.
				if ( class_exists( \EstrategiaWeb\Social\Admin::class ) ) :
					?>
					<h2><?php esc_html_e( 'Social networks', 'estrategia-web' ); ?></h2>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \EstrategiaWeb\Social\Admin::PAGE ) ); ?>"><?php esc_html_e( 'Social networks are edited in Estrategia Web › Social networks.', 'estrategia-web' ); ?></a></p>
				<?php endif; ?>

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
				 * Sections of OTHER modules that save into this same option
				 * (1.1.0: Matomo, modules/matomo/includes/class-admin-fields.php).
				 * They go inside the form, before Save; their fields are named
				 * estrategia_web_site[key] and Settings sanitises them.
				 *
				 * @param array    $data Settings::all().
				 * @param callable $name Key → field name.
				 */
				do_action( 'estrategia_web_site_settings_sections', $data, $name );
				?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
