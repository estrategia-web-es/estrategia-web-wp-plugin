<?php
/**
 * Los datos de la empresa: UNA opción, `armazon_site` (array), registrada con
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
 *   tests/lib.mjs.
 * - Caso frontera: un dato que solo tiene UNA web (el número de registro
 *   sanitario de una clínica). No entra: va al tema hijo. Entra lo que tendría
 *   cualquier web corporativa.
 * - La trampa: el controlador REST de ajustes devuelve null para TODA la
 *   opción si lo guardado no cumple el esquema (un tipo distinto, una clave de
 *   enabled_types que ya no está en el enum). Por eso sanitize() deja siempre
 *   los tipos exactos del esquema, y quitar un valor del enum de
 *   enabled_types es versión mayor.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'armazon_site';

	/** Los tipos de contenido que se pueden activar. Orden = orden del menú. */
	public const TYPES = array( 'client', 'testimonial', 'location', 'project', 'portfolio' );

	public const SOCIAL = array( 'facebook', 'instagram', 'linkedin', 'x', 'youtube', 'tiktok' );

	/** Campos de texto de una línea. */
	private const TEXT = array( 'company_name', 'legal_name', 'tax_id', 'phone', 'phone_secondary', 'whatsapp_message' );

	/** Campos de varias líneas (los saltos se conservan y se pintan como <br>). */
	private const TEXTAREA = array( 'address', 'schedule' );

	public const DEFAULTS = array(
		'company_name'     => '',
		'legal_name'       => '',
		'tax_id'           => '',
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
	);

	public static function register(): void {
		register_setting(
			'armazon_site',
			self::OPTION,
			array(
				'type'              => 'object',
				'label'             => __( 'Company details', 'armazon-site' ),
				'description'       => __( 'Company details and site options of the Armazón Site plugin.', 'armazon-site' ),
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
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'company_name'     => $string,
				'legal_name'       => $string,
				'tax_id'           => $string,
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
			),
		);
	}

	/** @return array<string, mixed> Siempre con todas las claves de DEFAULTS. */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$all    = array_merge( self::DEFAULTS, $stored );
		$all['social'] = array_merge( self::DEFAULTS['social'], is_array( $all['social'] ) ? $all['social'] : array() );
		/**
		 * Permite a una web fijar datos por código (por ejemplo, un entorno de
		 * pruebas con otro teléfono).
		 *
		 * @param array $all Datos ya mezclados con los valores por defecto.
		 */
		return (array) apply_filters( 'armazon_site_settings', $all );
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
				self::error( 'email', __( 'The email address is not valid. The previous one has been kept.', 'armazon-site' ) );
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
					self::error( 'whatsapp', __( 'The WhatsApp number is not valid (international format, digits only). The previous one has been kept.', 'armazon-site' ) );
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

		// Solo las claves del contrato, con sus tipos exactos (ver «La trampa»).
		$out              = array_intersect_key( $out, self::DEFAULTS );
		$out['social']    = array_map( 'strval', array_intersect_key( $out['social'], self::DEFAULTS['social'] ) );
		$out['allow_svg'] = (bool) $out['allow_svg'];
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
			self::error( $field, sprintf( __( 'The address in «%s» is not a valid web address. The previous one has been kept.', 'armazon-site' ), $field ) );
			return null;
		}
		return $url;
	}

	private static function error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'armazon_site_' . $code, $message );
		}
	}
}
