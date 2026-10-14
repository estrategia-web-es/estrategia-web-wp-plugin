<?php
/**
 * Ayudas comunes para los bloques de cualquier módulo. Nada aquí registra un
 * bloque: cada módulo registra los suyos (register_block_type en init).
 *
 * Tres cosas, que antes vivían en el tema (inc/blocks.php e
 * inc/legacy-names.php del tema 1.0.0) y en estrategia-web-forms:
 *
 * 1. ETIQUETAS de los controles autogenerados (supports.autoRegister,
 *    WordPress 7.0). block.json no traduce la clave «label» de un atributo
 *    (no está en block-i18n.json), así que se ponen desde
 *    blocks/<bloque>/labels.php, que devuelve atributo => texto traducido.
 *    Vale para cualquier bloque cuyo nombre empiece por «estrategia-web»
 *    (estrategia-web/…, estrategia-web-site/…, estrategia-web-forms/…).
 * 2. TRADUCCIONES de los scripts de editor (edit.js, editor.js). WordPress
 *    espera un JSON por script con el md5 de su RUTA en el nombre, que pide
 *    un paso de build y se rompe al mover el fichero. En vez de eso, las
 *    mismas traducciones PHP que usa el servidor (languages/*.l10n.php) se
 *    le pasan a wp.i18n.setLocaleData en línea, una vez por script. Una sola
 *    fuente y sin build. Requisito: el script usa el dominio estrategia-web.
 * 3. ALIAS de los bloques del tema «Armazón» (armazon/carousel, armazon/map,
 *    armazon/whatsapp, armazon/language-switcher → estrategia-web/…). Un alias
 *    copia el tipo nuevo y, al pintar, renombra el bloque y sus hijos (y las
 *    clases de su marcado) y lo pinta con render_block(): sale lo mismo que
 *    con el nombre nuevo. Oculto del insertador. Solo se registra el alias de
 *    un bloque cuyo módulo está encendido. Quien reescribe el contenido de
 *    verdad es el migrador (includes/class-armazon-migration.php); esto es
 *    la red para el marcado que quede.
 *
 * Cómo se mantiene:
 * - Un bloque nuevo de un módulo no toca este fichero: si usa autoRegister,
 *   pone su labels.php; si tiene editor, usa el dominio estrategia-web.
 * - LEGACY_THEME_BLOCKS es la lista de la 1.0.0, FIJA: un bloque nuevo no
 *   entra. Se quita en la 2.0.0, con el resto de alias, cuando todas las
 *   webs que usaron Armazón hayan pasado el migrador (07-10-2026: solo
 *   Lavandería Mallorca).
 * - La trampa de 2: si el script se encola pero no es el de un bloque
 *   (editorScript de block.json), no se entera. Para esos está el filtro
 *   estrategia_web_editor_script_handles.
 * - La trampa de 3: el alias necesita el tipo nuevo ya registrado; por eso
 *   corre en init con prioridad 99 (los bloques se registran en init, 10).
 */

namespace EstrategiaWeb;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	/** Los bloques del tema en la 1.0.0: los únicos con alias armazon/…. */
	public const LEGACY_THEME_BLOCKS = array( 'carousel', 'map', 'whatsapp', 'language-switcher' );

	public static function boot(): void {
		add_filter( 'block_type_metadata', array( self::class, 'attribute_labels' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_script_translations' ), 20 );
		add_action( 'init', array( self::class, 'register_legacy_theme_blocks' ), 99 );
	}

	/** Si un nombre de bloque es de este plugin. */
	public static function is_ours( string $name ): bool {
		return (bool) preg_match( '#^estrategia-web(-[a-z0-9-]+)?/#', $name );
	}

	/**
	 * Pone las etiquetas traducidas de labels.php a los atributos (1).
	 *
	 * @param array $metadata block.json leído.
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

	/** Pasa las traducciones a wp.i18n para los scripts de editor (2). */
	public static function editor_script_translations(): void {
		$handles = array();
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block_type ) {
			if ( self::is_ours( $name ) ) {
				$handles = array_merge( $handles, (array) $block_type->editor_script_handles );
			}
		}
		/**
		 * Scripts de editor que no son de un bloque y también usan el dominio estrategia-web.
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
			// Una vez por script: forms tiene un solo editor.js para nueve bloques.
			wp_add_inline_script( $handle, $script, 'before' );
		}
	}

	/**
	 * Un bloque leído con nombres viejos, con los nuevos (él y sus hijos) (3).
	 *
	 * @param array $parsed Bloque de parse_blocks().
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

	/** Registra armazon/<bloque> como alias de estrategia-web/<bloque> (3). */
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
