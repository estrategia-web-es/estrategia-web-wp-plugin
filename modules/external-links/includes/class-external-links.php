<?php
/**
 * External links in a new tab (0.5.2): every FRONT-END <a href> whose host is
 * not the site's gets target="_blank", «noopener» in rel and the notice
 * «(opens in a new tab)» for screen readers.
 *
 * Why: David asked for it on 2026-10-06 with a real case, «Hablar con un
 * asesor» on Lavandería's /hosteleria/, a button to wa.me that took the
 * visitor away from the site. Same for Google Maps, Facebook or a content
 * link to another domain. It lives in the plugin and not in the theme because
 * it serves any site and cannot depend on each block marking it by hand.
 *
 * How:
 * - ONE filter over the whole page, not one per block: core's
 *   `wp_template_enhancement_output_buffer` (6.9+; the plugin requires 7.1).
 *   It also catches what is not a block (a classic theme's footer, a
 *   shortcode, the cookie notice) and walks the HTML ONCE. Core only opens
 *   that buffer for front-end templates and only for text/html: neither the
 *   dashboard, nor the editor, nor REST, nor feeds go through here.
 * - It hooks on `template_redirect`, when the theme's functions.php has
 *   already had the chance to switch it off; when off, the buffer is not even
 *   opened (the page keeps streaming).
 * - With WP_HTML_Tag_Processor, never with regular expressions (core itself
 *   asks for that in this filter).
 *
 * What counts as external (is_external(), PURE, tested at level 1):
 * - only http(s) with a host, or «//host/…»;
 * - a host different from home_url()'s, comparing WITHOUT «www.» on both sides
 *   and accepting subdomains of the site's: with the site on x.es, www.x.es
 *   and tienda.x.es are internal. No public suffix list, on purpose: on the
 *   staging server (lavanderamallorca.sg-host.com) otro.sg-host.com has to
 *   come out external, and «the registrable domain» would make it internal.
 *
 * What is NOT touched:
 * - relative links, anchors (#), mailto:, tel:, sms:, javascript: and any
 *   other scheme that is not http(s);
 * - links that ALREADY have a target (any, _self included): whoever set it
 *   decided. That way the cookie notice's «Powered by», which carries its own
 *   target, rel and notice, does not come out duplicated.
 *
 * Accessibility: aria-label, not a hidden <span>. The Tag Processor does not
 *   insert HTML, only attributes, so the accessible name is rebuilt: the
 *   existing aria-label, or the link text (plus the alt of its images),
 *   followed by «(opens in a new tab)», the SAME msgid as the «Powered by»
 *   (in es_ES «(se abre en una pestaña nueva)»). It starts with the visible
 *   text, so it meets «label in name» (WCAG 2.5.3). With aria-labelledby, or
 *   with no text at all (an unnamed icon, already broken before), no
 *   aria-label is set: it would hide the name or say only «opens in…».
 *
 * Switching it off (on by default), in the theme's functions.php:
 *     add_filter( 'estrategia_web_site_external_links', '__return_false' );
 * A filter and not an option, like WebP and «Powered by»: a decision of
 * whoever builds the site, not client data. A single link that must open in
 * the same tab: give it target="_self" in the block (it is respected).
 *
 * How to maintain:
 * - Who and when: whoever touches the plugin. A new scheme or case goes into
 *   is_external() and tests/php/pure.php (level 1) with its assert in
 *   tests/pure.test.mjs; whatever depends on rendering the page goes in
 *   tests/external-links.test.mjs (level 2).
 * - THE EDGE CASE: a link to the client's OLD site on another domain
 *   (lavanderiamallorca.com from lavanderamallorca.sg-host.com, or the other
 *   way round after the domain switch) IS EXTERNAL: what decides is
 *   home_url()'s host, not who owns the site. If a site really has two own
 *   domains, put target="_self" on those links; do not add a list of «in-house»
 *   domains here.
 * - The pitfall: the filter runs INSIDE the output buffer callback. Nothing
 *   can be printed there nor another ob_start() opened (PHP fatal error), and
 *   an exception is caught by core, which serves the page untouched: a
 *   failure here does not break the site, but leaves the links as they were
 *   WITHOUT warning. If one day they «stop opening in a new tab», check the
 *   PHP log before the code.
 */

namespace EstrategiaWeb\Site;

final class External_Links {

	public static function boot(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_enable' ) );
	}

	public static function maybe_enable(): void {
		/**
		 * Whether links to another domain open in a new tab. Yes by default.
		 *
		 * @param bool $enabled
		 */
		if ( apply_filters( 'estrategia_web_site_external_links', true ) ) {
			add_filter( 'wp_template_enhancement_output_buffer', array( self::class, 'filter_output' ) );
		}
	}

	/** The buffer filter: the whole page, with the site's host. */
	public static function filter_output( $html ): string {
		$html = (string) $html;
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $html || '' === $home ) {
			return $html;
		}
		return self::rewrite( $html, $home, __( '(opens in a new tab)', 'estrategia-web' ) );
	}

	/**
	 * Walks the <a href> tags and marks the external ones. No WordPress
	 * functions except WP_HTML_Tag_Processor: the notice text arrives already
	 * translated.
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
	 * First pass: the text of each <a> (and the alt of its images), in page
	 * order. Two passes and not set_bookmark()/seek() on purpose: the Tag
	 * Processor limits seek() calls per document (1,000) and a page with many
	 * links would be left half done. <a> tags do not nest: the next A, opener
	 * or closer, ends the previous one.
	 *
	 * @return array<int, string> Index of the <a> (0, 1…) => text.
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

	/** Whether the link leaves the site. PURE: tests/php/pure.php. */
	public static function is_external( string $href, string $home_host ): bool {
		$href = trim( $href );
		if ( str_starts_with( $href, '//' ) ) {
			$href = 'https:' . $href;
		}
		$parts = parse_url( $href ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure class.
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

	/** Adds a value to rel without removing existing ones or repeating it. PURE. */
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
