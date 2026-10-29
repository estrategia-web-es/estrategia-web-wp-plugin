<?php
namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

/**
 * A LOCATION'S DATA, STORED ONCE (2.0.0, David, 27-10-2026: «los datos
 * duplicados se separan»). Up to 1.7.0 a location kept the same thing twice
 * — the address as a text AND by parts, the hours as a text AND day by day,
 * the map as a link AND as an embed URL, the static map as a URL AND as a
 * media ID — and the copies drifted apart (Lavandería: «Local 12» was in
 * one and not in the other). Now each datum is stored ONCE and the rest is
 * DERIVED here, when the page is rendered:
 *
 *   stored (template location.json)        derived (this class)
 *   street (1-2 lines), postal code,       the address text the page shows,
 *   locality, region, country              and the street on one line for Google
 *   hours (the «hours» field type)         the hours text the page shows
 *   map_query (a Google Business           «Open in Google Maps» link and the
 *   profile name, or an address)           embed URL of the map
 *   static_map_id (media library)          its URL (WordPress side: Location_Fields)
 *
 * PURE PHP (no WordPress), so the level 1 test (tests/location-data.test.mjs,
 * through tests/php/location-data.php) checks every rule with Lavandería's
 * real strings. The WordPress side — which post, which language, the old
 * meta while a site has not migrated — is class-location-fields.php.
 *
 * THE FORMAT, per language (FORMATS; a site changes it with the filter
 * estrategia_web_location_formats, Location_Fields::formats()):
 *   address    "{street}\n{postal_code} {locality}, {region}" — placeholders
 *              {street} {postal_code} {locality} {region} {country}. A part
 *              left empty takes its separator with it («07609 Llucmajor»
 *              with no region), and an empty line disappears.
 *   hours      ONE set of hours for every open day (Lavandería) → two lines:
 *              «open» with the days, then the times:
 *                «Abierto todos los días» / «De 8:00 a 23:00»
 *                «Open Monday to Saturday» / «8:00am – 4:00pm»
 *              SEVERAL sets → one «line» per set, in week order:
 *                «De lunes a viernes: de 9:00 a 14:00 y de 16:00 a 20:00»
 *                «Sábado: de 10:00 a 14:00»
 *              The days: «every_day» for the seven; «day_range» for a run
 *              of three or more consecutive days; the names joined with
 *              «list_and» otherwise. The times: «interval» per range, joined
 *              with «times_and»; «time» is a PHP date() format (G:i, H:i,
 *              g:ia). The first letter of each line is upper-cased.
 *   Unknown language → English.
 *
 * How to maintain:
 * - Who and when: whoever adds a language to the plugin adds its FORMATS
 *   entry, with a case in the level 1 test (the defaults are what a new site
 *   shows without touching anything). A site that wants another wording
 *   uses the filter, never an edit here.
 * - Boundary case, with a name: Lavandería's two locations ARE the defaults
 *   of es and en — «Abierto todos los días\nDe 8:00 a 23:00», «Open Monday
 *   to Saturday\n8:00am – 4:00pm» — and the level 1 test holds that, so a
 *   change to the defaults that would change what Lavandería says fails
 *   there first.
 * - Another: a day closed in the middle of a run (mo-tu + th-fr). It is two
 *   runs of two, so the days come out as a list («lunes, martes, jueves y
 *   viernes»), not as two ranges: a range is three days or more.
 * - THE TRAP: urlencode() and not rawurlencode() for the map query — Google's
 *   own links (and the ones Lavandería stored) carry «+» for spaces, and the
 *   migration compares the URL it derives with the one stored. And «!» MUST
 *   be encoded in the embed: it is the separator of Google's «pb» parameter
 *   (urlencode does it: %21).
 * - The parsers (parse_address(), parse_hours(), query_from_*()) are only for
 *   the migration of the old texts: best effort, and the migration REPORTS
 *   what they could not read and compares the derived text with the old one
 *   (a DIFFERENCE is shown, never hidden). Nothing at render time parses.
 */

require_once __DIR__ . '/class-hours.php';

final class Location_Data {

