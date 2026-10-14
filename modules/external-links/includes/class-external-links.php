<?php
/**
 * Enlaces externos en pestaña nueva (0.5.2): todo <a href> del FRONTAL cuyo
 * host no es el de la web sale con target="_blank", «noopener» en rel y el
 * aviso «(se abre en una pestaña nueva)» para lectores de pantalla.
 *
 * Por qué: lo pidió David el 06-10-2026 con un caso real, «Hablar con un
 * asesor» en /hosteleria/ de Lavandería, un botón a wa.me que sacaba al
 * visitante de la web. Lo mismo Google Maps, Facebook o un enlace del
 * contenido a otro dominio. Va en el plugin y no en el tema porque sirve a
 * cualquier web y no puede depender de que cada bloque lo marque a mano.
 *
 * Cómo:
 * - UN filtro sobre la página entera, no uno por bloque: el
 *   `wp_template_enhancement_output_buffer` del núcleo (6.9+; el plugin pide
 *   7.1). Pilla también lo que no es un bloque (el pie de un tema clásico, un
 *   shortcode, el aviso de cookies) y recorre el HTML UNA vez. El núcleo solo
 *   abre ese búfer en las plantillas del frontal y solo si es text/html: ni
 *   el escritorio, ni el editor, ni REST, ni los feeds pasan por aquí.
 * - Se engancha en `template_redirect`, cuando el functions.php del tema ya
 *   ha tenido ocasión de apagarlo; apagado, ni se abre el búfer (la página
 *   sigue saliendo en streaming).
 * - Con WP_HTML_Tag_Processor, nunca con expresiones regulares (lo pide el
 *   propio núcleo para ese filtro).
 *
 * Qué cuenta como externo (is_external(), PURA, probada en el nivel 1):
 * - solo http(s) con host, o «//host/…»;
 * - host distinto del de home_url(), comparando SIN «www.» a los dos lados y
 *   aceptando los subdominios del de la web: con la web en x.es, www.x.es y
 *   tienda.x.es son internos. Sin lista de sufijos públicos a propósito: en
 *   el servidor de pruebas (lavanderamallorca.sg-host.com) otro.sg-host.com
 *   tiene que salir externo, y «el dominio registrable» lo haría interno.
 *
 * Qué NO se toca:
 * - relativos, anclas (#), mailto:, tel:, sms:, javascript: y cualquier otro
 *   esquema que no sea http(s);
 * - los que YA traen target (cualquiera, también _self): quien lo puso
 *   decidió. Así el «Powered by» del aviso de cookies, que lleva su target,
 *   su rel y su aviso, no sale duplicado.
 *
 * Accesibilidad: aria-label, no un <span> oculto. El Tag Processor no inserta
 *   HTML, solo atributos, así que el nombre accesible se rehace: el aria-label
 *   que ya tuviera, o el texto del enlace (más el alt de sus imágenes), y
 *   detrás «(opens in a new tab)», el MISMO msgid que el «Powered by» (en
 *   es_ES «(se abre en una pestaña nueva)»). Empieza por el texto visible, así
 *   que cumple «nombre en la etiqueta» (WCAG 2.5.3). Con aria-labelledby, o
 *   sin texto ninguno (un icono sin nombre, que ya está mal de antes), no se
 *   pone aria-label: taparía el nombre o diría solo «se abre en…».
 *
 * Apagar (por defecto, encendido), en el functions.php del tema:
 *     add_filter( 'estrategia_web_site_external_links', '__return_false' );
 * Filtro y no opción, como el WebP y el «Powered by»: decisión de quien hace
 * la web, no un dato del cliente. Un enlace suelto que deba abrirse en la
 * misma pestaña: ponerle target="_self" en el bloque (se respeta).
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien toque el plugin. Un esquema o un caso nuevo entra
 *   en is_external() y en tests/php/pure.php (nivel 1) con su assert en
 *   tests/pure.test.mjs; lo que dependa de pintar la página, en
 *   tests/external-links.test.mjs (nivel 2).
 * - EL CASO FRONTERA: el enlace a la web VIEJA del cliente en otro dominio
 *   (lavanderiamallorca.com desde lavanderamallorca.sg-host.com, o al revés
 *   tras el cambio de dominio) ES EXTERNO: lo que decide es el host de
 *   home_url(), no de quién es la web. Si una web tiene dos dominios propios
 *   de verdad, se pone target="_self" en esos enlaces; no se añade aquí una
 *   lista de dominios «de la casa».
 * - La trampa: el filtro corre DENTRO del callback del búfer de salida.
 *   Ahí no se puede imprimir nada ni abrir otro ob_start() (error fatal de
 *   PHP), y una excepción la recoge el núcleo y sirve la página sin tocar:
 *   un fallo aquí no rompe la web, pero deja los enlaces como estaban SIN
 *   avisar. Si un día «dejan de abrirse en pestaña nueva», mirar el log de
 *   PHP antes que el código.
 */

