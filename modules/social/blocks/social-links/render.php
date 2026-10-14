<?php
/**
 * estrategia-web/social-links — las redes sociales de la web.
 *
 * Qué pinta: una lista (<ul>) con un enlace por red, en el orden de
 * «Estrategia Web → Redes sociales» (opción estrategia_web_social_links; ver
 * modules/social/includes/class-links.php). Sin JavaScript. Si no hay redes,
 * no pinta nada en la web; en el editor, un aviso de dónde se ponen.
 *
 * Atributos:
 * - display: «icons» (solo iconos) o «icons-names» (icono y nombre).
 * - size: small / medium / large → 1.25em / 1.5em / 2em, en la variable
 *   --estrategia-web-social-size (un tema la cambia sin tocar esto).
 * Y los de serie: color del texto (los iconos lo toman, ver abajo), tamaño
 * de letra (los em de arriba lo siguen) y margen.
 *
 * Decisiones:
 * - COLOR: los iconos son <svg fill="currentColor"> y el enlace lleva
 *   color: inherit, así que salen del color del texto donde se ponga el
 *   bloque (blanco en un pie oscuro) sin configurar nada. No se pintan con
 *   los colores de cada marca: eso es diseño de una web, no del plugin.
 * - ACCESIBLE: el nombre de cada enlace es «Instagram de <Empresa>» (el
 *   nombre de «Datos de la empresa», o el del sitio) más «(se abre en una
 *   pestaña nueva)», en texto oculto visualmente y no en aria-label, para que
 *   lo traduzcan los traductores automáticos (como el botón de WhatsApp). En
 *   «icons-names» el nombre visible va aria-hidden para no leerlo dos veces;
 *   el oculto empieza por él (WCAG 2.5.3, la etiqueta dentro del nombre).
 * - Pestaña nueva con rel="noopener noreferrer" solo en los enlaces http(s):
 *   un mailto: o un tel: no abren ninguna página.
 * - Diana táctil de 44 px como mínimo (WCAG 2.5.8 pide 24; 44 es la de
 *   Apple y la que piden los móviles), aunque el icono sea pequeño.
 *
 * Cómo se mantiene:
 * - Caso frontera: «que en el pie salgan en blanco y en la página de
 *   contacto con su color de marca» es diseño: color del bloque en el pie, y
 *   para el color de marca, CSS del tema por .is-network-<slug>. No se añade
 *   un atributo por marca.
 * - La trampa: la vista previa del editor la pinta ESTE fichero por REST
 *   (autoRegister → ServerSideRender). Un cambio aquí se ve en los dos
 *   sitios; un error aquí rompe también el editor.
 *
 * @package estrategia-web
 *
 * @var array $attributes Block attributes.
 */

use EstrategiaWeb\Social\Links;

defined( 'ABSPATH' ) || exit;

$estrategia_web_items = Links::resolved( 'estrategia-web-social-links__icon' );

if ( ! $estrategia_web_items ) {
	if ( wp_is_serving_rest_request() ) {
		printf(
			'<p %s>%s</p>',
			get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html__( 'Social networks: add them in Estrategia Web › Social networks.', 'estrategia-web' )
		);
	}
	return;
}

$estrategia_web_display = in_array( $attributes['display'] ?? '', array( 'icons', 'icons-names' ), true ) ? $attributes['display'] : 'icons';
$estrategia_web_size    = in_array( $attributes['size'] ?? '', array( 'small', 'medium', 'large' ), true ) ? $attributes['size'] : 'medium';
$estrategia_web_owner   = Links::owner();
?>
<ul <?php echo get_block_wrapper_attributes( array( 'class' => 'estrategia-web-social-links is-display-' . $estrategia_web_display . ' is-size-' . $estrategia_web_size ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php foreach ( $estrategia_web_items as $estrategia_web_item ) : ?>
		<?php
		/* translators: 1: network name («Instagram»), 2: company name. */
		$estrategia_web_label = sprintf( __( '%1$s of %2$s', 'estrategia-web' ), $estrategia_web_item['name'], $estrategia_web_owner );
		if ( $estrategia_web_item['new_tab'] ) {
			$estrategia_web_label .= ' ' . __( '(opens in a new tab)', 'estrategia-web' );
		}
		?>
		<li class="estrategia-web-social-links__item is-network-<?php echo esc_attr( $estrategia_web_item['icon'] ); ?>">
			<a class="estrategia-web-social-links__link" href="<?php echo esc_url( $estrategia_web_item['url'], Links::PROTOCOLS ); ?>"<?php echo $estrategia_web_item['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
				<?php echo $estrategia_web_item['svg']; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG de la colección o saneado por Svg_Sanitizer (Icons). ?>
				<?php if ( 'icons-names' === $estrategia_web_display ) : ?>
					<span class="estrategia-web-social-links__name" aria-hidden="true"><?php echo esc_html( $estrategia_web_item['name'] ); ?></span>
				<?php endif; ?>
				<span class="estrategia-web-social-links__sr"><?php echo esc_html( $estrategia_web_label ); ?></span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
