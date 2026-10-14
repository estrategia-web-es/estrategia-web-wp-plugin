<?php
/**
 * estrategia-web/carousel — carrusel de desplazamiento horizontal.
 *
 * Qué es: una fila desplazable (scroll-snap) con los bloques que se metan
 * dentro (imágenes, grupos, tarjetas: cada hijo directo es una diapositiva),
 * flechas anterior/siguiente, puntos opcionales y autoplay SOLO si se pide.
 *
 * Con una Consulta dentro (0.2.0): si un hijo directo es un core/query, cada
 * entrada de su post-template (<li>) es una diapositiva. Así una lista que
 * vive en la base de datos (clientes, opiniones) se pasa en carrusel sin
 * copiar sus fichas a mano. La consulta y su <ul> se aplanan con
 * display: contents (style.css) y view.js recorre los <li>.
 * - Caso frontera: la paginación de la consulta NO va dentro del carrusel
 *   (sería una diapositiva más); el carrusel enseña las perPage que traiga.
 * - La trampa: el post-template tiene que ser hijo DIRECTO de la consulta. Si
 *   se envuelve en un grupo, el grupo es la única diapositiva.
 * - En el editor la consulta se ve con su disposición normal (en columna): el
 *   aplanado es solo de la página publicada.
 *
 * En bucle (0.5.0, atributo loop, por defecto SÍ): «siguiente» en la última
 * va a la primera y «anterior» en la primera va a la última (también con
 * ← → del teclado). Las dos flechas están
 * siempre activas: sin bucle, «anterior» sale gris al cargar y en una web
 * parecía un carrusel roto. Con una Consulta dentro funciona igual (cuenta
 * entradas). Por qué SÍ por defecto: es lo que espera quien usa un carrusel
 * en una web de negocio, y el gris al cargar se veía como un fallo; un
 * carrusel que deba terminar (pasos de un proceso) pone loop: false.
 *
 * INFINITO (0.6.0): el bucle ya no rebobina. De la última se pasa a la
 * primera SIGUIENDO en la misma dirección (y al revés), con flechas, teclado,
 * puntos, autoplay y con el dedo (scroll-snap). Lo pidió David: el rebobinado
 * de la 0.5.0 recorría todas las diapositivas hacia atrás.
 * - Técnica: SIN CLONES. view.js pone a cada diapositiva un `order` de flex
 *   que ROTA la fila sin tocar el DOM, de modo que alrededor de la que se ve
 *   siempre hay diapositivas a los dos lados. Cuando la pista se para
 *   (scrollend; en Safari sin scrollend, tras 120 ms quieta) se vuelve a rotar
 *   alrededor de la del borde y se corrige scrollLeft en el mismo fotograma,
 *   sin animación: la diapositiva no se mueve en pantalla y el salto no se ve.
 *   Antes de cada paso con flechas o puntos se rota lo necesario para que el
 *   destino quede del lado al que se va.
 * - Por qué no clones: duplicarían enlaces enfocables, ids y lo que lee un
 *   lector de pantalla (habría que marcarlos inert/aria-hidden y quitarles
 *   los ids), cargarían otra vez las imágenes, y sobre todo cambiarían el
 *   :nth-child de las diapositivas, del que tiran los hijos (Lavandería da a
 *   cada foto del hero-carousel su proporción por nth-child(3n+…)). `order`
 *   no cambia el DOM: ni el nth-child, ni el orden de lectura, ni el del
 *   tabulador, que siguen siendo el lógico (1, 2, 3…).
 * - Rejilla: si el hijo hace la pista una rejilla de varias filas llenada
 *   por filas (Lavandería: la franja de clientes, dos filas de 12), lo que
 *   rota son las COLUMNAS, cada fila igual; rotar ficha a ficha pasaba fichas
 *   de una fila a otra. Las filas se miden con el orden natural al cargar y
 *   al cambiar de ancho. Una disposición irregular (filas de distinto largo,
 *   flex con wrap) no se rota: da la vuelta rebobinando, como la 0.5.0.
 *   Con varias filas los puntos no cuadran (cuentan fichas, no columnas):
 *   esos carruseles van sin puntos.
 * - Caso frontera: con UNA diapositiva, o todas a la vista, no hay nada que
 *   desplazar: la fila queda quieta (is-static) y no se toca su orden. Con
 *   solo UNA de más (p. ej. 3 fotos y 2 a la vista) el dedo solo encuentra
 *   vecina a un lado en cada momento; las flechas dan la vuelta igual, y
 *   desde la 0.7.2 DESLIZANDO: como la fila no da para desplazar una entera,
 *   view.js (glide) anima cada diapositiva con transform desde donde estaba,
 *   y la que da la vuelta sale por un lado con una copia inerte y temporal
 *   mientras entra por el otro. En reposo no queda ninguna copia.
 * - Pulsar seguido no salta (0.7.2): durante la animación propia la pista va
 *   sin scroll-snap, y los pasos que llegan mientras glide anima se encolan.
 * - Los puntos: el activo es el de la diapositiva del borde izquierdo
 *   (también con varias visibles); un punto lleva a la suya por el camino
 *   más corto. Inicio y Fin van a la primera y a la última.
 * - Con «reducir movimiento», los pasos son instantáneos; la vuelta se da
 *   igual.
 * - La trampa (para un hijo): una regla con `order` sobre las diapositivas
 *   pierde contra el estilo en línea de view.js, y un estilo que dependa de
 *   la posición VISUAL (:first-child para «la primera que se ve») no vale:
 *   la primera del DOM ya no es la de la izquierda. Las de :nth-child siguen
 *   pegadas a su diapositiva, que es lo que se quiere.
 * - Pruebas: tests/carousel-glide.test.mjs (cada paso desliza, con varios
 *   fotogramas intermedios y sin saltos) y tests/carousel-infinite.test.mjs, que mira fotograma a
 *   fotograma que la pista no retroceda.
 *
 * FOTOS (0.7.4): las diapositivas que se ven al cargar llevan
 * fetchpriority="high" (si el carrusel es lo primero de la página), las demás
 * loading="lazy", y todas un sizes del ancho de la diapositiva, o el del
 * atributo imageSizes. Todo en modules/carousel/includes/carousel-images.php, con su caso frontera
 * (Lavandería) y sus trampas. Prueba: tests/carousel-images.test.mjs.
 *
 * Decisiones:
 * - Sin JavaScript es una fila desplazable con el dedo, la rueda o las flechas
 *   del teclado: el comportamiento es una mejora, no un requisito. Los
 *   controles salen ocultos y view.js les quita la ocultación (clase is-ready).
 * - Comportamiento con la Interactivity API (view.js, módulo ES nativo).
 * - Editor: InnerBlocks necesita JavaScript en el editor, y WordPress 7.1 no
 *   admite módulos ES como editorScript (no hay editorScriptModule). Por eso
 *   edit.js es un script CLÁSICO que usa los globales wp.element y
 *   wp.blockEditor, sin JSX ni compilación. Su lista de dependencias está en
 *   edit.asset.php, escrita a mano.
 * - Cuántos se ven: por ANCHO DEL CARRUSEL (container queries), no de la
 *   ventana. Un carrusel en una columna estrecha enseña los de móvil aunque la
 *   pantalla sea grande, que es lo que se quiere.
 * - Accesibilidad: región con aria-roledescription «carousel» y nombre; la
 *   pista es enfocable (tabindex=0) y responde a ← → Inicio Fin; botones con
 *   nombre y aria-controls; prefers-reduced-motion quita el desplazamiento
 *   suave y anula el autoplay. El autoplay, si se activa, se para al pasar el
 *   ratón o al entrar el foco, y tiene botón de pausa (WCAG 2.2.2).
 *
 * Cómo se mantiene:
 * - Quién: quien toque el módulo carousel (hasta la 1.0.0, el tema padre). Cuándo: al cambiar el marcado o los
 *   atributos; un atributo nuevo lleva default para que los carruseles ya
 *   guardados sigan igual (condición 2 del padre: no obligar a tocar un hijo).
 * - Caso frontera: «quiero el carrusel con las flechas encima de las
 *   imágenes» es un AJUSTE (CSS del hijo sobre .estrategia-web-carousel__controls);
 *   «quiero un carrusel que sincronice dos filas» es OTRA COSA: bloque del hijo.
 * - Los cortes de container query (480px / 782px) copian settings.viewport de
 *   theme.json. Si se cambian allí, se cambian en style.css: CSS no deja usar
 *   variables dentro de @container.
 * - La trampa: los puntos se cuentan con los bloques internos al renderizar;
 *   un bloque interno que no pinta nada (un párrafo vacío borrado a medias)
 *   cuenta como diapositiva invisible.
 *
 * @package estrategia-web
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks HTML.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Diapositivas: cada bloque interno directo, SALVO un core/query, que aporta
 * una por cada <li> de su post-template (0.2.0). Las de la consulta se
 * cuentan en el HTML ya pintado: antes de renderizar no se sabe cuántas
 * entradas trae.
 */
