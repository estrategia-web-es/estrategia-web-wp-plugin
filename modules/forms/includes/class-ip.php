<?php
/**
 * La IP del remitente, recortada antes de guardarla (RGPD).
 *
 * Clase PURA (nivel 1). En el registro se guarda la IP TRUNCADA —IPv4 sin el
 * último octeto, IPv6 sin los últimos 80 bits—, que basta para ver «vienen
 * todos de la misma red» sin identificar a una persona. Para el límite de
 * envíos se usa un hash con sal del servidor (Submission handler), que no se
 * guarda en ningún sitio más que en un transitorio que caduca.
 *
 * La trampa: detrás de un proxy o de la caché de SiteGround REMOTE_ADDR puede
 * ser la del proxy. No se leen cabeceras X-Forwarded-For por defecto porque
 * las escribe el cliente y se falsifican; la web que esté detrás de un proxy
 * de confianza lo declara con el filtro 'estrategia_web_forms_client_ip'.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Ip {

	public static function truncate( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			// Se conservan los primeros 48 bits (6 bytes): la red del proveedor.
			$bin = substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
			return (string) inet_ntop( $bin );
		}
		return '';
	}
}
