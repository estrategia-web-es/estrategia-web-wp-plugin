<?php
/**
 * Where the FAQPage goes: the SEO plugin's graph, or our own script. The
 * decision is local-business's (its class-output.php): not «is Rank Math
 * active» but «did its filter run on this page».
 * The trap (the same as there): Rank Math calls rank_math/json_ld also over
 * REST for its editor preview; there is no queried object and nothing is
 * added. Never build the page from the global $post.
 */

namespace EstrategiaWeb\FaqSchema;

defined( 'ABSPATH' ) || exit;

final class Output {

	private static bool $delivered = false;

	/** @var array|false|null null = not computed yet, false = nothing on this page. */
	private static $node = null;

	public static function boot(): void {
		add_filter( 'rank_math/json_ld', array( self::class, 'into_host' ), 999 );
		add_filter( 'wpseo_schema_graph', array( self::class, 'into_host' ), 999 );
		add_action( 'wp_head', array( self::class, 'standalone' ), 99 );
	}

	/** This page's FAQPage node, or null. */
	public static function node(): ?array {
		if ( null === self::$node ) {
			self::$node = false;
			$post       = is_singular() ? get_queried_object() : null;
			if ( $post instanceof \WP_Post ) {
				$pattern    = static function ( int $ref ): ?array {
					$p = get_post( $ref );
					return ( $p && 'wp_block' === $p->post_type ) ? parse_blocks( (string) $p->post_content ) : null;
				};
				$questions  = Faq::questions( parse_blocks( (string) $post->post_content ), $pattern );
				self::$node = Faq::node( (string) get_permalink( $post ), $questions ) ?? false;
			}
		}
		return self::$node ?: null;
	}

	/** @param mixed $graph */
	public static function into_host( $graph ) {
		if ( ! is_array( $graph ) || is_admin() || wp_is_json_request() ) {
			return $graph;
		}
		self::$delivered = true;
		$node            = self::node();
		return $node ? Faq::merge( $graph, $node ) : $graph;
	}

	public static function standalone(): void {
		if ( self::$delivered ) {
			return;
		}
		$node = self::node();
		if ( ! $node ) {
			return;
		}
		$graph = array( '@context' => 'https://schema.org', '@graph' => array( $node ) );
		// JSON_HEX_TAG: a «</script>» in an answer cannot close the tag.
		echo '<script type="application/ld+json" class="estrategia-web-faq-schema">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON, tags hex-escaped.
	}
}
