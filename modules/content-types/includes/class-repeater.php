<?php
namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

/**
 * THE «repeater» FIELD TYPE (24-11-2026, «Llista repetible»): a LIST OF ROWS,
 * each row with the subfields the field declares in «fields». Made for the
 * funeral notices of Llar d'Estels (the acts of a notice: vigil, funeral,
 * rosary… each with its day, time and place); any definition can use it.
 * Example: examples/llest_esquela.json. And the «time» field type (HH:MM),
 * which is also a subfield type: time() below.
 *
 * THE STORED VALUE, the decision: ONE string meta holding a JSON LIST of
 * objects, one per row, subfield name → value, like «hours»
 * (class-hours.php says why a string and not an object meta):
 *
 *     [{"tipus":"Funeral","dia":"2026-11-25","hora":"17:00","lloc":"Parròquia de Sant Miquel"}]
 *
 *   - Only the subfields that have a value are stored; a row with none is
 *     dropped. "" = no rows (never [] or [{}]).
 *   - Values are strings, except a «number» subfield: a JSON number.
 *   - Keys the field does not declare are dropped, «max» rows at most (the
 *     rest are cut, the last ones).
 *   - REST shows it as a LIST, not as the JSON text (rest_schema(), with
 *     Registrar's prepare_callback): what an API reader and the block editor
 *     panel want. What comes in through REST is that list.
 *
 * THE RULES per subfield type (sub_value()): text, textarea (line breaks
 * kept as \n), number, date (YYYY-MM-DD, a real day), time (HH:MM, 24 h),
 * select (one of its option values, or nothing), url (http/https), email, tel
 * (digits, spaces and + ( ) - .). Anything that does not fit becomes empty.
 * Lenient like hours: a bad value empties that subfield, never the row or
 * the list.
 *
 * «required» on a subfield does NOT drop the row: the row is kept and the
 * field counts as incomplete (incomplete(), what Registrar::missing() asks),
 * so publishing is refused with the field's name, as for a required field.
 * «min»: with rows, fewer than min is incomplete too; with none, only a
 * required field is.
 *
 * WPML (merge_translation()): text and textarea subfields are translated,
 * the rest copied. WPML's setting is per meta KEY, so the field is
 * «translate» (Schema::normalize_field()) and the copy of the other
 * subfields is ours: when the ORIGINAL changes, Wpml::sync() writes into
 * each translation the original's rows with the translation's own texts,
 * row by row by position. In the translation's editor the rows and the
 * copied subfields are locked (Fields_Panel::config(), «lockRows»).
 *
 * Pure PHP except the WordPress sanitizers named in sub_value() (the level 1
 * test, tests/php/repeater.php, shims them): tests/repeater.test.mjs.
 *
 * How to maintain:
 * - Who and when: whoever adds a subfield type or a rule. A subfield type is
 *   SUBFIELD_TYPES here, sub_value(), rest_schema(), the JSON Schema's
 *   «subfield» enum (tests/schema.test.mjs fails if the two lists drift),
 *   the editor controls (assets/fields-panel.js SubControl(), the box's
 *   Fields_Panel::render_row()), Field_Format::sub_text()
 *   with its copy in assets/bindings.js, and the constructor's names
 *   (Page::strings() fieldTypes). In the same commit.
 * - Boundary case, with a name: a row whose only value is a NUMBER 0. It is
 *   a value (0 € is an answer), so the row is kept; an empty "" is not.
 * - The traps:
 *   · update_post_meta() UNSLASHES what it gets: a JSON with a quote in a
 *     text («\"») would lose its backslash and stop being JSON. The meta box
 *     passes it through wp_slash() (Fields_Panel::save_meta_box()); REST
 *     already slashes the value it writes.
 *   · Rows are matched to their translation BY POSITION: reordering the
 *     original moves the copied subfields but the translated texts stay
 *     where they were. Translate after the original's order is settled.
 */
final class Repeater {

