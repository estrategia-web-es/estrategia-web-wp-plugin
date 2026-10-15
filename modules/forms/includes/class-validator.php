<?php
/**
 * SERVER-SIDE validation and sanitizing of a submission, according to the
 * definition.
 *
 * The browser already validates (required, type=email…), but that is a
 * convenience for the visitor, not security: everything is repeated here,
 * because a submission can be forged without a browser. Only the fields the
 * definition lists are read; anything else that arrives is ignored.
 *
 * How to maintain:
 * - A new field type: its case in clean() and its limit in MAX_LENGTH.
 * - Edge case: a NON-required field with a malformed value (an optional
 *   email typed wrong) still gives an error. Empty is fine; wrong is not.
 * - The trap: the limits count characters (mb_strlen), not bytes. With
 *   strlen a text with accents was cut earlier than advertised.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Validator {

	public const MAX_LENGTH = array(
		'text'     => 500,
		'email'    => 254,
		'tel'      => 30,
		'textarea' => 10000,
		'select'   => 500,
		'checkbox' => 1,
	);

	/**
	 * @param array                $definition Definition loaded from the server.
	 * @param array<string, mixed> $input      What arrived in fields[...], unslashed (wp_unslash).
	 * @return array{values: array<string, string>, errors: array<string, string>}
	 */
	public static function validate( array $definition, array $input ): array {
		$values = array();
		$errors = array();

		foreach ( (array) ( $definition['fields'] ?? array() ) as $field ) {
			$name  = (string) $field['name'];
			$raw   = $input[ $name ] ?? '';
			$raw   = is_scalar( $raw ) ? (string) $raw : '';
			$value = self::clean( (string) $field['type'], $raw );

			$values[ $name ] = $value;

			if ( '' === $value ) {
				if ( ! empty( $field['required'] ) ) {
					$errors[ $name ] = 'checkbox' === $field['type']
						? __( 'You must check this box to continue.', 'estrategia-web' )
						: __( 'This field is required.', 'estrategia-web' );
				}
				continue;
			}

			$max = self::MAX_LENGTH[ $field['type'] ] ?? 500;
			if ( mb_strlen( $value ) > $max ) {
				$errors[ $name ] = __( 'This text is too long.', 'estrategia-web' );
				continue;
			}

			switch ( $field['type'] ) {
				case 'email':
					if ( ! is_email( $value ) ) {
						$errors[ $name ] = __( 'Please enter a valid email address.', 'estrategia-web' );
					}
					break;
				case 'tel':
					if ( ! preg_match( '/^\+?[0-9 ().\-]{6,30}$/', $value ) || preg_match_all( '/\d/', $value ) < 6 ) {
						$errors[ $name ] = __( 'Please enter a valid phone number.', 'estrategia-web' );
					}
					break;
				case 'select':
					if ( ! in_array( $value, (array) $field['options'], true ) ) {
						$errors[ $name ] = __( 'Please choose one of the options.', 'estrategia-web' );
					}
					break;
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	private static function clean( string $type, string $raw ): string {
		return match ( $type ) {
			'textarea' => trim( sanitize_textarea_field( $raw ) ),
			'email'    => trim( sanitize_text_field( $raw ) ),
			'checkbox' => '' !== $raw && '0' !== $raw ? '1' : '',
			default    => trim( sanitize_text_field( $raw ) ),
		};
	}
}
