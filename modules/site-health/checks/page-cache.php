<?php
/**
 * RECOMMENDED: no page cache on a site that is not hosted on SiteGround.
 *
 * Criterion: without a page cache every visit builds the page in PHP
 * (around a second on a shared hosting); with one, it is a file. On
 * SiteGround the server caches pages itself (its dynamic cache), so a
 * SiteGround site is good as it is. Anywhere else one of these must be
 * active (plugin file in active_plugins):
 *   sg-cachepress/sg-cachepress.php   SiteGround Speed Optimizer, ONLY with its
 *                                     file-based cache on (option
 *                                     siteground_optimizer_file_caching): off
 *                                     SiteGround it is the only cache it has
 *   wp-rocket/wp-rocket.php           WP Rocket
 *   litespeed-cache/litespeed-cache.php LiteSpeed Cache
 *   w3-total-cache/w3-total-cache.php W3 Total Cache
 *   wp-super-cache/wp-cache.php       WP Super Cache
 * The same five cache-purge knows (modules/cache-purge): a new one goes in
 * both.
 *
 * How SiteGround hosting is detected: Speed Optimizer's own test when its
 * library is loaded (SiteGround_Helper\Helper_Service::is_siteground()),
 * else the same heuristic copied from it (26-11-2026, its trunk):
 * open_basedir empty AND (/etc/yum.repos.d/baseos.repo OR /Z exists).
 *
 * Edge case, by name: with open_basedir set, SiteGround's own test says «not
 * SiteGround» (it cannot look); then a SiteGround site without a cache
 * plugin gets the warning. The cure is the same as anywhere: Speed
 * Optimizer, which every SiteGround site has anyway.
 *
 * Real case: one of the findings of the Llar d'Estels audit (25-26/11/2026):
 * the site moves to OVH, where nothing caches pages unless a plugin does.
 *
 * The trap: the option is «1»/«0» as a string; and Speed Optimizer active
 * with the file cache OFF is the common state of a site that has just left
 * SiteGround (its dynamic cache was the server's, and it stayed behind).
 */

defined( 'ABSPATH' ) || exit;

use EstrategiaWeb\SiteHealth\Probe;

return array(
	'id'     => 'page-cache',
	'async'  => false,
	'title'  => static fn() => __( 'Pages are cached', 'estrategiaweb-toolkit' ),
	'gather' => static function (): array {
		if ( class_exists( '\SiteGround_Helper\Helper_Service' ) && method_exists( '\SiteGround_Helper\Helper_Service', 'is_siteground' ) ) {
			$siteground = (bool) \SiteGround_Helper\Helper_Service::is_siteground();
		} else {
			$siteground = '' === (string) ini_get( 'open_basedir' ) && ( @file_exists( '/etc/yum.repos.d/baseos.repo' ) || @file_exists( '/Z' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the same silenced test as SiteGround's.
		}
		return array(
			'siteground'   => $siteground,
			'plugins'      => Probe::active_plugins(),
			'file_caching' => (string) get_option( 'siteground_optimizer_file_caching', '0' ),
		);
	},
	'judge'  => static function ( array $d ): array {
		if ( ! empty( $d['siteground'] ) ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Pages are cached by the hosting', 'estrategiaweb-toolkit' ),
				'description' => __( 'The site is hosted on SiteGround, whose servers cache the pages.', 'estrategiaweb-toolkit' ),
			);
		}
		$plugins = array_flip( (array) ( $d['plugins'] ?? array() ) );
		$caches  = array(
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
		);
		$on      = array_values( array_intersect_key( $caches, $plugins ) );
		if ( isset( $plugins['sg-cachepress/sg-cachepress.php'] ) && in_array( (string) ( $d['file_caching'] ?? '0' ), array( '1', 'true', 'yes' ), true ) ) {
			array_unshift( $on, 'SiteGround Speed Optimizer' );
		}
		if ( $on ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Pages are cached', 'estrategiaweb-toolkit' ),
				/* translators: %s: plugin names, comma-separated. */
				'description' => sprintf( __( 'Pages are cached by: %s.', 'estrategiaweb-toolkit' ), implode( ', ', $on ) ),
			);
		}
		$sg = isset( $plugins['sg-cachepress/sg-cachepress.php'] );
		return array(
			'status'      => 'recommended',
			'label'       => __( 'Pages are not cached', 'estrategiaweb-toolkit' ),
			'description' => $sg
				? __( 'SiteGround Speed Optimizer is active but its file-based cache is off, and outside SiteGround that is the only page cache it has. Every visit builds the page from scratch.', 'estrategiaweb-toolkit' )
				: __( 'The site is not on SiteGround and no page cache plugin is active, so every visit builds the page from scratch. SiteGround Speed Optimizer\'s file-based cache works on any hosting.', 'estrategiaweb-toolkit' ),
			'actions'     => array(
				$sg
					? array(
						'path' => 'admin.php?page=sgo_caching',
						'text' => __( 'Speed Optimizer › Caching', 'estrategiaweb-toolkit' ),
					)
					: array(
						'path' => 'plugin-install.php?s=sg-cachepress&tab=search&type=term',
						'text' => __( 'Install SiteGround Speed Optimizer', 'estrategiaweb-toolkit' ),
					),
			),
		);
	},
);
