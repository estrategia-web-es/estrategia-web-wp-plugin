<?php
/**
 * Los datos de la empresa: UNA opción, `estrategia_web_site` (array), registrada con
 * la Settings API y expuesta en REST (/wp/v2/settings) con su esquema.
 *
 * Es CONTRATO: el tema padre, los temas hijos y la carga de contenido leen y
 * escriben estos nombres (README, «El contrato»). Renombrar una clave es
 * versión mayor.
 *
 * Cómo se guarda (y por qué así):
 * - Se FUSIONA con lo que ya hay: un PUT por REST con solo {"phone": "…"}
 *   cambia el teléfono y deja lo demás. Es lo que necesita la carga de
 *   contenido (rellenar por partes). La pantalla de ajustes manda siempre
 *   todas las claves (con campos ocultos para las casillas), así que una
 *   casilla desmarcada sí se guarda como «no».
 * - Lo que no valida NO se guarda y se queda el valor anterior: un correo mal
 *   escrito no borra el bueno. En la pantalla sale un aviso; por REST la
 *   respuesta devuelve el valor que quedó, que es la forma de notarlo.
 *
 * Cómo se mantiene:
 * - Una clave nueva entra en DEFAULTS, en schema(), en sanitize(), en la
 *   pantalla (Admin), en la lista del editor (assets/editor.js) y en el
 *   README, en el mismo commit. Si una prueba la necesita, también en
 *   tests/lib.mjs. Si es una OPCIÓN y no un dato que se enseña (como las de
 *   Matomo), también en Company::NOT_DATA.
 * - Caso frontera: un dato que solo tiene UNA web (el número de registro
 *   sanitario de una clínica). No entra: va al tema hijo. Entra lo que tendría
 *   cualquier web corporativa.
 * - Los objetos (`social`, `rewrite_slugs` desde 0.2.0) se fusionan también
 *   POR CLAVE: mandar {"rewrite_slugs":{"client":"cliente"}} no borra el slug
 *   fijado de `client_sector`. Y se guardan siempre con todas sus claves (las
 *   no fijadas, ''), para que REST devuelva un objeto y no un [] vacío.
 * - `rewrite_slugs` NO sale en la pantalla, a propósito: cambia URL
 *   publicadas y lo fija quien monta la web (REST, carga de contenido). La
 *   pantalla no lo manda, y la fusión lo conserva al guardarla.
 * - Caso frontera de `rewrite_slugs`: un nombre que no es del plugin
 *   («services») da 400 por REST (additionalProperties); por
 *   `wp option update` se ignora en silencio.
 * - Datos legales (0.4.0): `legal_name` (razón social), `tax_id` (NIF/CIF),
 *   `address` (domicilio) y `registry`. `registry` es UN texto libre y no
 *   tomo/folio/hoja/inscripción por separado, a propósito: el aviso legal lo
 *   escribe como una frase, y no toda empresa está en el Mercantil (un
 *   autónomo no tiene; una cooperativa va a otro registro). Se escribe sin
 *   «Inscrita en», que lo pone la frase. Caso frontera: el domicilio social
 *   distinto de la dirección de la tienda. Hoy no tiene clave propia; si
 *   varias webs lo piden, entra `legal_address` (versión menor).
 * - Matomo (0.3.0): `matomo_url` se guarda SIEMPRE acabada en «/» y sin el
 *   «matomo.php»/«matomo.js» que se pega a veces del código de seguimiento;
 *   `matomo_site_id` es entero (0 = sin configurar); `matomo_mode` es uno de
 *   MATOMO_MODES (otro valor por REST → 400; por la pantalla, se queda el
 *   anterior). Qué hace cada modo, en la cabecera de class-matomo.php.
 * - La trampa: el controlador REST de ajustes devuelve null para TODA la
 *   opción si lo guardado no cumple el esquema (un tipo distinto, una clave de
 *   enabled_types que ya no está en el enum). Por eso sanitize() deja siempre
 *   los tipos exactos del esquema (el id de Matomo, int; nunca "3"), y quitar
 *   un valor del enum de enabled_types o de matomo_mode es versión mayor.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'estrategia_web_site';

	/** Los tipos de contenido que se pueden activar. Orden = orden del menú. */
	public const TYPES = array( 'client', 'testimonial', 'location', 'project', 'portfolio' );

	public const SOCIAL = array( 'facebook', 'instagram', 'linkedin', 'x', 'youtube', 'tiktok' );

	/** Modos de Matomo (0.3.0). El primero es el de por defecto. Ver class-matomo.php. */
	public const MATOMO_MODES = array( 'cookieless', 'hybrid' );

	/**
	 * Los nombres que admite `rewrite_slugs` (0.2.0): los tipos y las
	 * taxonomías del plugin. Una taxonomía nueva en Content_Types entra aquí
	 * también, o su slug no se podrá fijar.
	 */
	public const REWRITE_NAMES = array( 'client', 'client_sector', 'testimonial', 'location', 'project', 'project_category', 'portfolio', 'portfolio_category' );

	/** Campos de texto de una línea. */
	private const TEXT = array( 'company_name', 'legal_name', 'tax_id', 'registry', 'phone', 'phone_secondary', 'whatsapp_message' );

	/** Campos de varias líneas (los saltos se conservan y se pintan como <br>). */
	private const TEXTAREA = array( 'address', 'schedule' );

	public const DEFAULTS = array(
		'company_name'     => '',
		'legal_name'       => '',
		'tax_id'           => '',
		// 0.4.0. La inscripción tal como se escribe en el aviso legal (ver «Datos legales»).
		'registry'         => '',
		'email'            => '',
		'phone'            => '',
		'phone_secondary'  => '',
		'whatsapp'         => '',
		'whatsapp_message' => '',
		'address'          => '',
		'schedule'         => '',
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
		// 0.2.0. Vacío = el slug traducido de siempre. Ver Content_Types::slug().
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
		// 0.3.0. Sin URL o sin id no se imprime nada (Matomo::config()).
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
				'label'             => __( 'Company details', 'estrategia-web-site' ),
				'description'       => __( 'Company details and site options of the Estrategia Web Site plugin.', 'estrategia-web-site' ),
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array( 'schema' => self::schema() ),
			)
		);
	}

	/** Esquema REST. Sin `format: email` a propósito: ver «Cómo se guarda». */
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

	/** @return array<string, mixed> Siempre con todas las claves de DEFAULTS. */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$all    = array_merge( self::DEFAULTS, $stored );
		$all['social'] = array_merge( self::DEFAULTS['social'], is_array( $all['social'] ) ? $all['social'] : array() );
		$all['rewrite_slugs'] = array_merge( self::DEFAULTS['rewrite_slugs'], is_array( $all['rewrite_slugs'] ) ? $all['rewrite_slugs'] : array() );
		/**
		 * Permite a una web fijar datos por código (por ejemplo, un entorno de
		 * pruebas con otro teléfono).
		 *
		 * @param array $all Datos ya mezclados con los valores por defecto.
		 */
		return (array) apply_filters( 'estrategia_web_site_settings', $all );
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/** @return string[] */
	public static function enabled_types(): array {
		return array_values( array_intersect( self::TYPES, (array) self::get( 'enabled_types' ) ) );
	}

	/**
	 * Fusiona lo recibido con lo guardado y valida cada clave. Se llama desde
	 * la pantalla, desde REST y desde `wp option update` (sanitize_option).
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

		if ( array_key_exists( 'email', $input ) ) {
			$email = sanitize_email( (string) $input['email'] );
			if ( '' === trim( (string) $input['email'] ) ) {
				$out['email'] = '';
			} elseif ( is_email( $email ) && $email === trim( (string) $input['email'] ) ) {
				$out['email'] = $email;
			} else {
				self::error( 'email', __( 'The email address is not valid. The previous one has been kept.', 'estrategia-web-site' ) );
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
					self::error( 'whatsapp', __( 'The WhatsApp number is not valid (international format, digits only). The previous one has been kept.', 'estrategia-web-site' ) );
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
			// La pantalla manda un '' oculto para que «ninguno» llegue: se descarta aquí.
			$out['enabled_types'] = array_values( array_intersect( self::TYPES, array_map( 'strval', (array) $input['enabled_types'] ) ) );
		}

		if ( array_key_exists( 'allow_svg', $input ) ) {
			$out['allow_svg'] = rest_sanitize_boolean( $input['allow_svg'] );
		}

		// Por clave, como social: mandar un slug no borra los demás. Fuera de
		// REST (que ya da 400) un nombre desconocido se ignora.
		if ( isset( $input['rewrite_slugs'] ) && ( is_array( $input['rewrite_slugs'] ) || is_object( $input['rewrite_slugs'] ) ) ) {
			foreach ( (array) $input['rewrite_slugs'] as $name => $slug ) {
				if ( in_array( $name, self::REWRITE_NAMES, true ) ) {
					$out['rewrite_slugs'][ $name ] = sanitize_title( (string) $slug );
				}
			}
		}

		if ( array_key_exists( 'matomo_url', $input ) ) {
			// Se acepta lo que se pega del código de seguimiento («…/matomo.php»).
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

		// Solo las claves del contrato, con sus tipos exactos (ver «La trampa»).
		$out                  = array_intersect_key( $out, self::DEFAULTS );
		$out['social']        = array_map( 'strval', array_intersect_key( $out['social'], self::DEFAULTS['social'] ) );
		$out['rewrite_slugs'] = array_map( 'strval', array_intersect_key( $out['rewrite_slugs'], self::DEFAULTS['rewrite_slugs'] ) );
		$out['allow_svg']      = (bool) $out['allow_svg'];
		$out['matomo_url']     = (string) $out['matomo_url'];
		$out['matomo_site_id'] = absint( $out['matomo_site_id'] );
		$out['matomo_mode']    = in_array( $out['matomo_mode'], self::MATOMO_MODES, true ) ? $out['matomo_mode'] : self::MATOMO_MODES[0];
		return $out;
	}

	/** URL http(s) limpia, '' si viene vacía, o null si no vale (se queda la anterior). */
	private static function url( string $raw, string $field ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$url = esc_url_raw( $raw, array( 'http', 'https' ) );
		// filter_var y no wp_http_validate_url(): esa resuelve el DNS del dominio.
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://[^/]+\.[^/]+#i', $url ) ) {
			/* translators: %s: the field name. */
			self::error( $field, sprintf( __( 'The address in «%s» is not a valid web address. The previous one has been kept.', 'estrategia-web-site' ), $field ) );
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
