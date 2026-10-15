<?php
/**
 * Which option of a dropdown comes preselected when the form is rendered.
 *
 * The case that prompted it: the same quote form on the hospitality,
 * restaurant and boat pages, and on each one the «What service do you
 * need?» field has to arrive with its own already chosen. PURE class (does
 * not call WordPress): the level 1 test runs it with the machine's PHP.
 *
 * The sources, from strongest to weakest (Blocks::render_field orders them):
 * 1. What the visitor chose, on the return of a no-JS submission with
 *    errors. If there is a return, only it counts: not even the page's value
 *    is applied if the visitor left the field empty.
 * 2. The URL parameter af_<field name> (?af_servicio=2 or
 *    ?af_servicio=lavanderia-para-barcos-y-yates): for linking from another
 *    page. Prefixed on purpose: a field called «name», «p» or «s» would
 *    clash with WordPress query vars and change the page being served.
 * 3. The «content» of the synced block that contains the form
 *    (pattern/overrides context): {"servicio":{"defaultOption":3}}. This is
 *    what is needed when the form is a synced pattern, because the
 *    attributes inside are the same on every page.
 * 4. The block's own defaultOption attribute.
 *
 * A source is a POSITION (1 = the first option; 0 = none) or, only from the
 * URL, the exact text of the option or its slug. The position is stored
 * rather than the text because of WPML: options are translated line by line
 * and keep their order, so the translated page preselects the same option
 * without the translator having to copy the text character for character
 * (if it does not match, the failure is silent: the select comes out
 * unselected).
 *
 * Security: only an option that EXISTS in the list is returned. Anything
 * that does not match is ignored and falls through to the next source; it
 * never reaches the HTML.
 *
 * How to maintain:
 * - A new source (e.g. a theme filter) goes into the candidates array of
 *   Blocks::render_field at its place in the order, and its case into
 *   tests/preselect.test.mjs.
 * - Edge case: reordering the options in the editor does NOT shift the
 *   attribute's preselection (the editor relocates it by its text, see
 *   assets/editor.js), but it DOES shift the one in a synced block's
 *   «content» and in links by position: those live outside the block and
 *   nobody sees them when reordering. If a site links with ?af_field=, the
 *   slug is better.
 * - The trap: a synced block's «content» only arrives if the field block
 *   declares "usesContext": ["pattern/overrides"] in its block.json; without
 *   it WordPress does not pass it and source 3 stays silent without error.
 *   And if the editor offers «Reset» on the synced block (it does when the
 *   pattern has core blocks with overrides), it wipes the whole «content»,
 *   preselection included. Opening and saving the page does NOT wipe it:
 *   tests/admin.test.mjs checks that.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Preselect {

	/** URL parameter prefix: af_<field name>. */
	public const URL_PREFIX = 'af_';

	/**
	 * @param string[]                  $options    The dropdown options, already clean.
	 * @param array<int|string|null>    $candidates The sources, from strongest to weakest.
	 * @param callable(string): string  $slug       sanitize_title() in the plugin.
	 * @return string The preselected option, or '' if no source matches.
	 */
	public static function pick( array $options, array $candidates, callable $slug ): string {
		foreach ( $candidates as $candidate ) {
			$option = self::match( $options, $candidate, $slug );
			if ( '' !== $option ) {
				return $option;
			}
		}
		return '';
	}

	/** @param string[] $options */
	private static function match( array $options, int|string|null $candidate, callable $slug ): string {
		if ( null === $candidate || '' === $candidate ) {
			return '';
		}
		if ( is_int( $candidate ) || ctype_digit( $candidate ) ) {
			$position = (int) $candidate;
			return $position >= 1 && $position <= count( $options ) ? $options[ $position - 1 ] : '';
		}
		if ( in_array( $candidate, $options, true ) ) {
			return $candidate;
		}
		$wanted = (string) $slug( $candidate );
		if ( '' === $wanted ) {
			return '';
		}
		foreach ( $options as $option ) {
			if ( (string) $slug( $option ) === $wanted ) {
				return $option;
			}
		}
		return '';
	}
}
