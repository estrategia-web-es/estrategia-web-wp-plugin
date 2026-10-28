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
 *   {{url_pagina}}, {{nombre_sitio}}) and worked as read aliases until
 *   2.0.1, which removed them: «wp estrategia-web migrate-form-markers»
 *   renames them in the saved forms (class-marker-migration.php, where the
 *   old names live now). A site that has not run it shows {{fecha}}
 *   verbatim in its emails: the "unknown marker" rule.
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
 * - Edge case, a field named like a fixed marker (a booking form with a
 *   field "submission_date"): the FIXED marker wins and the field cannot be
 *   reached from a template (Notifier merges the fixed values after the
 *   fields). The fix is renaming the field. A field named like an OLD name
 *   ("fecha") is reachable again since 2.0.1: {{fecha}} is that field.
 * - The pitfall: renaming a fixed marker means saved forms stop working.
 *   It takes a migration like Marker_Migration, not an alias: aliases stay
 *   forever (this one lasted from 1.4.0 to 2.0.1).
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Markers {

	public const ALL_FIELDS = 'all_fields';
	public const DATE       = 'submission_date';
	public const PAGE_URL   = 'page_url';
	public const SITE_NAME  = 'site_name';

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
