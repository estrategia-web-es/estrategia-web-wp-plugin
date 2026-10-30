<?php
/**
 * The pure part: parsed blocks in, a FAQPage node out. No WordPress here
 * (tests/php/faq.php runs it with the machine's PHP); reading a synced
 * pattern comes in as a callable.
 */

namespace EstrategiaWeb\FaqSchema;

defined( 'ABSPATH' ) || exit;

final class Faq {

	/** The accordion styles that are a FAQ: Lavandería's «faq» and the parent's «faq-list». */
	public const STYLE = '/(^|\s)is-style-faq(-list)?(\s|$)/';

	/** HTML Google accepts in Answer.text. */
	public const ANSWER_TAGS = '<h1><h2><h3><h4><h5><h6><br><ol><ul><li><a><p><b><strong><i><em>';

	/**
	 * The questions of every FAQ accordion in a list of parsed blocks.
	 *
	 * @param array         $blocks  parse_blocks() output.
	 * @param callable|null $pattern fn( int $ref ): ?array — the parsed blocks of a synced pattern.
	 * @param int[]         $seen    Synced patterns already read (a pattern that includes itself).
	 * @return array<array{0: string, 1: string}> [question, answer HTML]
	 */
	public static function questions( array $blocks, ?callable $pattern = null, array $seen = array() ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			if ( 'core/accordion' === $name && preg_match( self::STYLE, (string) ( $block['attrs']['className'] ?? '' ) ) ) {
				foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $item ) {
					$pair = self::item( $item );
					if ( $pair ) {
						$out[] = $pair;
					}
				}
				continue;
			}
			$ref = (int) ( $block['attrs']['ref'] ?? 0 );
			if ( 'core/block' === $name && $ref && $pattern && ! in_array( $ref, $seen, true ) ) {
				$inner = $pattern( $ref );
				if ( is_array( $inner ) ) {
					$out = array_merge( $out, self::questions( $inner, $pattern, array_merge( $seen, array( $ref ) ) ) );
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::questions( $block['innerBlocks'], $pattern, $seen ) );
			}
		}
		return $out;
	}

	/** One accordion item as [question, answer], or null if either is empty. */
	private static function item( array $item ): ?array {
		$question = '';
		$answer   = '';
		foreach ( (array) ( $item['innerBlocks'] ?? array() ) as $part ) {
			$name = $part['blockName'] ?? '';
			if ( 'core/accordion-heading' === $name ) {
				// Only the title span: the heading also carries the icon («+»).
				$heading  = (string) ( $part['innerHTML'] ?? '' );
				$question = self::text( preg_match( '#<span[^>]*\bwp-block-accordion-heading__toggle-title\b[^>]*>(.*?)</span>#s', $heading, $m ) ? $m[1] : $heading );
			} elseif ( 'core/accordion-panel' === $name ) {
				$answer = self::answer( self::html( $part ) );
			}
		}
		return ( '' !== $question && '' !== $answer ) ? array( $question, $answer ) : null;
	}

	/** A block's HTML with its inner blocks in place (what parse_blocks keeps apart). */
	private static function html( array $block ): string {
		$html  = '';
		$inner = (array) ( $block['innerBlocks'] ?? array() );
		$i     = 0;
		foreach ( (array) ( $block['innerContent'] ?? array( $block['innerHTML'] ?? '' ) ) as $chunk ) {
			$html .= is_string( $chunk ) ? $chunk : ( isset( $inner[ $i ] ) ? self::html( $inner[ $i++ ] ) : '' );
		}
		return $html;
	}

	private static function text( string $html ): string {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	private static function answer( string $html ): string {
		$kept = strip_tags( $html, self::ANSWER_TAGS );
		// Attributes out, except a link's href: Google reads the text, not the classes.
		$kept = preg_replace( '/<(?!a\s)([a-z0-9]+)\s[^>]*>/i', '<$1>', $kept );
		$kept = preg_replace( '/<a\s[^>]*?href=("[^"]*"|\'[^\']*\')[^>]*>/i', '<a href=$1>', $kept );
		return trim( preg_replace( '/\s+/u', ' ', $kept ) );
	}

	/**
	 * The FAQPage node, or null with no questions.
	 *
	 * @param array<array{0: string, 1: string}> $questions
	 */
	public static function node( string $url, array $questions ): ?array {
		if ( ! $questions ) {
			return null;
		}
		$entities = array();
		foreach ( $questions as $q ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $q[0],
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $q[1] ),
			);
		}
		return array( '@type' => 'FAQPage', '@id' => $url . '#faq', 'url' => $url, 'mainEntity' => $entities );
	}

	/** Whether a node's @type is or includes FAQPage. */
	private static function is_faq( $node ): bool {
		return is_array( $node ) && in_array( 'FAQPage', (array) ( $node['@type'] ?? array() ), true );
	}

	/**
	 * Our node into the host's graph: Rank Math's is keyed, Yoast's a list.
	 * Steps aside (returns it untouched) if the host already has a FAQPage.
	 */
	public static function merge( array $graph, array $node ): array {
		foreach ( $graph as $existing ) {
			if ( self::is_faq( $existing ) ) {
				return $graph;
			}
		}
		if ( array_is_list( $graph ) ) {
			$graph[] = $node;
		} else {
			$graph['estrategia-web-faq'] = $node;
		}
		return $graph;
	}
}
