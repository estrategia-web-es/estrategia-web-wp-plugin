<?php
/**
 * Replacement of {{name}} markers in the notification templates.
 *
 * PURE class: it does not call WordPress, which is why it is tested at
 * level 1 (tests/markers.test.mjs runs it with the machine's PHP).
 *
 * Rules, which the README repeats for whoever writes templates:
 * - A known marker without a value (empty optional field) is replaced by "".
 * - An UNKNOWN marker is left as is, on purpose: a typo in the template
 *   ({{emial}}) shows up in the email instead of vanishing silently.
 * - Spaces are allowed inside the braces: {{ name }}.
 *
 * The names of the fixed markers are in Spanish because they are written by
 * whoever configures the form, not by the code (row 8: the domain in its own
 * language).
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Markers {

	public const ALL_FIELDS = 'todos_los_campos';
	public const DATE       = 'fecha';
	public const PAGE_URL   = 'url_pagina';
	public const SITE_NAME  = 'nombre_sitio';

	/**
	 * @param string                $template Template with markers.
	 * @param array<string, string> $values   marker name => value already prepared.
	 * @param callable|null         $escape   Applied to each value before inserting it (HTML); null = as is.
	 * @param string[]              $raw      Markers that are NOT escaped even if there is $escape (e.g. the HTML table already built).
	 */
	public static function replace( string $template, array $values, ?callable $escape = null, array $raw = array() ): string {
		$values = array_change_key_case( $values, CASE_LOWER );
		$raw    = array_map( 'strtolower', $raw );

		return (string) preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_-]+)\s*\}\}/',
			static function ( array $m ) use ( $values, $escape, $raw ): string {
				$key = strtolower( $m[1] );
				if ( ! array_key_exists( $key, $values ) ) {
					return $m[0];
				}
				$value = (string) $values[ $key ];
				if ( null !== $escape && ! in_array( $key, $raw, true ) ) {
					$value = $escape( $value );
				}
				return $value;
			},
			$template
		);
	}

	/**
	 * Strips line breaks: a subject or a header with "\n" is a potential
	 * header injection.
	 */
	public static function single_line( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) );
	}
}
