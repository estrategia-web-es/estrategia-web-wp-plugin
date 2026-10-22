<?php
/**
 * «Type of business» on the «Company details» screen: the option
 * estrategia_web_local_business_type, one of Types::ALL (LocalBusiness by
 * default). It is the company's; a location can say otherwise with its own
 * field (Data::location_node()).
 *
 * Why its OWN option and not a key of estrategia_web_site (as Matomo did):
 * that option is the contract of the themes and the content loads, with a
 * REST schema that returns null for the WHOLE option if one value is off
 * (modules/site/includes/class-settings.php, «The trap»); a list of 68
 * types that only grows is safer on its own. It is registered in the SAME
 * settings group (estrategia_web_site), so the screen's Save stores it with
 * the rest, and with show_in_rest a content load sets it through
 * /wp/v2/settings.
 *
 * How to maintain:
 * - Who and when: whoever changes what can be chosen — the list is Types.
 * - Boundary case, with a name: the screen saved with this module OFF. The
 *   field is not there and the setting is not registered, so options.php
 *   does not touch it: the type chosen before stays.
 * - The trap: options.php saves every option of the group, and one not in
 *   the form arrives as null. sanitize() keeps the stored value for null
 *   (and for anything outside the list), never «LocalBusiness».
 */

namespace EstrategiaWeb\LocalBusiness;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'estrategia_web_site_settings_sections', array( self::class, 'render' ), 20 );
	}

	public static function register(): void {
		register_setting(
			'estrategia_web_site',
			Data::OPTION,
			array(
				'type'              => 'string',
				'label'             => __( 'Type of business', 'estrategia-web' ),
				'description'       => __( 'The schema.org type search engines are told the company is.', 'estrategia-web' ),
				'default'           => Types::DEFAULT,
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => Types::ALL,
					),
				),
			)
		);
	}

	/** A type of the list; otherwise (null from options.php, a typo over WP-CLI) what was stored. */
	public static function sanitize( $value ): string {
		$valid = Types::valid( $value );
		if ( null !== $valid ) {
			return $valid;
		}
		if ( null !== $value && function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'estrategia_web_site', 'estrategia_web_local_business_type', __( 'That type of business is not in the list. The previous one has been kept.', 'estrategia-web' ) );
		}
		return Data::business_type();
	}

	public static function render(): void {
		$current = Data::business_type();
		?>
		<h2><?php esc_html_e( 'Search engines (schema.org)', 'estrategia-web' ); ?></h2>
		<p><?php esc_html_e( 'What Google is told about the company and its locations (structured data). The locations\' addresses, hours and coordinates are filled in on each location.', 'estrategia-web' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="estrategia-web-local-business-type"><?php esc_html_e( 'Type of business', 'estrategia-web' ); ?></label></th>
				<td>
					<select id="estrategia-web-local-business-type" name="<?php echo esc_attr( Data::OPTION ); ?>" aria-describedby="estrategia-web-local-business-type-help">
						<?php foreach ( Types::ALL as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $current, $type ); ?>><?php echo esc_html( $type ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description" id="estrategia-web-local-business-type-help"><?php esc_html_e( 'The schema.org name, as Google reads it (e.g. DryCleaningOrLaundry for a laundry, Restaurant, Store). LocalBusiness if none fits.', 'estrategia-web' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
