<?php
/**
 * The links derived from the company data: tel:, mailto: and wa.me.
 *
 * PURE class on purpose (no WordPress functions): the level 1 test
 * (tests/pure.test.mjs) runs it with the machine's PHP, without Playground.
 *
 * How to maintain:
 * - A new derived link goes into Company::DERIVED, value() and labels(), and
 *   into the README table, in the same commit: it is part of the contract. Here
 *   only if it is a link of a NEW KIND; `phone_secondary_href` (0.2.0) and
 *   `estrategia_web_location_phone_href` reuse tel(). The editor (assets/editor.js) has
 *   no list of its own: it reads Company::keys() and labels().
 * - Edge case: a phone number written WITHOUT a country code («971 12 34 56»).
 *   It gets the default code (34, filterable with
 *   `estrategia_web_site_default_country_code`). One starting with «00» is treated as
 *   international («0044…» → «+44…»).
 * - The trap: WhatsApp does NOT accept «+», spaces or the «00» in wa.me: only
 *   digits with the country code. That is why the `whatsapp` setting is already
 *   saved that way, and here it is cleaned again in case it comes from elsewhere.
 */

namespace EstrategiaWeb\Site;

final class Links {

	/** «971 12 34 56» → «tel:+34971123456». Empty if there are no digits. */
	public static function tel( string $phone, string $country_code = '34' ): string {
		$international = self::international( $phone, $country_code );
		return '' === $international ? '' : 'tel:+' . $international;
	}

	/** «hello@example.com» → «mailto:hello@example.com». Does not validate: that is the sanitiser's job. */
	public static function mailto( string $email ): string {
		$email = trim( $email );
		return '' === $email ? '' : 'mailto:' . $email;
	}

	/** «34600000000» + «Hi, I'd like…» → «https://wa.me/34600000000?text=Hi%2C%20I%27d%20like…». */
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
	 * Digits only, with the country code in front. No «+».
	 *
	 * «+34 971…» and «0034 971…» → «34971…»; «971…» → default country code.
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
		// It already carries the country code (the usual case in the `whatsapp` setting, which is asked for «without +»).
		if ( '' !== $country_code && str_starts_with( $digits, $country_code ) && strlen( $digits ) > 9 ) {
			return $digits;
		}
		return $country_code . $digits;
	}
}
