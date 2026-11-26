<?php
/**
 * RECOMMENDED: cookies waiting for confirmation in the consent module's
 * registry, or no published page shows the cookie table.
 * Only registered when the consent module is on.
 *
 * Criterion: the consent module DETECTS the cookies visitors' browsers
 * carry and lists them as «pending» (option estrategia_web_cookies, status
 * pending) until someone confirms their category, purpose and duration. A
 * pending cookie is not in the cookie table visitors read, so the cookie
 * policy is incomplete while it waits. And the table only reaches visitors
 * on a page that has the estrategia-web/cookie-table block.
 *
 * Edge case, by name: «ignored» cookies do not count (someone decided), nor
 * do «confirmed» ones. A cookie policy page WITHOUT the table is also
 * flagged by legal-pages only if it is missing altogether; here it is
 * flagged because its list does not follow the registry.
 *
 * Real case: one of the findings of the Llar d'Estels audit (25-26/11/2026):
 * a cookie policy is only as complete as the registry behind its table.
 *
 * The trap: the registry is filled by visits (the detector), so a site
 * just launched shows «nothing pending» until the first visitors come; a
 * good result the first day is not a final one.
 */

defined( 'ABSPATH' ) || exit;

use EstrategiaWeb\SiteHealth\Probe;

return array(
	'id'      => 'cookies-pending',
	'async'   => false,
	'title'   => static fn() => __( 'Cookies are confirmed and listed', 'estrategiaweb-toolkit' ),
	'applies' => static fn() => Probe::module_on( 'consent' ),
	'gather'  => static function (): array {
		$pending = array();
		// The consent module's class lives in the Site namespace (it came from that plugin).
		$all     = class_exists( \EstrategiaWeb\Site\Cookie_Registry::class ) ? \EstrategiaWeb\Site\Cookie_Registry::all() : (array) get_option( 'estrategia_web_cookies', array() );
		foreach ( $all as $name => $entry ) {
			if ( 'pending' === ( $entry['status'] ?? '' ) ) {
				$pending[] = (string) $name;
			}
		}
		return array(
			'pending' => $pending,
			'table'   => (bool) Probe::pages_with( '<!-- wp:estrategia-web/cookie-table', 1 ),
		);
	},
	'judge'   => static function ( array $d ): array {
		$pending = array_map( 'strval', (array) ( $d['pending'] ?? array() ) );
		$items   = array();
		if ( $pending ) {
			$shown   = array_slice( $pending, 0, 10 );
			$items[] = sprintf(
				/* translators: 1: number of cookies, 2: their names, comma-separated. */
				_n( '%1$d cookie waiting for confirmation: %2$s', '%1$d cookies waiting for confirmation: %2$s', count( $pending ), 'estrategiaweb-toolkit' ),
				count( $pending ),
				implode( ', ', $shown ) . ( count( $pending ) > 10 ? '…' : '' )
			);
		}
		if ( empty( $d['table'] ) ) {
			$items[] = __( 'No published page shows the cookie table block', 'estrategiaweb-toolkit' );
		}
		if ( ! $items ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Cookies are confirmed and listed', 'estrategiaweb-toolkit' ),
				'description' => __( 'Every detected cookie has been reviewed, and the cookie table is on a published page.', 'estrategiaweb-toolkit' ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'The cookie policy does not list every cookie', 'estrategiaweb-toolkit' ),
			'description' => __( 'Visitors read the cookies in the cookie table, which only shows the confirmed ones. A cookie still pending is used on the site but not explained.', 'estrategiaweb-toolkit' ),
			'items'       => $items,
			'actions'     => array(
				array(
					'path' => 'admin.php?page=estrategia-web-settings&tab=cookies',
					'text' => __( 'Estrategia Web › Settings › Cookies', 'estrategiaweb-toolkit' ),
				),
			),
		);
	},
);
