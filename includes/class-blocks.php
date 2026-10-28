<?php
/**
 * Shared helpers for the blocks of any module. Nothing here registers a
 * block: each module registers its own (register_block_type on init).
 *
 * Two things, which used to live in the theme (inc/blocks.php of theme
 * 1.0.0) and in estrategia-web-forms:
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
 * (Until 2.0.1 there was a third: the armazon/… aliases of the old theme's
 * blocks. Removed with the Armazón migrator, CHANGELOG 2.0.1.)
 *
 * How to maintain:
 * - A new block of a module does not touch this file: if it uses
 *   autoRegister, it ships its labels.php; if it has an editor, it uses the
 *   estrategia-web domain.
 * - The trap of 2: if the script is enqueued but is not a block's
 *   (editorScript in block.json), it is not picked up. Those use the
 *   estrategia_web_editor_script_handles filter.
 */

namespace EstrategiaWeb;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	public static function boot(): void {
		add_filter( 'block_type_metadata', array( self::class, 'attribute_labels' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_script_translations' ), 20 );
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
}
