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
 *
 * ENTRY markers (unreleased, 24-11-2026): {{post_title}}, {{post_url}} and
 * {{post_field:<key>}} read the ENTRY the form was shown in (an obituary, a
 * product…), resolved on the server from the id signed into the form
 * (Token::context(), Rest::context_post()). Without a valid signature, or
 * with an entry that is no longer public, they are all known and EMPTY: the
 * submission goes on. {{post_field:<key>}} is the only marker with an
 * argument; post_field_key() decides which meta it may read (never one that
 * starts with «_») and post_field_keys() finds the ones a template uses, so
 * only those are read. The case that brought them, by name: a funeral home's
 * obituary, To = the funeral home's mailbox, Cc = {{post_field:correu_familia}},
 * subject «Condolences: {{post_title}}» (README, «Markers of the entry»).
 * The trap that bit: replace() lowercases the marker name, so the key is
 * lowercased too; a meta written with capitals («correuFamilia») cannot be
 * reached. The content-types fields are lowercase by their schema.
 *
 * And addresses(): every To / Cc / Bcc / Reply-To is split and validated
 * AFTER the markers are replaced, one address at a time; whatever carries a
 * control character (a line break: «a@b.c\nBcc: x@y.z») is dropped whole,
 * never cut, so a value can never add a header.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Markers {

	public const ALL_FIELDS = 'all_fields';
	public const DATE       = 'submission_date';
	public const PAGE_URL   = 'page_url';
	public const SITE_NAME  = 'site_name';
	/** 2.11.0, dossier forms only: the private link to come back and complete it, and what is still missing. Empty elsewhere. */
	public const DOSSIER_URL = 'dossier_url';
	public const MISSING     = 'missing_fields';
	/** The entry the form was shown in (empty without a valid signed context). */
	public const POST_TITLE = 'post_title';
	public const POST_URL   = 'post_url';
	/** {{post_field:<key>}}: the prefix of the only marker with an argument. */
	public const POST_FIELD = 'post_field';

	/** A marker: a name, or «post_field:» and a key. Spaces allowed inside the braces. */
	private const PATTERN = '/\{\{\s*([A-Za-z0-9_-]+(?::[A-Za-z0-9_-]+)?)\s*\}\}/';

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
			self::PATTERN,
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

	/**
	 * The keys of the {{post_field:<key>}} markers the templates use,
	 * lowercased (as replace() looks them up) and without repeats.
	 *
	 * @return string[]
	 */
	public static function post_field_keys( string ...$templates ): array {
		$keys = array();
		foreach ( $templates as $template ) {
			preg_match_all( self::PATTERN, $template, $m );
			foreach ( $m[1] as $name ) {
				$name = strtolower( $name );
				if ( str_starts_with( $name, self::POST_FIELD . ':' ) ) {
					$keys[ substr( $name, strlen( self::POST_FIELD ) + 1 ) ] = true;
				}
			}
		}
		return array_keys( $keys );
	}

	/**
	 * Which meta {{post_field:<key>}} reads on an entry of type $post_type,
	 * or '' when it may not read any. In this order:
	 * 1. <key> itself, if it is a meta registered for the type
	 *    (register_post_meta: the content-types fields, and any plugin's);
	 * 2. the type's prefix + <key>, if THAT is registered: content-types
	 *    names its fields with the type's prefix (type «lle_obituary», field
	 *    «lle_correu_familia»), so {{post_field:correu_familia}} finds it;
	 * 3. <key> as a public meta (an ACF field, a meta written by hand).
	 * Never a key that starts with «_» (WordPress's protected meta: edit
	 * locks, backups, private plugin data) nor one $is_protected refuses.
	 *
	 * @param string[] $registered   The meta keys registered for the type.
	 * @param callable $is_protected fn( string $key ): bool — is_protected_meta() in WordPress.
	 */
	public static function post_field_key( string $key, string $post_type, array $registered, callable $is_protected ): string {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $key ) ) {
			return '';
		}
		$cut    = strpos( $post_type, '_' );
		$prefix = $cut ? substr( $post_type, 0, $cut + 1 ) : '';
		$pick   = $key;
		if ( ! in_array( $key, $registered, true ) && '' !== $prefix && in_array( $prefix . $key, $registered, true ) ) {
			$pick = $prefix . $key;
		}
		return $is_protected( $pick ) ? '' : $pick;
	}

	/**
	 * The addresses of a To / Cc / Bcc / Reply-To line once its markers are
	 * replaced: split by commas or semicolons, each one validated on its own.
	 * Empty, invalid and repeated ones are dropped and the rest go on. One
	 * with a control character INSIDE (a line break, a tab, a NUL) is dropped
	 * whole: cutting it at the break would keep «a@b.c» and hide that someone
	 * tried to add a header. Whitespace around it is trimmed first.
	 *
	 * @param callable $is_email fn( string ): bool|string — is_email() in WordPress.
	 * @param string[] $except   Addresses already used (in To, for the Cc): not repeated.
	 * @return string[]
	 */
	public static function addresses( string $line, callable $is_email, array $except = array() ): array {
		$out  = array();
		$seen = array_flip( array_map( 'strtolower', $except ) );
		foreach ( preg_split( '/[,;]/', $line ) as $address ) {
			$address = trim( $address ); // A meta saved from a textarea ends in "\n": that one is not an injection.
			if ( '' === $address || preg_match( '/[\x00-\x1F\x7F]/', $address ) || ! $is_email( $address ) ) {
				continue;
			}
			$lower = strtolower( $address );
			if ( ! isset( $seen[ $lower ] ) ) {
				$seen[ $lower ] = true;
				$out[]          = $address;
			}
		}
		return $out;
	}
}
