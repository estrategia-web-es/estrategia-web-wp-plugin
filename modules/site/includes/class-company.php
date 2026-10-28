<?php
/**
 * Read a company detail by its contract KEY: the one used by the Block
 * Bindings source `estrategia-web-site/company`, the editor and any theme.
 *
 * Keys: those of Settings::DEFAULTS (minus enabled_types, allow_svg and
 * rewrite_slugs, which are options and not data: NOT_DATA), `social.<network>` with a dot, and the DERIVED ones below.
 *
 * How to maintain:
 * - Who and when: whoever adds a key, in the same commit as its test
 *   (tests/bindings.test.mjs) and its row in the README.
 * - A new derived key: DERIVED + value() + labels() (+ Links if it is a link
 *   of a new kind) + README. assets/editor.js is NOT touched: it reads the
 *   keys from keys() and labels(). It is contract: it is not renamed. The last
 *   one in, `phone_secondary_href` (0.2.0), is the example.
 * - A new key also shows up ON ITS OWN in the `[estrategia_web_company]` shortcode
 *   (0.4.0, includes/class-shortcode.php), which reads value().
 * - The data of ONE location does not go here: it belongs to the `estrategia-web-site/location`
 *   source (includes/class-location.php).
 * - Edge case: a key that does not exist («phone_mobile») returns '' and not
 *   an error: a wrongly bound block shows empty on the site and in the editor
 *   shows the key name, which is where it gets noticed.
 * - `social.<network>` is an ALIAS UNTIL 2.0.0 (15-10-2026): the six fixed
 *   fields are no longer edited in «Company data»; the networks are the free
 *   list of the social module (estrategia_web_social_links). With that module
 *   on, value('social.facebook') is the link of the FIRST network in the list
 *   with the Facebook icon (Social\Links::url_for); with it off, the old value
 *   still saved. It is kept alive so as not to break themes that bind it. In
 *   2.0.0 it leaves keys(), labels() and this class. A new theme does not use
 *   it: it places the estrategia-web/social-links block.
 * - The trap: for a theme, `Company::value()` is the public PHP API; reading
 *   `get_option('estrategia_web_site')` directly skips the
 *   `estrategia_web_site_settings` filter and the derived keys.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Company {

	public const DERIVED = array( 'phone_href', 'phone_secondary_href', 'email_href', 'whatsapp_href' );

	/**
	 * Keys that are plugin options, not data that is shown. A new option in
	 * Settings::DEFAULTS goes in here, or it shows up as bindable data in the
	 * editor (the Matomo ones, 0.3.0, are the example).
	 */
	private const NOT_DATA = array( 'enabled_types', 'allow_svg', 'social', 'rewrite_slugs', 'matomo_url', 'matomo_site_id', 'matomo_mode', 'hours' );

	/** Country code for phone numbers written without one. */
	public static function country_code(): string {
		return (string) apply_filters( 'estrategia_web_site_default_country_code', '34' );
	}

	/** @return string[] All valid keys, in screen order. */
	public static function keys(): array {
		$keys = array();
		foreach ( array_keys( Settings::DEFAULTS ) as $key ) {
			if ( 'social' === $key ) {
				foreach ( Settings::SOCIAL as $network ) {
					$keys[] = 'social.' . $network;
				}
			} elseif ( ! in_array( $key, self::NOT_DATA, true ) ) {
				$keys[] = $key;
			}
		}
		return array_merge( $keys, self::DERIVED );
	}

	/** The value as plain text (unescaped). '' if the key does not exist or is empty. */
	public static function value( string $key ): string {
		$data = Settings::all();
		switch ( $key ) {
			case 'phone_href':
				return Links::tel( (string) $data['phone'], self::country_code() );
			case 'phone_secondary_href':
				return Links::tel( (string) $data['phone_secondary'], self::country_code() );
			case 'email_href':
				return Links::mailto( (string) $data['email'] );
			case 'whatsapp_href':
				return Links::whatsapp( (string) $data['whatsapp'], (string) $data['whatsapp_message'], self::country_code() );
			case 'schedule':
				return self::schedule( $data );
		}
		if ( str_starts_with( $key, 'social.' ) ) {
			$network = substr( $key, 7 );
			if ( ! in_array( $network, Settings::SOCIAL, true ) ) {
				return '';
			}
			// Alias until 2.0.0 (see the header): from the social module's list if it is on.
			if ( class_exists( \EstrategiaWeb\Social\Links::class ) ) {
				return \EstrategiaWeb\Social\Links::url_for( $network );
			}
			return (string) ( $data['social'][ $network ] ?? '' );
		}
		if ( in_array( $key, self::NOT_DATA, true ) || ! array_key_exists( $key, Settings::DEFAULTS ) ) {
			return '';
		}
		return (string) $data[ $key ];
	}

	/**
	 * «schedule», the opening hours as the page shows them (2.0.0): DERIVED
	 * from «hours» (day by day) with the location's format for the request's
	 * language (content-types' Location_Data / Location_Fields). While a site
	 * has the old free text and no «hours», the old text.
	 */
	private static function schedule( array $data ): string {
		$hours = (string) ( $data['hours'] ?? '' );
		if ( '' === $hours ) {
			return (string) ( $data['schedule'] ?? '' );
		}
		if ( ! class_exists( \EstrategiaWeb\ContentTypes\Location_Data::class ) ) {
			Settings::hours_class();
			require_once dirname( __DIR__, 2 ) . '/content-types/includes/class-location-data.php';
		}
		return class_exists( \EstrategiaWeb\ContentTypes\Location_Fields::class )
			? \EstrategiaWeb\ContentTypes\Location_Fields::company_hours_text( $hours )
			: \EstrategiaWeb\ContentTypes\Location_Data::hours_text( $hours, \EstrategiaWeb\ContentTypes\Location_Data::default_format( determine_locale() ) );
	}

	/**
	 * Human-readable name of each key: used by the settings screen and by the
	 * source's field list in the editor. @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'company_name'     => __( 'Company name', 'estrategia-web' ),
			'legal_name'       => __( 'Legal name', 'estrategia-web' ),
			'tax_id'           => __( 'Tax ID', 'estrategia-web' ),
			'registry'         => __( 'Company registry', 'estrategia-web' ),
			'email'            => __( 'Email', 'estrategia-web' ),
			'phone'            => __( 'Phone', 'estrategia-web' ),
			'phone_secondary'  => __( 'Secondary phone', 'estrategia-web' ),
			'whatsapp'         => __( 'WhatsApp', 'estrategia-web' ),
			'whatsapp_message' => __( 'WhatsApp message', 'estrategia-web' ),
			'address'          => __( 'Address', 'estrategia-web' ),
			'schedule'         => __( 'Opening hours', 'estrategia-web' ),
			'map_url'          => __( 'Map link', 'estrategia-web' ),
			'social.facebook'  => __( 'Facebook', 'estrategia-web' ),
			'social.instagram' => __( 'Instagram', 'estrategia-web' ),
			'social.linkedin'  => __( 'LinkedIn', 'estrategia-web' ),
			'social.x'         => __( 'X (Twitter)', 'estrategia-web' ),
			'social.youtube'   => __( 'YouTube', 'estrategia-web' ),
			'social.tiktok'    => __( 'TikTok', 'estrategia-web' ),
			'phone_href'       => __( 'Phone (link)', 'estrategia-web' ),
			'phone_secondary_href' => __( 'Secondary phone (link)', 'estrategia-web' ),
			'email_href'      => __( 'Email (link)', 'estrategia-web' ),
			'whatsapp_href'    => __( 'WhatsApp (link)', 'estrategia-web' ),
		);
	}

	/** All the data by key: what the editor receives. @return array<string,string> */
	public static function values(): array {
		$out = array();
		foreach ( self::keys() as $key ) {
			$out[ $key ] = self::value( $key );
		}
		return $out;
	}
}
