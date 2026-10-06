<?php
/**
 * WebP (0.5.1): los tamaños que WordPress genera de un JPEG o un PNG subido
 * se guardan en WebP.
 *
 * Por qué: la auditoría de Lavandería (06-10-2026) daba «formatos de próxima
 * generación» en 8 de 10 pasadas (hasta 366 KiB en el inicio): el servidor
 * entregaba image/jpeg aunque el navegador aceptara WebP. Va en el plugin y
 * no en el tema porque es lo que se GUARDA en la mediateca: cambiar de tema no
 * puede cambiarlo. Y en código, no en el panel de SiteGround, para que viaje
 * con la web.
 *
 * Cómo: el filtro image_editor_output_format de WordPress (5.8+), con
 * image/jpeg → image/webp e image/png → image/webp. MEDIDO (06-10-2026,
 * tests/webp.test.mjs): WordPress convierte TODOS los ficheros que genera,
 * también el principal: al subir foto.jpg, la mediateca sirve foto.webp
 * (source_url, el «full») y sus tamaños foto-1024x640.webp…; el .jpg subido
 * se queda en disco como original_image y no se sirve. De más de 2560 px,
 * foto-scaled.webp. El srcset que WordPress pinta al servir la página sale
 * del meta, así que en el contenido NUEVO todo va en WebP sin hacer nada.
 * - Solo si el editor de imágenes del servidor sabe escribir WebP
 *   (wp_image_editor_supports). Si no sabe, no hace nada: los tamaños siguen
 *   en JPEG/PNG y no se rompe ninguna subida.
 * - Apagar (por defecto, encendido), en el functions.php del tema:
 *       add_filter( 'armazon_site_webp', '__return_false' );
 *   Filtro y no opción, como el «Powered by»: es una decisión de quien hace
 *   la web, no un dato que el cliente toque.
 * - PNG con transparencia: WebP la conserva. GIF no se toca (animaciones).
 *
 * LA TRAMPA: LO YA SUBIDO NO CAMBIA SOLO, Y REGENERAR LO EMPEORA SI NO SE
 * TOCA EL CONTENIDO. Hace falta regenerar (`wp media regenerate --yes`) o
 * volver a subir la foto. Al regenerar, el META pasa a .webp (también el
 * fichero principal), pero las URL guardadas en el contenido de las páginas
 * NO cambian (nadie reescribe post_content):
 * - una foto insertada con la URL del fichero principal (foto.jpg: el
 *   «full», o la source_url que usa la carga de contenido de Lavandería para
 *   TODAS) sigue sirviendo, porque el .jpg queda como original_image, pero
 *   ya no casa con el meta: WordPress no le pone srcset y el navegador baja
 *   el JPEG entero a cualquier ancho. Peor que antes de regenerar.
 * - una insertada con la URL de un tamaño intermedio (foto-1024x683.jpg, lo
 *   que guarda el editor al elegir «Grande») apunta a un fichero que
 *   `wp media regenerate` BORRA: imagen rota. Con --skip-delete no se rompe,
 *   pero sigue en JPEG y sin srcset.
 * El arreglo: después de regenerar, volver a escribir en el contenido la URL
 * que da ahora la mediateca (en Lavandería, volver a pasar su carga de
 * contenido, que pone la source_url). Lo prueba tests/webp.test.mjs (nivel
 * 2), que imita el regenerate de WP-CLI con una ruta de pruebas (WP-CLI no
 * está en Playground: que lo haga igual en el servidor real es la hipótesis
 * que hay que mirar en la primera pasada).
 *
 * Cómo se mantiene:
 * - Quién: quien toque el plugin. Cuándo: si WordPress cambia el formato por
 *   defecto (ya pasó: 6.7 añadió HEIC → JPEG en este mismo filtro; por eso
 *   aquí se AÑADE al array, nunca se sustituye).
 * - Caso frontera: un tema que quiera AVIF es un ajuste del mismo filtro de
 *   WordPress en su functions.php, con prioridad mayor que 10; no se toca esto.
 */

namespace Armazon\Site;

defined( 'ABSPATH' ) || exit;

final class Webp {

	/** Formatos de origen que pasan a WebP. */
	private const FROM = array( 'image/jpeg', 'image/png' );

	private static ?bool $supported = null;

	/** Calidad del WebP por defecto (0.5.4). WordPress usa 82 para todos los formatos. */
	public const QUALITY = 75;

	public static function boot(): void {
		add_filter( 'image_editor_output_format', array( self::class, 'output_format' ), 10, 3 );
		add_filter( 'wp_editor_set_quality', array( self::class, 'quality' ), 10, 2 );
	}

	/**
	 * Calidad de los WebP que genera el editor de imágenes (0.5.4). Solo
	 * image/webp: un JPEG o un PNG que se siga guardando como tal no cambia.
	 *
	 * Por qué 75: PageSpeed de Lavandería (06-10-2026), «mejorar la entrega de
	 * imágenes», contaba compresión de más en las fotos de portada aun con el
	 * tamaño bien elegido. 75 es lo que usa Google (cwebp) por defecto; a la
	 * vista, en fotos, no se distingue de 82 y pesa en torno a un 20 % menos.
	 *
	 * Ajuste (como el WebP mismo, un filtro y no una opción: es de quien hace
	 * la web), en el functions.php del tema:
	 *     add_filter( 'armazon_site_webp_quality', fn() => 82 );
	 * Devolver 0 o menos deja la de WordPress.
	 *
	 * LA TRAMPA: solo vale para lo que se genere DESDE que esté activo. Lo ya
	 * subido no cambia hasta regenerarlo (en Lavandería, el paso «webp» de
	 * despliegue/desplegar.mjs, que lo hace sin romper el contenido).
	 *
	 * @param int|mixed $quality   Calidad que trae WordPress (82) o otro filtro.
	 * @param string    $mime_type Formato que se va a guardar.
	 */
	public static function quality( $quality, $mime_type = '' ): int {
		if ( 'image/webp' !== $mime_type ) {
			return (int) $quality;
		}
		/**
		 * Calidad (1-100) de los WebP que genera el editor de imágenes. 0: la de WordPress.
		 *
		 * @param int $quality
		 */
		$ours = (int) apply_filters( 'armazon_site_webp_quality', self::QUALITY );
		return $ours > 0 ? min( 100, $ours ) : (int) $quality;
	}

	/**
	 * Si el editor de imágenes de este servidor escribe WebP. Se pregunta una
	 * vez por petición: elegir el editor carga y prueba Imagick/GD.
	 */
	public static function supported(): bool {
		self::$supported ??= (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		return self::$supported;
	}

	/**
	 * @param array<string, string> $formats  Origen => destino (WordPress trae HEIC → JPEG).
	 * @param string                $filename Ruta de la imagen.
	 * @param string                $mime     Tipo de la imagen de origen.
	 * @return array<string, string>
	 */
	public static function output_format( $formats, $filename = '', $mime = '' ): array {
		$formats = (array) $formats;
		/**
		 * Si los tamaños de las imágenes subidas se guardan en WebP. Por defecto, sí.
		 *
		 * @param bool $enabled
		 */
		if ( ! apply_filters( 'armazon_site_webp', true ) || ! self::supported() ) {
			return $formats;
		}
		foreach ( self::FROM as $from ) {
			$formats[ $from ] = 'image/webp';
		}
		return $formats;
	}
}