	public const FORMATS = array(
		'es' => array(
			'address'   => "{street}\n{postal_code} {locality}, {region}",
			'open'      => 'Abierto {days}',
			'line'      => '{days}: {times}',
			'every_day' => 'todos los días',
			'day_range' => 'de {from} a {to}',
			'list_and'  => ' y ',
			'days'      => array( 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo' ),
			'interval'  => 'de {open} a {close}',
			'times_and' => ' y ',
			'time'      => 'G:i',
		),
		'ca' => array(
			'address'   => "{street}\n{postal_code} {locality}, {region}",
			'open'      => 'Obert {days}',
			'line'      => '{days}: {times}',
			'every_day' => 'cada dia',
			'day_range' => 'de {from} a {to}',
			'list_and'  => ' i ',
			'days'      => array( 'dilluns', 'dimarts', 'dimecres', 'dijous', 'divendres', 'dissabte', 'diumenge' ),
			'interval'  => 'de {open} a {close}',
			'times_and' => ' i ',
			'time'      => 'G:i',
		),
		'en' => array(
			'address'   => "{street}\n{postal_code} {locality}, {region}",
			'open'      => 'Open {days}',
			'line'      => '{days}: {times}',
			'every_day' => 'every day',
			'day_range' => '{from} to {to}',
			'list_and'  => ' and ',
			'days'      => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
			'interval'  => '{open} – {close}',
			'times_and' => ' and ',
			'time'      => 'g:ia',
		),
	);

	/** The default format of a language («es», «ca_ES», «en_US»…); English if there is none. */
	public static function default_format( string $lang ): array {
		$code = strtolower( explode( '_', str_replace( '-', '_', $lang ) )[0] );
		return self::FORMATS[ $code ] ?? self::FORMATS['en'];
	}

	/** The street as stored: trimmed lines, empty ones out, «\n» between them. */
	public static function street( string $street ): string {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', $street ) ?: array() ), 'strlen' ) );
		return implode( "\n", $lines );
	}

	/** The street on ONE line, for schema.org's streetAddress: the lines joined with «, ». */
	public static function street_line( string $street ): string {
		return str_replace( "\n", ', ', self::street( $street ) );
	}

	/**
	 * The address text the page shows.
	 *
	 * @param array{street?:string,postal_code?:string,locality?:string,region?:string,country?:string} $parts
	 */
	public static function address_text( array $parts, array $format ): string {
		$values = array(
			'{street}'      => self::street( (string) ( $parts['street'] ?? '' ) ),
			'{postal_code}' => trim( (string) ( $parts['postal_code'] ?? '' ) ),
			'{locality}'    => trim( (string) ( $parts['locality'] ?? '' ) ),
			'{region}'      => trim( (string) ( $parts['region'] ?? '' ) ),
			'{country}'     => trim( (string) ( $parts['country'] ?? '' ) ),
		);
		if ( '' === implode( '', $values ) ) {
			return '';
		}
		$text  = strtr( (string) ( $format['address'] ?? self::FORMATS['en']['address'] ), $values );
		$lines = array();
		foreach ( preg_split( '/\R/u', $text ) ?: array() as $line ) {
			$line = preg_replace( '/[ \t]{2,}/u', ' ', $line );
			// A part left empty takes its separator with it: «, ,» → «,», and none at either end.
			$line = preg_replace( '/(\s*,\s*){2,}/u', ', ', $line );
			$line = trim( preg_replace( '/^[\s,]+|[\s,]+$/u', '', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	/** The hours text the page shows, from an «hours» value (JSON text or array). "" = no hours. */
	public static function hours_text( $hours, array $format ): string {
		$week = Hours::parse( $hours );
		if ( ! $week ) {
			return '';
		}
		$format = $format + self::FORMATS['en'];
		// Sets of days with the same ranges, in the order of their first day.
		$sets = array();
		foreach ( Hours::DAYS as $i => $day ) {
			if ( isset( $week[ $day ] ) ) {
				$key            = implode( ',', $week[ $day ] );
				$sets[ $key ][] = $i;
			}
		}
		$times = static function ( string $key ) use ( $format ): string {
			$parts = array();
			foreach ( explode( ',', $key ) as $range ) {
				list( $open, $close ) = explode( '-', $range );
				$parts[]              = strtr( $format['interval'], array( '{open}' => self::time( $open, $format['time'] ), '{close}' => self::time( $close, $format['time'] ) ) );
			}
			return self::join( $parts, $format['times_and'] );
		};
		if ( 1 === count( $sets ) ) {
			$key = (string) array_key_first( $sets );
			return self::ucfirst( strtr( $format['open'], array( '{days}' => self::days( $sets[ $key ], $format ) ) ) )
				. "\n" . self::ucfirst( $times( $key ) );
		}
		$lines = array();
		foreach ( $sets as $key => $days ) {
			$lines[] = self::ucfirst( strtr( $format['line'], array( '{days}' => self::days( $days, $format ), '{times}' => $times( (string) $key ) ) ) );
		}
		return implode( "\n", $lines );
	}

	/** «Open in Google Maps»: a search for the query (a Google Business profile name or an address). */
	public static function maps_url( string $query ): string {
		$query = trim( $query );
		return '' === $query ? '' : 'https://www.google.com/maps/search/?api=1&query=' . urlencode( $query );
	}

	/** The map's embed URL for the same search (no API key: Google's «Share › Embed a map» form). */
	public static function embed_url( string $query ): string {
		$query = trim( $query );
		return '' === $query ? '' : 'https://www.google.com/maps/embed?origin=mfe&pb=!1m3!2m1!1s' . urlencode( $query ) . '!6i15';
	}

	/**
	 * MIGRATION ONLY. The search query in an old «Google Maps link»: its
	 * «query» or «q» parameter, or the name in /maps/search/<q> or
	 * /maps/place/<q>. null: a short link (maps.app.goo.gl) or a place id —
	 * nothing a person could read back.
	 */
	public static function query_from_maps_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! preg_match( '/(^|\.)google\.[a-z.]+$/i', (string) ( $parts['host'] ?? '' ) ) ) {
			return null;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $args );
		foreach ( array( 'query', 'q' ) as $name ) {
			if ( isset( $args[ $name ] ) && is_string( $args[ $name ] ) && '' !== trim( $args[ $name ] ) ) {
				return trim( $args[ $name ] );
			}
		}
		if ( preg_match( '#/maps/(?:search|place)/([^/@?]+)#', (string) ( $parts['path'] ?? '' ), $m ) ) {
			$q = trim( urldecode( $m[1] ) );
			return '' !== $q ? $q : null;
		}
		return null;
	}

	/**
	 * MIGRATION ONLY. The search query in an old embed URL: the «search» form
	 * Google gives (pb=…!2m1!1s<query>!…) or a «q» parameter (the API embed).
	 * null: an embed of a place by its id (pb=!1m18!…), which has no query.
	 */
	public static function query_from_embed( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		if ( preg_match( '/!2m1!1s([^!&]+)/', $url, $m ) ) {
			$q = trim( urldecode( $m[1] ) );
			return '' !== $q ? $q : null;
		}
		$parts = parse_url( $url );
		parse_str( (string) ( is_array( $parts ) ? ( $parts['query'] ?? '' ) : '' ), $args );
		return isset( $args['q'] ) && is_string( $args['q'] ) && '' !== trim( $args['q'] ) ? trim( $args['q'] ) : null;
	}

	/**
	 * MIGRATION ONLY. An old address text → its parts: the LAST line that
	 * starts with a postal code («07609 Llucmajor, Islas Baleares») gives
	 * postal code, locality and region; the lines before it are the street.
	 * A line after it is read as the country if it names Spain; anything
	 * else there, or no postal-code line at all: null (not parsed).
	 *
	 * @return array{street:string,postal_code:string,locality:string,region:string,country:string}|null
	 */
	public static function parse_address( string $text ): ?array {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', $text ) ?: array() ), 'strlen' ) );
		for ( $i = count( $lines ) - 1; $i >= 1; $i-- ) {
			if ( preg_match( '/^(\d{4,5})\s+([^,]+?)(?:\s*,\s*(.+))?$/u', $lines[ $i ], $m ) ) {
				$after   = array_slice( $lines, $i + 1 );
				$country = '';
				if ( $after ) {
					if ( 1 !== count( $after ) || ! preg_match( '/^(españa|spain|espanya|espagne|spanien)$/iu', $after[0] ) ) {
						return null;
					}
					$country = 'ES';
				}
				return array(
					'street'      => implode( "\n", array_slice( $lines, 0, $i ) ),
					'postal_code' => $m[1],
					'locality'    => trim( $m[2] ),
					'region'      => trim( $m[3] ?? '' ),
					'country'     => $country,
				);
			}
		}
		return null;
	}

	/** Day names a person writes, in Spanish, Catalan and English (full and short) → day index, Monday 0. */
	private const DAY_WORDS = array(
		'lunes' => 0, 'martes' => 1, 'miércoles' => 2, 'miercoles' => 2, 'jueves' => 3, 'viernes' => 4, 'sábados' => 5, 'sabados' => 5, 'sábado' => 5, 'sabado' => 5, 'domingos' => 6, 'domingo' => 6,
		'dilluns' => 0, 'dimarts' => 1, 'dimecres' => 2, 'dijous' => 3, 'divendres' => 4, 'dissabtes' => 5, 'dissabte' => 5, 'diumenges' => 6, 'diumenge' => 6,
		'monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3, 'friday' => 4, 'saturdays' => 5, 'saturday' => 5, 'sundays' => 6, 'sunday' => 6,
		'lun' => 0, 'mar' => 1, 'mié' => 2, 'mie' => 2, 'jue' => 3, 'vie' => 4, 'sáb' => 5, 'sab' => 5, 'dom' => 6,
		'dl' => 0, 'dt' => 1, 'dc' => 2, 'dj' => 3, 'dv' => 4, 'ds' => 5, 'dg' => 6,
		'mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6,
	);

	/**
	 * MIGRATION ONLY. An old hours text → an «hours» value (day → ranges),
	 * best effort: the ways a person writes it in the three languages —
	 * «Abierto todos los días / De 8:00 a 23:00», «Lunes a viernes: 9-14 y
	 * 16-20», «Open Monday to Saturday / 8:00am – 4:00pm». A line with days
	 * and no times gives its days to the next line with times. Days without
	 * times, times without days, or nothing understood: null.
	 *
	 * @return array<string,string[]>|null
	 */
	public static function parse_hours( string $text ): ?array {
		$week    = array();
		$pending = null;
		$found   = false;
		foreach ( preg_split( '/\R/u', $text ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$days   = self::parse_days( $line );
			$ranges = self::parse_times( $line );
			if ( null !== $days && ! $ranges ) {
				if ( null !== $pending ) {
					return null; // Two lines of days in a row: not a shape this reads.
				}
				$pending = $days;
				continue;
			}
			if ( $ranges ) {
				$days    = $days ?? $pending;
				$pending = null;
				if ( null === $days ) {
					return null;
				}
				foreach ( $days as $d ) {
					$week[ Hours::DAYS[ $d ] ] = array_merge( $week[ Hours::DAYS[ $d ] ] ?? array(), $ranges );
				}
				$found = true;
				continue;
			}
			return null; // A line with neither: something this does not understand (closed days, notes).
		}
		if ( ! $found || null !== $pending ) {
			return null;
		}
		$week = Hours::parse( $week );
		return $week ?: null;
	}

	/** @return int[]|null The days a line names (indexes, Monday 0), or null if it names none. */
	private static function parse_days( string $line ): ?array {
		if ( preg_match( '/todos\s+los\s+d[ií]as|cada\s+d[ií]a|tots\s+els\s+dies|every\s*day|daily|diariamente|a\s+diario/iu', $line ) ) {
			return range( 0, 6 );
		}
		$words = array_keys( self::DAY_WORDS );
		usort( $words, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		$re = '/(?<!\p{L})(' . implode( '|', array_map( static fn( $w ) => preg_quote( $w, '/' ), $words ) ) . ')\.?(?!\p{L})/iu';
		if ( ! preg_match_all( $re, $line, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$days = array();
		$prev = null;
		foreach ( $m[1] as $i => $match ) {
			$day = self::DAY_WORDS[ mb_strtolower( $match[0] ) ];
			if ( null !== $prev ) {
				$end   = $m[0][ $i - 1 ][1] + strlen( $m[0][ $i - 1 ][0] );
				$between = substr( $line, $end, $match[1] - $end );
				if ( preg_match( '/^\s*(a|al|to|through|fins\s+a|-|–|—)\s*$/iu', $between ) ) {
					for ( $d = $prev + 1; ( $d % 7 ) !== ( $day + 1 ) % 7 && $d < $prev + 8; $d++ ) {
						$days[] = $d % 7;
					}
					$prev = $day;
					continue;
				}
			}
			$days[] = $day;
			$prev   = $day;
		}
		return array_values( array_unique( $days ) );
	}

	/** @return string[] The «HH:MM-HH:MM» ranges a line has («8:00 a 23:00», «9-14», «8:00am – 4:00pm»). */
	private static function parse_times( string $line ): array {
		$t   = '(\d{1,2})(?:[:.h](\d{2}))?\s*(am|pm|a\.m\.|p\.m\.|h)?';
		$out = array();
		if ( preg_match_all( '/(?<![\d:])' . $t . '\s*(?:a|-|–|—|to|hasta|fins\s+a)\s*' . $t . '(?![\d:])/iu', $line, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$open  = self::hhmm( $m[1], $m[2] ?? '', $m[3] ?? '' );
				$close = self::hhmm( $m[4], $m[5] ?? '', $m[6] ?? '' );
				if ( null !== $open && null !== $close ) {
					$out[] = $open . '-' . $close;
				}
			}
		}
		return $out;
	}

	private static function hhmm( string $h, string $min, string $ampm ): ?string {
		$hour = (int) $h;
		$ampm = strtolower( str_replace( '.', '', $ampm ) );
		if ( 'pm' === $ampm && $hour < 12 ) {
			$hour += 12;
		} elseif ( 'am' === $ampm && 12 === $hour ) {
			$hour = 0;
		}
		if ( 24 === $hour && ( '' === $min || '00' === $min ) ) {
			return '23:59';
		}
		$minute = '' === $min ? 0 : (int) $min;
		return $hour > 23 || $minute > 59 ? null : sprintf( '%02d:%02d', $hour, $minute );
	}

	/** «HH:MM» in a date() format: only G H g h i a A are needed, without WordPress or a timezone. */
	public static function time( string $hhmm, string $format ): string {
		list( $h, $m ) = array_map( 'intval', explode( ':', $hhmm ) );
		$g             = 0 === $h % 12 ? 12 : $h % 12;
		$out           = '';
		foreach ( str_split( $format ) as $char ) {
			$out .= match ( $char ) {
				'G'     => (string) $h,
				'H'     => sprintf( '%02d', $h ),
				'g'     => (string) $g,
				'h'     => sprintf( '%02d', $g ),
				'i'     => sprintf( '%02d', $m ),
				'a'     => $h < 12 ? 'am' : 'pm',
				'A'     => $h < 12 ? 'AM' : 'PM',
				default => $char,
			};
		}
		return $out;
	}

	/** @param int[] $days Indexes, Monday 0, in week order. */
	private static function days( array $days, array $format ): string {
		$names = array_values( (array) $format['days'] );
		if ( 7 === count( $days ) ) {
			return $format['every_day'];
		}
		$runs = array();
		foreach ( $days as $d ) {
			$last = count( $runs ) - 1;
			if ( $last >= 0 && end( $runs[ $last ] ) === $d - 1 ) {
				$runs[ $last ][] = $d;
			} else {
				$runs[] = array( $d );
			}
		}
		$parts = array();
		foreach ( $runs as $run ) {
			if ( count( $run ) >= 3 ) {
				$parts[] = strtr( $format['day_range'], array( '{from}' => $names[ $run[0] ] ?? '', '{to}' => $names[ end( $run ) ] ?? '' ) );
			} else {
				foreach ( $run as $d ) {
					$parts[] = $names[ $d ] ?? '';
				}
			}
		}
		return self::join( $parts, $format['list_and'] );
	}

	/** «a», «a y b», «a, b y c». */
	private static function join( array $parts, string $and ): string {
		$last = array_pop( $parts );
		return $parts ? implode( ', ', $parts ) . $and . $last : (string) $last;
	}

	private static function ucfirst( string $text ): string {
		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}
}
