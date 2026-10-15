<?php
/**
 * SVG sanitising by ALLOWLIST, with no external dependencies.
 *
 * An SVG is an XML document the browser runs: it can carry <script>, on*
 * attributes, <foreignObject> with HTML inside, javascript: links, external
 * entities (XXE) and CSS that loads other URLs. That is why it does NOT look
 * for «the bad stuff» (there is always a new way): it rebuilds the document
 * with what is in the lists below and throws everything else away.
 *
 * Why our own and not enshrined/svg-sanitize (what Safe SVG and others use):
 * the plugin has no Composer or build step and is installed by copying the
 * folder. What is needed is a DOM and two lists, and PHP ships DOMDocument. If
 * one day a case shows up that this does not cover, the way out is THAT
 * library, not adding regex patches here.
 *
 * PURE class (no WordPress): the level 1 test runs it with the machine's PHP
 * against hostile SVGs (tests/php/pure.php).
 *
 * How to maintain:
 * - Who and when: whoever sees a legitimate SVG come out broken (a logo
 *   missing a gradient, a text that disappears). Check WHICH element or
 *   attribute was dropped and add it to ELEMENTS or ATTRIBUTES, with a case in
 *   pure.php.
 * - The criterion for letting something in: that it is PRESENTATION (shape,
 *   colour, geometry, text). Nothing that loads, links or runs: no <a>, no
 *   <foreignObject>, no <script>, no on* attributes, no <image> with an
 *   external URL.
 * - Edge case: <style> inside the SVG. It is allowed, because Illustrator uses
 *   it for every colour (.cls-1{fill:…}), but any url() that does not point to
 *   an #id of the drawing itself is removed, and it is dropped entirely if it
 *   carries @import, expression() or javascript:.
 * - The trap: SVG names are case-sensitive (linearGradient, viewBox,
 *   stdDeviation). Comparing in lowercase makes a logo with a gradient come
 *   out black, with no error.
 */

namespace EstrategiaWeb\Site;

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
	 * The sanitised SVG, or null if it cannot be accepted (it is not XML, the
	 * root is not <svg> or it declares entities).
	 */
	public static function sanitize( string $svg ): ?string {
		// Declared entities = XXE or «billion laughs». No logo needs them.
		if ( preg_match( '/<!ENTITY/i', $svg ) ) {
			return null;
		}
		$svg = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $svg );

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// Without LIBXML_NOENT (do not substitute entities) and with LIBXML_NONET (no network access).
		$ok = $doc->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $doc->documentElement;
		if ( ! $ok || ! $root || 'svg' !== $root->localName ) {
			return null;
		}

		// Processing instructions (<?xml-stylesheet…) and comments out.
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

	/** No namespace, or the SVG one. Out with sodipodi:, inkscape:, html:… */
	private static function svg_namespace( \DOMElement $el ): bool {
		return null === $el->namespaceURI || 'http://www.w3.org/2000/svg' === $el->namespaceURI;
	}

	private static function attribute_ok( \DOMElement $el, \DOMAttr $attr ): bool {
		$name = $attr->localName;
		$ns   = $attr->namespaceURI;
		// Only unprefixed attributes, xlink:href and xml:space.
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
		// What the browser ignores when reading a URL scheme: whitespace and control characters.
		$compact = strtolower( preg_replace( '/[\x00-\x20]+/', '', $value ) );
		if ( str_contains( $compact, 'javascript:' ) || str_contains( $compact, 'vbscript:' ) ) {
			return false;
		}

		if ( 'href' === $name ) {
			if ( str_starts_with( $value, '#' ) ) {
				return true;
			}
			// An embedded bitmap image yes; an external URL no (tracking, mixed content).
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

		// fill="url(#a)" yes; fill="url(https://…)" no.
		if ( str_contains( $compact, 'url(' ) && ! preg_match( '/^url\(\s*[\'"]?#[^)]*\)$/i', trim( $value ) ) ) {
			return false;
		}
		return true;
	}

	/** Clean CSS, or null if it is the kind that cannot be fixed by removing a piece. */
	private static function css( string $css ): ?string {
		$compact = strtolower( preg_replace( '/[\x00-\x20\\\\]+/', '', $css ) );
		foreach ( array( '@import', 'expression(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', '</' ) as $bad ) {
			if ( str_contains( $compact, $bad ) ) {
				return null;
			}
		}
		// url() that does not point to an #id of the drawing itself → none.
		return preg_replace( '/url\(\s*(?![\'"]?#)[^)]*\)/i', 'none', $css );
	}

	/**
	 * Width and height of the drawing for the media library: width/height if
	 * they are numbers (px or unitless); otherwise, those of the viewBox. [0, 0]
	 * if there is no way to tell.
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
					// If only one of the two is present, the other comes from the aspect ratio.
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
