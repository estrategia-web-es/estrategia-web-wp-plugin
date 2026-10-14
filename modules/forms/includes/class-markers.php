<?php
/**
 * Sustitución de marcadores {{nombre}} en las plantillas de los avisos.
 *
 * Clase PURA: no llama a WordPress, y por eso se prueba en el nivel 1
 * (tests/markers.test.mjs la ejecuta con el PHP de la máquina).
 *
 * Reglas, que el README repite para quien escribe plantillas:
 * - Un marcador conocido sin valor (campo opcional vacío) se sustituye por "".
 * - Un marcador DESCONOCIDO se deja tal cual, a propósito: una errata en la
 *   plantilla ({{emial}}) se ve en el correo en vez de desaparecer en silencio.
 * - Se admiten espacios dentro de las llaves: {{ nombre }}.
 *
 * Los nombres de los marcadores fijos van en castellano porque los escribe
 * quien configura el formulario, no el código (fila 8: el dominio en su idioma).
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Markers {

	public const ALL_FIELDS = 'todos_los_campos';
	public const DATE       = 'fecha';
	public const PAGE_URL   = 'url_pagina';
	public const SITE_NAME  = 'nombre_sitio';

	/**
	 * @param string                $template Plantilla con marcadores.
	 * @param array<string, string> $values   nombre del marcador => valor ya preparado.
	 * @param callable|null         $escape   Se aplica a cada valor antes de insertarlo (HTML); null = tal cual.
	 * @param string[]              $raw      Marcadores que NO se escapan aunque haya $escape (p. ej. la tabla HTML ya montada).
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
	 * Quita saltos de línea: un asunto o una cabecera con "\n" es una
	 * inyección de cabeceras en potencia.
	 */
	public static function single_line( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) );
	}
}
