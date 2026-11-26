<?php
/**
 * Suggestions (2.15.0): a text field or a repeater's text subfield with
 * `"suggest": "used"` offers, as you type, what that field already says in
 * the other entries of the type, most used first. Anything else can still be
 * typed: it is a <datalist> on the input (the browser's own list, no
 * library), in the meta box and in the block editor's panel.
 *
 * Why (David, 25-11-2026, Llar d'Estels' funeral notices): the place of each
 * act and the relationship of each group of relatives were typed by hand. In
 * the 68 notices there are 31 places for 87 acts (13 used only once) and 50
 * relationships for 159 uses: a closed list would not do, and typing «Parròquia
 * de Sant Julià de Campos» each time gives three spellings of one church.
 *
 * What it reads: the entries of the type in any status but trash and
 * auto-draft, at most 1,000 (the newest), only on the edit screen. A plain
 * field: GROUP BY over postmeta. A subfield: the repeater's JSON of each entry,
 * counted in PHP. At most 200 values, the most used first, then A→Z.
 *
 * How to maintain:
 * - Who and when: whoever adds another «source» (a fixed list in the
 *   definition, say) adds it to Schema (the «suggest» key: «used» is the only
 *   value today) and here.
 * - Edge case, by name: a value with different capitals or spaces («Tanatori
 *   de Campos» / «tanatori de campos ») is ONE suggestion: grouped by its
 *   trimmed, lowercased text, shown as the form used most.
 * - The trap: the datalist's id must be unique on the page, and a repeater
 *   row added by JavaScript is a CLONE: its inputs keep the «list» attribute,
 *   so the id goes per field and subfield, never per row.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Suggestions {

	public const MAX = 200;

	/** The id of a field's (or subfield's) datalist. */
	public static function list_id( string $field, string $sub = '' ): string {
		return 'estrategia-web-ct-suggest-' . $field . ( '' !== $sub ? '-' . $sub : '' );
	}

	/**
	 * The values of a field, or of a subfield of a repeater, most used first.
	 *
	 * @return string[]
	 */
	public static function values( string $type, string $field, string $sub = '' ): array {
		global $wpdb;
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- an admin screen, once.
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft') AND pm.meta_value <> ''
				ORDER BY p.post_date DESC LIMIT 1000",
				$field,
				$type
			)
		);
		$texts = array();
		foreach ( $rows as $raw ) {
			if ( '' === $sub ) {
				$texts[] = (string) $raw;
				continue;
			}
			$list = json_decode( (string) $raw, true );
			foreach ( is_array( $list ) ? $list : array() as $row ) {
				if ( is_array( $row ) && isset( $row[ $sub ] ) && is_scalar( $row[ $sub ] ) ) {
					$texts[] = (string) $row[ $sub ];
				}
			}
		}
		return self::rank( $texts );
	}

	/**
	 * PURE (tests/php): the distinct texts, most used first, then A→Z. One per
	 * trimmed lowercase text, shown as its most used spelling.
	 *
	 * @param string[] $texts
	 * @return string[]
	 */
	public static function rank( array $texts ): array {
		$groups = array();
		foreach ( $texts as $t ) {
			$t = trim( preg_replace( '/\s+/u', ' ', (string) $t ) );
			if ( '' === $t ) {
				continue;
			}
			$k = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t ) : strtolower( $t );
			$groups[ $k ]['n']           = ( $groups[ $k ]['n'] ?? 0 ) + 1;
			$groups[ $k ]['forms'][ $t ] = ( $groups[ $k ]['forms'][ $t ] ?? 0 ) + 1;
		}
		uksort(
			$groups,
			static fn( $a, $b ) => ( $groups[ $b ]['n'] <=> $groups[ $a ]['n'] ) ?: strcmp( (string) $a, (string) $b )
		);
		$out = array();
		foreach ( $groups as $g ) {
			arsort( $g['forms'] );
			$out[] = (string) array_key_first( $g['forms'] );
		}
		return array_slice( $out, 0, self::MAX );
	}

	/** A <datalist> with those values. */
	public static function datalist( string $id, array $values ): string {
		$html = '<datalist id="' . esc_attr( $id ) . '">';
		foreach ( $values as $v ) {
			$html .= '<option value="' . esc_attr( $v ) . '"></option>';
		}
		return $html . '</datalist>';
	}
}
