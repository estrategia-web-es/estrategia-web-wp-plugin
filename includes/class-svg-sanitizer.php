<?php
/**
 * Saneado de SVG por LISTA BLANCA, sin dependencias externas.
 *
 * Un SVG es un documento XML que el navegador ejecuta: puede llevar <script>,
 * atributos on*, <foreignObject> con HTML dentro, enlaces javascript:,
 * entidades externas (XXE) y CSS que carga otras URL. Por eso NO se busca «lo
 * malo» (siempre hay una forma nueva): se reconstruye con lo que está en las
 * listas de abajo y se tira todo lo demás.
 *
 * Por qué propio y no enshrined/svg-sanitize (lo que usan Safe SVG y otros):
 * el plugin no tiene Composer ni build y se instala copiando la carpeta. Lo
 * que hace falta es un DOM y dos listas, y PHP trae DOMDocument. Si un día
 * aparece un caso que esto no cubre, la salida es ESA librería, no añadir
 * parches de expresiones regulares aquí.
 *
 * Clase PURA (sin WordPress): la prueba de nivel 1 la corre con el PHP de la
 * máquina contra SVG hostiles (tests/php/pure.php).
 *
 * Cómo se mantiene:
 * - Quién y cuándo: quien vea un SVG legítimo que sale roto (un logo sin un
 *   degradado, un texto que desaparece). Se mira QUÉ elemento o atributo se
 *   cayó y se añade a ELEMENTS o ATTRIBUTES, con un caso en pure.php.
 * - Con qué criterio entra algo: que sea PRESENTACIÓN (forma, color,
 *   geometría, texto). Nada que cargue, enlace o ejecute: ni <a>, ni
 *   <foreignObject>, ni <script>, ni atributos on*, ni <image> con URL externa.
 * - Caso frontera: <style> dentro del SVG. Entra, porque Illustrator lo usa
 *   para todos los colores (.cls-1{fill:…}), pero se le quita cualquier url()
 *   que no apunte a un #id del propio dibujo y se tira entero si lleva
 *   @import, expression() o javascript:.
 * - La trampa: los nombres de SVG distinguen mayúsculas (linearGradient,
 *   viewBox, stdDeviation). Comparar en minúsculas hace que un logo con
 *   degradado salga negro, sin error.
 */

namespace Armazon\Site;

final class Svg_Sanitizer {

	public const ELEMENTS = array(
		'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'style',
		'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'textPath',
		'linearGradient', 'radialGradient', 'stop', 'pattern',
		'clipPath', 'mask', 'marker', 'image',
		'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feDropShadow',
		'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feMerge',
		'feMergeNode', 'feMorphology', 'feOffset',
	);

	public const ATTRIBUTES = array(
		'id', 'class', 'style', 'transform', 'role', 'aria-label', 'aria-hidden', 'focusable', 'lang',
		'version', 'width', 'height', 'viewBox', 'preserveAspectRatio', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
		'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr', 'd', 'points', 'pathLength',
		'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
		'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity',
		'color', 'display', 'visibility', 'overflow', 'vector-effect', 'paint-order', 'shape-rendering',
		'isolation', 'mix-blend-mode', 'enable-background', 'color-interpolation-filters',
		'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
		'clip-path', 'clip-rule', 'clipPathUnits', 'mask', 'maskUnits', 'maskContentUnits',
		'patternUnits', 'patternContentUnits', 'patternTransform',
		'marker-start', 'marker-mid', 'marker-end', 'markerWidth', 'markerHeight', 'markerUnits', 'refX', 'refY', 'orient',
		'filter', 'filterUnits', 'primitiveUnits', 'in', 'in2', 'result', 'stdDeviation', 'dx', 'dy',
		'mode', 'operator', 'k1', 'k2', 'k3', 'k4', 'values', 'type', 'tableValues', 'slope', 'intercept',
		'amplitude', 'exponent', 'flood-color', 'flood-opacity', 'radius',
		'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline',
		'letter-spacing', 'word-spacing', 'text-decoration', 'textLength', 'lengthAdjust', 'startOffset',
		'href', 'space',
	);

	/**
	 * El SVG saneado, o null si no se puede aceptar (no es XML, la raíz no es
	 * <svg> o declara entidades).
	 */
	public static function sanitize( string $svg ): ?string {
		// Entidades declaradas = XXE o «billion laughs». Ningún logo las necesita.
		if ( preg_match( '/<!ENTITY/i', $svg ) ) {
			return null;
		}
		$svg = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $svg );

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// Sin LIBXML_NOENT (no sustituir entidades) y con LIBXML_NONET (no ir a la red).
		$ok = $doc->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $doc->documentElement;
		if ( ! $ok || ! $root || 'svg' !== $root->localName ) {
			return null;
		}

