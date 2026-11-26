<?php
/**
 * RECOMMENDED (async: a DNS lookup): no DMARC record for the site's mail
 * domain.
 *
 * Criterion: without DMARC (a TXT record «v=DMARC1…» at _dmarc.<domain>)
 * Gmail and Outlook trust the domain's mail less — the site's own forms'
 * notifications and the company's mail land in spam more often — and
 * anyone can send mail «from» the domain. Since 2024 Gmail and Yahoo
 * require it from bulk senders.
 *
 * The domain: the home URL's host without «www.». If no record is found
 * there and the host has three labels or more (shop.example.com), the last
 * two are tried too (example.com): DMARC applies from the organisational
 * domain down. Edge case, by name: a two-label public suffix (example.co.uk,
 * example.com.es) is tried as «co.uk»/«com.es» in that second step and finds
 * nothing; it reads as «missing» only if the first lookup ALSO answered.
 *
 * A FAILED lookup is «could not check», never «missing»:
 * dns_get_record() returns false (no resolver, a timeout, the function
 * disabled by the hosting); an empty array means the DNS answered and
 * there is no record.
 *
 * Why async: a DNS query can take seconds; the Site Health screen runs it
 * through our REST route.
 *
 * Real case: one of the findings of the Llar d'Estels audit (25-26/11/2026).
 * The fix is in the domain's DNS, not in WordPress: the action is a
 * sentence, not a link.
 *
 * The trap: dns_get_record() warns (E_WARNING) on a failure instead of
 * throwing; the call is silenced, and only its return value is read.
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'     => 'dmarc',
	'async'  => true,
	'title'  => static fn() => __( 'The mail domain has a DMARC record', 'estrategiaweb-toolkit' ),
	'gather' => static function (): array {
		$host = preg_replace( '/^www\./i', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$out  = array(
			'domain'  => $host,
			'lookups' => array(),
		);
		if ( ! function_exists( 'dns_get_record' ) || '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $out;
		}
		$labels     = explode( '.', $host );
		$candidates = array_unique( array( $host, implode( '.', array_slice( $labels, -2 ) ) ) );
		foreach ( $candidates as $domain ) {
			$records = @dns_get_record( '_dmarc.' . $domain, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see «The trap».
			$txt     = false === $records ? false : array_map( static fn( $r ) => (string) ( $r['txt'] ?? implode( '', (array) ( $r['entries'] ?? array() ) ) ), (array) $records );
			$out['lookups'][ $domain ] = $txt;
			if ( $txt && preg_grep( '/^\s*v=DMARC1/i', $txt ) ) {
				break;
			}
		}
		return $out;
	},
	'judge'  => static function ( array $d ): array {
		$domain  = (string) ( $d['domain'] ?? '' );
		$lookups = (array) ( $d['lookups'] ?? array() );
		foreach ( $lookups as $name => $txt ) {
			$record = is_array( $txt ) ? array_values( preg_grep( '/^\s*v=DMARC1/i', array_map( 'strval', $txt ) ) ) : array();
			if ( $record ) {
				return array(
					'status'      => 'good',
					'label'       => __( 'The mail domain has a DMARC record', 'estrategiaweb-toolkit' ),
					/* translators: 1: a domain, 2: its DMARC record. */
					'description' => sprintf( __( '_dmarc.%1$s: %2$s', 'estrategiaweb-toolkit' ), (string) $name, $record[0] ),
				);
			}
		}
		$answered = $lookups && false !== reset( $lookups );
		if ( ! $answered ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Could not check the DMARC record', 'estrategiaweb-toolkit' ),
				/* translators: %s: a domain. */
				'description' => sprintf( __( 'The DNS lookup of _dmarc.%s did not answer from this server, so it is not known whether the record exists. Check it with any online DMARC checker.', 'estrategiaweb-toolkit' ), $domain ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'The mail domain has no DMARC record', 'estrategiaweb-toolkit' ),
			'description' => __( 'Without DMARC, mail from the domain (the site\'s form notifications too) lands in spam more often, and anyone can send mail pretending to be the domain.', 'estrategiaweb-toolkit' ),
			'actions'     => array(
				array(
					/* translators: %s: a domain. */
					'text' => sprintf( __( 'Add a TXT record at _dmarc.%s in the domain\'s DNS, for example «v=DMARC1; p=none; rua=mailto:…» to start.', 'estrategiaweb-toolkit' ), $domain ),
				),
			),
		);
	},
);