namespace EstrategiaWeb\Site;

final class External_Links {

	public static function boot(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_enable' ) );
	}

	public static function maybe_enable(): void {
		/**
		 * Si los enlaces a otro dominio se abren en pestaña nueva. Por defecto, sí.
		 *
		 * @param bool $enabled
		 */
		if ( apply_filters( 'estrategia_web_site_external_links', true ) ) {
			add_filter( 'wp_template_enhancement_output_buffer', array( self::class, 'filter_output' ) );
		}
	}

	/** El filtro del búfer: la página entera, con el host de la web. */
	public static function filter_output( $html ): string {
		$html = (string) $html;
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $html || '' === $home ) {
			return $html;
		}
		return self::rewrite( $html, $home, __( '(opens in a new tab)', 'estrategia-web' ) );
	}

	/**
	 * Recorre los <a href> y marca los externos. Sin funciones de WordPress
	 * salvo WP_HTML_Tag_Processor: el texto del aviso llega ya traducido.
	 */
	public static function rewrite( string $html, string $home_host, string $notice ): string {
		$texts = self::link_texts( $html );
		$p     = new \WP_HTML_Tag_Processor( $html );
		$i     = -1;
		while ( $p->next_tag( 'A' ) ) {
			++$i;
			$href = $p->get_attribute( 'href' );
			if ( ! is_string( $href ) || null !== $p->get_attribute( 'target' ) || ! self::is_external( $href, $home_host ) ) {
				continue;
			}
			$p->set_attribute( 'target', '_blank' );
			$rel = $p->get_attribute( 'rel' );
			$p->set_attribute( 'rel', self::add_rel( is_string( $rel ) ? $rel : '', 'noopener' ) );

			if ( null !== $p->get_attribute( 'aria-labelledby' ) ) {
				continue;
			}
			$label = $p->get_attribute( 'aria-label' );
			if ( is_string( $label ) && '' !== trim( $label ) ) {
				$p->set_attribute( 'aria-label', trim( $label ) . ' ' . $notice );
				continue;
			}
			$text = $texts[ $i ] ?? '';
			if ( '' !== $text ) {
				$p->set_attribute( 'aria-label', $text . ' ' . $notice );
			}
		}
		return $p->get_updated_html();
	}

	/**
	 * Primera pasada: el texto de cada <a> (y el alt de sus imágenes), por su
	 * orden en la página. En dos pasadas y no con set_bookmark()/seek() a
	 * propósito: el Tag Processor limita los seek() por documento (1.000) y
	 * una página con muchos enlaces se quedaría a medias. Los <a> no se
	 * anidan: el siguiente A, abra o cierre, termina el anterior.
	 *
	 * @return array<int, string> Índice del <a> (0, 1…) => texto.
	 */
	private static function link_texts( string $html ): array {
		$p     = new \WP_HTML_Tag_Processor( $html );
		$texts = array();
		$i     = -1;
		$open  = false;
		$buf   = '';
		while ( $p->next_token() ) {
			$name = $p->get_token_name();
			if ( 'A' === $name ) {
				if ( $open ) {
					$texts[ $i ] = trim( (string) preg_replace( '/\s+/u', ' ', $buf ) );
				}
				$open = ! $p->is_tag_closer();
				if ( $open ) {
					++$i;
					$buf = '';
				}
				continue;
			}
			if ( ! $open ) {
				continue;
			}
			if ( '#text' === $name ) {
				$buf .= $p->get_modifiable_text();
			} elseif ( 'IMG' === $name && ! $p->is_tag_closer() ) {
				$alt  = $p->get_attribute( 'alt' );
				$buf .= is_string( $alt ) ? ' ' . $alt . ' ' : '';
			}
		}
		return $texts;
	}

	/** Si el enlace sale de la web. PURA: tests/php/pure.php. */
	public static function is_external( string $href, string $home_host ): bool {
		$href = trim( $href );
		if ( str_starts_with( $href, '//' ) ) {
			$href = 'https:' . $href;
		}
		$parts = parse_url( $href ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- clase pura.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host = self::bare_host( $parts['host'] );
		$home = self::bare_host( $home_host );
		return '' !== $home && $host !== $home && ! str_ends_with( $host, '.' . $home );
	}

	/** «WWW.X.es.» → «x.es». */
	private static function bare_host( string $host ): string {
		$host = rtrim( strtolower( trim( $host ) ), '.' );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** Añade un valor a rel sin quitar los que ya hay ni repetirlo. PURA. */
	public static function add_rel( string $rel, string $token ): string {
		$tokens = preg_split( '/\s+/', trim( $rel ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $tokens as $t ) {
			if ( strtolower( $t ) === $token ) {
				return implode( ' ', $tokens );
			}
		}
		$tokens[] = $token;
		return implode( ' ', $tokens );
	}
}
