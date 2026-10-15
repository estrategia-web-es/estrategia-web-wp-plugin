<?php
/**
 * The sender's IP, truncated before storing it (GDPR).
 *
 * PURE class (level 1). The log stores the TRUNCATED IP —IPv4 without the
 * last octet, IPv6 without the last 80 bits—, which is enough to see «they
 * all come from the same network» without identifying a person. The
 * submission limit uses a hash salted with a server secret (Submission
 * handler), which is stored nowhere but in a transient that expires.
 *
 * The trap: behind a proxy or the SiteGround cache, REMOTE_ADDR may be the
 * proxy's. X-Forwarded-For headers are not read by default because the
 * client writes them and they can be forged; a site behind a trusted proxy
 * declares it with the 'estrategia_web_forms_client_ip' filter.
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
			// Keep the first 48 bits (6 bytes): the provider's network.
			$bin = substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
			return (string) inet_ntop( $bin );
		}
		return '';
	}
}
