<?php
/**
 * La lista de redes: la opción estrategia_web_social_links, su saneado y lo
 * que recibe el bloque ya resuelto (nombre, enlace, icono).
 *
 * Forma guardada: una lista ORDENADA de
 *     { "name": "Instagram", "url": "https://instagram.com/x", "icon": "instagram", "icon_id": 0 }
 * - icon: un slug de la colección (Icons::labels()) o '' = adivinarlo del
 *   enlace al pintar (Icons::guess: instagram.com → instagram; si no es de
 *   ninguna red, «website»).
 * - icon_id: un SVG de la mediateca; si vale, manda sobre icon.
 * - name: lo que se lee. Vacío = el nombre del icono («Instagram»).
 * Se escribe en la pantalla «Estrategia Web → Redes sociales» o por REST
 * (/wp/v2/settings, solo administradores):
 *     wp option update estrategia_web_social_links '[{"name":"Instagram","url":"https://instagram.com/x"}]' --format=json
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien añada un campo a cada red (p. ej. «solo en el
 *   pie»), aquí (FIELDS, sanitize_item, esquema de REST), en la pantalla y en
 *   wpml-config.xml si se lee.
 * - CASO FRONTERA con nombre: una web que ya tenía las redes en «Datos de la
 *   empresa» (los seis campos fijos social.instagram, etc., del módulo site).
 *   Mientras esta opción NO EXISTE, la lista sale de ahí (from_company): se
 *   enciende el módulo y el bloque pinta lo que ya había, sin migrar nada.
 *   En cuanto se guarda la lista una vez (aunque sea vacía), manda la lista
 *   y los campos de la empresa dejan de usarse para el bloque (siguen
 *   valiendo para la fuente de Block Bindings `social.<red>`). La pantalla
 *   lo avisa.
 * - Con WPML: name y url se traducen como textos de administración
 *   (wpml-config.xml, <admin-texts>), por POSICIÓN en la lista. La trampa:
 *   reordenar la lista desplaza las traducciones (la del 2º pasa a ser del
 *   que ahora es 2º). Tras reordenar, repasar la traducción. Sin probar con
 *   WPML (Playground no lo tiene).
 * - La trampa de la pantalla: con la lista VACÍA el navegador no manda el
 *   campo y options.php no guardaría nada. Va un oculto vacío delante, que
 *   sanitize() descarta (no es un array).
 */

namespace EstrategiaWeb\Social;

use EstrategiaWeb\Site\Company;

defined( 'ABSPATH' ) || exit;

final class Links {

	public const OPTION = 'estrategia_web_social_links';

	/** Grupo de la Settings API (el settings_fields() de la pantalla). */
	public const GROUP = 'estrategia_web_social';

	/** Tope de redes: una lista que crece sin control es un error de quien la carga. */
	public const MAX = 30;

	/** Esquemas de enlace admitidos. Ni javascript: ni data:, claro. */
	public const PROTOCOLS = array( 'http', 'https', 'mailto', 'tel' );

	/** Las redes que había en «Datos de la empresa» (Settings::SOCIAL del módulo site) → icono. */
	private const COMPANY = array( 'instagram', 'facebook', 'linkedin', 'x', 'youtube', 'tiktok' );

	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'Social network links, in order.', 'estrategia-web' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'    => array( 'type' => 'string' ),
								'url'     => array( 'type' => 'string' ),
								'icon'    => array( 'type' => 'string' ),
								'icon_id' => array( 'type' => 'integer' ),
							),
						),
					),
				),
			)
		);
	}

	/** @param mixed $input Lo que llega del formulario o de REST. */
	public static function sanitize( $input ): array {
		$out = array();
		foreach ( is_array( $input ) ? $input : array() as $item ) {
			$item = self::sanitize_item( $item );
			if ( $item ) {
				$out[] = $item;
			}
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/** Una red saneada, o null si no tiene un enlace válido (una fila vacía de la pantalla). */
	public static function sanitize_item( $item ): ?array {
		if ( is_object( $item ) ) {
			$item = (array) $item;
		}
		if ( ! is_array( $item ) ) {
			return null;
		}
		$url = trim( (string) ( $item['url'] ?? '' ) );
		if ( is_email( $url ) ) {
			$url = 'mailto:' . $url; // Quien escribe solo el correo quiere un mailto.
		}
		$url = '' === $url ? '' : esc_url_raw( $url, self::PROTOCOLS );
		if ( '' === $url ) {
			return null;
		}
		$icon    = sanitize_key( (string) ( $item['icon'] ?? '' ) );
		$icon_id = (int) ( $item['icon_id'] ?? 0 );
		return array(
			'name'    => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
			'url'     => $url,
			'icon'    => Icons::exists( $icon ) ? $icon : '',
			'icon_id' => ( $icon_id > 0 && 'image/svg+xml' === get_post_mime_type( $icon_id ) ) ? $icon_id : 0,
		);
	}

	/** Si la lista se ha guardado alguna vez (si no, se usan las redes de la empresa). */
	public static function saved(): bool {
		return null !== get_option( self::OPTION, null );
	}

	/** La lista guardada, o la de «Datos de la empresa» si nunca se guardó (ver la cabecera). */
	public static function items(): array {
		$saved = get_option( self::OPTION, null );
		return null === $saved ? self::from_company() : self::sanitize( $saved );
	}

	/** Las redes de los seis campos fijos de «Datos de la empresa», en su orden. */
	public static function from_company(): array {
		if ( ! class_exists( Company::class ) ) {
			return array();
		}
		$out = array();
		foreach ( self::COMPANY as $network ) {
			$url = Company::value( 'social.' . $network );
			if ( '' !== $url ) {
				$out[] = array( 'name' => '', 'url' => $url, 'icon' => $network, 'icon_id' => 0 );
			}
		}
		return $out;
	}

	/**
	 * Lo que pinta el bloque: cada red con su nombre ya decidido, su icono en
	 * <svg> y si abre en pestaña nueva. Las que se quedan sin icono no salen.
	 *
	 * @return array<int,array{name:string,url:string,icon:string,svg:string,new_tab:bool}>
	 */
	public static function resolved( string $svg_class = '' ): array {
		$labels = Icons::labels();
		$out    = array();
		foreach ( self::items() as $item ) {
			$slug = '' !== $item['icon'] ? $item['icon'] : Icons::guess( $item['url'] );
			$svg  = $item['icon_id'] ? Icons::attachment_svg( (int) $item['icon_id'], $svg_class ) : '';
			if ( '' === $svg ) {
				$svg = Icons::svg( $slug, $svg_class );
			}
			if ( '' === $svg ) {
				continue;
			}
			$out[] = array(
				'name'    => '' !== $item['name'] ? $item['name'] : ( $labels[ $slug ] ?? $item['url'] ),
				'url'     => $item['url'],
				'icon'    => $slug,
				'svg'     => $svg,
				// Pestaña nueva solo para las web: un mailto: o un tel: no abren página.
				'new_tab' => (bool) preg_match( '#^https?://#i', $item['url'] ),
			);
		}
		return $out;
	}

	/** El nombre de la empresa para «Instagram de <Empresa>»: el de «Datos de la empresa» o el del sitio. */
	public static function owner(): string {
		$name = class_exists( Company::class ) ? Company::value( 'company_name' ) : '';
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}
}
