<?php
/**
 * RECOMMENDED (async: a loopback GET of the home page): browser security
 * headers missing — HSTS (over https only), X-Frame-Options,
 * Referrer-Policy, Permissions-Policy, X-Content-Type-Options — or
 * X-Powered-By exposed.
 *
 * Criterion: the same five headers the security-headers module adds, read
 * from what the site REALLY sends (whoever adds them: that module, SiteGround
 * Security, the server's .htaccess, the hosting panel). X-Powered-By tells
 * anyone the PHP version, which is a list of known holes. The action is the
 * Modules screen: switching security-headers on fixes the five and hides
 * X-Powered-By wherever PHP can.
 *
 * Why async: one HTTP request to the site itself (wp_remote_get of home_url,
 * 10 s, no cookies, redirects followed), so it is run by the Site Health
 * screen's JavaScript and never slows the page.
 *
 * Edge case, by name: a loopback that fails (a firewall, basic auth on a
 * staging, a hosting that blocks a site calling itself) is «could not
 * check», never «missing»: WordPress' own loopback test says why.
 *
 * Real case: the Llar d'Estels audit (25-26/11/2026): its future hosting
 * (OVH, Apache) sends none of these headers and says «X-Powered-By: PHP/8.5»
 * — the case that also made the security-headers module.
 *
 * The trap: a page cache can serve headers that were true when the page was
 * cached; after switching the module on, empty the cache before checking.
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'     => 'security-headers',
	'async'  => true,
	'title'  => static fn() => __( 'The site sends the browser security headers', 'estrategiaweb-toolkit' ),
	'gather' => static function (): array {
		$url = home_url( '/' );
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => false, // A loopback: the certificate is the site's own business, not this check's.
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message() );
		}
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $res ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'https'   => 'https' === wp_parse_url( $url, PHP_URL_SCHEME ),
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'headers' => $headers,
		);
	},
	'judge'  => static function ( array $d ): array {
		$action = array(
			array(
				'path' => 'admin.php?page=estrategia-web',
				'text' => __( 'Estrategia Web › Modules › Security headers', 'estrategiaweb-toolkit' ),
			),
		);
		if ( isset( $d['error'] ) || (int) ( $d['code'] ?? 0 ) >= 400 || 0 === (int) ( $d['code'] ?? 0 ) ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Could not check the security headers', 'estrategiaweb-toolkit' ),
				'description' => __( 'The site could not load its own home page to read the headers it sends. This is not a missing header: WordPress\' «loopback request» test explains why the site cannot call itself.', 'estrategiaweb-toolkit' ),
				/* translators: %s: an HTTP status code or an error message. */
				'items'       => array( sprintf( __( 'Answer: %s', 'estrategiaweb-toolkit' ), (string) ( $d['error'] ?? $d['code'] ?? '' ) ) ),
			);
		}
		$have = array_change_key_case( (array) ( $d['headers'] ?? array() ), CASE_LOWER );
		$want = array( 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy' );
		if ( ! empty( $d['https'] ) ) {
			array_unshift( $want, 'Strict-Transport-Security' );
		}
		$items = array();
		foreach ( $want as $name ) {
			if ( ! isset( $have[ strtolower( $name ) ] ) ) {
				/* translators: %s: an HTTP header name. */
				$items[] = sprintf( __( 'Missing: %s', 'estrategiaweb-toolkit' ), $name );
			}
		}
		if ( isset( $have['x-powered-by'] ) ) {
			/* translators: %s: the value of the X-Powered-By header, e.g. PHP/8.3.6. */
			$items[] = sprintf( __( 'Exposed: X-Powered-By: %s', 'estrategiaweb-toolkit' ), $have['x-powered-by'] );
		}
		if ( ! $items ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'The site sends the browser security headers', 'estrategiaweb-toolkit' ),
				'description' => __( 'The home page sends the security headers browsers use, and does not tell which PHP version runs it.', 'estrategiaweb-toolkit' ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'Some browser security headers are missing', 'estrategiaweb-toolkit' ),
			'description' => __( 'These headers tell browsers to use https, not to show the site inside another one and not to give pages access to the camera or location. The Security headers module adds the ones that are missing.', 'estrategiaweb-toolkit' ),
			'items'       => $items,
			'actions'     => $action,
		);
	},
);
