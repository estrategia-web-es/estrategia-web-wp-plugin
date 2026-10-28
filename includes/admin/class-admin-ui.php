<?php
/**
 * ONE LOOK FOR EVERY PLUGIN SCREEN (2.0.1, David, 28-10-2026: «la página de
 * ajustes parece de otro plugin»). The pieces every «Estrategia Web» and
 * «Forms» screen is built with, and the stylesheet that draws them
 * (assets/admin.css, loaded on every screen whose ?page= starts with
 * «estrategia-web»):
 *
 *   header( title, lead, actions )  the title, the page-title buttons («Add
 *                                   new»…) and ONE line saying what the
 *                                   screen is for. Every screen has it.
 *   box_open( title ) / box_close() a white box with its title: every
 *                                   section of a form (the look of the
 *                                   location fields box and of the content
 *                                   types builder's).
 *   tabs( tabs, current, label )    the tabs: the content types builder's
 *                                   (a white strip, the active tab white),
 *                                   also in Settings and Redirects. Links,
 *                                   not buttons: each tab is its own URL.
 *   save( text )                    the save button, ALWAYS under the boxes
 *                                   it saves, on the left (WordPress's own
 *                                   place), never inside a table.
 *
 * How to maintain:
 * - Who: whoever adds a screen or a section to one. A new screen opens its
 *   wrap with class="wrap ew-screen" and calls header(); each section of a
 *   form is a box; a list of records (forms, content types, redirects,
 *   submissions) stays a WordPress list table, without a box.
 * - Criterion: a colour or a size that is not WordPress's admin (#c3c4c7,
 *   #dcdcde, #f6f7f7, #fff, #2271b1 for focus) does not go in admin.css: the
 *   screens follow the admin colour scheme.
 * - Edge case, by name: the Cookies tab. It has three forms (the notice,
 *   one per cookie row, adding one by hand): each box carries its own
 *   save() right under it, which is still «under the box it saves».
 * - The trap: a module's section is printed INSIDE the Settings page's
 *   form (Settings_Page::render()), so a section must not print its own
 *   <form> or save button: only its box. The page adds ONE save() after
 *   the last box of the form.
 */

namespace EstrategiaWeb\Admin;

defined( 'ABSPATH' ) || exit;

final class Admin_UI {

	public const HANDLE = 'estrategia-web-admin';

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/** Every plugin screen: ?page=estrategia-web… (Company details, Settings, Modules, Forms, Redirects…). */
	public static function enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which stylesheet to load.
		if ( str_starts_with( $page, 'estrategia-web' ) ) {
			wp_enqueue_style( self::HANDLE, ESTRATEGIA_WEB_URL . 'assets/admin.css', array(), ESTRATEGIA_WEB_VERSION );
		}
	}

	/**
	 * The screen's header: title, page-title buttons (already escaped HTML) and the line that says what it is for.
	 *
	 * @param string $actions HTML of «page-title-action» links or buttons, escaped by the caller.
	 */
	public static function header( string $title, string $lead, string $actions = '' ): void {
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( '' !== $actions ) {
			echo ' ' . $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller (see @param).
		}
		echo '<hr class="wp-header-end">';
		if ( '' !== $lead ) {
			echo '<p class="ew-lead">' . esc_html( $lead ) . '</p>';
		}
	}

	/** Opens a section box with its title; $id names the title for aria-labelledby. */
	public static function box_open( string $title, string $id = '' ): void {
		$id = '' !== $id ? $id : 'ew-box-' . sanitize_title( $title );
		printf( '<section class="ew-box" aria-labelledby="%1$s"><h2 class="ew-box-title" id="%1$s">%2$s</h2><div class="ew-box-body">', esc_attr( $id ), esc_html( $title ) );
	}

	public static function box_close(): void {
		echo '</div></section>';
	}

	/**
	 * The tabs: id => [label, url]. The current one carries aria-current.
	 *
	 * @param array<string, array{0:string,1:string}> $tabs
	 */
	public static function tabs( array $tabs, string $current, string $label ): void {
		printf( '<nav class="nav-tab-wrapper ew-tabs" aria-label="%s">', esc_attr( $label ) );
		foreach ( $tabs as $id => list( $text, $url ) ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s" data-tab="%3$s"%4$s>%5$s</a>',
				esc_url( $url ),
				$id === $current ? ' nav-tab-active' : '',
				esc_attr( (string) $id ),
				$id === $current ? ' aria-current="page"' : '',
				esc_html( $text )
			);
		}
		echo '</nav>';
	}

	/** The save button, under the boxes it saves. $text null = WordPress's «Save Changes». */
	public static function save( ?string $text = null, string $name = 'submit' ): void {
		echo '<div class="ew-actions">';
		submit_button( $text, 'primary', $name, false );
		echo '</div>';
	}
}