		// Instrucciones de proceso (<?xml-stylesheet…) y comentarios fuera.
		$xpath = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xpath->query( '//processing-instruction() | //comment()' ) ) as $node ) {
			$node->parentNode->removeChild( $node );
		}

		self::clean( $root );
		return $doc->saveXML( $root );
	}

	private static function clean( \DOMElement $el ): void {
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			if ( ! self::attribute_ok( $el, $attr ) ) {
				$el->removeAttributeNode( $attr );
			}
		}

		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof \DOMElement ) {
				if ( ! in_array( $child->localName, self::ELEMENTS, true ) || ! self::svg_namespace( $child ) ) {
					$el->removeChild( $child );
					continue;
				}
				if ( 'style' === $child->localName ) {
					$css = self::css( $child->textContent );
					if ( null === $css ) {
						$el->removeChild( $child );
						continue;
					}
					$child->textContent = $css;
					continue;
				}
				self::clean( $child );
			}
		}
	}

	/** Sin espacio de nombres o el de SVG. Fuera sodipodi:, inkscape:, html:… */
	private static function svg_namespace( \DOMElement $el ): bool {
		return null === $el->namespaceURI || 'http://www.w3.org/2000/svg' === $el->namespaceURI;
	}

	private static function attribute_ok( \DOMElement $el, \DOMAttr $attr ): bool {
		$name = $attr->localName;
		$ns   = $attr->namespaceURI;
		// Solo atributos sin prefijo, xlink:href y xml:space.
		if ( null !== $ns && ! ( 'http://www.w3.org/1999/xlink' === $ns && 'href' === $name ) && ! ( 'http://www.w3.org/XML/1998/namespace' === $ns && 'space' === $name ) ) {
			return false;
		}
		if ( null === $ns && 'space' === $name ) {
			return false;
		}
		if ( ! in_array( $name, self::ATTRIBUTES, true ) ) {
			return false;
		}

		$value   = $attr->value;
		// Lo que el navegador ignora al leer un esquema de URL: espacios y control.
		$compact = strtolower( preg_replace( '/[\x00-\x20]+/', '', $value ) );
		if ( str_contains( $compact, 'javascript:' ) || str_contains( $compact, 'vbscript:' ) ) {
			return false;
		}

		if ( 'href' === $name ) {
			if ( str_starts_with( $value, '#' ) ) {
				return true;
			}
			// Una imagen incrustada en mapa de bits sí; una URL externa no (rastreo, contenido mixto).
			return 'image' === $el->localName && 1 === preg_match( '#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+$#i', $value );
		}

		if ( 'style' === $name ) {
			$css = self::css( $value );
			if ( null === $css ) {
				return false;
			}
			$attr->value = $css;
			return true;
		}

		// fill="url(#a)" sí; fill="url(https://…)" no.
		if ( str_contains( $compact, 'url(' ) && ! preg_match( '/^url\(\s*[\'"]?#[^)]*\)$/i', trim( $value ) ) ) {
			return false;
		}
		return true;
	}

	/** CSS limpio, o null si es de los que no se arreglan quitando un trozo. */
	private static function css( string $css ): ?string {
		$compact = strtolower( preg_replace( '/[\x00-\x20\\\\]+/', '', $css ) );
		foreach ( array( '@import', 'expression(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', '</' ) as $bad ) {
			if ( str_contains( $compact, $bad ) ) {
				return null;
			}
		}
		// url() que no apunte a un #id del propio dibujo → none.
		return preg_replace( '/url\(\s*(?![\'"]?#)[^)]*\)/i', 'none', $css );
	}

	/**
	 * Ancho y alto del dibujo para la mediateca: width/height si son números
	 * (px o sin unidad); si no, los del viewBox. [0, 0] si no hay forma de saberlo.
	 *
	 * @return array{0:int,1:int}
	 */
	public static function dimensions( string $svg ): array {
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$ok       = ! preg_match( '/<!ENTITY/i', $svg ) && $doc->loadXML( preg_replace( '/<!DOCTYPE[^>]*>/i', '', $svg ), LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $ok || ! $doc->documentElement ) {
			return array( 0, 0 );
		}
		$root = $doc->documentElement;
		$num  = static function ( string $v ): float {
			return preg_match( '/^\s*([0-9]*\.?[0-9]+)\s*(px)?\s*$/i', $v, $m ) ? (float) $m[1] : 0.0;
		};
		$w = $num( $root->getAttribute( 'width' ) );
		$h = $num( $root->getAttribute( 'height' ) );
		if ( ( ! $w || ! $h ) && $root->hasAttribute( 'viewBox' ) ) {
			$box = preg_split( '/[\s,]+/', trim( $root->getAttribute( 'viewBox' ) ) );
			if ( 4 === count( $box ) ) {
				$vw = (float) $box[2];
				$vh = (float) $box[3];
				if ( $vw > 0 && $vh > 0 ) {
					// Si solo hay uno de los dos, el otro sale de la proporción.
					if ( $w ) {
						$h = $w * $vh / $vw;
					} elseif ( $h ) {
						$w = $h * $vw / $vh;
					} else {
						$w = $vw;
						$h = $vh;
					}
				}
			}
		}
		return array( (int) round( $w ), (int) round( $h ) );
	}
}
