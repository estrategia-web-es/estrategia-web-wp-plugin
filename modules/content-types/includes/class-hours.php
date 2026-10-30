<?php
namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

/**
 * THE «hours» FIELD TYPE (22-10-2026): opening hours as DATA — the days of
 * the week, each with zero or more opening ranges — instead of a sentence.
 * Made for the location template (estrategia_web_location_hours), where
 * modules/local-business turns it into schema.org openingHoursSpecification;
 * any definition can use it.
 *
 * THE STORED VALUE, the decision: ONE string meta holding a small JSON
 * object, day → list of «HH:MM-HH:MM» ranges, days in week order, closed
 * days left out:
 *
 *     {"mo":["08:00-14:00","16:00-20:00"],"tu":["08:00-14:00"],"sa":["09:00-13:00"]}
 *
 *   - One meta, not seven or fourteen: the constructor's rule is one field =
 *     one meta (one register_post_meta, one WPML «copy», one binding key).
 *   - A string and not an object meta: register_post_meta with type
 *     «object» needs a REST schema per key and WPML copies serialized arrays
 *     badly; a string travels everywhere (REST, WPML, WP-CLI, the content
 *     loads) as it is.
 *   - Ranges as «08:00-14:00» text, not [open, close] pairs: it is what a
 *     person reads and writes in the JSON box and in a content load.
 *   - "" = nothing filled in (the field is empty). {} is never stored.
 *
 * THE RULES (sanitize(), what is stored whatever comes in):
 *   - day keys: mo tu we th fr sa su; anything else is dropped;
 *   - a range is «HH:MM-HH:MM», 00:00–23:59 (open all day: 00:00-23:59, as
 *     Google asks); one with opening = closing is dropped;
 *   - closing BEFORE opening is kept: it closes after midnight (22:00-03:00),
 *     which schema.org reads exactly so;
 *   - per day: sorted by opening; overlapping or touching ranges are MERGED
 *     (08:00-14:00 + 13:00-16:00 → 08:00-16:00), so the same hours are
 *     always stored the same way and compare equal.
 *   Lenient on purpose: a bad range is dropped, never the whole value — a
 *   typo in Tuesday must not wipe the week. The editor panel marks a range it
 *   will not keep before saving (assets/fields-panel.js, errors()).
 *
 * Pure PHP (no WordPress): the level 1 test (tests/php/hours.php, run by
 * tests/hours.test.mjs) calls it with the machine's PHP.
 *
 * How to maintain:
 * - Who and when: whoever changes what hours can express. A change in the
 *   rules goes in sanitize(), in errors() (what the panel says), in
 *   assets/fields-panel.js (its copy of errors()) and in tests/hours.test.mjs,
 *   in the same commit.
 * - Boundary case, with a name: a range that crosses midnight and overlaps
 *   the next day's first one (fr 22:00-03:00, sa 02:00-…). It is NOT merged
 *   across days: each day is checked on its own. schema.org accepts it.
 * - The trap: json_decode of '{"mo":[]}' gives an ARRAY in PHP, but of '[]'
 *   too — an empty JSON object and an empty JSON list are the same thing
 *   here. That is why an empty week is stored as "" and never as {} or [].
 */

final class Hours {

	/** Day keys, Monday first (ISO 8601). */
	public const DAYS = array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' );

	/** Day key → schema.org DayOfWeek. */
	public const SCHEMA_DAYS = array(
		'mo' => 'Monday',
		'tu' => 'Tuesday',
		'we' => 'Wednesday',
		'th' => 'Thursday',
		'fr' => 'Friday',
		'sa' => 'Saturday',
		'su' => 'Sunday',
	);

	private const TIME  = '(?:[01]\d|2[0-3]):[0-5]\d';
	private const RANGE = '/^\s*(' . self::TIME . ')\s*[-–]\s*(' . self::TIME . ')\s*$/u';

	/**
	 * The stored value for whatever comes in: a JSON string, an array (day →
	 * list of ranges, or of [open, close] pairs), or anything else ("").
	 */
	public static function sanitize( $value ): string {
		$week = self::parse( $value );
		return $week ? (string) json_encode( $week, JSON_UNESCAPED_SLASHES ) : '';
	}

	/**
	 * The week as day → list of «HH:MM-HH:MM», valid ranges only, merged and
	 * sorted; closed days left out. Empty array: nothing.
	 *
	 * @return array<string, string[]>
	 */
	public static function parse( $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === trim( $value ) ? array() : json_decode( $value, true );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$week = array();
		foreach ( self::DAYS as $day ) {
			$ranges = array();
			foreach ( (array) ( $value[ $day ] ?? array() ) as $range ) {
				$pair = self::range( $range );
				if ( $pair ) {
					$ranges[] = $pair;
				}
			}
			if ( $ranges ) {
				$week[ $day ] = self::merge( $ranges );
			}
		}
		return self::pairs( $week ); // Back to the stored shape: «HH:MM-HH:MM».
	}

	/**
	 * What a person wrote for ONE day on the classic screen («08:00-14:00,
	 * 16:00-20:00») → its list of ranges. Separators: comma, semicolon,
	 * «and» is not understood (on purpose: one way to write it).
	 *
	 * @return string[]
	 */
	public static function from_line( string $line ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[,;\n]+/', $line ) ?: array() ), static fn( $r ) => '' !== $r ) );
	}

