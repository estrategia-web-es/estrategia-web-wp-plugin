<?php
/**
 * The company data: ONE option, `estrategia_web_site` (array), registered with
 * the Settings API and exposed over REST (/wp/v2/settings) with its schema.
 *
 * It is CONTRACT: the parent theme, the child themes and the content load read
 * and write these names (README, «The contract»). Renaming a key is a major
 * version.
 *
 * How it is saved (and why this way):
 * - It is MERGED with what is already there: a REST PUT with only
 *   {"phone": "…"} changes the phone and leaves the rest. It is what the
 *   content load needs (filling in parts). The settings screen always sends
 *   every key (with hidden fields for the checkboxes), so an unchecked box
 *   does get saved as «no».
 * - Whatever fails validation is NOT saved and the previous value stays: a
 *   mistyped email does not wipe out the good one. The screen shows a notice;
 *   over REST the response returns the value that stayed, which is how you
 *   notice.
 *
 * How to maintain:
 * - A new key goes into DEFAULTS, schema(), sanitize(), the screen (Admin),
 *   the editor list (assets/editor.js) and the README, in the same commit. A
 *   detail that is shown also goes into WPML_TRANSLATE or WPML_SAME (and, if
 *   translated, into wpml-config.xml's <admin-texts>). If
 *   a test needs it, also into tests/lib.mjs. If it is an OPTION and not a
 *   detail that is shown (like the Matomo ones), also into Company::NOT_DATA.
 * - Edge case: a detail only ONE site has (a clinic's health registration
 *   number). It does not go in: it goes to the child theme. What goes in is
 *   what any corporate site would have.
 * - Objects (`social`, `rewrite_slugs` since 0.2.0) are also merged BY KEY:
 *   sending {"rewrite_slugs":{"client":"cliente"}} does not wipe the slug set
 *   for `client_sector`. And they are always saved with all their keys (the
 *   unset ones, ''), so that REST returns an object and not an empty [].
 * - `social` (the six fixed social network fields) is NOT on the screen since
 *   15-10-2026: the networks are the social module's list, which copies these
 *   values once (Social\Links::migrate). It is still accepted and saved over
 *   REST so as not to lose data or break old content loads, and
 *   Company::value('social.<network>') is an alias until 2.0.0, when it leaves
 *   the contract (see class-company.php).
 * - `rewrite_slugs` is NOT on the screen, on purpose: it changes published
 *   URLs and is set by whoever builds the site (REST, content load). The
 *   screen does not send it, and the merge keeps it when the screen is saved.
 * - `rewrite_slugs` edge case: a name that does not belong to the plugin
 *   («services») gives a 400 over REST (additionalProperties); through
 *   `wp option update` it is silently ignored.
 * - Legal data (0.4.0): `legal_name` (registered company name), `tax_id`
 *   (Spanish NIF/CIF), `address` (registered address) and `registry`.
 *   `registry` is ONE free text and not volume/folio/sheet/entry separately,
 *   on purpose: the legal notice writes it as a sentence, and not every
 *   company is in the Mercantile Registry (a sole trader has none; a
 *   cooperative goes to another registry). It is written without «Registered
 *   in», which the sentence adds. Edge case: a registered office different
 *   from the shop address. Today it has no key of its own; if several sites
 *   ask for it, `legal_address` goes in (minor version).
 * - Matomo (0.3.0): `matomo_url` is ALWAYS saved ending in «/» and without the
 *   «matomo.php»/«matomo.js» that sometimes gets pasted from the tracking
 *   code; `matomo_site_id` is an integer (0 = not configured); `matomo_mode`
 *   is one of MATOMO_MODES (another value over REST → 400; from the screen,
 *   the previous one stays). What each mode does, in the header of
 *   modules/matomo/includes/class-matomo.php (1.1.0: Matomo is its own
 *   module, but its keys and their sanitising stay HERE, in this option, so as
 *   not to migrate data).
 * - The trap: the REST settings controller returns null for the WHOLE option
 *   if what is saved does not match the schema (a different type, an
 *   enabled_types key that is no longer in the enum). That is why sanitize()
 *   always leaves the exact schema types (the Matomo id, int; never "3"), and
 *   removing a value from the enabled_types or matomo_mode enum is a major
 *   version.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'estrategia_web_site';

	/**
	 * The content types «enabled_types» can switch on. Since 17-10-2026 they
	 * are registered by the content-types module from its templates, and this
	 * key is a switch read there (class-site-types.php): adding a key adds or
	 * switches on its definition, removing it switches it off.
	 */
	public const TYPES = array( 'client', 'testimonial', 'location', 'project', 'portfolio' );

	public const SOCIAL = array( 'facebook', 'instagram', 'linkedin', 'x', 'youtube', 'tiktok' );

	/** Matomo modes (0.3.0). The first one is the default. See class-matomo.php. */
	public const MATOMO_MODES = array( 'cookieless', 'hybrid' );

	/**
	 * The names `rewrite_slugs` accepts (0.2.0): the plugin's types and
	 * taxonomies. A new taxonomy in Content_Types goes in here too, or its slug
	 * cannot be set.
	 */
	public const REWRITE_NAMES = array( 'client', 'client_sector', 'testimonial', 'location', 'project', 'project_category', 'portfolio', 'portfolio_category' );

	/** Single-line text fields. */
	private const TEXT = array( 'company_name', 'legal_name', 'tax_id', 'registry', 'phone', 'phone_secondary', 'whatsapp_message' );

	/** Multi-line fields (line breaks are kept and rendered as <br>). */
	private const TEXTAREA = array( 'address', 'schedule' );

	/**
	 * WPML String Translation (wpml-config.xml, <admin-texts>): the details
	 * that are written in a language and are offered for translation, and
	 * the ones that are the same in every language. Every data key of
	 * DEFAULTS is in one of the two; the criterion and the edge case are in
	 * the XML, and modules/site/tests/wpml-config.test.mjs fails if the XML
	 * says otherwise or a key is left undecided.
	 */
	public const WPML_TRANSLATE = array( 'registry', 'whatsapp_message', 'address', 'schedule' );

	public const WPML_SAME = array( 'company_name', 'legal_name', 'tax_id', 'email', 'phone', 'phone_secondary', 'whatsapp', 'map_url' );

	public const DEFAULTS = array(
		'company_name'     => '',
		'legal_name'       => '',
		'tax_id'           => '',
		// 0.4.0. The registration as written in the legal notice (see «Legal data»).
		'registry'         => '',
		'email'            => '',
		'phone'            => '',
		'phone_secondary'  => '',
		'whatsapp'         => '',
		'whatsapp_message' => '',
		'address'          => '',
		// Up to 1.7.0 the opening hours as a free text; since 1.8.0 the text is DERIVED from «hours» (Company::value()) and this is only read while a site has not migrated it (wp estrategia-web migrate-location-data).
		'schedule'         => '',
		// 1.8.0. Opening hours day by day, the «hours» field type's JSON (content-types' class-hours.php). Hidden when the site has locations: each has its own.
		'hours'            => '',
		'map_url'          => '',
		'social'           => array(
			'facebook'  => '',
			'instagram' => '',
			'linkedin'  => '',
			'x'         => '',
			'youtube'   => '',
			'tiktok'    => '',
		),
		'enabled_types'    => array(),
		'allow_svg'        => false,
		// 0.2.0. Empty = the usual translated slug. Since 17-10-2026 only read when a type's definition is first stored (Site_Types::current_slug()); then the definition's slug rules.
		'rewrite_slugs'    => array(
			'client'             => '',
			'client_sector'      => '',
			'testimonial'        => '',
			'location'           => '',
			'project'            => '',
			'project_category'   => '',
			'portfolio'          => '',
			'portfolio_category' => '',
		),
		// 0.3.0. Without URL or without id nothing is printed (Matomo::config()).
		'matomo_url'       => '',
		'matomo_site_id'   => 0,
		'matomo_mode'      => 'cookieless',
	);

	public static function register(): void {
		register_setting(
			'estrategia_web_site',
			self::OPTION,
			array(
				'type'              => 'object',
				'label'             => __( 'Company details', 'estrategia-web' ),
				'description'       => __( 'Company details and site options of the Estrategia Web Site plugin.', 'estrategia-web' ),
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array( 'schema' => self::schema() ),
			)
		);
	}

	/** REST schema. No `format: email` on purpose: see «How it is saved». */
	public static function schema(): array {
		$string = array( 'type' => 'string' );
		$social = array();
		foreach ( self::SOCIAL as $network ) {
			$social[ $network ] = $string;
		}
		$slugs = array();
		foreach ( self::REWRITE_NAMES as $name ) {
			$slugs[ $name ] = $string;
		}
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'company_name'     => $string,
				'legal_name'       => $string,
				'tax_id'           => $string,
				'registry'         => $string,
				'email'            => $string,
				'phone'            => $string,
				'phone_secondary'  => $string,
				'whatsapp'         => $string,
				'whatsapp_message' => $string,
				'address'          => $string,
				'schedule'         => $string,
				// The stored JSON text, or day → «08:00-14:00, 16:00-20:00» (a line) or a list of ranges.
				'hours'            => array(
					'type'                 => array( 'string', 'object' ),
					'additionalProperties' => false,
					'properties'           => array_fill_keys( array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' ), array( 'type' => array( 'string', 'array' ), 'items' => array( 'type' => 'string' ) ) ),
				),
				'map_url'          => $string,
				'social'           => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $social,
				),
				'enabled_types'    => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => self::TYPES,
					),
				),
				'allow_svg'        => array( 'type' => 'boolean' ),
				'rewrite_slugs'    => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $slugs,
				),
				'matomo_url'       => $string,
				'matomo_site_id'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'matomo_mode'      => array(
					'type' => 'string',
					'enum' => self::MATOMO_MODES,
				),
			),
		);
	}

	/** @return array<string, mixed> Always with every key of DEFAULTS. */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$all    = array_merge( self::DEFAULTS, $stored );
		$all['social'] = array_merge( self::DEFAULTS['social'], is_array( $all['social'] ) ? $all['social'] : array() );
		$all['rewrite_slugs'] = array_merge( self::DEFAULTS['rewrite_slugs'], is_array( $all['rewrite_slugs'] ) ? $all['rewrite_slugs'] : array() );
		/**
		 * Lets a site set data from code (for example, a staging environment
		 * with another phone number).
		 *
		 * @param array $all Data already merged with the defaults.
		 */
		return (array) apply_filters( 'estrategia_web_site_settings', $all );
	}

	/**
	 * The «hours» rules live in the content-types module (class-hours.php,
	 * pure PHP); this core module loads the file itself, so it works with
	 * content-types switched off.
	 */
	public static function hours_class(): string {
		if ( ! class_exists( \EstrategiaWeb\ContentTypes\Hours::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/content-types/includes/class-hours.php';
		}
		return \EstrategiaWeb\ContentTypes\Hours::class;
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/** @return string[] */
	public static function enabled_types(): array {
		return array_values( array_intersect( self::TYPES, (array) self::get( 'enabled_types' ) ) );
	}

	/**
	 * Merges what was received with what is saved and validates each key.
	 * Called from the screen, from REST and from `wp option update`
	 * (sanitize_option).
	 */
	public static function sanitize( mixed $input ): array {
		$current = get_option( self::OPTION, array() );
		$out     = array_merge( self::DEFAULTS, is_array( $current ) ? $current : array() );
		$out['social'] = array_merge( self::DEFAULTS['social'], is_array( $out['social'] ) ? $out['social'] : array() );
		$out['rewrite_slugs'] = array_merge( self::DEFAULTS['rewrite_slugs'], is_array( $out['rewrite_slugs'] ) ? $out['rewrite_slugs'] : array() );
		$input   = is_array( $input ) ? $input : ( is_object( $input ) ? (array) $input : array() );

		foreach ( self::TEXT as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}
		foreach ( self::TEXTAREA as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = sanitize_textarea_field( (string) $input[ $key ] );
			}
		}
		if ( array_key_exists( 'hours', $input ) ) {
			$hours = $input['hours'];
			if ( is_object( $hours ) ) {
				$hours = (array) $hours;
			}
			if ( is_array( $hours ) && ! array_is_list( $hours ) ) {
				// The Company details screen sends one line per day («08:00-14:00, 16:00-20:00»).
				$hours = array_map( static fn( $line ) => is_string( $line ) ? self::hours_class()::from_line( $line ) : $line, $hours );
			}
			$out['hours'] = self::hours_class()::sanitize( $hours );
		}

		if ( array_key_exists( 'email', $input ) ) {
			$email = sanitize_email( (string) $input['email'] );
			if ( '' === trim( (string) $input['email'] ) ) {
				$out['email'] = '';
			} elseif ( is_email( $email ) && $email === trim( (string) $input['email'] ) ) {
				$out['email'] = $email;
			} else {
				self::error( 'email', __( 'The email address is not valid. The previous one has been kept.', 'estrategia-web' ) );
			}
		}

		if ( array_key_exists( 'whatsapp', $input ) ) {
			$raw = trim( (string) $input['whatsapp'] );
			if ( '' === $raw ) {
				$out['whatsapp'] = '';
			} else {
				$digits = Links::international( $raw, Company::country_code() );
				if ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) {
					$out['whatsapp'] = $digits;
				} else {
					self::error( 'whatsapp', __( 'The WhatsApp number is not valid (international format, digits only). The previous one has been kept.', 'estrategia-web' ) );
				}
			}
		}

		if ( array_key_exists( 'map_url', $input ) ) {
			$out['map_url'] = self::url( (string) $input['map_url'], 'map_url' ) ?? $out['map_url'];
		}

		if ( isset( $input['social'] ) && ( is_array( $input['social'] ) || is_object( $input['social'] ) ) ) {
			foreach ( (array) $input['social'] as $network => $url ) {
				if ( in_array( $network, self::SOCIAL, true ) ) {
					$out['social'][ $network ] = self::url( (string) $url, 'social_' . $network ) ?? $out['social'][ $network ];
				}
			}
		}

		if ( array_key_exists( 'enabled_types', $input ) ) {
			// The screen sends a hidden '' so that «none» arrives: it is discarded here.
			$out['enabled_types'] = array_values( array_intersect( self::TYPES, array_map( 'strval', (array) $input['enabled_types'] ) ) );
		}

		if ( array_key_exists( 'allow_svg', $input ) ) {
			$out['allow_svg'] = rest_sanitize_boolean( $input['allow_svg'] );
		}

		// By key, like social: sending one slug does not wipe the others. Outside
		// REST (which already gives a 400) an unknown name is ignored.
		if ( isset( $input['rewrite_slugs'] ) && ( is_array( $input['rewrite_slugs'] ) || is_object( $input['rewrite_slugs'] ) ) ) {
			foreach ( (array) $input['rewrite_slugs'] as $name => $slug ) {
				if ( in_array( $name, self::REWRITE_NAMES, true ) ) {
					$out['rewrite_slugs'][ $name ] = sanitize_title( (string) $slug );
				}
			}
		}

		if ( array_key_exists( 'matomo_url', $input ) ) {
			// What gets pasted from the tracking code («…/matomo.php») is accepted.
			$raw = preg_replace( '#(matomo|piwik)\.(php|js)$#i', '', trim( (string) $input['matomo_url'] ) );
			$url = self::url( (string) $raw, 'matomo_url' );
			if ( null !== $url ) {
				$out['matomo_url'] = '' === $url ? '' : trailingslashit( strtok( $url, '?#' ) );
			}
		}

		if ( array_key_exists( 'matomo_site_id', $input ) ) {
			$out['matomo_site_id'] = absint( $input['matomo_site_id'] );
		}

		if ( array_key_exists( 'matomo_mode', $input ) && in_array( $input['matomo_mode'], self::MATOMO_MODES, true ) ) {
			$out['matomo_mode'] = $input['matomo_mode'];
		}

		// Only the contract keys, with their exact types (see «The trap»).
		$out                  = array_intersect_key( $out, self::DEFAULTS );
		$out['social']        = array_map( 'strval', array_intersect_key( $out['social'], self::DEFAULTS['social'] ) );
		$out['rewrite_slugs'] = array_map( 'strval', array_intersect_key( $out['rewrite_slugs'], self::DEFAULTS['rewrite_slugs'] ) );
		$out['allow_svg']      = (bool) $out['allow_svg'];
		$out['matomo_url']     = (string) $out['matomo_url'];
		$out['matomo_site_id'] = absint( $out['matomo_site_id'] );
		$out['matomo_mode']    = in_array( $out['matomo_mode'], self::MATOMO_MODES, true ) ? $out['matomo_mode'] : self::MATOMO_MODES[0];
		return $out;
	}

	/** Clean http(s) URL, '' if it comes empty, or null if it is not valid (the previous one stays). */
	private static function url( string $raw, string $field ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$url = esc_url_raw( $raw, array( 'http', 'https' ) );
		// filter_var and not wp_http_validate_url(): that one resolves the domain's DNS.
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://[^/]+\.[^/]+#i', $url ) ) {
			/* translators: %s: the field name. */
			self::error( $field, sprintf( __( 'The address in «%s» is not a valid web address. The previous one has been kept.', 'estrategia-web' ), $field ) );
			return null;
		}
		return $url;
	}

	private static function error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'estrategia_web_site_' . $code, $message );
		}
	}
}
