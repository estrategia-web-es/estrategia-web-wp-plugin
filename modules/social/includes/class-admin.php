<?php
/**
 * “Estrategia Web → Social networks” screen (manage_options): the list of
 * networks, one row per network (name, link, icon), with Move up / Move
 * down / Remove and “Add network”. Saves through options.php (Settings
 * API): the sanitizing is the same as via REST (Links::sanitize).
 *
 * Reordering with buttons rather than drag and drop, on purpose: it works
 * with the keyboard and with a screen reader, and needs no library.
 *
 * It also works without JavaScript: the saved networks are shown plus an
 * empty row to add another; a network is removed by emptying its link.
 * assets/admin.js (classic script, not compiled) only adds the buttons, the
 * icon preview and the media library SVG picker, and renumbers the
 * name="…[n][…]" on submit so the saved order is the one on screen.
 *
 * How to maintain:
 * - A new per-network field: its <td> in row() (with data-field), its line
 *   in Links::sanitize_item and the schema; admin.js is not touched if the
 *   name follows the form estrategia_web_social_links[n][field].
 * - Edge case: the custom SVG is picked from the media library, which only
 *   allows UPLOADING SVG if “Settings › Media › Allow SVG” is ticked (and the
 *   user is allowed: site module, class-svg.php). The screen says so.
 * - The trap: the settings_fields() group must be Links::GROUP, the same as
 *   in register_setting, or options.php answers “the option is not in the
 *   allowed list”.
 */

namespace EstrategiaWeb\Social;