	/** The types a subfield may have (a field of the definition may have more). */
	public const SUBFIELD_TYPES = array( 'text', 'textarea', 'number', 'date', 'time', 'select', 'url', 'email', 'tel' );
	/** The keys a subfield may have. */
	public const SUBFIELD_KEYS = array( 'name', 'type', 'label', 'required', 'options' );
	/** Subfield types whose value is translated (the rest are copied from the original). */
	public const TRANSLATED = array( 'text', 'textarea' );
	/** A subfield name is a JSON key inside the meta, not a meta key: no prefix, short. */
	public const MAX_SUBFIELD = 32;

	private const TIME = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

	/** A time as HH:MM (24 h), or "" — strict: «9:30» or «09:30:00» are not stored. The «time» field type too. */
	public static function time( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return preg_match( self::TIME, $value ) ? $value : '';
	}

	/**
	 * The stored value for whatever comes in: the JSON text, the list REST
	 * sends, or the meta box's posted rows (index → subfield → value).
	 *
	 * @param array $field A NORMALIZED field (Schema::normalize_field()).
	 */
	public static function sanitize( $value, array $field ): string {
		$rows = self::rows( $value, $field );
		return $rows ? (string) json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
	}

	/**
	 * The rows, sanitized, as a list (what REST shows and the formatter reads).
	 *
	 * @return array<int, array<string, string|int|float>>
	 */
	public static function rows( $value, array $field ): array {
		$out = array();
		foreach ( self::decode( $value ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			foreach ( $field['fields'] ?? array() as $sub ) {
				$v = self::sub_value( $sub, $row[ $sub['name'] ] ?? '' );
				if ( '' !== $v ) {
					$clean[ $sub['name'] ] = $v;
				}
			}
			if ( $clean ) {
				$out[] = $clean;
			}
		}
		$max = $field['max'] ?? null;
		return null !== $max ? array_slice( $out, 0, max( 0, (int) $max ) ) : $out;
	}

	/** The list of rows in $value, unsanitized: JSON text, a list, or the meta box's index → row. */
	private static function decode( $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? array() : json_decode( $value, true );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		// The meta box posts estrategia_web_ct[key][7][sub]: integer keys in the order of the form.
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_int( $key ) ) {
				return array(); // An object (one row on its own, or a week of hours) is not a list of rows.
			}
		}
		return array_values( $value );
	}

	/**
	 * One subfield's value, sanitized by its type: "" when empty or invalid.
	 *
	 * @param array $sub A normalized subfield: name, type, label, required, options [{value,label}].
	 * @return string|int|float
	 */
	public static function sub_value( array $sub, $value ) {
		if ( is_array( $value ) || is_object( $value ) || null === $value || is_bool( $value ) ) {
			return '';
		}
		$str = trim( (string) $value );
		if ( '' === $str ) {
			return '';
		}
		switch ( $sub['type'] ) {
			case 'textarea':
				return trim( str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( $str ) ) );
			case 'number':
				return is_numeric( $str ) ? 0 + $str : '';
			case 'date':
				return Schema::is_date( $str ) ? $str : '';
			case 'time':
				return self::time( $str );
			case 'select':
				return in_array( $str, array_column( $sub['options'] ?? array(), 'value' ), true ) ? $str : '';
			case 'url':
				return (string) esc_url_raw( $str, array( 'http', 'https' ) );
			case 'email':
				return is_email( $str ) ? (string) sanitize_email( $str ) : '';
			case 'tel':
				return trim( (string) preg_replace( '/[^0-9+()\s.\-]/', '', sanitize_text_field( $str ) ) );
			default:
				return (string) sanitize_text_field( $str );
		}
	}

	/**
	 * Whether the stored value would leave the field incomplete for
	 * publishing (see the header): no rows and required; or rows, but fewer
	 * than «min»; or a row without one of its required subfields.
	 */
	public static function incomplete( $value, array $field ): bool {
		$rows = self::rows( $value, $field );
		if ( ! $rows ) {
			return ! empty( $field['required'] );
		}
		if ( null !== ( $field['min'] ?? null ) && count( $rows ) < (int) $field['min'] ) {
			return true;
		}
		foreach ( $rows as $row ) {
			foreach ( $field['fields'] ?? array() as $sub ) {
				if ( ! empty( $sub['required'] ) && ! array_key_exists( $sub['name'], $row ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Whether the field has subfields of both kinds: translated (text, textarea) and copied. Only then is there a merge to do. */
	public static function is_mixed( array $field ): bool {
		$types = array_column( $field['fields'] ?? array(), 'type' );
		return (bool) array_intersect( $types, self::TRANSLATED ) && (bool) array_diff( $types, self::TRANSLATED );
	}

	/**
	 * What a TRANSLATION stores when its original changes: the original's rows
	 * (how many, their order, every copied subfield) with the translation's
	 * own texts, row by row by position. A row the translation does not have
	 * yet takes the original's texts, to be translated.
	 */
	public static function merge_translation( $original, $translation, array $field ): string {
		$theirs = self::rows( $translation, $field );
		$rows   = array();
		foreach ( self::rows( $original, $field ) as $i => $row ) {
			$merged = array();
			foreach ( $field['fields'] ?? array() as $sub ) {
				$name = $sub['name'];
				$from = in_array( $sub['type'], self::TRANSLATED, true ) && isset( $theirs[ $i ][ $name ] ) ? $theirs[ $i ] : $row;
				if ( isset( $from[ $name ] ) ) {
					$merged[ $name ] = $from[ $name ];
				}
			}
			$rows[] = $merged;
		}
		return self::sanitize( $rows, $field );
	}

	/**
	 * The REST schema of the field: a list of objects. Lenient on purpose —
	 * a number may come as a numeric string, an unknown key passes — because
	 * the sanitizer is the one place that decides (a 400 here would refuse
	 * the whole post's save for one cell).
	 */
	public static function rest_schema( array $field ): array {
		$properties = array();
		foreach ( $field['fields'] ?? array() as $sub ) {
			$properties[ $sub['name'] ] = array( 'type' => 'number' === $sub['type'] ? array( 'number', 'string' ) : 'string' );
		}
		return array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => true,
			),
		);
	}

	/** @return string[] The subfield names a row format («{tipus}: {dia}») refers to, in order. */
	public static function placeholders( string $format ): array {
		preg_match_all( '/\{([a-z][a-z0-9_]*)\}/', $format, $m );
		return $m[1];
	}

	/**
	 * One row through its format: each {name} replaced by $values[name]. An
	 * EMPTY value takes with it the text that joins it to the previous one
	 * («{hora}–{fins}» with no «fins» gives «17:00», not «17:00–»), or, for
	 * the first one, the text that joins it to the next. Plain text: the
	 * caller escapes it.
	 *
	 * @param array<string,string> $values Subfield name → the text to show.
	 */
	public static function apply_format( string $format, array $values ): string {
		$parts = preg_split( '/(\{[a-z][a-z0-9_]*\})/', $format, -1, PREG_SPLIT_DELIM_CAPTURE );
		// $parts: literal, {a}, literal, {b}, literal… (odd indexes are placeholders).
		$count = count( $parts );
		$keep  = array_fill( 0, $count, true );
		$first = true;
		for ( $i = 1; $i < $count; $i += 2 ) {
			$value = (string) ( $values[ substr( $parts[ $i ], 1, -1 ) ] ?? '' );
			if ( '' === $value ) {
				$keep[ $i ] = false;
				if ( $first ) {
					$keep[ $i + 1 ] = $i + 2 >= $count; // The text after it, unless it is the end of the format.
				} else {
					$keep[ $i - 1 ] = false;
				}
			} else {
				$parts[ $i ] = $value;
				$first       = false;
			}
		}
		if ( $first ) {
			return ''; // Every value empty.
		}
		$out = '';
		foreach ( $parts as $i => $part ) {
			$out .= $keep[ $i ] ? $part : '';
		}
		return trim( $out );
	}
}
