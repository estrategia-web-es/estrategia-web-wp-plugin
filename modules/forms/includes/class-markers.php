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
 * Two kinds of markers:
 * - FIELD markers: {{<name of a field>}}. They belong to each site's content
 *   ({{nombre}}, {{email}}…) and this class knows nothing about them.
 * - FIXED markers: the four constants below. They are plugin vocabulary, so
 *   they are in English like the rest of the code (row 8), since 1.4.0.
 *   Until then they were in Spanish ({{todos_los_campos}}, {{fecha}},
 *   {{url_pagina}}, {{nombre_sitio}}), and sites have them SAVED in their
 *   forms: those names keep working as aliases (ALIASES) until 2.0.0.
 *
 * Why {{submission_date}} and not {{date}}: the default form names its fields
 * in English (name, email, message), so a booking form with a field called
 * "date" is the expected case, not a rare one. A fixed marker shadows a field
 * with the same name (see below), and {{date}} would have hidden the date the
 * visitor chose behind the date of the submission, without any error.
 *
 * How to maintain:
 * - A new fixed marker: a constant here, its value in
 *   Notifier::marker_values() and the FIXED_MARKERS list in
 *   assets/editor.js (the editor help). Pick a name no form would give a
 *   field: it will shadow it.
 * - Edge case, a field named like a fixed marker or an alias (a Spanish
 *   reservation form with a field "fecha"): the FIXED marker wins and the
 *   field cannot be reached from a template. That was already the behaviour
 *   before 1.4.0, and with_aliases() keeps it for the old names so that no
 *   saved email changes its content. The fix is renaming the field.
 * - The pitfall: an alias is resolved by copying the VALUE of its canonical
 *   marker (with_aliases()), not inside replace(). replace() only sees a
 *   values array; resolving there would make a field named "fecha" win over
 *   the alias and change emails already being sent.
 * - 2.0.0: delete ALIASES and with_aliases(), and say so in the CHANGELOG
 *   (sites that still use the old names will show {{fecha}} verbatim, which
 *   is the "unknown marker" rule doing its job).
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Markers {

	public const ALL_FIELDS = 'all_fields';
	public const DATE       = 'submission_date';
	public const PAGE_URL   = 'page_url';
	public const SITE_NAME  = 'site_name';

	/**
	 * Old (Spanish) name => current name. Deprecated, removed in 2.0.0.
	 */
	public const ALIASES = array(
		'todos_los_campos' => self::ALL_FIELDS,
		'fecha'            => self::DATE,
		'url_pagina'       => self::PAGE_URL,
		'nombre_sitio'     => self::SITE_NAME,
	);

	/**
	 * Adds the deprecated names with the value of their current marker.
	 * Call it on the FINAL values array (after the fields): the alias then
	 * overwrites a field with the same name, as the fixed marker did.
	 *
	 * @param array<string, string> $values marker name => value.
	 * @return array<string, string>
	 */
	public static function with_aliases( array $values ): array {
		foreach ( self::ALIASES as $old => $current ) {
			if ( array_key_exists( $current, $values ) ) {
				$values[ $old ] = $values[ $current ];
			}
		}
		return $values;
	}

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
