<?php
/**
 * The names from before 1.0.0 (when the plugin was called «Armazón
 * Forms»), as ALIASES that render the same as the new ones: the blocks
 * armazon-forms/… → estrategia-web-forms/….
 *
 * An alias copies the new type (attributes, supports) and, when rendering,
 * renames the block and its children (and the classes in its saved markup)
 * and renders it with render_block(): the output is EXACTLY what the new
 * name would produce. Hidden from the inserter. In the editor, an old block
 * shows up as «unsupported» until the migrator runs (estrategia-web-site,
 * class-armazon-migration.php), which is what rewrites the content, the
 * options, the log tables and the WPML strings; this is only the safety net
 * for any markup left over.
 *
 * What has NO alias, and why: the REST route armazon-forms/v1. Only the
 * block's own JS calls it, and that comes from the new plugin; a cached page
 * with the old JS would not find it either (the old folder is gone). That is
 * why the migrator clears the cache.
 *
 * How to maintain:
 * - WHEN IT IS REMOVED: in 2.0.0, once the migrator has run on every site
 *   that used Armazón (07-10-2026: only Lavandería Mallorca).
 * - A new block does NOT go here: only the names that existed with
 *   «armazon-forms/». They are taken from Blocks::BLOCKS, which in 1.0.0 is
 *   the list from back then; if a block is added later, put a fixed list
 *   here by hand with the nine from 1.0.0.
 * - The trap: the alias needs the new type already registered, hence init 99.
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
			if ( 'form-ref' === $slug ) {
				continue; // 1.9.0: born after the rename, it never had an old name.
			}
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
	 * A block read with old names, with the new ones (itself and its children).
	 *
	 * @param array $parsed Block from parse_blocks().
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
