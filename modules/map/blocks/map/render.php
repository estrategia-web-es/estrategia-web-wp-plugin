<?php
/**
 * estrategia-web/map — mapa «en dos clics».
 *
 * Qué es: una imagen estática (de la mediateca) con la dirección, un botón
 * «Ver mapa» que SOLO ENTONCES pone el iframe de Google Maps, y un enlace
 * «Abrir en Google Maps». Antes del clic no se pide nada a Google: el iframe
 * está en el HTML sin src (about:blank) y view.js le pone la URL.
 *
 * Decisiones:
 * - El clic del visitante es el consentimiento para ESA carga, por eso el mapa
 *   no necesita aviso de cookies. Debajo del botón se dice qué pasa al pulsar.
 * - Si el sitio declara la categoría «maps» en el consentimiento (el aviso de
 *   cookies del plugin estrategia-web-site desde la 0.7.0 del padre; antes,
 *   inc/consent.php) y el visitante la aceptó, el mapa carga directo, también
 *   si la acepta después con la página abierta (evento estrategia-web:consent).
 *   SIN EL PLUGIN el mapa funciona igual: imagen estática o recuadro, y
 *   «Ver mapa» carga ese mapa al pulsar. Solo habla con el aviso por su
 *   contrato JS (window.estrategiaWebConsent?.…, el evento), nunca por PHP, para no
 *   depender de que esté (tests/map.test.mjs corre sin él).
 * - La URL de embed se valida: https, host www.google.com o maps.google.com y
 *   ruta /maps/embed. Se acepta pegar el <iframe> entero que da Google
 *   (Compartir › Insertar un mapa): se saca el src.
 * - Sin imagen elegida sale un recuadro neutro con la dirección.
 * - Editor: edit.js (script clásico) por el selector de imagen de la
 *   mediateca, que autoRegister no ofrece; la vista previa es ServerSideRender.
 * - SIN IMAGEN por defecto (0.3.0): antes del clic se ve un recuadro con los
 *   DATOS del sitio en texto (nombre, dirección, teléfono con tel:, horario),
 *   con los colores del tema, y debajo «Ver mapa» y «Abrir en Google Maps».
 *   Por qué: una captura de Google Maps como foto se veía mal (David,
 *   02-10-2026) y aparentaba un mapa que no se puede mover. La imagen sigue
 *   siendo posible (elegida, o la destacada con useFeaturedImage) pero es la
 *   excepción. Con «maps» aceptado en el consentimiento se pinta el iframe
 *   directamente, como antes.
 *   Caso frontera: el recuadro dice lo que el bloque sabe; los datos de una
 *   sede llegan por bindings (p. ej. la fuente estrategia-web-site/location).
 *   La trampa: las fuentes de bindings devuelven el texto ya escapado y con
 *   <br>; render.php lo devuelve a texto plano antes de escaparlo (si no, sale
 *   «&amp;amp;» o «<br>» a la vista).
 * - MAPA ESTÁTICO (0.5.0, atributos staticMapUrl o staticMapId): una imagen
 *   de OpenStreetMap generada UNA vez con tools/static-map.mjs (marcador del
 *   color de la marca y «© OpenStreetMap» dentro) va DEBAJO del recuadro de
 *   datos, que se mantiene: en ancho, el recuadro flota a la izquierda sobre
 *   el mapa; en estrecho (< 480 px de bloque), va debajo. No es una captura de
 *   Google (su licencia no lo permite y pedirla al visitar manda la IP a
 *   Google sin consentimiento). Se sirve desde el PROPIO sitio:
 *   staticMapUrl es una ruta del tema («assets/maps/sede.webp», se busca en
 *   el hijo y luego en el padre), una ruta del sitio («/wp-content/…») o una
 *   URL del mismo dominio; cualquier otra se descarta (estrategia_web_map_static_url).
 *   staticMapId es la misma imagen subida a la mediateca. Acepta bindings
 *   (cada sede de una Consulta, su mapa). Si hay mapa estático, la «foto»
 *   (imageId/imageUrl/useFeaturedImage) no se usa.
 *   Caso frontera: el mapa estático no se mueve ni hace zoom; quien quiera
 *   moverlo pulsa «Ver mapa», que carga Google y CONCEDE la categoría «maps»
 *   del consentimiento (window.estrategiaWebConsent.grant), así los demás mapas de
 *   la web cargan ya sin preguntar. Si la web no declara «maps», el clic solo
 *   carga ese mapa, como antes.
 *   La trampa: el marcador va en el CENTRO de la imagen y el recuadro tapa la
 *   izquierda; por eso el recuadro no pasa del 42 % del ancho. Un hijo que lo
 *   ensanche tapa el marcador.
 * - SIN DATOS (0.6.0, atributo showDetails, por defecto SÍ): con
 *   showDetails:false el bloque no pinta su recuadro de datos (ni la
 *   dirección bajo una foto). Para cuando la ficha de la sede ya los dice al
 *   lado: en Lavandería la dirección salía dos veces, en la ficha y dentro
 *   del mapa. Queda: con mapa estático o foto, SOLO la imagen, y debajo
 *   «Ver mapa» y «Abrir en Google Maps»; sin imagen, solo los botones (el
 *   marco no se pinta hasta que hay mapa: una caja vacía parecería rota).
 *   La dirección se sigue pasando: hace falta para el enlace a Google Maps,
 *   el título del iframe y el texto alternativo del mapa estático.
 *       <!-- wp:estrategia-web/map {"showDetails":false,"metadata":{"bindings":{
 *         "address":…,"embedUrl":…,"staticMapUrl":…}}} /-->
 *   Por qué un atributo y no adivinarlo: el bloque no ve lo que hay al lado
 *   (un hermano en el mismo grupo puede ser la ficha o no). Lo sabe el patrón
 *   del hijo, que es quien lo pone.
 *   Caso frontera: un mapa suelto (página de contacto sin ficha) se queda
 *   con el valor por defecto: sin datos no diría dónde está.
 * - Dentro de una Consulta (0.2.0): sus atributos de texto aceptan Block
 *   Bindings (modules/map/module.php), así que la dirección y la URL de embed pueden
 *   salir de los campos de cada entrada (core/post-meta); y sin imagen
 *   elegida usa la imagen destacada de la entrada (usesContext postId). Así
 *   una lista de sedes que vive en la base de datos lleva cada una su mapa.
 *
 * Cómo se mantiene:
 * - Quién: quien toque el módulo map (hasta la 1.0.0, el tema padre). Cuándo: si Google cambia el formato de la URL
 *   de embed (se ajusta estrategia_web_map_embed_url) o al añadir un proveedor.
 * - Caso frontera: otro proveedor (OpenStreetMap) es un AJUSTE si es otra URL
 *   de iframe con la misma mecánica: se amplía la validación aquí. Un mapa con
 *   marcadores propios y JS del proveedor es OTRA COSA: bloque del hijo.
 * - La trampa que costó la tarde (01-10-2026): el iframe NO lleva
 *   loading="lazy". Con lazy, si el src se pone al hidratar (consentimiento
 *   ya aceptado) mientras el iframe aún está oculto, Chrome no lo carga nunca:
 *   src puesto, frame en about:blank, recuadro en blanco. Sin src no se carga
 *   nada de todos modos, así que lazy no ahorraba nada.
 * - Otra: el enlace «Abrir en Google Maps» es un <a> normal y no hace
 *   ninguna petición hasta que se pulsa; las pruebas miran PETICIONES, no
 *   apariciones de «google.com» en el HTML, que las hay por diseño.
 *
 * @package estrategia-web
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'estrategia_web_map_embed_url' ) ) {
	/**
	 * Returns a safe Google Maps embed URL, or '' if the value is not one.
	 * Accepts the bare URL or the whole <iframe> code Google gives.
	 *
	 * @param string $value Attribute value.
	 */
	function estrategia_web_map_embed_url( string $value ): string {
		$value = trim( html_entity_decode( $value ) );
		if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $value, $match ) ) {
			$value = $match[1];
		}
		$parts = wp_parse_url( $value );
		if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) ) {
			return '';
		}
		if ( ! in_array( $parts['host'], array( 'www.google.com', 'maps.google.com' ), true ) ) {
			return '';
		}
		if ( empty( $parts['path'] ) || ! str_starts_with( $parts['path'], '/maps/embed' ) ) {
			return '';
		}
		return esc_url_raw( $value );
	}
}

