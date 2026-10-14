<?php
/**
 * Qué opción de un desplegable viene marcada al pintar el formulario.
 *
 * Caso que lo motivó: el mismo formulario de presupuesto en las páginas de
 * hostelería, restauración y barcos, y en cada una el «¿Qué servicio
 * necesitas?» tiene que llegar ya con el suyo. Clase PURA (no llama a
 * WordPress): la prueba de nivel 1 la ejecuta con el PHP de la máquina.
 *
 * Las fuentes, de más fuerte a más débil (Blocks::render_field las ordena):
 * 1. Lo que eligió el visitante, en la vuelta de un envío sin JS con errores.
 *    Si hay vuelta, manda solo ella: ni siquiera se aplica el valor de la
 *    página si el visitante dejó el campo vacío.
 * 2. El parámetro de URL af_<nombre del campo> (?af_servicio=2 o
 *    ?af_servicio=lavanderia-para-barcos-y-yates): para enlazar desde otra
 *    página. Con prefijo a propósito: un campo llamado «name», «p» o «s»
 *    chocaría con las variables de consulta de WordPress y cambiaría la
 *    página que se sirve.
 * 3. El «content» del bloque sincronizado que contiene el formulario
 *    (contexto pattern/overrides): {"servicio":{"defaultOption":3}}. Es lo
 *    que hace falta cuando el formulario es un patrón sincronizado, porque
 *    los atributos de dentro son los mismos en todas las páginas.
 * 4. El atributo defaultOption del propio bloque.
 *
 * Una fuente es una POSICIÓN (1 = la primera opción; 0 = ninguna) o, solo
 * desde la URL, el texto exacto de la opción o su slug. Se guarda la posición
 * y no el texto por WPML: las opciones se traducen línea a línea y conservan
 * el orden, así que la página traducida preselecciona la misma opción sin que
 * el traductor tenga que copiar el texto al carácter (si no casa, el fallo
 * es silencioso: el select sale sin marcar).
 *
 * Seguridad: solo se devuelve una opción que EXISTE en la lista. Lo que no
 * casa se ignora y cede a la fuente siguiente; nunca llega al HTML.
 *
 * Cómo se mantiene:
 * - Una fuente nueva (p. ej. un filtro del tema) entra en el array de
 *   candidatos de Blocks::render_field en su sitio del orden, y su caso en
 *   tests/preselect.test.mjs.
 * - Caso frontera: reordenar las opciones en el editor NO desplaza la
 *   preselección del atributo (el editor la recoloca por su texto, ver
 *   assets/editor.js), pero SÍ la del «content» de un bloque sincronizado y la
 *   de los enlaces con posición: esas viven fuera del bloque y nadie las ve al
 *   reordenar. Si una web enlaza con ?af_campo=, mejor el slug.
 * - La trampa: el «content» de un bloque sincronizado solo llega si el bloque
 *   del campo declara "usesContext": ["pattern/overrides"] en su block.json;
 *   sin eso WordPress no se lo pasa y la fuente 3 calla sin error. Y si el
 *   editor ofrece «Restablecer» en el bloque sincronizado (lo hace cuando el
 *   patrón tiene bloques core con anulaciones), borra el «content» entero,
 *   preselección incluida. Abrir y guardar la página NO lo borra: lo
 *   comprueba tests/admin.test.mjs.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Preselect {

	/** Prefijo del parámetro de URL: af_<nombre del campo>. */
	public const URL_PREFIX = 'af_';

	/**
	 * @param string[]                  $options    Las opciones del desplegable, ya limpias.
	 * @param array<int|string|null>    $candidates Las fuentes, de la más fuerte a la más débil.
	 * @param callable(string): string  $slug       sanitize_title() en el plugin.
	 * @return string La opción preseleccionada, o '' si ninguna fuente casa.
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
