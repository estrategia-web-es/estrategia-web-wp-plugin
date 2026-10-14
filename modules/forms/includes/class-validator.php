<?php
/**
 * Validación y saneado EN SERVIDOR de un envío, según la definición.
 *
 * El navegador ya valida (required, type=email…), pero eso es comodidad del
 * visitante, no seguridad: aquí se repite todo, porque un envío se puede
 * fabricar sin navegador. Solo se leen los campos que dice la definición;
 * cualquier otra cosa que llegue se ignora.
 *
 * Cómo se mantiene:
 * - Un tipo de campo nuevo: su caso en clean() y su límite en MAX_LENGTH.
 * - Caso frontera: un campo NO obligatorio con un valor mal formado (un
 *   correo opcional escrito mal) da error igual. Vacío sí vale; mal, no.
 * - La trampa: los límites cuentan caracteres (mb_strlen), no bytes. Con
 *   strlen un texto con tildes se cortaba antes de lo anunciado.
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
	 * @param array                $definition Definición cargada del servidor.
	 * @param array<string, mixed> $input      Lo recibido en fields[...], sin barras (wp_unslash).
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
