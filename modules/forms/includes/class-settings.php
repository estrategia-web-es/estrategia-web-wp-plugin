<?php
/**
 * Global plugin settings (a single option, 'estrategia_web_forms_settings').
 *
 * ONLY what is the same for every form on the site goes here: the log
 * retention, the antispam and the default texts. What belongs to each form
 * (fields, notifications, its own messages) lives in its blocks.
 *
 * How to maintain:
 * - A new setting goes into DEFAULTS, into sanitize() and into the screen
 *   (Admin::render_settings), in the same commit. If it is a text the
 *   visitor reads, it also goes into TRANSLATABLE so that WPML sees it.
 * - Edge case: a text that only one form needs different is NOT a setting:
 *   it is an attribute of the form block.
 * - The trap: an empty text means «the default one, translated with
 *   gettext». If it is filled in, it stops being translated with the .po
 *   and is translated with WPML (String Translation) instead. The two paths
 *   do not mix.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'estrategia_web_forms_settings';

	/** Seconds a token is valid: longer than any reasonable page cache. */
	public const TOKEN_MAX_AGE = 7 * DAY_IN_SECONDS;

	public const DEFAULTS = array(
		'retention_days'  => 365,
		'min_seconds'     => 3,
		'rate_limit'      => 5,
		'rate_window'     => 10,
		'success_message' => '',
		'error_message'   => '',
		'email_footer'    => '',
	);

	/** String name in WPML => setting key. */
	public const TRANSLATABLE = array(
		'Default success message' => 'success_message',
		'Default error message'   => 'error_message',
		'Email footer'            => 'email_footer',
	);

	/** @return array<string, mixed> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$all    = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
		/**
		 * Lets a site (or the tests) set settings in code.
		 *
		 * @param array $all Settings already merged with the defaults.
		 */
		return (array) apply_filters( 'estrategia_web_forms_settings', $all );
	}

	public static function get( string $key ): mixed {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** @param array<string, mixed> $input */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = self::DEFAULTS;
		foreach ( array( 'retention_days', 'min_seconds', 'rate_limit', 'rate_window' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}
		foreach ( array( 'success_message', 'error_message', 'email_footer' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $input[ $key ] );
			}
		}
		return $clean;
	}

	/** Registers the filled-in texts in WPML. Called when the settings are saved. */
	public static function register_strings(): void {
		$all = self::all();
		foreach ( self::TRANSLATABLE as $name => $key ) {
			if ( '' !== (string) $all[ $key ] ) {
				Language::register_string( $name, (string) $all[ $key ] );
			}
		}
	}

	/**
	 * A global text in language $lang. It must be called with the locale
	 * already switched (Language::switch_to) so that the default comes out
	 * translated.
	 */
	public static function text( string $key, string $lang ): string {
		$value = (string) self::get( $key );
		$name  = (string) array_search( $key, self::TRANSLATABLE, true );
		if ( '' !== $value ) {
			return Language::translate_string( $name, $value, $lang );
		}
		return match ( $key ) {
			'success_message' => __( 'Thank you. Your message has been sent.', 'estrategia-web' ),
			'error_message'   => __( 'The message could not be sent. Please check the form and try again.', 'estrategia-web' ),
			default           => '',
		};
	}
}