if ( ! function_exists( 'estrategia_web_map_static_url' ) ) {
	/**
	 * URL of the static map image (0.5.0), only if it is served by THIS site.
	 * A relative path («assets/maps/sede.webp») is a file of the active theme
	 * (the child, then the parent). An absolute URL must be on the site's own
	 * host: a static map from a third party would be a request without consent.
	 *
	 * @param string $value Attribute value.
	 */
	function estrategia_web_map_static_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( empty( $parts['host'] ) ) {
			if ( str_starts_with( $value, '/' ) ) {
				return esc_url_raw( home_url( $value ) );
			}
			$path = preg_replace( '#^\./#', '', $value );
			if ( str_contains( $path, '..' ) ) {
				return '';
			}
			foreach ( array( get_stylesheet_directory() => get_stylesheet_directory_uri(), get_template_directory() => get_template_directory_uri() ) as $dir => $uri ) {
				if ( file_exists( $dir . '/' . $path ) ) {
					return esc_url_raw( $uri . '/' . $path );
				}
			}
			return '';
		}
		$site = wp_parse_url( home_url() );
		return isset( $site['host'] ) && strtolower( $parts['host'] ) === strtolower( $site['host'] ) ? esc_url_raw( $value ) : '';
	}
}

// Los datos llegan de atributos o de Block Bindings: una fuente de texto los
// trae con <br> en vez de saltos (las de estrategia-web-site lo hacen); se
// devuelven a saltos para escaparlos aquí una sola vez.
$estrategia_web_text      = static fn( string $key ): string => trim( html_entity_decode( wp_strip_all_tags( preg_replace( '/<br\s*\/?>/i', "\n", (string) $attributes[ $key ] ) ), ENT_QUOTES, 'UTF-8' ) );
$estrategia_web_place     = $estrategia_web_text( 'placeName' );
$estrategia_web_phone     = $estrategia_web_text( 'phone' );
$estrategia_web_hours     = $estrategia_web_text( 'hours' );
$estrategia_web_address   = $estrategia_web_text( 'address' );
$estrategia_web_embed     = estrategia_web_map_embed_url( (string) $attributes['embedUrl'] );
$estrategia_web_maps_url  = trim( (string) $attributes['mapsUrl'] );
if ( '' === $estrategia_web_maps_url && '' !== $estrategia_web_address ) {
	$estrategia_web_maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $estrategia_web_address );
}

