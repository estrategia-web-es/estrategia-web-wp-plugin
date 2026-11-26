<?php
/**
 * CRITICAL: search engines are told not to index a site that is not a staging.
 *
 * Criterion: Settings › Reading › «Discourage search engines» (blog_public =
 * 0) is right on a staging copy and a disaster on the real site: Google drops
 * it. It is ticked while a site is being built and has to be unticked on the
 * launch day — the step that gets forgotten. Critical, because every day it
 * stays on the site loses visitors.
 *
 * How «staging» is detected (is_staging() below), in this order:
 * 1. wp_get_environment_type() is «staging», «development» or «local»
 *    (WP_ENVIRONMENT_TYPE; LocalWP and most hostings' staging tools set it).
 * 2. The host name says so: localhost, *.local, *.test, *.localhost, a label
 *    «staging», «stage», «dev» or «test» (staging.x.com, staging3.x.com,
 *    dev-x.com), or a SUBDOMAIN of estrategiaweb.es (our previews).
 * Anything else is a real site. Edge case, by name: a bare IP (127.0.0.1, a
 * VPS before its domain) is NOT a staging — nobody launches on an IP, but a
 * site that answers on one and on its domain is the same site, and the test
 * WordPress is one. estrategiaweb.es ITSELF is a real site (no subdomain).
 *
 * Real case: the Llar d'Estels audit (25-26/11/2026) put this first among
 * the checks every site needs before going live.
 *
 * The trap: SiteGround's staging copies keep WP_ENVIRONMENT_TYPE unset
 * («production»); they are caught by their host name (stagingN.<domain>).
 */

defined( 'ABSPATH' ) || exit;

return array(
	'id'     => 'search-engines',
	'async'  => false,
	'title'  => static fn() => __( 'Search engines can index the site', 'estrategiaweb-toolkit' ),
	'gather' => static fn() => array(
		'public'      => (string) get_option( 'blog_public', '1' ),
		'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
		'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
	),
	'judge'  => static function ( array $d ): array {
		$host    = strtolower( (string) ( $d['host'] ?? '' ) );
		$staging = in_array( $d['environment'] ?? '', array( 'staging', 'development', 'local' ), true )
			|| 'localhost' === $host
			|| preg_match( '/\.(local|test|localhost)$/', $host )
			|| preg_match( '/(^|[.-])(staging\d*|stage|dev|test)([.-])/', $host )
			|| preg_match( '/\.estrategiaweb\.es$/', $host );
		if ( '0' !== (string) ( $d['public'] ?? '1' ) ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Search engines can index the site', 'estrategiaweb-toolkit' ),
				'description' => __( 'The site does not ask search engines to stay away.', 'estrategiaweb-toolkit' ),
			);
		}
		if ( $staging ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'This staging copy is hidden from search engines', 'estrategiaweb-toolkit' ),
				'description' => __( 'Search engines are discouraged, which is right for a copy that is not the real site. Untick it on the real site on launch day.', 'estrategiaweb-toolkit' ),
			);
		}
		return array(
			'status'      => 'critical',
			'label'       => __( 'Search engines are told not to index the site', 'estrategiaweb-toolkit' ),
			'description' => __( '«Discourage search engines from indexing this site» is ticked on a site that does not look like a staging copy. Google drops a site like this from its results.', 'estrategiaweb-toolkit' ),
			'actions'     => array(
				array(
					'path' => 'options-reading.php',
					'text' => __( 'Settings › Reading', 'estrategiaweb-toolkit' ),
				),
			),
		);
	},
);
