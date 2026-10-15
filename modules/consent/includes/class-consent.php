<?php
/**
 * Own, minimal cookie notice (consent) — 0.5.0.
 *
 * What it is: a notice with Accept / Reject / Configure (all three with the
 * same visual weight, as the AEPD requires), declarable categories, the
 * choice in an own technical cookie (estrategia_web_consent), and the choice
 * exposed:
 * - to PHP: estrategia_web_consent_has( 'maps' ), estrategia_web_consent_categories()
 *           (includes/consent-functions.php; inside, this class);
 * - to JS:  window.estrategiaWebConsent.has( 'maps' ) / .get() / .open() /
 *           .grant( 'maps' ) / .categories, the «estrategia-web:consent» event on
 *           document (detail.accepted), «estrategia-web:consent-ready» (0.5.0, once
 *           on boot, also without a choice) and the Interactivity store
 *           «estrategia-web/consent» (blocks/consent/view.js);
 * - and it activates only the <script type="text/plain" data-estrategia-web-consent="X">
 *   of the accepted categories.
 * The notice is printed on wp_body_open, at the START of the body (it does not
 * depend on the theme placing a block); if the theme does not call
 * wp_body_open(), on wp_footer, as before 0.5.1.
 *
 * 1.1.0 — CATEGORIES, DETECTOR AND POLICY TABLE
 *
 * The categories are the AEPD ones: necessary (always, not asked),
 * preferences, analytics and advertising, plus «maps and third-party
 * content», which already existed. The SLUGS do not change, because the
 * notice revision is a hash of the slugs and changing them would make ALL
 * visitors who already chose see the notice again (trap 2):
 *     necessary    Necessary                (not stored: always)
 *     preferences  Preferences              (new in 1.1.0)
 *     statistics   Analytics                (previously labelled «Statistics»)
 *     marketing    Advertising              (previously labelled «Marketing»)
 *     maps         Maps and third-party content
 * Accepted as ALIASES (when declaring and when asking, in PHP and in JS):
 * analytics → statistics, advertising and ads → marketing. That way a new
 * module can write has( 'analytics' ) without knowing the history.
 * - «preferences» was added at the END of known_categories() on purpose: the
 *   order of that list is part of the revision. When printing, the notice
 *   sorts them with display_order() (preferences, analytics, advertising,
 *   maps), which does not touch the revision.
 * - Who declares categories: the CODE with the usual filter (the theme,
 *   hybrid Matomo, google-tags) and, since 1.1.0, the ADMINISTRATOR in
 *   «Estrategia Web → Cookies» (option estrategia_web_cookies_settings, key
 *   ask). They are merged; the administrator cannot remove what the code
 *   declares. code_categories() returns only the code ones.
 * - REOPENING THE PANEL (for the footer): the «Cookie settings» block, the
 *   [estrategia_web_consent_settings] shortcode, window.estrategiaWebConsent.open()
 *   and, since 1.1.0, ANY link to «#estrategia-web-consent» or element with
 *   the «estrategia-web-consent-open» class (or the
 *   data-estrategia-web-consent-open attribute): it works for a navigation
 *   menu link or an editor button with «Additional CSS class(es)».
 * - The DETECTOR, the cookie REGISTRY and the policy TABLE have their own
 *   header: includes/class-cookie-registry.php, class-cookie-rest.php and
 *   class-cookie-table.php; the screen, admin/class-cookies-page.php.
 *
 * FOCUS ORDER (0.5.1, WCAG 2.4.3). In the footer it was the last thing on the
 * page: with a keyboard it took 30-35 Tab presses to reach Accept / Reject
 * (Lavandería audit, 06-10-2026). Now it is the first in the DOM and the
 * first Tab lands on it, even though it shows at the bottom (it is
 * position: fixed). Decisions:
 * - First in the DOM and do NOT move focus on open: stealing focus on page
 *   load disorients screen readers and jumps over «Skip to content». This way
 *   the first Tab finds it, and whoever does not want to answer keeps tabbing
 *   into the page.
 * - No focus trap: it is role="dialog" with aria-modal="false" and the page
 *   stays usable; Tab leaves it and Shift+Tab comes back. The AEPD (Guide on
 *   the use of cookies, 2023) requires the first layer to be visible and
 *   accept and reject to be at the same level, not that it blocks the site.
 * - Escape does NOT close it the first time: closing without choosing would
 *   leave the visitor without a notice and without a decision, and it would
 *   show again on the next page. Reopened from «Cookie settings» (there is
 *   already a choice), it does.
 * - The trap: core's «Skip to content» is inserted by a script INSIDE
 *   .wp-site-blocks, after the notice; while the notice is open it is the
 *   second Tab stop, not the first. Chosen or not, once closed it is the
 *   first again.
 * Test: tests/consent-browser.test.mjs («the FIRST thing when tabbing»).
 * The estrategia-web-site/consent block is the LINK to reopen it, meant for
 * the footer; there is also the [estrategia_web_consent_settings] shortcode.
 *
 * WHY IT IS HERE AND NOT IN THE PARENT THEME (it came from armazon-wp-padre
 * 0.6.0, inc/consent.php): consent is functionality, not presentation. If a
 * site changes theme it cannot be left without a notice, and Matomo, which is
 * in this plugin, depends on it. The three layers (Armazón decision
 * 2026-10-01): how it looks, the theme's; what works and is stored, here.
 * It was moved WITHOUT changing the contract: the same cookie with the same
 * format and the same revision (a visitor who already chose does not see the
 * notice again), the same estrategia_web_consent_categories filter, the same
 * PHP functions, the same JS API, the same event and the same store.
 *
 * 1.0.0, THE RENAME (Armazón Sitio → Estrategia Web Site): the cookie goes
 * from armazon_consent to estrategia_web_consent with the SAME format and the
 * same revision. The old one is read if the new one is missing (choice() and
 * view.js) and the browser rewrites it with the new name and deletes the old
 * one: whoever already chose does NOT see the notice again. Filters,
 * functions, JS API, event and store change prefix (estrategia_web_…,
 * window.estrategiaWebConsent, «estrategia-web:consent»); parent and plugin
 * are updated together.
 *
 * The block name: estrategia-web-site/consent. The old ones,
 * armazon-site/consent and armazon/consent (parent theme ≤ 0.6.0), are
 * inserter-hidden ALIASES (includes/class-legacy-names.php) that print the
 * same, for markup not yet migrated. Removed in 2.0.0.
 *
 * Coexistence with an old parent theme (≤ 0.6.0, which still ships the
 * notice): if estrategia_web_consent_categories() already exists when
 * after_setup_theme arrives, the theme defined it; this plugin does NOT boot
 * its notice (there would be two, and PHP crashes when redeclaring the
 * functions). That is why the global functions are loaded late, on
 * after_setup_theme, and not when the plugin loads: plugins load BEFORE the
 * theme's functions.php.
 * And the other way round, parent 0.7.0 with an old estrategia-web-site
 * (≤ 0.4.0): there is no notice. Nothing optional loads (maps require a
 * click; hybrid Matomo stays without cookies), so it fails on the safe side,
 * but the site loses the notice: update both at once.
 *
 * How a theme or a plugin declares categories:
 *
 *     add_filter( 'estrategia_web_consent_categories', fn( $c ) => array_merge( (array) $c, array( 'maps' ) ) );
 *
 * Known categories: see above (1.1.0). «necessary» is always there and is
 * not asked. IF NONE IS DECLARED, THE NOTICE DOES NOT SHOW, and that is
 * normal: with cookieless Matomo (row 16 of the Armazón) and maps in two
 * clicks (estrategia-web/map from the parent), most sites have nothing to
 * ask. Matomo in hybrid mode declares «statistics» by itself
 * (modules/matomo), and google-tags whatever each tag needs.
 *
 * «Powered by» (0.5.0): at the foot of the notice, «Powered by Estrategia Web»
 * linking to https://estrategiaweb.es/, in a new tab, rel="noopener" and
 * WITHOUT nofollow (Juan wants it: it counts as a link). SINCE 1.0.0 IT IS
 * OFF: WordPress.org guideline 10 forbids linking to the author on the public
 * site unless whoever uses the plugin enables it. It is switched on with the
 * FILTER estrategia_web_site_consent_powered_by (not with an option: it is
 * the signature of whoever builds the site, not something the client touches
 * in their dashboard). Lavandería switches it on in its child theme.
 *
 *     // Switch it on:
 *     add_filter( 'estrategia_web_site_consent_powered_by', '__return_true' );
 *     // On, with another text or URL (whatever is not passed is kept):
 *     add_filter( 'estrategia_web_site_consent_powered_by', fn( $p ) => array_merge( $p, array( 'show' => true, 'text' => 'Made by X', 'url' => 'https://x.es/' ) ) );
 *
 * Edge case: an empty 'url' prints the text without a link; an empty 'text'
 * does not print the line. The name «Estrategia Web» is not translated (it is
 * a brand); «Powered by %s» is, and in es_ES it is left in English, as
 * CookieYes does.
 *
 * WP Consent API (plugin): if active, the «optin» type is declared and the JS
 * calls wp_set_consent() on save: statistics → statistics and
 * statistics-anonymous, marketing → marketing, preferences → preferences
 * (1.1.0). «maps» has no equivalent in that API and is not communicated. It
 * does not depend on it: without the plugin, everything stays the same.
 *
 * How to maintain:
 * - Who: whoever touches the plugin. When: on adding a category, changing the
 *   default legal text or touching the cookie format. The tests are
 *   tests/consent.test.mjs (level 2) and tests/consent-browser.test.mjs
 *   (level 3, with hybrid Matomo against this real notice); the registry
 *   ones, tests/cookies.test.mjs and cookies-browser.test.mjs.
 * - Changing the declared categories changes the «revision» (hash of the
 *   list) and the notice shows again to everyone: that is correct, they are
 *   being asked about something new. To force it without changing
 *   categories (another policy), the estrategia_web_consent_revision filter.
 * - Edge case: a site needs an unknown category (e.g. «chat»). It is a
 *   SETTING if a label and description are enough: it is added here in a
 *   minor version, at the END of known_categories() (like preferences in
 *   1.1.0) and in DISPLAY_ORDER. If it needs other logic, it belongs to that
 *   site.
 * - Trap 1: estrategia_web_consent_has() reads the cookie on the SERVER. With
 *   page caching (SiteGround, etc.) one visitor's cached page is served to
 *   another. To decide whether to load something, better in the browser
 *   (text/plain + data-estrategia-web-consent, or the event).
 * - Trap 2: the revision is md5 of the list + the revision filter, and it
 *   must give EXACTLY the same as the parent did (tests/consent.test.mjs
 *   checks it with the value computed by hand). If the order of the known
 *   categories is touched, the revision changes and ALL visitors see the
 *   notice again.
 * - Trap 3: the text filters (estrategia_web_consent_text, …) changed prefix
 *   in 1.0.0 along with everything else: a theme or plugin that hooked
 *   armazon_consent_… stops tuning the notice until it is renamed.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Consent {

	/** Technical cookie holding the choice (1.0.0; previously armazon_consent). */
	public const COOKIE = 'estrategia_web_consent';

	/**
	 * The cookie with its pre-1.0.0 name. It is READ if the new one is
	 * missing, and the browser moves it to the new one and deletes it
	 * (blocks/consent/view.js): whoever already chose does not see the notice
	 * again because of the rename. Removed in 2.0.0, with the Legacy_Names
	 * aliases.
	 */
	public const LEGACY_COOKIE = 'armazon_consent';

	/** Name of the link block. The old ones (armazon/consent, armazon-site/consent) are aliases: Legacy_Names. */
	public const BLOCK = 'estrategia-web-site/consent';

	/** Handles WordPress derives from blocks/consent/block.json. */
	public const SCRIPT_MODULE = 'estrategia-web-site-consent-view-script-module';
	public const STYLE         = 'estrategia-web-site-consent-style';

	/** 1.1.0: other names accepted for a category (see the header). */
	public const ALIASES = array(
		'analytics'   => 'statistics',
		'advertising' => 'marketing',
		'ads'         => 'marketing',
	);

	/** 1.1.0: order in which the notice and the screen show them (does not touch the revision). */
	public const DISPLAY_ORDER = array( 'preferences', 'statistics', 'marketing', 'maps' );

	private static bool $active = false;

	public static function boot(): void {
		add_action( 'after_setup_theme', array( self::class, 'take_over' ), 0 );
	}

	/** Whether the notice belongs to this plugin in this request (false with an old parent theme that still ships it). */
	public static function active(): bool {
		return self::$active;
	}

	/** Boots the notice unless the theme already defines it (see the header, «Coexistence»). */
	public static function take_over(): void {
		if ( function_exists( 'estrategia_web_consent_categories' ) ) {
			return;
		}
		self::$active = true;
		require_once ESTRATEGIA_WEB_CONSENT_DIR . 'includes/consent-functions.php';

		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'init', array( self::class, 'register_settings' ) );
		add_filter( 'block_type_metadata', array( self::class, 'block_labels' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		// 0.5.1: at the START of the body (focus order, see the header); in the
		// footer only if the theme does not call wp_body_open().
		add_action( 'wp_body_open', array( self::class, 'print_notice' ), 5 );
		add_action( 'wp_footer', array( self::class, 'print_notice' ), 5 );
		add_shortcode( 'estrategia_web_consent_settings', array( self::class, 'shortcode' ) );
		// 1.1.0: cookie registry, detector and policy table.
		add_shortcode( Cookie_Table::SHORTCODE, array( Cookie_Table::class, 'shortcode' ) );
		add_action( 'rest_api_init', array( Cookie_Rest::class, 'register' ) );
		if ( is_admin() ) {
			require_once ESTRATEGIA_WEB_CONSENT_DIR . 'includes/admin/class-cookies-page.php';
			Cookies_Page::boot();
		}
		if ( function_exists( 'wp_has_consent' ) ) {
			add_filter( 'wp_get_consent_type', static fn( $type ) => self::categories() ? 'optin' : $type );
		}
	}

	/**
	 * Optional categories that can be described. THE ORDER IS PART OF THE
	 * REVISION: a new one goes at the end (trap 2).
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function known_categories(): array {
		return array(
			'statistics'  => array(
				'label'       => __( 'Analytics', 'estrategia-web' ),
				'description' => __( 'Help us understand how the site is used.', 'estrategia-web' ),
			),
			'maps'        => array(
				'label'       => __( 'Maps and third-party content', 'estrategia-web' ),
				'description' => __( 'Load embedded maps and other content served by third parties, which may set their own cookies.', 'estrategia-web' ),
			),
			'marketing'   => array(
				'label'       => __( 'Advertising', 'estrategia-web' ),
				'description' => __( 'Measure campaigns and show relevant advertising.', 'estrategia-web' ),
			),
			'preferences' => array(
				'label'       => __( 'Preferences', 'estrategia-web' ),
				'description' => __( 'Remember choices that change how the site behaves or looks, such as the region or recently viewed items.', 'estrategia-web' ),
			),
		);
	}

	/** A category slug with aliases resolved («analytics» → «statistics»). */
	public static function normalize( string $category ): string {
		$category = strtolower( trim( $category ) );
		return self::ALIASES[ $category ] ?? $category;
	}

	/**
	 * The categories in display order (DISPLAY_ORDER).
	 *
	 * @param string[] $categories
	 * @return string[]
	 */
	public static function display_order( array $categories ): array {
		$order = array_flip( self::DISPLAY_ORDER );
		usort( $categories, static fn( $a, $b ) => ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 ) );
		return $categories;
	}

	/**
	 * The ones declared by the CODE (the filter), in the stable order of known_categories().
	 *
	 * @return string[]
	 */
	public static function code_categories(): array {
		$declared = array_map( static fn( $c ) => self::normalize( (string) $c ), (array) apply_filters( 'estrategia_web_consent_categories', array() ) );
		return array_values( array_intersect( array_keys( self::known_categories() ), $declared ) );
	}

	/**
	 * Optional categories the notice asks about: the code ones and the ones
	 * the administrator adds (1.1.0), in a stable order.
	 *
	 * @return string[]
	 */
	public static function categories(): array {
		$declared = array_merge( self::code_categories(), Cookie_Registry::settings()['ask'] );
		return array_values( array_intersect( array_keys( self::known_categories() ), $declared ) );
	}

	/** Revision: a choice stored with another revision is not valid and the notice shows again. */
	public static function revision(): string {
		$seed = implode( ',', self::categories() ) . '|' . apply_filters( 'estrategia_web_consent_revision', '1' );
		return substr( md5( $seed ), 0, 8 );
	}

	/** Lifetime of the choice, in seconds. 12 months (the AEPD recommends not exceeding 24). */
	public static function max_age(): int {
		return (int) apply_filters( 'estrategia_web_consent_max_age', YEAR_IN_SECONDS );
	}

	/**
	 * The stored choice, or null if there is none valid for the revision.
	 * Cookie format: "<revision>.<cat~cat>.<unix>".
	 *
	 * @return string[]|null Accepted optional categories.
	 */
	public static function choice(): ?array {
		$name = empty( $_COOKIE[ self::COOKIE ] ) ? self::LEGACY_COOKIE : self::COOKIE;
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}
		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
		$parts = explode( '.', $raw );
		if ( 3 !== count( $parts ) || self::revision() !== $parts[0] ) {
			return null;
		}
		$accepted = '' === $parts[1] ? array() : explode( '~', $parts[1] );
		return array_values( array_intersect( self::categories(), $accepted ) );
	}

	/** Whether a category is accepted. «necessary» always. Accepts aliases. */
	public static function has( string $category ): bool {
		$category = self::normalize( $category );
		if ( 'necessary' === $category ) {
			return true;
		}
		$choice = self::choice();
		return null !== $choice && in_array( $category, $choice, true );
	}

	/**
	 * The «Powered by» line, already filtered. See the header.
	 *
	 * @return array{show: bool, text: string, url: string}
	 */
	public static function powered_by(): array {
		$defaults = array(
			// OFF by default (1.0.0): WordPress.org (guideline 10) does not allow
			// linking to the author on the public site unless the user enables it.
			'show' => false,
			/* translators: %s: name of the company that built the site (a brand, not translated). */
			'text' => sprintf( __( 'Powered by %s', 'estrategia-web' ), 'Estrategia Web' ),
			'url'  => 'https://estrategiaweb.es/',
		);
		/**
		 * The «Powered by» line of the cookie notice.
		 *
		 * @param array{show: bool, text: string, url: string}|false $powered false removes it.
		 */
		$value = apply_filters( 'estrategia_web_site_consent_powered_by', $defaults );
		if ( ! is_array( $value ) ) {
			$value = array( 'show' => (bool) $value );
		}
		$value = array_merge( $defaults, $value );
		return array(
			'show' => (bool) $value['show'] && '' !== trim( (string) $value['text'] ),
			'text' => (string) $value['text'],
			'url'  => (string) esc_url_raw( (string) $value['url'], array( 'http', 'https' ) ),
		);
	}

	/** Registers the link block and the table block (1.1.0). The old link names are Legacy_Names aliases. */
	public static function register_blocks(): void {
		register_block_type( ESTRATEGIA_WEB_CONSENT_DIR . 'blocks/consent' );
		register_block_type( ESTRATEGIA_WEB_CONSENT_DIR . 'blocks/cookie-table' );
	}

	/** 1.1.0: the registry settings, also through /wp/v2/settings (for scripts and tests). */
	public static function register_settings(): void {
		register_setting(
			'estrategia_web_cookies',
			Cookie_Registry::SETTINGS,
			array(
				'type'              => 'object',
				'description'       => __( 'Cookie notice: detector on or off, and categories added by the administrator.', 'estrategia-web' ),
				'sanitize_callback' => array( Cookie_Registry::class, 'sanitize_settings' ),
				'default'           => array(
					'detector' => true,
					'ask'      => array(),
				),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'detector' => array( 'type' => 'boolean' ),
							'ask'      => array(
								'type'  => 'array',
								'items' => array(
									'type' => 'string',
									'enum' => array_keys( self::known_categories() ),
								),
							),
						),
					),
				),
			)
		);
	}

	/** Label of the autoRegister control (block.json does not translate the «label» key). */
	private static function label_text(): string {
		return __( 'Link text (empty: “Cookie settings”)', 'estrategia-web' );
	}

	/**
	 * Sets the translated label on the block attribute.
	 *
	 * @param array $metadata block.json as read.
	 */
	public static function block_labels( array $metadata ): array {
		if ( ( $metadata['name'] ?? '' ) === self::BLOCK && isset( $metadata['attributes']['label'] ) ) {
			$metadata['attributes']['label']['label'] = self::label_text();
		}
		return $metadata;
	}

	/** Interactivity state shared by the notice and the link. */
	public static function state(): void {
		$known    = self::known_categories();
		$settings = Cookie_Registry::settings();
		wp_interactivity_state(
			'estrategia-web/consent',
			array(
				'categories'   => self::categories(),
				'labels'       => array_map( static fn( $c ) => $c['label'], array_intersect_key( $known, array_flip( self::categories() ) ) ),
				'aliases'      => self::ALIASES,
				'revision'     => self::revision(),
				'cookieName'   => self::COOKIE,
				'legacyCookie' => self::LEGACY_COOKIE,
				'maxAge'       => self::max_age(),
				'isOpen'       => false,
				'showSettings' => false,
				// 1.1.0: the detector (blocks/consent/view.js, class-cookie-rest.php).
				// No «server list version» on purpose: the report itself changes
				// it (a row comes in) and, with page caching, each visitor would
				// see an old version and send again on every page. The «version»
				// that counts is the visitor's list of NAMES: it is sent if it
				// changes or after «days» days.
				'detector'     => $settings['detector'] ? array(
					'url'   => rest_url( Cookie_Rest::NAMESPACE . '/cookies/seen' ),
					'days'  => (int) apply_filters( 'estrategia_web_cookies_report_days', 7 ),
					'delay' => 4000,
				) : null,
			)
		);
	}

	/** Puts the module and the stylesheet on the site when there is something to ask. */
	public static function enqueue(): void {
		if ( ! self::categories() ) {
			return;
		}
		self::state();
		wp_enqueue_script_module( self::SCRIPT_MODULE );
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Prints the notice. Hidden by default: view.js shows it if there is no
	 * valid choice. Without JavaScript it stays hidden, and nothing optional
	 * loads either (the scripts are text/plain), so nothing is lost.
	 */
	public static function print_notice(): void {
		static $printed = false;
		$categories = self::categories();
		if ( ! $categories || $printed ) {
			return;
		}
		$printed = true;
		$known   = self::known_categories();
		$policy  = get_privacy_policy_url();
		$powered = self::powered_by();
		$text    = apply_filters(
			'estrategia_web_consent_text',
			__( 'We use our own and third-party cookies for the purposes listed below. You can accept them, reject them or choose which ones to allow. Necessary cookies are always active.', 'estrategia-web' )
		);

		ob_start();
		?>
		<section
			id="estrategia-web-consent"
			class="estrategia-web-consent"
			hidden
			role="dialog"
			aria-modal="false"
			aria-labelledby="estrategia-web-consent-title"
			aria-describedby="estrategia-web-consent-text"
			data-wp-interactive="estrategia-web/consent"
			data-wp-init="callbacks.init"
			data-wp-watch="callbacks.focusOnOpen"
			data-wp-bind--hidden="!state.isOpen"
			data-wp-on--keydown="actions.onKeydown"
		>
			<h2 id="estrategia-web-consent-title" class="estrategia-web-consent__title" tabindex="-1"><?php esc_html_e( 'Cookies', 'estrategia-web' ); ?></h2>
			<p id="estrategia-web-consent-text" class="estrategia-web-consent__text">
				<?php echo esc_html( $text ); ?>
				<?php if ( $policy ) : ?>
					<a href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Privacy and cookie policy', 'estrategia-web' ); ?></a>
				<?php endif; ?>
			</p>

			<form class="estrategia-web-consent__settings" id="estrategia-web-consent-settings" hidden data-wp-bind--hidden="!state.showSettings" data-wp-on--submit="actions.saveSettings">
				<fieldset>
					<legend><?php esc_html_e( 'Cookie categories', 'estrategia-web' ); ?></legend>
					<label class="estrategia-web-consent__category">
						<input type="checkbox" checked disabled>
						<span><strong><?php esc_html_e( 'Necessary', 'estrategia-web' ); ?></strong> — <?php esc_html_e( 'Required for the site to work, including remembering this choice. Always active.', 'estrategia-web' ); ?></span>
					</label>
					<?php foreach ( self::display_order( $categories ) as $slug ) : ?>
					<label class="estrategia-web-consent__category">
						<input
							type="checkbox"
							name="estrategia-web-consent-category"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php echo wp_interactivity_data_wp_context( array( 'category' => $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							data-wp-bind--checked="state.isChecked"
							data-wp-on--change="actions.toggleCategory"
						>
						<span><strong><?php echo esc_html( $known[ $slug ]['label'] ); ?></strong> — <?php echo esc_html( $known[ $slug ]['description'] ); ?></span>
					</label>
					<?php endforeach; ?>
				</fieldset>
				<button type="submit" class="wp-element-button estrategia-web-consent__button"><?php esc_html_e( 'Save choices', 'estrategia-web' ); ?></button>
			</form>

			<div class="estrategia-web-consent__actions">
				<button type="button" class="wp-element-button estrategia-web-consent__button" data-estrategia-web-consent-action="accept" data-wp-on--click="actions.acceptAll"><?php esc_html_e( 'Accept all', 'estrategia-web' ); ?></button>
				<button type="button" class="wp-element-button estrategia-web-consent__button" data-estrategia-web-consent-action="reject" data-wp-on--click="actions.rejectAll"><?php esc_html_e( 'Reject all', 'estrategia-web' ); ?></button>
				<button type="button" class="wp-element-button estrategia-web-consent__button" data-estrategia-web-consent-action="configure" aria-controls="estrategia-web-consent-settings" data-wp-bind--aria-expanded="state.showSettings" data-wp-on--click="actions.toggleSettings"><?php esc_html_e( 'Configure', 'estrategia-web' ); ?></button>
			</div>
			<?php if ( $powered['show'] ) : ?>
			<p class="estrategia-web-consent__powered">
				<?php if ( '' !== $powered['url'] ) : ?>
				<a href="<?php echo esc_url( $powered['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $powered['text'] ); ?><span class="estrategia-web-consent__sr"> <?php esc_html_e( '(opens in a new tab)', 'estrategia-web' ); ?></span></a>
				<?php else : ?>
				<?php echo esc_html( $powered['text'] ); ?>
				<?php endif; ?>
			</p>
			<?php endif; ?>
		</section>
		<?php
		echo wp_interactivity_process_directives( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput -- built above with escaping.
	}

	/**
	 * HTML of the «Cookie settings» link: the block, its alias and the shortcode.
	 *
	 * - It is a <button>, not an <a href="#">: it opens something on the page, it does not navigate.
	 * - If the site declares no categories it prints nothing (there is no
	 *   notice to reopen). In the editor it does show a note, so it does not
	 *   look broken.
	 *
	 * @param array $attributes Block attributes (label).
	 */
	public static function render_link( array $attributes ): string {
		if ( ! self::categories() ) {
			if ( wp_is_serving_rest_request() ) {
				return sprintf(
					'<p %s>%s</p>',
					get_block_wrapper_attributes(),
					esc_html__( 'Cookie settings: this site declares no optional cookie categories, so there is no notice to reopen and this link is not shown.', 'estrategia-web' )
				);
			}
			return '';
		}
		$label = trim( (string) ( $attributes['label'] ?? '' ) );
		$label = '' !== $label ? $label : __( 'Cookie settings', 'estrategia-web' );
		return sprintf(
			'<p %s><button type="button" class="estrategia-web-consent-link__button" aria-controls="estrategia-web-consent" data-wp-interactive="estrategia-web/consent" data-wp-on--click="actions.open">%s</button></p>',
			get_block_wrapper_attributes( array( 'class' => 'estrategia-web-consent-link' ) ),
			esc_html( $label )
		);
	}

	/**
	 * [estrategia_web_consent_settings label="…"]: the same as the block.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'label' => '' ), $atts, 'estrategia_web_consent_settings' );
		return render_block(
			array(
				'blockName'    => self::BLOCK,
				'attrs'        => array( 'label' => (string) $atts['label'] ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}
}
