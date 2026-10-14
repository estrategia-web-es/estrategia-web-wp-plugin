<?php
/**
 * El nonce del formulario: una marca de tiempo firmada con la sal del sitio.
 *
 * Clase PURA (nivel 1): recibe el secreto y la hora, no los busca.
 *
 * Por qué NO es wp_create_nonce(): un nonce de WordPress va atado al usuario,
 * y la API REST trata como ANÓNIMA cualquier petición con cookies que no
 * lleve la cabecera X-WP-Nonce. Un editor con la sesión abierta que probara
 * su propio formulario sin JavaScript fallaría siempre: el nonce se hizo para
 * su usuario y se comprueba contra el usuario 0. Este token hace lo mismo
 * —prueba que el envío sale de un formulario que pintó este servidor, para
 * esa definición y hace poco— sin depender de quién mira.
 *
 * Y de paso es el reloj del antispam: la marca de tiempo es el momento en que
 * se pintó el formulario (o el primer foco, si hay JS: ver view.js), así que
 * «demasiado rápido» y «caducado» salen de la misma comprobación.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Token {

	public const OK       = 'ok';
	public const INVALID  = 'invalid';
	public const TOO_FAST = 'too_fast';
	public const EXPIRED  = 'expired';

	public static function create( string $definition_hash, int $now, string $secret ): string {
		return $now . '.' . self::sign( $definition_hash, $now, $secret );
	}

	/**
	 * @return string Una de las constantes OK, INVALID, TOO_FAST, EXPIRED.
	 */
	public static function verify( string $token, string $definition_hash, int $now, string $secret, int $min_seconds, int $max_age ): string {
		if ( ! preg_match( '/^(\d{9,12})\.([a-f0-9]{40})$/', $token, $m ) ) {
			return self::INVALID;
		}
		$issued = (int) $m[1];
		if ( ! hash_equals( self::sign( $definition_hash, $issued, $secret ), $m[2] ) ) {
			return self::INVALID;
		}
		$age = $now - $issued;
		if ( $age < $min_seconds ) {
			return self::TOO_FAST;
		}
		if ( $age > $max_age ) {
			return self::EXPIRED;
		}
		return self::OK;
	}

	private static function sign( string $definition_hash, int $issued, string $secret ): string {
		return substr( hash_hmac( 'sha256', 'estrategia-web-forms|' . $definition_hash . '|' . $issued, $secret ), 0, 40 );
	}
}
