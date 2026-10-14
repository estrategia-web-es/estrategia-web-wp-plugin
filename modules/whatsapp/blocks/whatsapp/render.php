<?php
/**
 * estrategia-web/whatsapp — botón flotante de WhatsApp.
 *
 * Qué es: un enlace fijo en una esquina a https://wa.me/<número>?text=<mensaje>.
 * Sin JavaScript: no hace falta. Pensado para la parte de pie (parts/footer.html
 * ya lo lleva): si no hay número, no pinta nada.
 *
 * De dónde salen los datos, por orden: atributos del bloque → opciones del
 * sitio estrategia_web_whatsapp_phone y estrategia_web_whatsapp_message (modules/whatsapp/module.php;
 * se cambian con WP-CLI o REST /wp/v2/settings). Así el número vive en un
 * sitio y no en cada página.
 *
 * Decisiones:
 * - El número va en formato internacional sin «+» ni espacios (34600111222).
 *   Se quitan los caracteres que no son dígitos y un «00» inicial. NO se
 *   adivina el prefijo del país: un 600111222 sin 34 no funciona en wa.me, y
 *   suponer España sería meter una web concreta en el padre.
 * - Accesible: nombre en texto oculto visualmente (no solo aria-label, para
 *   que lo traduzcan los traductores automáticos) y aviso de pestaña nueva;
 *   el icono es decorativo (aria-hidden); 3.5rem de diana táctil.
 * - Color: el verde de WhatsApp (#128C7E, contraste 4.1:1 con el icono blanco; para
 *   un gráfico WCAG pide 3:1) en la
 *   propiedad --estrategia-web-whatsapp-background. Es la marca de WhatsApp, no la
 *   de ningún cliente; un hijo puede cambiarlo.
 *
 * Cómo se mantiene:
 * - Caso frontera: «que salga solo en horario de oficina» es OTRA COSA (lógica
 *   de un negocio): bloque o filtro en el hijo. «Que vaya a la izquierda» es
 *   un AJUSTE: atributo position.
 * - La trampa: supports.multiple=false impide dos en una página desde el
 *   editor, pero no si uno está en la parte de pie y otro en el contenido de
 *   la página: el del pie ya cubre todas, no se pone en páginas.
 *
 * @package estrategia-web
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$estrategia_web_phone   = '' !== trim( (string) $attributes['phone'] ) ? $attributes['phone'] : (string) get_option( 'estrategia_web_whatsapp_phone', '' );
$estrategia_web_message = '' !== trim( (string) $attributes['message'] ) ? $attributes['message'] : (string) get_option( 'estrategia_web_whatsapp_message', '' );
$estrategia_web_phone   = preg_replace( '/^00/', '', preg_replace( '/\D+/', '', $estrategia_web_phone ) );

if ( '' === $estrategia_web_phone ) {
	if ( wp_is_serving_rest_request() ) {
		printf(
			'<p %s>%s</p>',
			get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html__( 'WhatsApp button: set the phone number in the block settings or in the estrategia_web_whatsapp_phone site option.', 'estrategia-web' )
		);
	}
	return;
}

$estrategia_web_href = 'https://wa.me/' . $estrategia_web_phone;
if ( '' !== trim( $estrategia_web_message ) ) {
	$estrategia_web_href .= '?text=' . rawurlencode( $estrategia_web_message );
}
$estrategia_web_position = in_array( $attributes['position'], array( 'bottom-right', 'bottom-left' ), true ) ? $attributes['position'] : 'bottom-right';
$estrategia_web_label    = '' !== trim( (string) $attributes['label'] ) ? $attributes['label'] : __( 'Chat on WhatsApp', 'estrategia-web' );
?>
<a
	<?php echo get_block_wrapper_attributes( array( 'class' => 'estrategia-web-whatsapp is-position-' . $estrategia_web_position ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	href="<?php echo esc_url( $estrategia_web_href ); ?>"
	target="_blank"
	rel="noopener noreferrer"
>
	<svg class="estrategia-web-whatsapp__icon" viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false">
		<path fill="currentColor" d="M12 2a10 10 0 0 0-8.66 15l-1.3 4.8 4.92-1.29A10 10 0 1 0 12 2zm0 1.8a8.2 8.2 0 1 1-4.18 15.25l-.3-.18-2.92.77.78-2.85-.2-.31A8.2 8.2 0 0 1 12 3.8z"/>
		<path fill="currentColor" d="M8.9 7.2c-.2-.45-.4-.46-.6-.47h-.5a1 1 0 0 0-.72.34 3 3 0 0 0-.94 2.24 5.2 5.2 0 0 0 1.1 2.77 11.9 11.9 0 0 0 4.97 4.37c2.46.97 2.96.78 3.5.73a2.9 2.9 0 0 0 1.96-1.38 2.4 2.4 0 0 0 .17-1.38c-.07-.12-.26-.2-.55-.34s-1.73-.85-2-.95-.46-.14-.66.15-.75.94-.92 1.14-.34.21-.63.07a8 8 0 0 1-2.35-1.45 8.8 8.8 0 0 1-1.62-2.02c-.17-.29 0-.45.13-.59l.43-.5a2 2 0 0 0 .29-.48.53.53 0 0 0-.03-.5c-.07-.15-.64-1.6-.9-2.17z"/>
	</svg>
	<span class="screen-reader-text"><?php echo esc_html( $estrategia_web_label ); ?> <?php esc_html_e( '(opens in a new tab)', 'estrategia-web' ); ?></span>
</a>
