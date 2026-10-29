<?php
/**
 * The PURE part of the module: normalising a source, the keys a request is
 * looked up with, reading the CSV and validating a rule against the others.
 * No WordPress (not one wp_* function, no $wpdb): that way level 1 tests it
 * with the machine's PHP (tests/php/pure.php), using Lavandería's real CSV.
 *
 * =========================================================================
 * THE MATCHING CRITERION (what decides whether a request matches a source)
 * =========================================================================
 *
 * Source and request are reduced to the same KEY, and they match if the key
 * is equal:
 *
 *   1. Scheme, domain (https://example.com/x → /x) and the # are dropped.
 *   2. The path is decoded ONCE (%xx → character; %253F stays as %3F).
 *   3. If after decoding the path contains a «?» (old WordPress URLs
 *      exported to static: /index.html%3Fp=6981.html), whatever follows is
 *      treated as query. So /index.html%3Fp=6981.html and
 *      /index.html?p=6981.html are the same key.
 *   4. Repeated slashes collapse to one; no trailing slash
 *      (/contacto/ = /contacto); the root stays «/». Everything lower-case
 *      (WordPress paths are; «/Contacto/» matches «/contacto/»), query
 *      values included.
 *   5. Query: page_id is the same as p (WordPress resolves both by ID),
 *      parameters sorted by name, empty ones dropped.
 *
 * Any query in the REQUEST that is not part of the source is ignored: the
 * source /contacto/ matches /contacto/?utm_source=x, and /?p=6981 matches
 * /?p=6981&lang=es. That is why a request is looked up with several keys
 * (candidates()), from most to least specific: the whole query, each single
 * parameter, and the bare path. Named edge case: a source with TWO
 * parameters (/?a=1&b=2) matches exactly those two, not a=1&b=2&c=3. The
 * WordPress URLs that need redirecting carry one (p or page_id).
 *
 * The trap that bit: decoding BEFORE splitting on «?» turns
 * /index.html%3Fp=6981.html into path + query; splitting first and decoding
 * afterwards leaves the «?» inside the path. That is why it splits twice
 * (step 3): both forms end up the same, whether the «?» comes encoded or not.
 */

namespace EstrategiaWeb\Redirects;

defined( 'ABSPATH' ) || exit;

final class Rules {

	public const TYPES = array( 301, 302, 410 );

	/** How many hops are followed when looking for a loop (A→B→C→…→A). */
	public const MAX_HOPS = 20;

	/** Maximum number of request parameters looked at (a 500-parameter query does not produce 500 keys). */
	public const MAX_PARAMS = 10;

