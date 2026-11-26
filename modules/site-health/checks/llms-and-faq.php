<?php
/**
 * RECOMMENDED: the llms.txt module is off or its text is empty; or, with
 * faq-schema on, FAQ blocks that module cannot read.
 *
 * Criterion, llms.txt: AI assistants read /llms.txt to learn what a site is
 * about; the llms module serves it from a text written per language
 * (option estrategia_web_llms, by language code). Off, or on with no text,
 * the site has none.
 *
 * Criterion, FAQ: faq-schema turns into FAQPage structured data only a
 * core/accordion with a FAQ style (class is-style-faq or is-style-faq-list,
 * the parent theme's styles; FAQ::STYLE in modules/faq-schema). A page of
 * questions built otherwise is invisible to it:
 * - a core/accordion WITHOUT one of those styles;
 * - two or more core/details blocks on a page that says it is a FAQ (its
 *   title or text has «FAQ», «preguntas frecuentes», «preguntes
 *   freqüents/frequents», «häufig gestellte», «questions fréquentes» or
 *   «frequently asked»).
 * ONE query over post_content (LIKE '<!-- wp:details' OR '<!-- wp:accordion'),
 * published pages and posts, at most 30 rows: the list says «first 30».
 *
 * Edge case, by name: an accordion that is NOT a FAQ (a menu, a timetable)
 * is listed too; the fix there is nothing, and the line says «if it is a
 * FAQ». A details block alone (one question) is never listed.
 *
 * Real case: one of the findings of the Llar d'Estels audit (25-26/11/2026):
 * questions on the site that faq-schema, switched on, could not read.
 *
 * The trap: «<!-- wp:accordion» also matches accordion-item, -heading and
 * -panel; only the block itself (followed by a space) is read, and its
 * className comes from the block's JSON attributes, not from the HTML.
 */

defined( 'ABSPATH' ) || exit;

use EstrategiaWeb\SiteHealth\Probe;

return array(
	'id'     => 'llms-and-faq',
	'async'  => false,
	'title'  => static fn() => __( 'AI assistants and search engines can read the site\'s summary and FAQs', 'estrategiaweb-toolkit' ),
	'gather' => static function (): array {
		global $wpdb;
		$llms = Probe::module_on( 'llms' );
		$text = $llms && class_exists( \EstrategiaWeb\Llms\Runtime::class ) ? \EstrategiaWeb\Llms\Runtime::texts() : array();
		$faq  = Probe::module_on( 'faq-schema' );
		$rows = array();
		if ( $faq ) {
			foreach ( (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one capped LIKE, Site Health screen only.
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID ASC LIMIT 30",
					'%' . $wpdb->esc_like( '<!-- wp:details' ) . '%',
					'%' . $wpdb->esc_like( '<!-- wp:accordion' ) . '%'
				)
			) as $r ) {
				$rows[] = array(
					'id'      => (int) $r->ID,
					'title'   => (string) $r->post_title,
					'content' => (string) $r->post_content,
				);
			}
		}
		return array(
			'llms'      => $llms,
			'llms_text' => (bool) array_filter( array_map( 'trim', array_map( 'strval', (array) $text ) ), 'strlen' ),
			'faq'       => $faq,
			'rows'      => $rows,
		);
	},
	'judge'  => static function ( array $d ): array {
		$items   = array();
		$actions = array();
		if ( empty( $d['llms'] ) ) {
			$items[]   = __( 'The llms.txt module is off: the site has no llms.txt.', 'estrategiaweb-toolkit' );
			$actions[] = array(
				'path' => 'admin.php?page=estrategia-web',
				'text' => __( 'Estrategia Web › Modules', 'estrategiaweb-toolkit' ),
			);
		} elseif ( empty( $d['llms_text'] ) ) {
			$items[]   = __( 'The llms.txt module is on but its text is empty.', 'estrategiaweb-toolkit' );
			$actions[] = array(
				'path' => 'admin.php?page=estrategia-web-settings&tab=llms',
				'text' => __( 'Estrategia Web › Settings › llms.txt', 'estrategiaweb-toolkit' ),
			);
		}
		$faq_words = '/\bfaqs?\b|preguntas frecuentes|preguntes freq(ü|u)ents|häufig gestellte|questions fréquentes|frequently asked/iu';
		$faq_pages = false;
		if ( ! empty( $d['faq'] ) ) {
			foreach ( (array) ( $d['rows'] ?? array() ) as $r ) {
				$content = (string) ( $r['content'] ?? '' );
				$title   = (string) ( $r['title'] ?? '' );
				$plain   = 0;
				preg_match_all( '/<!-- wp:accordion(?:\s+(\{.*?\}))?\s+\/?-->/s', $content, $m );
				foreach ( $m[1] as $json ) {
					$attrs = '' === $json ? array() : (array) json_decode( $json, true );
					if ( ! preg_match( '/(^|\s)is-style-faq(-list)?(\s|$)/', (string) ( $attrs['className'] ?? '' ) ) ) {
						++$plain;
					}
				}
				if ( $plain ) {
					/* translators: %s: a page's title. */
					$items[]   = sprintf( __( '«%s»: an accordion without a FAQ style (if it is a FAQ, choose the FAQ style in the block\'s Styles)', 'estrategiaweb-toolkit' ), $title );
					$faq_pages = true;
				}
				if ( substr_count( $content, '<!-- wp:details' ) >= 2 && preg_match( $faq_words, $title . ' ' . strip_tags( $content ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- judge() is pure PHP (level 1 runs it without WordPress); only matched, never printed.
					/* translators: %s: a page's title. */
					$items[]   = sprintf( __( '«%s»: questions in Details blocks (turn them into an accordion with the FAQ style)', 'estrategiaweb-toolkit' ), $title );
					$faq_pages = true;
				}
			}
			if ( $faq_pages && count( (array) ( $d['rows'] ?? array() ) ) >= 30 ) {
				$items[] = __( 'Only the first 30 pages with these blocks were read.', 'estrategiaweb-toolkit' );
			}
		}
		if ( $faq_pages ) {
			$actions[] = array(
				'path' => 'edit.php?post_type=page',
				'text' => __( 'Pages', 'estrategiaweb-toolkit' ),
			);
		}
		if ( ! $items ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'AI assistants and search engines can read the site\'s summary and FAQs', 'estrategiaweb-toolkit' ),
				'description' => __( 'The site has an llms.txt, and its FAQs are blocks the FAQ schema module reads.', 'estrategiaweb-toolkit' ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'The site\'s summary or FAQs are not readable by machines', 'estrategiaweb-toolkit' ),
			'description' => __( 'AI assistants read llms.txt to learn what the site is about, and Google shows FAQs from their structured data, which the FAQ schema module only builds from accordions with the FAQ style.', 'estrategiaweb-toolkit' ),
			'items'       => $items,
			'actions'     => $actions,
		);
	},
);