$estrategia_web_count = 0;
foreach ( $block->inner_blocks as $estrategia_web_inner ) {
	if ( 'core/query' !== $estrategia_web_inner->name ) {
		++$estrategia_web_count;
	}
}
if ( str_contains( $content, 'wp-block-post-template' ) ) {
	$estrategia_web_tags = new WP_HTML_Tag_Processor( $content );
	while ( $estrategia_web_tags->next_tag( array( 'tag_name' => 'LI', 'class_name' => 'wp-block-post' ) ) ) {
		++$estrategia_web_count;
	}
}
$estrategia_web_track_id = wp_unique_id( 'estrategia-web-carousel-' );

// Fotos (0.7.4): prioridad a las que se ven al cargar, diferidas las demás y
// un sizes del ancho de la diapositiva. Cómo y por qué: modules/carousel/includes/carousel-images.php.
\EstrategiaWeb\Carousel\images_register( $estrategia_web_track_id, (array) $attributes );
$content = \EstrategiaWeb\Carousel\images_mark( (string) $content, $estrategia_web_track_id );

$estrategia_web_loop    = ! empty( $attributes['loop'] );
$estrategia_web_label    = '' !== trim( (string) $attributes['label'] ) ? $attributes['label'] : __( 'Carousel', 'estrategia-web' );

