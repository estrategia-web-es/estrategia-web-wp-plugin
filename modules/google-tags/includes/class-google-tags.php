<?php
namespace EstrategiaWeb\GoogleTags;

defined( 'ABSPATH' ) || exit;

/**
 * Google tags (GA4, Ads, Tag Manager) behind the cookie notice, with Consent
 * Mode v2.
 *
 * What is printed, and where: ONE inline script in the <head>, on wp_head with
 * priority 1 (before any other tag, Matomo's at 20 included), id
 * «estrategia-web-google-tags». Nothing if all three fields are empty or if
 * the request is not measured (dashboard, previews, users who can edit:
 * estrategia_web_google_tags_track filter, like Matomo). The script:
 * 1. Creates dataLayer and gtag() and sends gtag('consent', 'default', …) with
 *    EVERYTHING «denied» (except security_storage) BEFORE any tag exists.
 *    That is what Consent Mode v2 requires: the «default» has to come first.
 * 2. Listens to the notice (window.estrategiaWebConsent and the
 *    «estrategia-web:consent» and «estrategia-web:consent-ready» events: both
 *    channels, because of the load-order pitfall the notice describes) and on
 *    every choice sends gtag('consent', 'update', …) with what was accepted:
 *       analytics_storage                          ← statistics (Analytics)
 *       ad_storage, ad_user_data, ad_personalization ← marketing (Advertising)
 *       functionality_storage, personalization_storage ← preferences
 * 3. Loads Google's scripts (gtag/js?id=… with the GA4 and Ads config,
 *    gtm.js?id=…) depending on the MODE:
 *    - «basic» (default): ONLY once Analytics or Advertising has been
 *      accepted. Before that not a single request goes to Google: not even the
 *      visitor's IP. That is what the AEPD requires (no third parties without
 *      consent).
 *    - «advanced» (opt-in): they load as soon as the page opens, with
 *      everything «denied»; Google sets no cookies and sends cookieless pings
 *      that it later «models». Measurement improves, but those pings DO go out
 *      before consent, with the IP. Its legality in Spain is disputed (the
 *      AEPD has not endorsed it): only if the client decides so knowingly.
 * 4. If Analytics or Advertising is withdrawn with the page open: 'update' to
 *    denied and delete Google's first-party cookies for that category
 *    (_ga, _ga_*, _gid, _gat* / _gcl_*, _gac_*), on the domain and its
 *    parents. The already loaded Google script stays until the next page, but
 *    with consent denied it does not write.
 * 5. Form conversion (optional): with a «Conversion label» (AW-123/abc) and
 *    Advertising accepted, each successful submission of a form from the forms
 *    module («estrategia-web:form-sent» event) sends
 *    gtag('event', 'conversion', {send_to}). Two ways the event arrives:
 *    - JavaScript submission: view.js fires it when the server says OK. If
 *      Advertising is not accepted at that moment, it is not counted.
 *    - Submission WITHOUT JavaScript (native POST, 303 back with
 *      ?af_result=…): the form, rendered again with an OK result, prints a
 *      one-line script that fires it with detail.returned = true, ONCE per
 *      submission (modules/forms/includes/class-blocks.php, announce()). That
 *      script runs while the page is still being parsed, usually BEFORE the
 *      notice has said what was accepted; so a «returned» conversion WAITS
 *      (pending) and is sent the first time Advertising is granted on that
 *      page, by the stored choice or by a click. If it is never granted,
 *      nothing is sent.
 *    Only the form id travels, never what was typed.
 *
 * Cookies: it declares to the cookie registry (estrategia_web_cookie_services
 * filter, priority 99) «google-analytics» with a G- id and «google-ads» with
 * an AW- id; their cookies are in modules/consent/includes/class-cookie-services.php.
 * Tag Manager alone declares nothing (named edge case there).
 *
 * Notice categories: the module DECLARES with the
 * estrategia_web_consent_categories filter (priority 99, appending, like
 * Matomo) statistics if there is GA4, marketing if there is Ads, and both with
 * GTM. It depends only on the CONFIGURATION, never on who visits: the notice
 * revision has to be the same for everyone (even more so with page cache).
 *
 * No GTM <noscript>: an iframe to Google without JavaScript cannot wait for
 * consent. Visitors browsing without JS are not measured.
 *
 * How to maintain:
 * - Who and when: whoever touches analytics. Tests in
 *   modules/google-tags/tests/ (google-tags.test.mjs level 2,
 *   google-tags-browser.test.mjs level 3).
 * - A new field: FIELDS (pattern), defaults(), sanitize(), the screen and
 *   config_for_js(). The patterns also go into the REST schema (400 if they
 *   do not match).
 * - Edge case: the client already has Site Kit or another plugin that prints
 *   gtag. There would be two dataLayer/gtag and two «default»: use ONE of the
 *   two. This one does not detect the other.
 * - The pitfall: gtag() has to be a GLOBAL function that pushes `arguments`
 *   (not an array): gtag.js only understands Arguments objects in dataLayer.
 *   A gtag = (...a) => dataLayer.push(a) looks the same and Google IGNORES it
 *   without an error. That is why the script is Google's verbatim, in ES5.
 */

final class Google_Tags {

	public const OPTION = 'estrategia_web_google_tags';

	/** Field => pattern (empty is always valid: «do not load»). */
	public const FIELDS = array(
		'ga4'            => '^G-[A-Z0-9]{4,20}$',
		'ads'            => '^AW-[0-9]{6,15}$',
		'ads_form_label' => '^AW-[0-9]{6,15}/[A-Za-z0-9_-]{4,40}$',
		'gtm'            => '^GTM-[A-Z0-9]{4,12}$',
	);