use EstrategiaWeb\Admin\Admin_UI;
use EstrategiaWeb\Admin\Menu;
use EstrategiaWeb\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = 'estrategia-web-social';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( Menu::PARENT, __( 'Social networks', 'estrategia-web' ), __( 'Social networks', 'estrategia-web' ), 'manage_options', self::PAGE, array( self::class, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::PAGE ) ) {
			return;
		}
		wp_enqueue_media();
		$base = plugin_dir_url( __DIR__ );
		wp_enqueue_style( 'estrategia-web-social-admin', $base . 'assets/admin.css', array(), ESTRATEGIA_WEB_VERSION );
		wp_enqueue_script( 'estrategia-web-social-admin', $base . 'assets/admin.js', array(), ESTRATEGIA_WEB_VERSION, true );
		wp_add_inline_script( 'estrategia-web-social-admin', 'window.estrategiaWebSocialIcons = ' . wp_json_encode( Icons::all() ) . ';', 'before' );
		self::inline_translations();
	}

	/**
	 * The strings of admin.js. It is not a block or editor script, so
	 * class-blocks.php does not pick it up: they are passed here already translated.
	 */
	private static function inline_translations(): void {
		$strings = array(
			'up'      => __( 'Move up', 'estrategia-web' ),
			'down'    => __( 'Move down', 'estrategia-web' ),
			'remove'  => __( 'Remove', 'estrategia-web' ),
			'svg'     => __( 'Own SVG…', 'estrategia-web' ),
			'svgOff'  => __( 'Remove own SVG', 'estrategia-web' ),
			'pick'    => __( 'Choose an SVG icon', 'estrategia-web' ),
			'use'     => __( 'Use this icon', 'estrategia-web' ),
		);
		wp_add_inline_script( 'estrategia-web-social-admin', 'window.estrategiaWebSocialText = ' . wp_json_encode( $strings ) . ';', 'before' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$items = Links::items();
		echo '<div class="wrap ew-screen estrategia-web-social">';
		Admin_UI::header( __( 'Social networks', 'estrategia-web' ), __( 'The links shown by the “Social networks” block, in this order. The icon is guessed from the link; choose one only if it gets it wrong, or use your own SVG from the media library.', 'estrategia-web' ) );
		echo '<form method="post" action="options.php">';
		Admin_UI::box_open( __( 'Networks', 'estrategia-web' ), 'ew-box-social' );
		settings_fields( Links::GROUP );
		// So that an empty list gets through (see the header of Links).
		printf( '<input type="hidden" name="%s[]" value="">', esc_attr( Links::OPTION ) );
		echo '<table class="widefat striped estrategia-web-social__table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Icon', 'estrategia-web' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'estrategia-web' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Link', 'estrategia-web' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Order', 'estrategia-web' ) . '</span></th>';
		echo '</tr></thead><tbody class="estrategia-web-social__rows">';
		$index = 0;
		foreach ( $items as $item ) {
			self::row( $index++, $item );
		}
		self::row( $index, array( 'name' => '', 'url' => '', 'icon' => '', 'icon_id' => 0 ) ); // The empty one, for adding.
		echo '</tbody></table>';
		echo '<p><button type="button" class="button estrategia-web-social__add" hidden>' . esc_html__( 'Add network', 'estrategia-web' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'To remove a network without JavaScript, empty its link.', 'estrategia-web' ) . ' <a href="' . esc_url( Settings_Page::url( 'media' ) ) . '">' . esc_html__( 'Your own SVG can only be uploaded if “Allow SVG uploads” is on in Settings › Media.', 'estrategia-web' ) . '</a></p>';
		Admin_UI::box_close();
		Admin_UI::save();
		echo '</form></div>';
	}

	private static function row( int $index, array $item ): void {
		$name    = static fn( string $field ): string => Links::OPTION . '[' . $index . '][' . $field . ']';
		$options = '<option value="">' . esc_html__( 'Automatic (from the link)', 'estrategia-web' ) . '</option>';
		foreach ( Icons::labels() as $slug => $label ) {
			$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $item['icon'], $slug, false ), esc_html( $label ) );
		}
		$preview = $item['icon_id'] ? Icons::attachment_svg( (int) $item['icon_id'] ) : '';
		if ( '' === $preview && '' !== $item['url'] ) {
			$preview = Icons::svg( '' !== $item['icon'] ? $item['icon'] : Icons::guess( $item['url'] ) );
		}
		$id = 'estrategia-web-social-' . $index;
		echo '<tr class="estrategia-web-social__row">';
		printf(
			'<td><span class="estrategia-web-social__preview" aria-hidden="true">%1$s</span> <label class="screen-reader-text" for="%2$s-icon">%3$s</label><select id="%2$s-icon" data-field="icon" name="%4$s">%5$s</select><input type="hidden" data-field="icon_id" name="%6$s" value="%7$d"></td>',
			$preview, // phpcs:ignore WordPress.Security.EscapeOutput -- SVG from Icons (collection or sanitized).
			esc_attr( $id ),
			esc_html__( 'Icon', 'estrategia-web' ),
			esc_attr( $name( 'icon' ) ),
			$options, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			esc_attr( $name( 'icon_id' ) ),
			(int) $item['icon_id']
		);
		printf(
			'<td><label class="screen-reader-text" for="%1$s-name">%2$s</label><input type="text" class="regular-text" id="%1$s-name" data-field="name" name="%3$s" value="%4$s" placeholder="%5$s"></td>',
			esc_attr( $id ),
			esc_html__( 'Name', 'estrategia-web' ),
			esc_attr( $name( 'name' ) ),
			esc_attr( $item['name'] ),
			esc_attr__( 'Empty: the icon name', 'estrategia-web' )
		);
		printf(
			'<td><label class="screen-reader-text" for="%1$s-url">%2$s</label><input type="text" inputmode="url" class="large-text" id="%1$s-url" data-field="url" name="%3$s" value="%4$s" placeholder="https://"></td>',
			esc_attr( $id ),
			esc_html__( 'Link', 'estrategia-web' ),
			esc_attr( $name( 'url' ) ),
			esc_attr( $item['url'] )
		);
		echo '<td class="estrategia-web-social__actions"></td></tr>';
	}
}
