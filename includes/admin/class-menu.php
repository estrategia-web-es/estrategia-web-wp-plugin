<?php
/**
 * THE «Estrategia Web» ADMIN MENU (1.7.0): one top-level item, at position
 * 3 (right after the Dashboard, where «Company details» was), that opens on
 * Company details. Its submenu, in this order:
 *
 *   1. Company details   page=estrategia-web-site          (site, core)
 *   2. Content types     page=estrategia-web-content-types (content-types)
 *   3. Social networks   page=estrategia-web-social        (social)
 *   4. Settings          page=estrategia-web-settings      (tabs: class-settings-page.php)
 *   5. Redirects         page=estrategia-web-redirects     (redirects)
 *   6. Modules           page=estrategia-web               (always LAST)
 *
 * Why the top-level item's slug is Company details' (estrategia-web-site)
 * and not «estrategia-web»: WordPress opens a top-level item on the page
 * with its slug, and keeping the two slugs that already existed means the
 * two most linked URLs (Company details and Modules) did not change at all.
 * Modules_Page::MENU, the parent modules used up to 1.6.1, is now this same
 * PARENT, so a child theme that hung a page on it still lands here.
 *
 * How to maintain:
 * - A module's page goes in the submenu with add_submenu_page( Menu::PARENT,
 *   … ) from its own folder. Its place: ORDER below. A slug not in ORDER is
 *   shown after the known ones and before Modules, in registration order.
 * - A module's screen that becomes a Settings tab: its old slug goes into
 *   LEGACY, and the old URL keeps working (redirect, below).
 * - Edge case, by name: an old URL whose tab is not there now (old
 *   page=estrategia-web-google with google-tags AND matomo off). It is not
 *   redirected: WordPress answers «not allowed», as it did before with the
 *   module off. With only google-tags off it still lands on Analytics
 *   (Matomo's section).
 * - The trap: the redirect has to happen BEFORE WordPress decides the page
 *   does not exist. That decision is in wp-admin/menu.php right after
 *   admin_menu (user_can_access_admin_page() → wp_die), before admin_init.
 *   So it is the last callback of admin_menu, not admin_init.
 * - Tests: tests/menu.test.mjs (level 1: order() and legacy_target(); level
 *   2: the real menu and each old URL).
 */

namespace EstrategiaWeb\Admin;

defined( 'ABSPATH' ) || exit;

final class Menu {

	/** Slug of the top-level item (= Company details). */
	public const PARENT = 'estrategia-web-site';

	/** Position in the admin menu: after the Dashboard (2). */
	public const POSITION = 3;

	/** Submenu order. Modules_Page::SLUG is not here: it always goes last. */
	public const ORDER = array(
		self::PARENT,
		'estrategia-web-content-types',
		'estrategia-web-social',
		Settings_Page::SLUG,
		'estrategia-web-redirects',
	);

	/** Old screen slug → tab of the Settings page it became (1.7.0). */
	public const LEGACY = array(
		'estrategia-web-cookies' => 'cookies',
		'estrategia-web-google'  => 'analytics',
	);

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'register' ), 5 ); // Before every submenu (10, 20).
		add_action( 'admin_menu', array( self::class, 'sort' ), 999 );
		add_action( 'admin_menu', array( self::class, 'legacy_redirect' ), PHP_INT_MAX );
	}

	/** The screen the top-level item opens: Company details (site is core), or Modules if it were missing. */
	private static function home(): callable {
		return class_exists( \EstrategiaWeb\Site\Admin::class ) ? array( \EstrategiaWeb\Site\Admin::class, 'render' ) : array( Modules_Page::class, 'render' );
	}

	public static function register(): void {
		add_menu_page(
			__( 'Company details', 'estrategia-web' ),
			__( 'Estrategia Web', 'estrategia-web' ),
			'manage_options',
			self::PARENT,
			self::home(),
			'dashicons-screenoptions',
			self::POSITION
		);
		add_submenu_page( self::PARENT, __( 'Company details', 'estrategia-web' ), __( 'Company details', 'estrategia-web' ), 'manage_options', self::PARENT, self::home() );
	}

	/**
	 * The submenu slugs in their place. Pure function.
	 *
	 * @param string[] $slugs As registered.
	 * @return string[]
	 */
	public static function order( array $slugs ): array {
		$rank = static function ( string $slug, int $i ): int {
			if ( Modules_Page::SLUG === $slug ) {
				return PHP_INT_MAX;
			}
			$known = array_search( $slug, self::ORDER, true );
			return false !== $known ? $known : count( self::ORDER ) + $i;
		};
		$ranked = array();
		foreach ( array_values( $slugs ) as $i => $slug ) {
			$ranked[ $slug ] = $rank( (string) $slug, $i );
		}
		asort( $ranked );
		return array_map( 'strval', array_keys( $ranked ) );
	}

	/** Puts the registered submenu in ORDER (admin_menu, late). */
	public static function sort(): void {
		global $submenu;
		if ( empty( $submenu[ self::PARENT ] ) ) {
			return;
		}
		$items = array();
		foreach ( $submenu[ self::PARENT ] as $item ) {
			$items[ $item[2] ] = $item;
		}
		$submenu[ self::PARENT ] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own submenu.
		foreach ( self::order( array_keys( $items ) ) as $slug ) {
			$submenu[ self::PARENT ][] = $items[ $slug ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Where an old slug goes now. Pure function.
	 *
	 * @param string   $page The ?page= asked for.
	 * @param string[] $tabs The Settings tabs available in this request.
	 * @return string|null The tab, or null if it is not an old slug or its tab is not there.
	 */
	public static function legacy_target( string $page, array $tabs ): ?string {
		$tab = self::LEGACY[ $page ] ?? null;
		return null !== $tab && in_array( $tab, $tabs, true ) ? $tab : null;
	}

	/** admin.php?page=<old slug>&… → the Settings tab, keeping the other args. */
	public static function legacy_redirect(): void {
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		if ( 'admin.php' !== $pagenow || ! isset( self::LEGACY[ $page ] ) ) {
			return;
		}
		$tab = self::legacy_target( $page, array_keys( Settings_Page::tabs() ) );
		if ( null === $tab ) {
			return;
		}
		$args = array_diff_key( wp_unslash( $_GET ), array( 'page' => 1, 'tab' => 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( Settings_Page::url( $tab, array_map( 'rawurlencode', array_filter( $args, 'is_string' ) ) ) );
		exit;
	}
}