$estrategia_web_per_view = array(
	'mobile'  => max( 1, (float) $attributes['perViewMobile'] ),
	'tablet'  => max( 1, (float) $attributes['perViewTablet'] ),
	'desktop' => max( 1, (float) $attributes['perViewDesktop'] ),
);

$estrategia_web_context = array(
	'ready'      => false,
	'atStart'    => true,
	'atEnd'      => false,
	'current'    => 0,
	'count'      => $estrategia_web_count,
	'loop'       => $estrategia_web_loop,
	'autoplay'   => (bool) $attributes['autoplay'],
	'interval'   => max( 2000, (int) $attributes['autoplayInterval'] ),
	'playing'    => false,
	'hover'      => false,
	'focused'    => false,
	'labelPause' => __( 'Pause carousel', 'estrategia-web' ),
	'labelPlay'  => __( 'Play carousel', 'estrategia-web' ),
);

$estrategia_web_wrapper = get_block_wrapper_attributes(
	array(
		'class'                => 'estrategia-web-carousel',
		'role'                 => 'region',
		'aria-roledescription' => _x( 'carousel', 'ARIA role description', 'estrategia-web' ),
		'aria-label'           => $estrategia_web_label,
		'style'                => sprintf(
			'--estrategia-web-carousel-per-view-mobile:%s;--estrategia-web-carousel-per-view-tablet:%s;--estrategia-web-carousel-per-view-desktop:%s;',
			$estrategia_web_per_view['mobile'],
			$estrategia_web_per_view['tablet'],
			$estrategia_web_per_view['desktop']
		),
	)
);
?>
<div
	<?php echo $estrategia_web_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by core. ?>
	data-wp-interactive="estrategia-web/carousel"
	<?php echo wp_interactivity_data_wp_context( $estrategia_web_context ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	data-wp-init="callbacks.init"
	data-wp-class--is-ready="context.ready"
	data-wp-class--is-static="state.isStatic"
	data-wp-on-async--mouseenter="actions.hoverIn"
	data-wp-on-async--mouseleave="actions.hoverOut"
	data-wp-on-async--focusin="actions.focusIn"
	data-wp-on-async--focusout="actions.focusOut"
>
	<div
		class="estrategia-web-carousel__track"
		id="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
		tabindex="0"
		aria-label="<?php echo esc_attr( $estrategia_web_label ); ?>"
		data-wp-on-async--scroll="callbacks.onScroll"
		data-wp-on--keydown="actions.onKeydown"
	>
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- inner blocks. ?>
	</div>

	<?php if ( $attributes['showArrows'] || $attributes['autoplay'] ) : ?>
	<div class="estrategia-web-carousel__controls">
		<?php if ( $attributes['autoplay'] ) : ?>
		<button
			type="button"
			class="estrategia-web-carousel__toggle"
			aria-controls="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
			data-wp-on--click="actions.toggleAutoplay"
			aria-label="<?php echo esc_attr( $estrategia_web_context['labelPause'] ); ?>"
			data-wp-bind--aria-label="state.toggleLabel"
		>
			<span class="estrategia-web-carousel__toggle-icon" aria-hidden="true" data-wp-class--is-playing="context.playing"></span>
		</button>
		<?php endif; ?>
		<?php if ( $attributes['showArrows'] ) : ?>
		<button
			type="button"
			class="estrategia-web-carousel__arrow estrategia-web-carousel__prev"
			aria-controls="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
			aria-label="<?php echo esc_attr__( 'Previous slide', 'estrategia-web' ); ?>"
			data-wp-on--click="actions.prev"
			<?php echo $estrategia_web_loop ? '' : 'data-wp-bind--disabled="context.atStart"'; ?>
		>
			<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2"/></svg>
		</button>
		<button
			type="button"
			class="estrategia-web-carousel__arrow estrategia-web-carousel__next"
			aria-controls="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
			aria-label="<?php echo esc_attr__( 'Next slide', 'estrategia-web' ); ?>"
			data-wp-on--click="actions.next"
			<?php echo $estrategia_web_loop ? '' : 'data-wp-bind--disabled="context.atEnd"'; ?>
		>
			<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2"/></svg>
		</button>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( $attributes['showDots'] && $estrategia_web_count > 1 ) : ?>
	<div class="estrategia-web-carousel__dots" role="group" aria-label="<?php echo esc_attr__( 'Choose slide', 'estrategia-web' ); ?>">
		<?php for ( $estrategia_web_i = 0; $estrategia_web_i < $estrategia_web_count; $estrategia_web_i++ ) : ?>
		<button
			type="button"
			class="estrategia-web-carousel__dot"
			aria-controls="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
			aria-label="<?php echo esc_attr( sprintf( /* translators: %d: slide number. */ __( 'Go to slide %d', 'estrategia-web' ), $estrategia_web_i + 1 ) ); ?>"
			<?php echo wp_interactivity_data_wp_context( array( 'index' => $estrategia_web_i ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			data-wp-on--click="actions.goTo"
			data-wp-bind--aria-current="state.isCurrentDot"
		></button>
		<?php endfor; ?>
	</div>
	<?php endif; ?>
</div>