	/**
	 * Normalised path and parameters of a URL, path or source.
	 *
	 * @param string $url  Anything: «/x/», «/x?p=1», «https://domain/x», «/x%3Fp=1».
	 * @param string $base Install path if WordPress lives in a subfolder («/blog»); it is stripped.
	 * @return array{0: string, 1: array<string, string>} [ path, sorted parameters ]
	 */
	public static function split( string $url, string $base = '' ): array {
		$url = trim( $url );
		if ( preg_match( '#^(?:[a-z][a-z0-9+.\-]*:)?//[^/?\#]*#i', $url, $m ) ) {
			$url = substr( $url, strlen( $m[0] ) );
		}
		$url = explode( '#', $url, 2 )[0];

		list( $path, $query ) = array_pad( explode( '?', $url, 2 ), 2, '' );
		$path = rawurldecode( $path );
		if ( false !== strpos( $path, '?' ) ) { // Step 3: the %3F of old URLs.
			list( $path, $more ) = explode( '?', $path, 2 );
			$query = '' === $query ? $more : $more . '&' . $query;
		}

		$path = '/' . ltrim( (string) preg_replace( '#/+#', '/', $path ), '/' );
		$base = '/' . trim( self::lower( $base ), '/' );
		$path = self::lower( $path );
		if ( '/' !== $base && ( $path === $base || 0 === strpos( $path, $base . '/' ) ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $base ) ), '/' );
		}
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		$params = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			list( $k, $v ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$k = self::lower( trim( urldecode( $k ) ) );
			$v = self::lower( trim( urldecode( $v ) ) );
			if ( '' === $k || '' === $v ) {
				continue;
			}
			if ( 'page_id' === $k ) {
				$k = 'p';
			}
			$params[ $k ] = $v; // Repeated (p=1&p=2): the last one wins, as in $_GET.
		}
		ksort( $params, SORT_STRING );
		return array( $path, $params );
	}

	/** The key a source is stored (and looked up) under. '' if there is nothing to match. */
	public static function key( string $source, string $base = '' ): string {
		if ( '' === trim( $source ) ) {
			return '';
		}
		list( $path, $params ) = self::split( $source, $base );
		return self::join( $path, $params );
	}

	/**
	 * Keys a REQUEST is looked up with, from most to least specific.
	 *
	 * @return string[]
	 */
	public static function candidates( string $request_uri, string $base = '' ): array {
		list( $path, $params ) = self::split( $request_uri, $base );
		$params = array_slice( $params, 0, self::MAX_PARAMS, true );
		$keys   = array();
		if ( $params ) {
			$keys[] = self::join( $path, $params );
			if ( isset( $params['p'] ) ) {
				$keys[] = self::join( $path, array( 'p' => $params['p'] ) ); // The case that matters, first.
			}
			foreach ( $params as $k => $v ) {
				$keys[] = self::join( $path, array( $k => $v ) );
			}
		}
		$keys[] = $path;
		return array_values( array_unique( $keys ) );
	}

	/** Hash of a key: what the unique index holds (a key can exceed 191 characters). */
	public static function hash( string $key ): string {
		return md5( $key );
	}

	/**
	 * Reads a redirects CSV. Accepts Lavandería's
	 * (origen,destino,tipo,motivo,revisar) and what this module exports
	 * (with activa, visitas, ultima_visita, which are ignored on import except
	 * activa). Header in Spanish or English; without a header, positional
	 * (source, target, type, note). Separator «,» or «;» (Spanish-locale
	 * Excel), decided by the first line. With or without BOM.
	 *
	 * «revisar» (review) criterion: a row with anything in that column comes in
	 * DISABLED, with its content added to the note. A rule someone flagged for
	 * review does not start working on its own.
	 *
	 * @return array{rows: array<int, array{line: int, source: string, target: string, type: string, note: string, active: bool}>, errors: array<int, string>}
	 */
	public static function parse_csv( string $csv ): array {
		$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$first = strtok( (string) $csv, "\n" );
		$sep   = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',';

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream for fgetcsv(), not a file.
		fwrite( $fh, (string) $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- same stream.
		rewind( $fh );

		$aliases = array(
			'source' => array( 'origen', 'source', 'from', 'origin', 'url', 'desde' ),
			'target' => array( 'destino', 'target', 'to', 'destination', 'hasta' ),
			'type'   => array( 'tipo', 'type', 'code', 'status', 'codigo', 'código' ),
			'note'   => array( 'motivo', 'nota', 'note', 'notes', 'reason', 'comment' ),
			'review' => array( 'revisar', 'review' ),
			'active' => array( 'activa', 'active', 'enabled' ),
		);
		$cols   = array( 'source' => 0, 'target' => 1, 'type' => 2, 'note' => 3 );
		$rows   = array();
		$errors = array();
		$line   = 0;
		while ( false !== ( $cells = fgetcsv( $fh, 0, $sep, '"', '' ) ) ) {
			++$line;
			if ( array( null ) === $cells || '' === trim( implode( '', array_map( 'strval', $cells ) ) ) ) {
				continue; // Empty line.
			}
			$cells = array_map( static fn( $c ) => trim( (string) $c ), $cells );
			if ( 1 === $line ) {
				$header = array_map( static fn( $c ) => self::lower( $c ), $cells );
				$found  = array();
				foreach ( $aliases as $field => $names ) {
					foreach ( $header as $i => $h ) {
						if ( in_array( $h, $names, true ) ) {
							$found[ $field ] = $i;
							break;
						}
					}
				}
				if ( isset( $found['source'] ) ) {
					$cols = $found;
					continue;
				}
			}
			$get    = static fn( string $f ): string => isset( $cols[ $f ], $cells[ $cols[ $f ] ] ) ? self::uncell( $cells[ $cols[ $f ] ] ) : '';
			$review = $get( 'review' );
			$note   = $get( 'note' );
			$active = ! isset( $cols['active'] ) || ! in_array( self::lower( $get( 'active' ) ), array( '0', 'no', 'false', 'off' ), true );
			if ( '' !== $review ) {
				$active = false;
				$note   = trim( $note . ' [revisar: ' . $review . ']' );
			}
			$rows[] = array(
				'line'   => $line,
				'source' => $get( 'source' ),
				'target' => $get( 'target' ),
				'type'   => $get( 'type' ),
				'note'   => $note,
				'active' => $active,
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream, not a file.
		return array( 'rows' => $rows, 'errors' => $errors );
	}

	/**
	 * A cell that the export protected against formulas («'=…») goes back to what it was.
	 */
	private static function uncell( string $cell ): string {
		return preg_match( "/^'[=+\-@]/", $cell ) ? substr( $cell, 1 ) : $cell;
	}

	/** Protects a cell of the exported CSV: Excel executes whatever starts with = + - @. */
	public static function cell( string $value ): string {
		return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Cleans and validates a rule against the existing ones. Pure: the others
	 * arrive as an array key → rule.
	 *
	 * What it rejects: empty source or the home page alone; a type other than
	 * 301/302/410; 301/302 without a target or with a target that is neither a
	 * path («/x») nor an http(s) URL; target equal to source; a source that
	 * already has another rule (duplicate); and a loop: following the target
	 * through the rules (active or not) leads back to the source (A→B→A,
	 * A→B→C→A). A chain without a loop (A→B, B→C) is allowed: it works, with
	 * one extra hop.
	 *
	 * A 410 has no target: if it comes with one, it is emptied (not an error;
	 * Lavandería's CSV doesn't include one, but a hand-written one might).
	 *
	 * @param array{source: string, target: string, type: mixed, note?: string, active?: bool} $rule
	 * @param array<string, array{id?: int, key?: string, target: string, type: int, active: bool}> $others By key, excluding this one.
	 * @param string $home Site domain («https://lavanderiamallorca.com»): an absolute target to this same site counts as a path for loops.
	 * @return array{0: array{source: string, key: string, target: string, type: int, note: string, active: bool}|null, 1: string} [ clean rule or null, error code ]
	 */
	public static function validate( array $rule, array $others, string $home = '', string $base = '' ): array {
		$source = trim( (string) $rule['source'] );
		$target = trim( (string) ( $rule['target'] ?? '' ) );
		$type   = (int) ( '' === trim( (string) ( $rule['type'] ?? '' ) ) ? 301 : $rule['type'] );
		$key    = self::key( $source, $base );

		if ( '' === $key ) {
			return array( null, 'empty_source' );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $source ) && ! self::same_site( $source, $home ) ) {
			return array( null, 'foreign_source' );
		}
		if ( '/' === $key ) {
			return array( null, 'home_source' );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return array( null, 'bad_type' );
		}
		if ( 410 === $type ) {
			$target = '';
		} elseif ( '' === $target ) {
			return array( null, 'empty_target' );
		} elseif ( ! preg_match( '#^/#', $target ) && ! preg_match( '#^https?://[^/\s]+#i', $target ) ) {
			return array( null, 'bad_target' );
		} elseif ( preg_match( '/\s/', $target ) ) {
			return array( null, 'bad_target' );
		}
		if ( isset( $others[ $key ] ) ) {
			return array( null, 'duplicate' );
		}

		if ( '' !== $target ) {
			$next = self::internal_key( $target, $home, $base );
			if ( null !== $next && $next === $key ) {
				return array( null, 'same_target' );
			}
			$seen = array( $key => true );
			for ( $hop = 0; null !== $next && $hop < self::MAX_HOPS; $hop++ ) {
				if ( isset( $seen[ $next ] ) ) {
					return array( null, 'loop' );
				}
				$seen[ $next ] = true;
				$other         = $others[ $next ] ?? null;
				// DISABLED ones too: otherwise enabling one in bulk (which doesn't go through here) would close the loop.
				if ( ! $other || 410 === (int) $other['type'] ) {
					break;
				}
				$next = self::internal_key( (string) $other['target'], $home, $base );
			}
		}

		return array(
			array(
				'source' => $source,
				'key'    => $key,
				'target' => $target,
				'type'   => $type,
				'note'   => trim( (string) ( $rule['note'] ?? '' ) ),
				'active' => (bool) ( $rule['active'] ?? true ),
			),
			'',
		);
	}

	/** Key of a target if it belongs to this site (path or URL with the same domain); null if external. */
	public static function internal_key( string $target, string $home, string $base = '' ): ?string {
		if ( '' === $target ) {
			return null;
		}
		if ( '/' === $target[0] && 0 !== strpos( $target, '//' ) ) {
			return self::key( $target, $base );
		}
		return self::same_site( $target, $home ) ? self::key( $target, $base ) : null;
	}

	private static function same_site( string $url, string $home ): bool {
		$host = static fn( string $u ): string => preg_replace( '/^www\./', '', self::lower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) );
		return '' !== $home && '' !== $host( $url ) && $host( $url ) === $host( $home );
	}

	private static function join( string $path, array $params ): string {
		if ( ! $params ) {
			return $path;
		}
		$pairs = array();
		foreach ( $params as $k => $v ) {
			$pairs[] = $k . '=' . $v;
		}
		return $path . '?' . implode( '&', $pairs );
	}

	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
