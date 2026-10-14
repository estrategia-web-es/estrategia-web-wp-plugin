<?php
/**
 * Rutas REST del registro de cookies (espacio estrategia-web/v1).
 *
 * PÚBLICA — POST /cookies/seen  {"names": ["_ga", "_ga_ABC123", …]}
 *   La llama el navegador de un visitante que ACEPTÓ alguna categoría
 *   (blocks/consent/view.js), con los NOMBRES de las cookies que ve por
 *   document.cookie. Nunca valores. Contesta 202 sin contar nada de la lista.
 *
 *   Por qué SIN nonce: la página puede venir de una caché (SiteGround, etc.)
 *   y el nonce de la caché caduca a las 12-24 h; con nonce el detector se
 *   apagaría solo en las webs con caché, que son casi todas. En su lugar:
 *   - Ritmo por cliente: una petición cada REPORT_INTERVAL segundos por IP.
 *     La IP NO se guarda: solo un hash con sal del día, en un transitorio que
 *     caduca con el intervalo. (Es la IP de REMOTE_ADDR: detrás de un proxy
 *     todos comparten una, y eso solo hace el límite más estricto.)
 *   - Ritmo global: HOURLY_CAP peticiones por hora para toda la web.
 *   - Tamaño: MAX_NAMES nombres por petición, cada uno con los caracteres de
 *     RFC 6265 y ≤ 128; el registro no pasa de Cookie_Registry::MAX_ENTRIES.
 *   - Origen: si llega cabecera Origin, tiene que ser la de la web.
 *   - Con el detector apagado (pantalla Cookies) contesta 403 y no apunta nada.
 *   Lo peor que puede hacer alguien que la ataque: llenar la lista de
 *   pendientes con nombres inventados (como mucho MAX_ENTRIES), que no salen
 *   en la política hasta que alguien los confirma, y se borran en la pantalla.
 *
 * DE ADMINISTRACIÓN (manage_options) — GET/POST/DELETE /cookies
 *   Lo mismo que la pantalla, para scripts y pruebas: GET lista todo (con la
 *   versión y la atribución de la base), POST {name, provider, category,
 *   purpose, duration, status} crea o cambia, DELETE ?name= borra.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque el detector. Prueba:
 *   modules/consent/tests/cookies.test.mjs (nivel 2: límites, origen, valores
 *   que no se guardan) y cookies-browser.test.mjs (nivel 3: lo que manda un
 *   navegador de verdad al aceptar).
 * - Los límites se afinan con los filtros estrategia_web_cookies_report_interval
 *   y estrategia_web_cookies_hourly_cap (segundos y peticiones/hora).
 * - Caso frontera: dos visitantes detrás de la misma IP (una oficina). El
 *   segundo recibe 429 durante el intervalo; no pasa nada: el primero ya
 *   mandó lo mismo, y el navegador lo vuelve a intentar al cambiar la lista o
 *   al acabar el periodo.
 * - La trampa: el transitorio de ritmo es un option en la base de datos si no
 *   hay caché de objetos. Por eso la clave lleva un hash corto y caduca sola,
 *   y las pruebas borran estrategia_web_cs_* (la ruta de reset de su mu-plugin).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Cookie_Rest {

	public const NAMESPACE = 'estrategia-web/v1';

	/** Nombres como mucho por petición. */
	public const MAX_NAMES = 60;

	/** Prefijo de los transitorios de ritmo (las pruebas los borran). */
	public const RATE_PREFIX = 'estrategia_web_cs_';

	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/cookies/seen',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'seen' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'names' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
						'maxItems' => self::MAX_NAMES,
					),
				),
			)
		);
		$admin = static fn() => current_user_can( 'manage_options' );
		register_rest_route(
			self::NAMESPACE,
			'/cookies',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'index' ),
					'permission_callback' => $admin,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => $admin,
					'args'                => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'delete' ),
					'permission_callback' => $admin,
					'args'                => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/** Segundos entre dos informes del mismo cliente. */
	public static function interval(): int {
		return max( 0, (int) apply_filters( 'estrategia_web_cookies_report_interval', 10 * MINUTE_IN_SECONDS ) );
	}

	/** Informes por hora para toda la web. */
	public static function hourly_cap(): int {
		return max( 0, (int) apply_filters( 'estrategia_web_cookies_hourly_cap', 100 ) );
	}

	/** El informe del detector. Ver la cabecera. */
	public static function seen( \WP_REST_Request $request ) {
		if ( ! Consent::active() || ! Cookie_Registry::settings()['detector'] ) {
			return new \WP_Error( 'estrategia_web_detector_off', 'Detector off.', array( 'status' => 403 ) );
		}
		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new \WP_Error( 'estrategia_web_detector_origin', 'Wrong origin.', array( 'status' => 403 ) );
		}

		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$client = self::RATE_PREFIX . substr( md5( $ip . '|' . gmdate( 'Ymd' ) . '|' . wp_salt( 'nonce' ) ), 0, 16 );
		$hour   = self::RATE_PREFIX . 'hour_' . gmdate( 'YmdH' );
		$count  = (int) get_transient( $hour );
		if ( ( self::interval() > 0 && get_transient( $client ) ) || $count >= self::hourly_cap() ) {
			return new \WP_Error( 'estrategia_web_detector_rate', 'Too many reports.', array( 'status' => 429 ) );
		}
		if ( self::interval() > 0 ) {
			set_transient( $client, 1, self::interval() );
		}
		set_transient( $hour, $count + 1, HOUR_IN_SECONDS );

		$names = array_slice( array_filter( (array) $request->get_param( 'names' ), 'is_string' ), 0, self::MAX_NAMES );
		Cookie_Registry::record_seen( $names );
		return new \WP_REST_Response( array( 'ok' => true ), 202 );
	}

	public static function index(): array {
		$ocd = Cookie_Registry::ocd();
		return array(
			'version'     => Cookie_Registry::version(),
			'settings'    => Cookie_Registry::settings(),
			'cookies'     => (object) Cookie_Registry::all(),
			'attribution' => array(
				'source'      => $ocd['source'] ?? '',
				'license'     => $ocd['license'] ?? '',
				'attribution' => $ocd['attribution'] ?? '',
				'generated'   => $ocd['generated'] ?? '',
			),
		);
	}

	public static function save( \WP_REST_Request $request ) {
		$entry = Cookie_Registry::save( (string) $request->get_param( 'name' ), (array) $request->get_params() );
		return is_wp_error( $entry ) ? $entry : array(
			'name'  => trim( (string) $request->get_param( 'name' ) ),
			'entry' => $entry,
		);
	}

	public static function delete( \WP_REST_Request $request ) {
		return array( 'deleted' => Cookie_Registry::delete( (string) $request->get_param( 'name' ) ) );
	}
}
