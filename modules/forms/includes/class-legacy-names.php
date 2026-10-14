<?php
/**
 * Los nombres de antes de 1.0.0 (cuando el plugin se llamaba «Armazón
 * Forms»), como ALIAS que pintan lo mismo que los nuevos: los bloques
 * armazon-forms/… → estrategia-web-forms/….
 *
 * Un alias copia el tipo nuevo (atributos, supports) y, al pintar, renombra
 * el bloque y sus hijos (y las clases de su marcado guardado) y lo pinta con
 * render_block(): sale EXACTAMENTE lo que saldría con el nombre nuevo. Oculto
 * del insertador. En el editor, un bloque viejo sale como «no admitido» hasta
 * que se pasa el migrador (estrategia-web-site, class-armazon-migration.php),
 * que es quien reescribe el contenido, las opciones, las tablas del registro
 * y las cadenas de WPML; esto es solo la red para el marcado que quede.
 *
 * Lo que NO tiene alias, y por qué: la ruta REST armazon-forms/v1. Solo la
 * llama el JS del propio bloque, que sale del plugin nuevo; una página
 * cacheada con el JS viejo tampoco lo encontraría (la carpeta vieja ya no
 * está). Por eso el migrador vacía la caché.
 *
 * Cómo se mantiene:
 * - CUÁNDO SE QUITA: en la 2.0.0, una vez pasado el migrador en todas las
 *   webs que usaron Armazón (07-10-2026: solo Lavandería Mallorca).
 * - Un bloque nuevo NO entra aquí: solo los nombres que existieron con
 *   «armazon-forms/». Se sacan de Blocks::BLOCKS, que en 1.0.0 es la lista
 *   de entonces; si se añade un bloque después, se pone aquí a mano una lista
 *   fija con los nueve de 1.0.0.
 * - La trampa: el alias necesita el tipo nuevo ya registrado, por eso init 99.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Legacy_Names {

	public const OLD = 'armazon-forms';
	public const NEW = 'estrategia-web-forms';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 99 );
	}

	public static function register(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array_keys( Blocks::BLOCKS ) as $slug ) {
			$old  = self::OLD . '/' . $slug;
			$type = $registry->get_registered( self::NEW . '/' . $slug );
			if ( ! $type || $registry->is_registered( $old ) ) {
				continue;
			}
			register_block_type(
				$old,
				array(
					'api_version'      => 3,
					'title'            => $type->title,
					'category'         => $type->category,
					'attributes'       => $type->attributes,
					'uses_context'     => $type->uses_context,
					'provides_context' => $type->provides_context,
					'parent'           => null,
					'supports'         => array_merge( (array) $type->supports, array( 'inserter' => false ) ),
					'render_callback'  => static fn( $attributes, $content, $block ) => render_block( self::rename( $block->parsed_block ) ),
				)
			);
		}
	}

	/**
	 * Un bloque leído con nombres viejos, con los nuevos (él y sus hijos).
	 *
	 * @param array $parsed Bloque de parse_blocks().
	 */
	public static function rename( array $parsed ): array {
		$name = (string) ( $parsed['blockName'] ?? '' );
		if ( str_starts_with( $name, self::OLD . '/' ) ) {
			$parsed['blockName']    = self::NEW . substr( $name, strlen( self::OLD ) );
			$swap                   = static fn( $html ) => is_string( $html ) ? str_replace( self::OLD, self::NEW, $html ) : $html;
			$parsed['innerHTML']    = $swap( $parsed['innerHTML'] ?? '' );
			$parsed['innerContent'] = array_map( $swap, $parsed['innerContent'] ?? array() );
		}
		$parsed['innerBlocks'] = array_map( array( self::class, 'rename' ), $parsed['innerBlocks'] ?? array() );
		return $parsed;
	}
}
