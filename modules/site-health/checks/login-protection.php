<?php
/**
 * RECOMMENDED: nothing limits login attempts and XML-RPC is open — no known
 * limiter plugin is active.
 *
 * Criterion: without a limit, anyone can try passwords on wp-login.php and
 * through XML-RPC (system.multicall: hundreds of passwords in one request)
 * for as long as they like. Our standard is SiteGround Security, which works
 * outside SiteGround too (it limits logins and switches XML-RPC off); any of
 * the other well-known limiters below is accepted as well.
 *
 * The known limiters (plugin file in active_plugins). A new one goes in
 * LIMITERS below, and in this list:
 *   sg-security/sg-security.php                                   SiteGround Security
 *   limit-login-attempts-reloaded/limit-login-attempts-reloaded.php Limit Login Attempts Reloaded
 *   wordfence/wordfence.php                                       Wordfence
 *   loginizer/loginizer.php                                       Loginizer
 *   all-in-one-wp-security-and-firewall/wp-security.php           All-In-One Security
 *   better-wp-security/better-wp-security.php                     Solid Security (iThemes)
 *
 * Edge case, by name: a limit applied OUTSIDE WordPress (the hosting's WAF,
 * Cloudflare rules, fail2ban) is invisible from PHP: the site still gets the
 * warning. It is «recommended» for that reason; the description says
 * whether XML-RPC answers (the xmlrpc_enabled filter), which is the part a
 * plugin like Disable XML-RPC fixes on its own.
 *
 * Real case: one of the findings of the Llar d'Estels audit (25-26/11/2026),
 * on a hosting outside SiteGround where nothing came with the server.
 *
 * The trap: is_plugin_active() is not loaded outside wp-admin (the weekly
 * cron runs this too), so the list is read from the option directly.
 */

defined( 'ABSPATH' ) || exit;

use EstrategiaWeb\SiteHealth\Probe;

return array(
	'id'     => 'login-protection',
	'async'  => false,
	'title'  => static fn() => __( 'Login attempts are limited', 'estrategiaweb-toolkit' ),
	'gather' => static fn() => array(
		'plugins' => Probe::active_plugins(),
		'xmlrpc'  => (bool) apply_filters( 'xmlrpc_enabled', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter, read, not added.
	),
	'judge'  => static function ( array $d ): array {
		$limiters = array(
			'sg-security/sg-security.php'                                   => 'SiteGround Security',
			'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' => 'Limit Login Attempts Reloaded',
			'wordfence/wordfence.php'                                       => 'Wordfence',
			'loginizer/loginizer.php'                                       => 'Loginizer',
			'all-in-one-wp-security-and-firewall/wp-security.php'           => 'All-In-One Security',
			'better-wp-security/better-wp-security.php'                     => 'Solid Security',
		);
		$on       = array_values( array_intersect_key( $limiters, array_flip( (array) ( $d['plugins'] ?? array() ) ) ) );
		if ( $on ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Login attempts are limited', 'estrategiaweb-toolkit' ),
				/* translators: %s: plugin names, comma-separated. */
				'description' => sprintf( __( 'Repeated failed logins are blocked by: %s.', 'estrategiaweb-toolkit' ), implode( ', ', $on ) ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'Nothing limits login attempts', 'estrategiaweb-toolkit' ),
			'description' => __( 'Anyone can keep trying passwords on the login page for as long as they like. SiteGround Security limits the attempts and closes XML-RPC, and works on any hosting.', 'estrategiaweb-toolkit' ),
			'items'       => array(
				! empty( $d['xmlrpc'] )
					? __( 'XML-RPC is open: it accepts hundreds of password attempts in a single request.', 'estrategiaweb-toolkit' )
					: __( 'XML-RPC is already closed.', 'estrategiaweb-toolkit' ),
			),
			'actions'     => array(
				array(
					'path' => 'plugin-install.php?s=sg-security&tab=search&type=term',
					'text' => __( 'Install SiteGround Security', 'estrategiaweb-toolkit' ),
				),
			),
		);
	},
);
