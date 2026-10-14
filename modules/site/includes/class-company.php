<?php
/**
 * Leer un dato de la empresa por su CLAVE de contrato: la que usan la fuente
 * de Block Bindings `estrategia-web-site/company`, el editor y cualquier tema.
 *
 * Claves: las de Settings::DEFAULTS (menos enabled_types, allow_svg y
 * rewrite_slugs, que son opciones y no datos: NOT_DATA), `social.<red>` con punto, y las DERIVADAS de abajo.
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien añada una clave, en el mismo commit que su prueba
 *   (tests/bindings.test.mjs) y su fila en el README.
 * - Una clave derivada nueva: DERIVED + value() + labels() (+ Links si es un
 *   enlace de un tipo nuevo) + README. assets/editor.js NO se toca: lee las
 *   claves de keys() y labels(). Es contrato: no se renombra. La última que
 *   entró, `phone_secondary_href` (0.2.0), es el ejemplo.
 * - Una clave nueva sale SOLA también en el shortcode `[estrategia_web_company]`
 *   (0.4.0, includes/class-shortcode.php), que lee value().
 * - Los datos de UNA sede no van aquí: son de la fuente `estrategia-web-site/location`
 *   (includes/class-location.php).
 * - Caso frontera: una clave que no existe («phone_mobile») devuelve '' y no
 *   un error: un bloque mal enlazado se ve vacío en la web y en el editor
 *   enseña el nombre de la clave, que es donde se nota.
 * - La trampa: para un tema, `Company::value()` es la API pública en PHP;
 *   leer `get_option('estrategia_web_site')` directamente se salta el filtro
 *   `estrategia_web_site_settings` y las derivadas.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Company {

	public const DERIVED = array( 'phone_href', 'phone_secondary_href', 'email_href', 'whatsapp_href' );

	/**
	 * Claves que son opciones del plugin, no datos que se enseñan. Una opción
	 * nueva en Settings::DEFAULTS entra aquí, o aparece como dato enlazable en
	 * el editor (las de Matomo, 0.3.0, son el ejemplo).
	 */
	private const NOT_DATA = array( 'enabled_types', 'allow_svg', 'social', 'rewrite_slugs', 'matomo_url', 'matomo_site_id', 'matomo_mode' );

	/** Prefijo de país para teléfonos escritos sin él. */
	public static function country_code(): string {
		return (string) apply_filters( 'estrategia_web_site_default_country_code', '34' );
	}

	/** @return string[] Todas las claves válidas, en orden de pantalla. */
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

	/** El valor en texto plano (sin escapar). '' si la clave no existe o está vacía. */
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
		}
		if ( str_starts_with( $key, 'social.' ) ) {
			return (string) ( $data['social'][ substr( $key, 7 ) ] ?? '' );
		}
		if ( in_array( $key, self::NOT_DATA, true ) || ! array_key_exists( $key, Settings::DEFAULTS ) ) {
			return '';
		}
		return (string) $data[ $key ];
	}

	/**
	 * Nombre legible de cada clave: lo usan la pantalla de ajustes y la lista
	 * de campos de la fuente en el editor. @return array<string,string>
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

	/** Todos los datos por clave: lo que recibe el editor. @return array<string,string> */
	public static function values(): array {
		$out = array();
		foreach ( self::keys() as $key ) {
			$out[ $key ] = self::value( $key );
		}
		return $out;
	}
}