if ( '' === $estrategia_web_embed && '' === $estrategia_web_maps_url ) {
	if ( wp_is_serving_rest_request() ) {
		printf(
			'<p %s>%s</p>',
			get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html__( 'Map: add the address and the Google Maps embed URL in the block settings.', 'estrategia-web' )
		);
	}
	return;
}

$estrategia_web_static = '';
if ( $attributes['staticMapId'] ) {
	$estrategia_web_static = (string) wp_get_attachment_image_url( (int) $attributes['staticMapId'], 'full' );
}
if ( '' === $estrategia_web_static ) {
	$estrategia_web_static = estrategia_web_map_static_url( $estrategia_web_text( 'staticMapUrl' ) );
}

$estrategia_web_image = '';
if ( '' !== $estrategia_web_static ) {
	$estrategia_web_image = sprintf(
		'<img class="estrategia-web-map__static" src="%s" alt="%s" loading="lazy" decoding="async" data-wp-bind--hidden="context.loaded">',
		esc_url( $estrategia_web_static ),
		esc_attr(
			'' !== $estrategia_web_address
				/* translators: %s: postal address. */
				? sprintf( __( 'Street map around %s', 'estrategia-web' ), str_replace( "\n", ', ', $estrategia_web_address ) )
				: __( 'Street map of the area', 'estrategia-web' )
		)
	);
} elseif ( $attributes['imageId'] ) {
	$estrategia_web_image = wp_get_attachment_image(
		(int) $attributes['imageId'],
		'large',
		false,
		array(
			'class'               => 'estrategia-web-map__image',
			'alt'                 => $attributes['imageAlt'],
			'loading'             => 'lazy',
			'data-wp-bind--hidden' => 'context.loaded',
		)
	);
}
// Dentro de una Consulta, SOLO si se pide (useFeaturedImage, 0.3.0; en 0.2.0
// era siempre): la imagen destacada de la entrada.
if ( '' === $estrategia_web_image && '' === $attributes['imageUrl'] && $attributes['useFeaturedImage'] && ! empty( $block->context['postId'] ) && has_post_thumbnail( (int) $block->context['postId'] ) ) {
	$estrategia_web_image = wp_get_attachment_image(
		get_post_thumbnail_id( (int) $block->context['postId'] ),
		'large',
		false,
		array(
			'class'                => 'estrategia-web-map__image',
			'alt'                  => $attributes['imageAlt'],
			'loading'              => 'lazy',
			'data-wp-bind--hidden' => 'context.loaded',
		)
	);
}
if ( '' === $estrategia_web_image && '' !== $attributes['imageUrl'] ) {
	$estrategia_web_image = sprintf(
		'<img class="estrategia-web-map__image" src="%s" alt="%s" loading="lazy" data-wp-bind--hidden="context.loaded">',
		esc_url( $attributes['imageUrl'] ),
		esc_attr( $attributes['imageAlt'] )
	);
}

// Foto = una imagen que SUSTITUYE al recuadro (la de 0.2.0). El mapa
// estático no es foto: va DEBAJO del recuadro de datos (0.5.0).
$estrategia_web_has_photo = '' !== $estrategia_web_image && '' === $estrategia_web_static;

