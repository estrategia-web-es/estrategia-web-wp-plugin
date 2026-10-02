<?php
/**
 * Los enlaces que salen de los datos de la empresa: tel:, mailto: y wa.me.
 *
 * Clase PURA a propósito (sin funciones de WordPress): la prueba de nivel 1
 * (tests/pure.test.mjs) la corre con el PHP de la máquina, sin Playground.
 *
 * Cómo se mantiene:
 * - Un enlace derivado nuevo entra en Company::DERIVED, value() y labels(), y
 *   en la tabla del README, en el mismo commit: es parte del contrato. Aquí
 *   solo si es un enlace de una CLASE nueva; `phone_secondary_href` (0.2.0) y
 *   `location_phone_href` reutilizan tel(). El editor (assets/editor.js) no
 *   tiene lista propia: lee Company::keys() y labels().
 * - Caso frontera: un teléfono escrito SIN prefijo («971 12 34 56»). Se le
 *   pone el prefijo por defecto (34, filtrable con
 *   `armazon_site_default_country_code`). Uno con «00» delante se trata como
 *   internacional («0044…» → «+44…»).
 * - La trampa: WhatsApp NO acepta «+», ni espacios, ni el «00» en wa.me: solo
 *   dígitos con el prefijo del país. Por eso el ajuste `whatsapp` se guarda ya
 *   así, y aquí se vuelve a limpiar por si llega de otro sitio.
 */

namespace Armazon\Site;

final class Links {

	/** «971 12 34 56» → «tel:+34971123456». Vacío si no hay dígitos. */
	public static function tel( string $phone, string $country_code = '34' ): string {
		$international = self::international( $phone, $country_code );
		return '' === $international ? '' : 'tel:+' . $international;
	}

	/** «hola@ejemplo.com» → «mailto:hola@ejemplo.com». No valida: eso es del saneado. */
	public static function mailto( string $email ): string {
		$email = trim( $email );
		return '' === $email ? '' : 'mailto:' . $email;
	}

	/** «34600000000» + «Hola, quería…» → «https://wa.me/34600000000?text=Hola%2C%20quer%C3%ADa…». */
	public static function whatsapp( string $number, string $message = '', string $country_code = '34' ): string {
		$international = self::international( $number, $country_code );
		if ( '' === $international ) {
			return '';
		}
		$url     = 'https://wa.me/' . $international;
		$message = trim( $message );
		return '' === $message ? $url : $url . '?text=' . rawurlencode( $message );
	}

	/**
	 * Solo dígitos, con el prefijo del país delante. Sin «+».
	 *
	 * «+34 971…» y «0034 971…» → «34971…»; «971…» → prefijo por defecto.
	 */
	public static function international( string $phone, string $country_code = '34' ): string {
		$phone = trim( $phone );
		$plus  = str_starts_with( $phone, '+' );
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}
		if ( $plus ) {
			return $digits;
		}
		if ( str_starts_with( $digits, '00' ) ) {
			return substr( $digits, 2 );
		}
		$country_code = preg_replace( '/\D+/', '', $country_code );
		// Ya lleva el prefijo (lo normal en el ajuste `whatsapp`, que se pide «sin +»).
		if ( '' !== $country_code && str_starts_with( $digits, $country_code ) && strlen( $digits ) > 9 ) {
			return $digits;
		}
		return $country_code . $digits;
	}
}