	public const MODES = array( 'basic', 'advanced' );

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'wp_head', array( self::class, 'print' ), 1 );
		add_filter( 'estrategia_web_consent_categories', array( self::class, 'consent_categories' ), 99 );
		add_filter( 'estrategia_web_cookie_services', array( self::class, 'cookie_services' ), 99 );
	}

	/** Third-party services for the cookie registry (see the header). From the configuration only. */
	public static function cookie_services( mixed $services ): array {
		$services = (array) $services;
		$s        = self::settings();
		if ( '' !== $s['ga4'] ) {
			$services[] = 'google-analytics';
		}
		if ( '' !== $s['ads'] ) {
			$services[] = 'google-ads';
		}
		return array_values( array_unique( $services ) );
	}

	public static function defaults(): array {
		return array(
			'ga4'            => '',
			'ads'            => '',
			'ads_form_label' => '',
			'gtm'            => '',
			'mode'           => 'basic',
		);
	}

	public static function register(): void {
		$properties = array(
			'mode' => array(
				'type' => 'string',
				'enum' => self::MODES,
			),
		);
		foreach ( self::FIELDS as $field => $pattern ) {
			$properties[ $field ] = array(
				'type'    => 'string',
				'pattern' => '(' . $pattern . ')|^$',
			);
		}
		register_setting(
			'estrategia_web_google_tags',
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'Google tags: GA4, Ads and Tag Manager IDs and the loading mode.', 'estrategia-web' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => $properties,
					),
				),
			)
		);
	}

	/** The saved values, complete and clean. */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	/** Merges with the saved values and validates. An id that does not match is discarded (and the screen says so). */
	public static function sanitize( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$saved = get_option( self::OPTION, array() );
		$out   = self::clean( array_merge( is_array( $saved ) ? $saved : array(), $value ) );
		foreach ( array_keys( self::FIELDS ) as $field ) {
			$raw = strtoupper( trim( (string) ( $value[ $field ] ?? '' ) ) );
			if ( 'ads_form_label' === $field ) {
				$raw = trim( (string) ( $value[ $field ] ?? '' ) ); // The label is case-sensitive.
			}
			if ( '' !== $raw && $out[ $field ] !== $raw && function_exists( 'add_settings_error' ) ) {
				/* translators: %s: the value that was not saved. */
				add_settings_error( self::OPTION, 'estrategia-web-google-' . $field, sprintf( __( '«%s» does not look like a valid ID and was not saved.', 'estrategia-web' ), $raw ) );
			}
		}
		return $out;
	}

	/** Valid values only: whatever does not match its pattern becomes empty. */
	public static function clean( array $value ): array {
		$out = self::defaults();
		foreach ( self::FIELDS as $field => $pattern ) {
			$raw = trim( (string) ( $value[ $field ] ?? '' ) );
			if ( 'ads_form_label' !== $field ) {
				$raw = strtoupper( $raw );
			}
			$out[ $field ] = preg_match( '#' . $pattern . '#', $raw ) ? $raw : '';
		}
		$out['mode'] = in_array( $value['mode'] ?? '', self::MODES, true ) ? $value['mode'] : 'basic';
		return $out;
	}

	/** Whether there is anything to load. */
	public static function configured( ?array $settings = null ): bool {
		$s = $settings ?? self::settings();
		return '' !== $s['ga4'] || '' !== $s['ads'] || '' !== $s['gtm'];
	}

	/** Categories each tag requires (see the header). From the configuration only. */
	public static function consent_categories( mixed $categories ): array {
		$categories = (array) $categories;
		$s          = self::settings();
		$needed     = array();
		if ( '' !== $s['ga4'] || '' !== $s['gtm'] ) {
			$needed[] = 'statistics';
		}
		if ( '' !== $s['ads'] || '' !== $s['gtm'] ) {
			$needed[] = 'marketing';
		}
		return array_values( array_unique( array_merge( $categories, $needed ) ) );
	}

	/** Whether this request is measured (like Matomo). */
	public static function should_track(): bool {
		$track = ! is_admin() && ! is_preview() && ! is_customize_preview() && ! current_user_can( 'edit_posts' );
		/**
		 * Decides whether this request loads the Google tags.
		 *
		 * @param bool $track What the plugin decided.
		 */
		return (bool) apply_filters( 'estrategia_web_google_tags_track', $track );
	}

	public static function print(): void {
		$s = self::settings();
		if ( ! self::configured( $s ) || ! self::should_track() ) {
			return;
		}
		wp_print_inline_script_tag( self::script( $s ), array( 'id' => 'estrategia-web-google-tags' ) );
	}

	/** What the browser script needs. */
	public static function config_for_js( array $s ): array {
		$form = '';
		if ( '' !== $s['ads_form_label'] && '' !== $s['ads'] && str_starts_with( $s['ads_form_label'], $s['ads'] . '/' ) ) {
			$form = $s['ads_form_label'];
		}
		return array(
			'ga4'      => $s['ga4'],
			'ads'      => $s['ads'],
			'gtm'      => $s['gtm'],
			'form'     => $form,
			'advanced' => 'advanced' === $s['mode'],
		);
	}

	/** The JS (ES5, no on-screen text). See the header, points 1-5. */
	public static function script( array $s ): string {
		$config = wp_json_encode( self::config_for_js( $s ) );
		return strtr(
			self::template( 'google-tags.js' ),
			array( '__CONFIG__' => $config )
		);
	}

	/**
	 * A JS template from ../assets/, without its header comment and its final
	 * newline: the header is for whoever opens the file, not for the page.
	 */
	private static function template( string $file ): string {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/' . $file );
		return rtrim( (string) preg_replace( '#^/\*.*?\*/\n#s', '', $js, 1 ), "\n" );
	}
}
