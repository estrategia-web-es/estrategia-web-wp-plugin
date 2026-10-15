<?php
/**
 * Pantalla «Estrategia Web → Redes sociales» (manage_options): la lista de
 * redes, una fila por red (nombre, enlace, icono), con Subir / Bajar /
 * Quitar y «Añadir red». Guarda por options.php (Settings API): el saneado
 * es el mismo que por REST (Links::sanitize).
 *
 * Ordenar con botones y no arrastrando, a propósito: se puede con teclado y
 * con lector de pantalla, y no necesita ninguna librería.
 *
 * Sin JavaScript también funciona: salen las redes guardadas y una fila vacía
 * para añadir otra; se quita una vaciando su enlace. assets/admin.js (script
 * clásico, sin compilar) solo añade los botones, la vista del icono y el
 * selector de SVG de la mediateca, y renumera los name="…[n][…]" al enviar
 * para que el orden guardado sea el que se ve.
 *
 * Cómo se mantiene:
 * - Un campo nuevo por red: su <td> en row() (con data-field), su línea en
 *   Links::sanitize_item y el esquema, y admin.js no se toca si el name
 *   sigue la forma estrategia_web_social_links[n][campo].
 * - Caso frontera: el SVG propio se elige de la mediateca, que solo deja
 *   SUBIR SVG si «Datos de la empresa → Permitir SVG» está marcado (y el
 *   usuario puede: módulo site, class-svg.php). La pantalla lo dice.
 * - La trampa: el grupo de settings_fields() tiene que ser Links::GROUP, el
 *   mismo de register_setting, o options.php contesta «la opción no está en
 *   la lista permitida».
 */

namespace EstrategiaWeb\Social;

use EstrategiaWeb\Admin\Modules_Page;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE = 'estrategia-web-social';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( Modules_Page::MENU, __( 'Social networks', 'estrategia-web' ), __( 'Social networks', 'estrategia-web' ), 'manage_options', self::PAGE, array( self::class, 'render' ) );
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
	 * Los textos de admin.js. No es un script de bloque ni del editor, así que
	 * class-blocks.php no se entera: se le pasan aquí ya traducidos.
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
		echo '<div class="wrap estrategia-web-social"><h1>' . esc_html__( 'Social networks', 'estrategia-web' ) . '</h1>';
		echo '<p>' . esc_html__( 'The links shown by the “Social networks” block, in this order. The icon is guessed from the link; choose one only if it gets it wrong, or use your own SVG from the media library.', 'estrategia-web' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( Links::GROUP );
		// Para que una lista vacía llegue (ver la cabecera de Links).
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
		self::row( $index, array( 'name' => '', 'url' => '', 'icon' => '', 'icon_id' => 0 ) ); // La vacía, para añadir.
		echo '</tbody></table>';
		echo '<p><button type="button" class="button estrategia-web-social__add" hidden>' . esc_html__( 'Add network', 'estrategia-web' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'To remove a network without JavaScript, empty its link. Your own SVG can only be uploaded if “Allow SVG uploads” is on in Company details.', 'estrategia-web' ) . '</p>';
		submit_button();
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
			$preview, // phpcs:ignore WordPress.Security.EscapeOutput -- SVG de Icons (colección o saneado).
			esc_attr( $id ),
			esc_html__( 'Icon', 'estrategia-web' ),
			esc_attr( $name( 'icon' ) ),
			$options, // phpcs:ignore WordPress.Security.EscapeOutput -- escapado arriba.
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
