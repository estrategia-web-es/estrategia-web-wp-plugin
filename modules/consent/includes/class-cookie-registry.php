<?php
/**
 * El REGISTRO DE COOKIES de la web: qué cookies hay, de quién son, para qué
 * sirven, cuánto duran y en qué categoría del aviso van. De aquí salen la
 * pantalla «Estrategia Web → Cookies» (admin/class-cookies-page.php), la
 * tabla de la política de cookies (class-cookie-table.php) y lo que apunta
 * el detector (class-cookie-rest.php).
 *
 * Cómo se llena:
 * 1. El DETECTOR: cuando un visitante ACEPTA alguna categoría, su navegador
 *    manda los NOMBRES de las cookies de primera parte que ve
 *    (document.cookie), nunca los valores (blocks/consent/view.js). Cada
 *    nombre nuevo entra como «pendiente» con una PROPUESTA de proveedor,
 *    categoría, finalidad y duración.
 * 2. A MANO, en la pantalla: las de terceros que el navegador no deja ver por
 *    document.cookie (las de google.com en el iframe de un mapa, las HttpOnly).
 * El administrador confirma, cambia o ignora. Solo lo CONFIRMADO sale en la
 * tabla de la política.
 *
 * De dónde sale la propuesta, por este orden (suggest()):
 * - base(): las nuestras, aquí abajo, traducibles. Mandan sobre la base de
 *   datos externa: la cookie del propio aviso, las de WordPress y
 *   wp-wpml_current_language (WPML la pone ANTES de cualquier elección para
 *   recordar el idioma: es técnica y va como NECESARIA).
 * - data/open-cookie-database.json: extracto de la Open Cookie Database
 *   (Apache 2.0, atribución dentro del JSON y en data/NOTICE), regenerado con
 *   tools/open-cookie-database.mjs. Textos en inglés: son una propuesta.
 * Dentro de cada fuente: el nombre exacto y, si no, el PREFIJO más largo
 * (comodín). Un comodín se guarda con «*» al final: _ga_* recoge _ga_ABC123,
 * _pk_id* recoge _pk_id.87.a1b2. Así cada medición de Analytics no crea una
 * fila nueva.
 *
 * Dónde se guarda: la opción estrategia_web_cookies (sin autoload), clave =
 * nombre o patrón, valor = ENTRY_DEFAULTS. Los ajustes (detector sí/no y las
 * categorías que el administrador añade al aviso) en
 * estrategia_web_cookies_settings.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque el aviso de cookies. Una cookie de WordPress
 *   o de un plugin que USAMOS en nuestras webs y que la base externa
 *   clasifica mal, entra en base() con su texto traducible (y su línea en el
 *   .po). Lo demás lo decide cada web en su pantalla.
 * - Criterio para base(): solo cookies que pone NUESTRA pila (WordPress,
 *   WPML, este plugin). La de un plugin que solo usa una web la confirma esa
 *   web en su pantalla.
 * - Caso frontera con nombre: wp-wpml_current_language. La base externa la da
 *   como Functional (y la mapeamos a necesaria); aun así está en base() porque
 *   su duración real es 1 DÍA (wpml_cookies del HTML de Lavandería,
 *   14-10-2026) y la base dice «session». Y porque se pone antes de la
 *   elección: si un día cayera en otra categoría, la web estaría poniendo una
 *   cookie no necesaria sin consentimiento.
 * - Límites contra la inundación (el detector es público): MAX_ENTRIES filas
 *   en total, nombres con los caracteres de RFC 6265 y como mucho 128. Lo
 *   demás se ignora sin error. Los de ritmo, en class-cookie-rest.php.
 * - La trampa: un patrón casa por PREFIJO, así que «_ga*» se comería también
 *   _gac_, _gat… Por eso los patrones de base() terminan en el separador
 *   (_ga_*, wp-settings-*) y la búsqueda mira primero el nombre EXACTO
 *   (_ga) antes que cualquier patrón.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Cookie_Registry {

	/** Las cookies conocidas: nombre o patrón => ENTRY_DEFAULTS. */
	public const OPTION = 'estrategia_web_cookies';

	/** Ajustes: detector (bool) y ask (categorías que el administrador añade al aviso). */
	public const SETTINGS = 'estrategia_web_cookies_settings';

	/** Filas como mucho (el detector es público: no puede crecer sin fin). */
	public const MAX_ENTRIES = 300;

	/** Categorías en las que puede ir una cookie, en el orden de la tabla. */
	public const CATEGORIES = array( 'necessary', 'preferences', 'statistics', 'marketing', 'maps' );

	public const STATUSES = array( 'pending', 'confirmed', 'ignored' );

	public const ENTRY_DEFAULTS = array(
		'provider'   => '',
		'category'   => 'necessary',
		'purpose'    => '',
		'duration'   => '',
		'status'     => 'pending',
		'source'     => 'detected', // detected | manual
		'suggested'  => '',         // de dónde salió la propuesta: base | ocd | ''
		'example'    => '',         // un nombre concreto visto, para los patrones
		'first_seen' => 0,
		'last_seen'  => 0,
	);

	/** @var array|null Extracto de la Open Cookie Database, leído una vez por petición. */
	private static ?array $ocd = null;

	/** Si un nombre (o patrón con «*» final) es aceptable: caracteres de token de RFC 6265, ≤ 128. */
	public static function name_ok( string $name ): bool {
		return (bool) preg_match( '/^[!#$%&\'+\-.^_`|~0-9A-Za-z]{1,128}\*?$/', $name );
	}

	/** Si una clave es un patrón (termina en «*»). */
	public static function is_pattern( string $key ): bool {
		return str_ends_with( $key, '*' );
	}

	/**
	 * Qué clave de $keys corresponde a $name: la exacta y, si no, el patrón
	 * con el prefijo más largo. Pura (sin WordPress): se prueba en el nivel 1.
	 *
	 * @param string[] $keys Claves (nombres o patrones con «*»).
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
	 * Las nuestras: mandan sobre la base externa. Ver el criterio en la cabecera.
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
			// Matomo: la base externa tiene también las de Piwik PRO con un
			// prefijo MÁS LARGO (_pk_id. contra _pk_id) y ganaría por longitud
			// con el proveedor equivocado. Matomo es nuestra pila: van aquí.
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

	/** El extracto de la Open Cookie Database: { source, license, attribution, commit, generated, cookies }. */
	public static function ocd(): array {
		if ( null === self::$ocd ) {
			$file      = ESTRATEGIA_WEB_CONSENT_DIR . 'data/open-cookie-database.json';
			$data      = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$ocd = is_array( $data ) && isset( $data['cookies'] ) ? $data : array( 'cookies' => array() );
		}
		return self::$ocd;
	}

	/**
	 * La propuesta para un nombre concreto: clave (el nombre o el patrón que lo
	 * recoge) y datos. null si nadie lo conoce.
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
		// Un prefijo (o un nombre que la base marca como comodín) se guarda como patrón.
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

	/** El prefijo de la base como patrón: «_ga_» → «_ga_*», «_pk_id» → «_pk_id*». */
	private static function pattern_for( string $prefix ): string {
		return rtrim( $prefix, '*' ) . '*';
	}

	/** @return array<string, array> Todas las filas, con los campos completos. */
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
	 * Apunta lo que manda el detector. Devuelve cuántas filas nuevas y cuántas
	 * ya conocidas. Los nombres raros, y los nuevos por encima de MAX_ENTRIES,
	 * se ignoran.
	 *
	 * @param string[] $names Nombres de cookies (sin valores).
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
	 * Crea o cambia una fila (la pantalla y la ruta REST de administración).
	 * Lo que no llega se queda; en una fila NUEVA, lo vacío se rellena con la
	 * propuesta.
	 *
	 * @param array $data provider, category, purpose, duration, status.
	 * @return array|\WP_Error La fila guardada.
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
	 * Las confirmadas, por categoría y en el orden de CATEGORIES.
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
	 * Los ajustes, completos.
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

	/** Saneado de SETTINGS (pantalla y REST). */
	public static function sanitize_settings( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'detector' => ! empty( $value['detector'] ),
			'ask'      => array_values( array_intersect( array_keys( Consent::known_categories() ), array_map( 'strval', (array) ( $value['ask'] ?? array() ) ) ) ),
		);
	}

	/**
	 * Versión de la lista: cambia cuando entra o sale una fila. La da la ruta
	 * REST de administración, para que un script sepa si algo cambió. El
	 * navegador NO la usa (ver Consent::state()).
	 */
	public static function version(): string {
		return substr( md5( implode( ',', array_keys( self::all() ) ) . '|' . ESTRATEGIA_WEB_VERSION ), 0, 8 );
	}
}
