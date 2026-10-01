<?php
/**
 * Leer un dato de la empresa por su CLAVE de contrato: la que usan la fuente
 * de Block Bindings `armazon-site/company`, el editor y cualquier tema.
 *
 * Claves: las de Settings::DEFAULTS (menos enabled_types y allow_svg, que son
 * opciones y no datos), `social.<red>` con punto, y las DERIVADAS de abajo.
 *
 * Cómo se mantiene:
 * - Una clave derivada nueva: DERIVED + value() + Links si es un enlace +
 *   assets/editor.js (FIELDS) + README. Es contrato: no se renombra.
 * - Caso frontera: una clave que no existe («phone_mobile») devuelve '' y no
 *   un error: un bloque mal enlazado se ve vacío en la web y en el editor
 *   enseña el nombre de la clave, que es donde se nota.
 * - La trampa: para un tema, `Company::value()` es la API pública en PHP;
 *   leer `get_option('armazon_site')` directamente se salta el filtro
 *   `armazon_site_settings` y las derivadas.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Company {

	public const DERIVED = array( 'phone_href', 'email_href', 'whatsapp_href' );

	/** Claves que son opciones del plugin, no datos que se enseñan. */
	private const NOT_DATA = array( 'enabled_types', 'allow_svg', 'social' );

	/** Prefijo de país para teléfonos escritos sin él. */
	public static function country_code(): string {
		return (string) apply_filters( 'armazon_site_default_country_code', '34' );
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
			'company_name'     => __( 'Company name', 'armazon-site' ),
			'legal_name'       => __( 'Legal name', 'armazon-site' ),
			'tax_id'           => __( 'Tax ID', 'armazon-site' ),
			'email'            => __( 'Email', 'armazon-site' ),
			'phone'            => __( 'Phone', 'armazon-site' ),
			'phone_secondary'  => __( 'Secondary phone', 'armazon-site' ),
			'whatsapp'         => __( 'WhatsApp', 'armazon-site' ),
			'whatsapp_message' => __( 'WhatsApp message', 'armazon-site' ),
			'address'          => __( 'Address', 'armazon-site' ),
			'schedule'         => __( 'Opening hours', 'armazon-site' ),
			'map_url'          => __( 'Map link', 'armazon-site' ),
			'social.facebook'  => __( 'Facebook', 'armazon-site' ),
			'social.instagram' => __( 'Instagram', 'armazon-site' ),
			'social.linkedin'  => __( 'LinkedIn', 'armazon-site' ),
			'social.x'         => __( 'X (Twitter)', 'armazon-site' ),
			'social.youtube'   => __( 'YouTube', 'armazon-site' ),
			'social.tiktok'    => __( 'TikTok', 'armazon-site' ),
			'phone_href'       => __( 'Phone (link)', 'armazon-site' ),
			'email_href'       => __( 'Email (link)', 'armazon-site' ),
			'whatsapp_href'    => __( 'WhatsApp (link)', 'armazon-site' ),
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
