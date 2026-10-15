<?php
/**
 * Shared helpers for the blocks of any module. Nothing here registers a
 * block: each module registers its own (register_block_type on init).
 *
 * Three things, which used to live in the theme (inc/blocks.php and
 * inc/legacy-names.php of theme 1.0.0) and in estrategia-web-forms:
 *
 * 1. LABELS of the auto-generated controls (supports.autoRegister,
 *    WordPress 7.0). block.json does not translate the «label» key of an
 *    attribute (it is not in block-i18n.json), so they are set from
 *    blocks/<block>/labels.php, which returns attribute => translated text.
 *    It applies to any block whose name starts with «estrategia-web»
 *    (estrategia-web/…, estrategia-web-site/…, estrategia-web-forms/…).
 * 2. TRANSLATIONS of the editor scripts (edit.js, editor.js). WordPress
 *    expects one JSON per script with the md5 of its PATH in the name, which
 *    needs a build step and breaks when the file moves. Instead, the same
 *    PHP translations the server uses (languages/*.l10n.php) are passed
 *    inline to wp.i18n.setLocaleData, once per script. A single source and
 *    no build. Requirement: the script uses the estrategia-web domain.
 * 3. ALIASES of the blocks of the «Armazón» theme (armazon/carousel,
 *    armazon/map, armazon/whatsapp, armazon/language-switcher →
 *    estrategia-web/…). An alias copies the new type and, when rendering,
 *    renames the block and its children (and the classes in its markup) and
 *    renders it with render_block(): the output is the same as with the new
 *    name. Hidden from the inserter. An alias is only registered for a block
 *    whose module is on. What actually rewrites the content is the migrator
 *    (includes/class-armazon-migration.php); this is the safety net for any
 *    markup left behind.
 *
 * How to maintain:
 * - A new block of a module does not touch this file: if it uses
 *   autoRegister, it ships its labels.php; if it has an editor, it uses the
 *   estrategia-web domain.
 * - LEGACY_THEME_BLOCKS is the 1.0.0 list, FIXED: a new block does not go in.
 *   It is removed in 2.0.0, along with the rest of the aliases, once every
 *   site that used Armazón has gone through the migrator (07-10-2026: only
 *   Lavandería Mallorca).
 * - The trap of 2: if the script is enqueued but is not a block's
 *   (editorScript in block.json), it is not picked up. Those use the
 *   estrategia_web_editor_script_handles filter.
 * - The trap of 3: the alias needs the new type already registered; that is
 *   why it runs on init with priority 99 (blocks are registered on init, 10).
 */

namespace EstrategiaWeb;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	/** The theme's blocks in 1.0.0: the only ones with an armazon/… alias. */
	public const LEGACY_THEME_BLOCKS = array( 'carousel', 'map', 'whatsapp', 'language-switcher' );

	public static function boot(): void {
		add_filter( 'block_type_metadata', array( self::class, 'attribute_labels' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_script_translations' ), 20 );
		add_action( 'init', array( self::class, 'register_legacy_theme_blocks' ), 99 );
	}

	/** Whether a block name belongs to this plugin. */
	public static function is_ours( string $name ): bool {
		return (bool) preg_match( '#^estrategia-web(-[a-z0-9-]+)?/#', $name );
	}

	/**
	 * Applies the translated labels from labels.php to the attributes (1).
	 *
	 * @param array $metadata block.json as read.
	 */
	public static function attribute_labels( array $metadata ): array {
		if ( empty( $metadata['name'] ) || ! self::is_ours( (string) $metadata['name'] ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}
		$labels_file = dirname( (string) $metadata['file'] ) . '/labels.php';
		if ( ! is_readable( $labels_file ) ) {
			return $metadata;
		}
		$labels = require $labels_file;
		foreach ( (array) $labels as $attribute => $label ) {
			if ( isset( $metadata['attributes'][ $attribute ] ) ) {
				$metadata['attributes'][ $attribute ]['label'] = $label;
			}
		}
		return $metadata;
	}

	/** Passes the translations to wp.i18n for the editor scripts (2). */
	public static function editor_script_translations(): void {
		$handles = array();
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block_type ) {
			if ( self::is_ours( $name ) ) {
				$handles = array_merge( $handles, (array) $block_type->editor_script_handles );
			}
		}
		/**
		 * Editor scripts that do not belong to a block and also use the estrategia-web domain.
		 *
		 * @param string[] $handles
		 */
		$handles = array_unique( (array) apply_filters( 'estrategia_web_editor_script_handles', $handles ) );
		if ( ! $handles ) {
			return;
		}

		$translations = get_translations_for_domain( 'estrategia-web' );
		$locale_data  = array(
			'' => array(
				'domain' => 'estrategia-web',
				'lang'   => determine_locale(),
			),
		);
		foreach ( $translations->entries as $entry ) {
			if ( empty( $entry->translations ) ) {
				continue;
			}
			$key                 = $entry->context ? $entry->context . "\u{0004}" . $entry->singular : $entry->singular;
			$locale_data[ $key ] = $entry->translations;
		}
		$script = sprintf( 'wp.i18n.setLocaleData( %s, "estrategia-web" );', wp_json_encode( $locale_data ) );
		foreach ( $handles as $handle ) {
			// Once per script: forms has a single editor.js for nine blocks.
			wp_add_inline_script( $handle, $script, 'before' );
		}
	}

	/**
	 * A block read with old names, returned with the new ones (itself and its children) (3).
	 *
	 * @param array $parsed Block from parse_blocks().
	 */
	public static function legacy_rename( array $parsed ): array {
		$name = (string) ( $parsed['blockName'] ?? '' );
		if ( str_starts_with( $name, 'armazon/' ) && in_array( substr( $name, 8 ), self::LEGACY_THEME_BLOCKS, true ) ) {
			$parsed['blockName']    = 'estrategia-web/' . substr( $name, 8 );
			$swap                   = static fn( $html ) => is_string( $html ) ? preg_replace( '#(?<![\w-])armazon-#', 'estrategia-web-', $html ) : $html;
			$parsed['innerHTML']    = $swap( $parsed['innerHTML'] ?? '' );
			$parsed['innerContent'] = array_map( $swap, $parsed['innerContent'] ?? array() );
		}
		$parsed['innerBlocks'] = array_map( array( self::class, 'legacy_rename' ), $parsed['innerBlocks'] ?? array() );
		return $parsed;
	}

	/** Registers armazon/<block> as an alias of estrategia-web/<block> (3). */
	public static function register_legacy_theme_blocks(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::LEGACY_THEME_BLOCKS as $slug ) {
			$type = $registry->get_registered( 'estrategia-web/' . $slug );
			if ( ! $type || $registry->is_registered( 'armazon/' . $slug ) ) {
				continue;
			}
			register_block_type(
				'armazon/' . $slug,
				array(
					'api_version'      => 3,
					'title'            => $type->title,
					'category'         => $type->category,
					'attributes'       => $type->attributes,
					'uses_context'     => $type->uses_context,
					'provides_context' => $type->provides_context,
					'supports'         => array_merge( (array) $type->supports, array( 'inserter' => false ) ),
					'render_callback'  => static fn( $attributes, $content, $block ) => render_block( self::legacy_rename( $block->parsed_block ) ),
				)
			);
		}
	}
}