// Sin datos (0.6.0, showDetails:false): ni recuadro ni dirección bajo la foto,
// porque la ficha de al lado ya los dice. Sin imagen, el marco no se pinta
// hasta que hay mapa: una caja vacía antes del clic parecería rota.
$estrategia_web_details     = ! isset( $attributes['showDetails'] ) || false !== $attributes['showDetails'];
$estrategia_web_frame_later = ! $estrategia_web_details && '' === $estrategia_web_image;
$estrategia_web_no_frame    = false;
if ( $estrategia_web_frame_later && '' === $estrategia_web_embed ) {
	// Nada que enseñar ni antes ni después del clic: solo el enlace.
	$estrategia_web_frame_later = false;
	$estrategia_web_no_frame    = true;
}

$estrategia_web_title = '' !== $estrategia_web_address
	/* translators: %s: postal address. */
	? sprintf( __( 'Map: %s', 'estrategia-web' ), $estrategia_web_address )
	: __( 'Map', 'estrategia-web' );
?>
<figure
	<?php echo get_block_wrapper_attributes( array( 'class' => 'estrategia-web-map' . ( '' !== $estrategia_web_static ? ' has-static-map' : '' ) . ( $estrategia_web_details ? '' : ' is-without-details' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	data-wp-interactive="estrategia-web/map"
	<?php echo wp_interactivity_data_wp_context( array( 'loaded' => false, 'embedUrl' => $estrategia_web_embed ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	data-wp-init="callbacks.init"
	data-wp-class--is-loaded="context.loaded"
>
	<?php if ( ! $estrategia_web_no_frame ) : ?>
	<div class="estrategia-web-map__frame"<?php echo $estrategia_web_frame_later ? ' hidden data-wp-bind--hidden="!context.loaded"' : ''; ?>>
		<?php if ( '' !== $estrategia_web_image ) : ?>
			<?php echo $estrategia_web_image; // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping above. ?>
		<?php endif; ?>
		<?php if ( ! $estrategia_web_has_photo && $estrategia_web_details ) : ?>
			<div class="estrategia-web-map__card" data-wp-bind--hidden="context.loaded">
				<?php if ( '' !== $estrategia_web_place ) : ?>
				<p class="estrategia-web-map__name"><?php echo esc_html( $estrategia_web_place ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $estrategia_web_address ) : ?>
				<address class="estrategia-web-map__card-address"><?php echo nl2br( esc_html( $estrategia_web_address ), false ); ?></address>
				<?php endif; ?>
				<?php if ( '' !== $estrategia_web_phone ) : ?>
				<p class="estrategia-web-map__phone"><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $estrategia_web_phone ), array( 'tel' ) ); ?>"><?php echo esc_html( $estrategia_web_phone ); ?></a></p>
				<?php endif; ?>
				<?php if ( '' !== $estrategia_web_hours ) : ?>
				<p class="estrategia-web-map__hours"><?php echo nl2br( esc_html( $estrategia_web_hours ), false ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( '' !== $estrategia_web_embed ) : ?>
		<iframe
			class="estrategia-web-map__iframe"
			title="<?php echo esc_attr( $estrategia_web_title ); ?>"
			hidden
			data-wp-bind--hidden="!context.loaded"
			data-wp-bind--src="state.src"
			referrerpolicy="strict-origin-when-cross-origin"
			allowfullscreen
		></iframe>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<figcaption class="estrategia-web-map__caption">
		<?php if ( '' !== $estrategia_web_address && $estrategia_web_has_photo && $estrategia_web_details ) : ?>
		<address class="estrategia-web-map__address"><?php echo esc_html( $estrategia_web_address ); ?></address>
		<?php endif; ?>
		<?php if ( '' !== $estrategia_web_embed ) : ?>
		<div class="estrategia-web-map__actions" data-wp-bind--hidden="context.loaded">
			<button type="button" class="wp-element-button estrategia-web-map__load" data-wp-on--click="actions.load"><?php esc_html_e( 'Show map', 'estrategia-web' ); ?></button>
			<p class="estrategia-web-map__notice"><?php esc_html_e( 'The map is served by Google. Loading it sends your IP address to Google, which may set its own cookies.', 'estrategia-web' ); ?></p>
		</div>
		<?php endif; ?>
		<?php if ( '' !== $estrategia_web_maps_url ) : ?>
		<a class="estrategia-web-map__link" href="<?php echo esc_url( $estrategia_web_maps_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Open in Google Maps', 'estrategia-web' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'estrategia-web' ); ?></span>
		</a>
		<?php endif; ?>
	</figcaption>
</figure>