	/** The classic screen's line for one day: «08:00-14:00, 16:00-20:00». */
	public static function to_line( $value, string $day ): string {
		return implode( ', ', self::parse( $value )[ $day ] ?? array() );
	}

	/**
	 * Why ONE range would not be kept, or "" if it would. What the panel
	 * shows next to it (the same rules as range(); assets/fields-panel.js has
	 * its copy).
	 */
	public static function error( $range ): string {
		if ( ! is_string( $range ) || ! preg_match( self::RANGE, $range, $m ) ) {
			return 'format';
		}
		return $m[1] === $m[2] ? 'empty' : '';
	}

	/**
	 * schema.org openingHoursSpecification: one entry per distinct range,
	 * with every day that has it (Monday to Saturday 08:00–16:00 is ONE
	 * entry with six days, not six entries).
	 *
	 * @return array<int, array{"@type":string,dayOfWeek:string[],opens:string,closes:string}>
	 */
	public static function specification( $value ): array {
		$by_range = array();
		foreach ( self::parse_pairs( $value ) as $day => $ranges ) {
			foreach ( $ranges as $pair ) {
				$by_range[ $pair[0] . '-' . $pair[1] ][] = self::SCHEMA_DAYS[ $day ];
			}
		}
		$out = array();
		foreach ( $by_range as $range => $days ) {
			list( $opens, $closes ) = explode( '-', $range );
			$out[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $days,
				'opens'     => $opens,
				'closes'    => $closes,
			);
		}
		// Earliest opening first, then by first day: a stable order for the tests and for diffs.
		usort( $out, static fn( $a, $b ) => array( $a['opens'], array_search( $a['dayOfWeek'][0], self::SCHEMA_DAYS, true ) ) <=> array( $b['opens'], array_search( $b['dayOfWeek'][0], self::SCHEMA_DAYS, true ) ) );
		return $out;
	}

	/**
	 * For people: runs of consecutive days with the same hours, one line
	 * each — «Mon–Sat: 8:00–16:00». Closed days are left out.
	 *
	 * @param string[] $day_names Seven short names, Monday first, in the request's language.
	 * @param callable $time      fn( "HH:MM" ): string, the site's time format.
	 * @param string   $and       What joins two ranges of a day (« · », «, »).
	 * @return string[]
	 */
	public static function summary( $value, array $day_names, callable $time, string $and = ', ' ): array {
		$week  = self::parse_pairs( $value );
		$lines = array();
		$start = null;
		$prev  = null;
		$key   = null;
		$flush = static function ( $from, $to, $ranges ) use ( &$lines, $day_names, $time, $and ) {
			$names   = array_combine( self::DAYS, array_slice( array_pad( array_values( $day_names ), 7, '' ), 0, 7 ) );
			$days    = $from === $to ? $names[ $from ] : $names[ $from ] . '–' . $names[ $to ];
			$lines[] = $days . ': ' . implode( $and, array_map( static fn( $p ) => $time( $p[0] ) . '–' . $time( $p[1] ), $ranges ) );
		};
		foreach ( self::DAYS as $day ) {
			$ranges = $week[ $day ] ?? null;
			$this_key = $ranges ? json_encode( $ranges ) : null;
			if ( $this_key !== $key ) {
				if ( null !== $key ) {
					$flush( $start, $prev, $week[ $start ] );
				}
				$start = $day;
				$key   = $this_key;
			}
			$prev = $day;
		}
		if ( null !== $key ) {
			$flush( $start, $prev, $week[ $start ] );
		}
		return $lines;
	}

	/** @return array<string, array<int, array{0:string,1:string}>> */
	private static function parse_pairs( $value ): array {
		$out = array();
		foreach ( self::parse( $value ) as $day => $ranges ) {
			$out[ $day ] = array_map( static fn( $r ) => explode( '-', $r ), $ranges );
		}
		return $out;
	}

	/** «08:00-14:00» or ["08:00","14:00"] → [open, close], or null if it is not a range to keep. */
	private static function range( $range ): ?array {
		if ( is_array( $range ) && 2 === count( $range ) && array_is_list( $range ) ) {
			$range = $range[0] . '-' . $range[1];
		}
		if ( ! is_string( $range ) || '' !== self::error( $range ) ) {
			return null;
		}
		preg_match( self::RANGE, $range, $m );
		return array( $m[1], $m[2] );
	}

	/**
	 * Sorted by opening, overlapping or touching ones merged. A range that
	 * crosses midnight (close < open) is left as it is: it is the last one of
	 * its day and merging it would need the next day.
	 */
	private static function merge( array $ranges ): array {
		usort( $ranges, static fn( $a, $b ) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] );
		$out = array();
		foreach ( $ranges as $pair ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && $out[ $last ][1] > $out[ $last ][0] && $pair[1] > $pair[0] && $pair[0] <= $out[ $last ][1] ) {
				$out[ $last ][1] = max( $out[ $last ][1], $pair[1] );
				continue;
			}
			$out[] = $pair;
		}
		return $out;
	}

	/** @param array<string, array<int, array{0:string,1:string}>> $week → day → «HH:MM-HH:MM» list */
	private static function pairs( array $week ): array {
		return array_map( static fn( $ranges ) => array_map( static fn( $p ) => $p[0] . '-' . $p[1], $ranges ), $week );
	}
}
