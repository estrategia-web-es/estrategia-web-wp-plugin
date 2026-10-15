<?php
/**
 * The site's COOKIE REGISTRY: which cookies exist, whose they are, what they
 * are for, how long they last and which notice category they belong to. It
 * feeds the «Estrategia Web → Cookies» screen (admin/class-cookies-page.php),
 * the cookie policy table (class-cookie-table.php) and what the detector
 * records (class-cookie-rest.php).
 *
 * How it gets filled:
 * 1. The DETECTOR: when a visitor ACCEPTS some category, their browser sends
 *    the NAMES of the first-party cookies it sees (document.cookie), never the
 *    values (blocks/consent/view.js). Each new name comes in as «pending»
 *    with a SUGGESTION of provider, category, purpose and duration.
 * 2. BY HAND, on the screen: third-party cookies the browser does not expose
 *    through document.cookie (google.com ones in a map iframe, HttpOnly ones).
 * The administrator confirms, changes or ignores. Only what is CONFIRMED
 * shows in the policy table.
 *
 * Where the suggestion comes from, in this order (suggest()):
 * - base(): ours, below, translatable. They override the external database:
 *   the notice's own cookie, the WordPress ones and wp-wpml_current_language
 *   (WPML sets it BEFORE any choice to remember the language: it is technical
 *   and goes as NECESSARY).
 * - data/open-cookie-database.json: extract of the Open Cookie Database
 *   (Apache 2.0, attribution inside the JSON and in data/NOTICE), regenerated
 *   with tools/open-cookie-database.mjs. English texts: they are a suggestion.
 * Within each source: the exact name and, failing that, the longest PREFIX
 * (wildcard). A wildcard is stored with a trailing «*»: _ga_* catches
 * _ga_ABC123, _pk_id* catches _pk_id.87.a1b2. That way each Analytics
 * measurement does not create a new row.
 *
 * Where it is stored: the estrategia_web_cookies option (no autoload), key =
 * name or pattern, value = ENTRY_DEFAULTS. The settings (detector on/off and
 * the categories the administrator adds to the notice) in
 * estrategia_web_cookies_settings.
 *
 * How to maintain:
 * - Who and when: whoever touches the cookie notice. A cookie from WordPress
 *   or from a plugin we USE on our sites that the external database
 *   misclassifies goes into base() with its translatable text (and its line
 *   in the .po). Everything else is decided by each site on its screen.
 * - Criterion for base(): only cookies set by OUR stack (WordPress, WPML,
 *   this plugin). One from a plugin used by a single site is confirmed by
 *   that site on its screen.
 * - Named edge case: wp-wpml_current_language. The external database lists it
 *   as Functional (and we map it to necessary); even so it is in base()
 *   because its real duration is 1 DAY (wpml_cookies in the Lavandería HTML,
 *   14-10-2026) and the database says «session». And because it is set before
 *   the choice: if one day it fell into another category, the site would be
 *   setting a non-necessary cookie without consent.
 * - Flood limits (the detector is public): MAX_ENTRIES rows in total, names
 *   with RFC 6265 characters and at most 128. Anything else is ignored
 *   without error. The rate limits, in class-cookie-rest.php.
 * - The trap: a pattern matches by PREFIX, so «_ga*» would also swallow
 *   _gac_, _gat… That is why the base() patterns end in the separator
 *   (_ga_*, wp-settings-*) and the lookup checks the EXACT name (_ga) before
 *   any pattern.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Cookie_Registry {

	/** The known cookies: name or pattern => ENTRY_DEFAULTS. */
	public const OPTION = 'estrategia_web_cookies';

	/** Settings: detector (bool) and ask (categories the administrator adds to the notice). */
	public const SETTINGS = 'estrategia_web_cookies_settings';

	/** Maximum rows (the detector is public: it cannot grow without bound). */
	public const MAX_ENTRIES = 300;

	/** Categories a cookie can belong to, in table order. */
	public const CATEGORIES = array( 'necessary', 'preferences', 'statistics', 'marketing', 'maps' );

	public const STATUSES = array( 'pending', 'confirmed', 'ignored' );

	public const ENTRY_DEFAULTS = array(
		'provider'   => '',
		'category'   => 'necessary',
		'purpose'    => '',
		'duration'   => '',
		'status'     => 'pending',
		'source'     => 'detected', // detected | manual
		'suggested'  => '',         // where the suggestion came from: base | ocd | ''
		'example'    => '',         // a concrete name seen, for patterns
		'first_seen' => 0,
		'last_seen'  => 0,
	);

	/** @var array|null Open Cookie Database extract, read once per request. */
	private static ?array $ocd = null;

	/** Whether a name (or pattern with a trailing «*») is acceptable: RFC 6265 token characters, ≤ 128. */
	public static function name_ok( string $name ): bool {
		return (bool) preg_match( '/^[!#$%&\'+\-.^_`|~0-9A-Za-z]{1,128}\*?$/', $name );
	}

	/** Whether a key is a pattern (ends in «*»). */
	public static function is_pattern( string $key ): bool {
		return str_ends_with( $key, '*' );
	}

	/**
	 * Which key of $keys matches $name: the exact one and, failing that, the
	 * pattern with the longest prefix. Pure (no WordPress): tested at level 1.
	 *
	 * @param string[] $keys Keys (names or patterns with «*»).
	 */
	public static function match_key( array $keys, string $name ): ?string {
		if ( in_array( $name, $keys, true ) ) {
			return $name;
		}
		$best = null;
		foreach ( $keys as $key ) {
			if ( self::is_pattern( $key ) ) {
				$prefix = substr( $key, 0, -1 );
				if ( '' !== $prefix && str_starts_with( $name, $prefix ) && ( null === $best || strlen( $key ) > strlen( $best ) ) ) {
					$best = $key;
				}
			}
		}
		return $best;
	}

	/**
	 * Ours: they override the external database. See the criterion in the header.
	 *
	 * @return array<string, array{provider: string, category: string, purpose: string, duration: string}>
	 */
	public static function base(): array {
		$site = __( 'This website', 'estrategia-web' );
		return array(
			Consent::COOKIE            => array(
				'provider' => $site,
				'category' => 'necessary',
				'purpose'  => __( 'Remembers your choice in the cookie notice.', 'estrategia-web' ),
				'duration' => '1 year',
			),
			Consent::LEGACY_COOKIE     => array(
				'provider' => $site,
				'category' => 'necessary',
				'purpose'  => __( 'Previous name of the cookie that remembers your choice in the cookie notice; it is replaced on the next visit.', 'estrategia-web' ),
				'duration' => '1 year',
			),
			'wp-wpml_current_language' => array(
				'provider' => 'WPML',
				'category' => 'necessary',
				'purpose'  => __( 'Remembers the language in which you are browsing the site.', 'estrategia-web' ),
				'duration' => '1 day',
			),
			'_icl_current_language'    => array(
				'provider' => 'WPML',
				'category' => 'necessary',
				'purpose'  => __( 'Remembers the language in which you are browsing the site.', 'estrategia-web' ),
				'duration' => '1 day',
			),
			'pll_language'             => array(
				'provider' => 'Polylang',
				'category' => 'necessary',
				'purpose'  => __( 'Remembers the language in which you are browsing the site.', 'estrategia-web' ),
				'duration' => '1 year',
			),
			'wordpress_test_cookie'    => array(
				'provider' => 'WordPress',
				'category' => 'necessary',
				'purpose'  => __( 'Checks that the browser accepts cookies. Only on the login page.', 'estrategia-web' ),
				'duration' => 'session',
			),
			'wordpress_logged_in_*'    => array(
				'provider' => 'WordPress',
				'category' => 'necessary',
				'purpose'  => __( 'Keeps the session of the site’s users (editors and administrators).', 'estrategia-web' ),
				'duration' => 'session',
			),
			'wp-settings-*'            => array(
				'provider' => 'WordPress',
				'category' => 'necessary',
				'purpose'  => __( 'Remembers the dashboard settings of the site’s users (editors and administrators).', 'estrategia-web' ),
				'duration' => '1 year',
			),
			// Matomo: the external database also has the Piwik PRO ones with a
			// LONGER prefix (_pk_id. versus _pk_id) and they would win by length
			// with the wrong provider. Matomo is our stack: they go here.
			'_pk_id.*'                 => array(
				'provider' => 'Matomo',
				'category' => 'statistics',
				'purpose'  => __( 'Statistics: recognises a returning visitor (an anonymous identifier). Only after accepting analytics.', 'estrategia-web' ),
				'duration' => '13 months',
			),
			'_pk_ses.*'                => array(
				'provider' => 'Matomo',
				'category' => 'statistics',
				'purpose'  => __( 'Statistics: groups the pages viewed in the same visit.', 'estrategia-web' ),
				'duration' => '30 minutes',
			),
			'_pk_ref.*'                => array(
				'provider' => 'Matomo',
				'category' => 'statistics',
				'purpose'  => __( 'Statistics: remembers the website the visitor came from.', 'estrategia-web' ),
				'duration' => '6 months',
			),
			'mtm_cookie_consent'       => array(
				'provider' => 'Matomo',
				'category' => 'statistics',
				'purpose'  => __( 'Statistics: remembers that analytics cookies were accepted.', 'estrategia-web' ),
				'duration' => '30 years',
			),
			'comment_author_*'         => array(
				'provider' => 'WordPress',
				'category' => 'preferences',
				'purpose'  => __( 'Remembers your name and email for the next comment, only if you ask for it.', 'estrategia-web' ),
				'duration' => '1 year',
			),
		);
	}

	/** The Open Cookie Database extract: { source, license, attribution, commit, generated, cookies }. */
	public static function ocd(): array {
		if ( null === self::$ocd ) {
			$file      = ESTRATEGIA_WEB_CONSENT_DIR . 'data/open-cookie-database.json';
			$data      = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$ocd = is_array( $data ) && isset( $data['cookies'] ) ? $data : array( 'cookies' => array() );
		}
		return self::$ocd;
	}

	/**
	 * The suggestion for a concrete name: key (the name or the pattern that
	 * catches it) and data. null if nobody knows it.
	 *
	 * @return array{key: string, provider: string, category: string, purpose: string, duration: string, suggested: string}|null
	 */
	public static function suggest( string $name ): ?array {
		$base = self::base();
		$key  = self::match_key( array_keys( $base ), $name );
		if ( null !== $key ) {
			return array( 'key' => $key ) + $base[ $key ] + array( 'suggested' => 'base' );
		}
		$exact = null;
		$best  = null;
		foreach ( self::ocd()['cookies'] as $row ) {
			list( $cookie, $provider, $category, $purpose, $duration, $wildcard ) = array_pad( (array) $row, 6, '' );
			if ( $cookie === $name ) {
				$exact = $row;
				break;
			}
			if ( $wildcard && '' !== $cookie && str_starts_with( $name, (string) $cookie ) && ( null === $best || strlen( (string) $cookie ) > strlen( (string) $best[0] ) ) ) {
				$best = $row;
			}
		}
		$row = $exact ?? $best;
		if ( null === $row ) {
			return null;
		}
		// A prefix (or a name the database marks as wildcard) is stored as a pattern.
		$key = ( null === $exact || ! empty( $row[5] ) ) ? self::pattern_for( (string) $row[0] ) : $name;
		return array(
			'key'       => $key,
			'provider'  => (string) $row[1],
			'category'  => in_array( $row[2], self::CATEGORIES, true ) ? (string) $row[2] : 'necessary',
			'purpose'   => (string) $row[3],
			'duration'  => (string) $row[4],
			'suggested' => 'ocd',
		);
	}

	/** The database prefix as a pattern: «_ga_» → «_ga_*», «_pk_id» → «_pk_id*». */
	private static function pattern_for( string $prefix ): string {
		return rtrim( $prefix, '*' ) . '*';
	}

	/** @return array<string, array> All rows, with complete fields. */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $key => $entry ) {
			if ( self::name_ok( (string) $key ) && is_array( $entry ) ) {
				$out[ (string) $key ] = array_merge( self::ENTRY_DEFAULTS, array_intersect_key( $entry, self::ENTRY_DEFAULTS ) );
			}
		}
		ksort( $out, SORT_STRING | SORT_FLAG_CASE );
		return $out;
	}

	private static function store( array $entries ): void {
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Records what the detector sends. Returns how many rows are new and how
	 * many were already known. Odd names, and new ones beyond MAX_ENTRIES, are
	 * ignored.
	 *
	 * @param string[] $names Cookie names (without values).
	 * @return array{added: int, seen: int}
	 */
	public static function record_seen( array $names, ?int $now = null ): array {
		$now     = $now ?? time();
		$entries = self::all();
		$added   = 0;
		$seen    = 0;
		foreach ( array_unique( array_map( 'strval', $names ) ) as $name ) {
			if ( ! self::name_ok( $name ) || self::is_pattern( $name ) ) {
				continue;
			}
			$key = self::match_key( array_keys( $entries ), $name );
			if ( null === $key ) {
				$suggestion = self::suggest( $name );
				$key        = $suggestion['key'] ?? $name;
				if ( ! isset( $entries[ $key ] ) ) {
					if ( count( $entries ) >= self::MAX_ENTRIES ) {
						continue;
					}
					$entries[ $key ] = array_merge(
						self::ENTRY_DEFAULTS,
						$suggestion ? array_intersect_key( $suggestion, self::ENTRY_DEFAULTS ) : array(),
						array(
							'source'     => 'detected',
							'first_seen' => $now,
						)
					);
					++$added;
				}
			} else {
				++$seen;
			}
			$entries[ $key ]['last_seen'] = $now;
			if ( self::is_pattern( $key ) && '' === $entries[ $key ]['example'] ) {
				$entries[ $key ]['example'] = $name;
			}
		}
		if ( $added || $seen ) {
			self::store( $entries );
		}
		return array(
			'added' => $added,
			'seen'  => $seen,
		);
	}

	/**
	 * Creates or changes a row (the screen and the admin REST route). What is
	 * not sent stays; in a NEW row, empty fields are filled from the
	 * suggestion.
	 *
	 * @param array $data provider, category, purpose, duration, status.
	 * @return array|\WP_Error The saved row.
	 */
	public static function save( string $key, array $data ) {
		$key = trim( $key );
		if ( ! self::name_ok( $key ) ) {
			return new \WP_Error( 'estrategia_web_cookie_name', __( 'Invalid cookie name. Use the exact name, or a prefix ending in * for a family of cookies (e.g. _ga_*).', 'estrategia-web' ), array( 'status' => 400 ) );
		}
		$entries = self::all();
		$is_new  = ! isset( $entries[ $key ] );
		if ( $is_new && count( $entries ) >= self::MAX_ENTRIES ) {
			return new \WP_Error( 'estrategia_web_cookie_full', __( 'The cookie list is full. Delete the cookies you do not need.', 'estrategia-web' ), array( 'status' => 400 ) );
		}
		$entry = $entries[ $key ] ?? array_merge( self::ENTRY_DEFAULTS, array( 'source' => 'manual' ) );
		if ( $is_new ) {
			$suggestion = self::suggest( self::is_pattern( $key ) ? substr( $key, 0, -1 ) : $key );
			foreach ( array( 'provider', 'category', 'purpose', 'duration' ) as $field ) {
				if ( '' === trim( (string) ( $data[ $field ] ?? '' ) ) && $suggestion ) {
					$data[ $field ] = $suggestion[ $field ];
				}
			}
			$entry['suggested'] = $suggestion['suggested'] ?? '';
		}
		foreach ( array( 'provider', 'duration' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$entry[ $field ] = sanitize_text_field( (string) $data[ $field ] );
			}
		}
		if ( array_key_exists( 'purpose', $data ) ) {
			$entry['purpose'] = sanitize_textarea_field( (string) $data['purpose'] );
		}
		if ( isset( $data['category'] ) && in_array( $data['category'], self::CATEGORIES, true ) ) {
			$entry['category'] = $data['category'];
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ) {
			$entry['status'] = $data['status'];
		}
		$entries[ $key ] = $entry;
		self::store( $entries );
		Cookie_Table::register_strings( $key, $entry );
		return $entry;
	}

	public static function delete( string $key ): bool {
		$entries = self::all();
		if ( ! isset( $entries[ $key ] ) ) {
			return false;
		}
		unset( $entries[ $key ] );
		self::store( $entries );
		return true;
	}

	/**
	 * The confirmed ones, by category and in CATEGORIES order.
	 *
	 * @return array<string, array<string, array>>
	 */
	public static function confirmed_by_category(): array {
		$out = array_fill_keys( self::CATEGORIES, array() );
		foreach ( self::all() as $key => $entry ) {
			if ( 'confirmed' === $entry['status'] ) {
				$out[ $entry['category'] ][ $key ] = $entry;
			}
		}
		return array_filter( $out );
	}

	/**
	 * The settings, complete.
	 *
	 * @return array{detector: bool, ask: string[]}
	 */
	public static function settings(): array {
		$saved = get_option( self::SETTINGS, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'detector' => ! array_key_exists( 'detector', $saved ) || (bool) $saved['detector'],
			'ask'      => array_values( array_intersect( array_keys( Consent::known_categories() ), (array) ( $saved['ask'] ?? array() ) ) ),
		);
	}

	/** Sanitisation of SETTINGS (screen and REST). */
	public static function sanitize_settings( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'detector' => ! empty( $value['detector'] ),
			'ask'      => array_values( array_intersect( array_keys( Consent::known_categories() ), array_map( 'strval', (array) ( $value['ask'] ?? array() ) ) ) ),
		);
	}

	/**
	 * Version of the list: changes when a row comes in or goes out. Exposed by
	 * the admin REST route, so a script can tell whether something changed.
	 * The browser does NOT use it (see Consent::state()).
	 */
	public static function version(): string {
		return substr( md5( implode( ',', array_keys( self::all() ) ) . '|' . ESTRATEGIA_WEB_VERSION ), 0, 8 );
	}
}
