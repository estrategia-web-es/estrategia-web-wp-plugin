<?php
/**
 * The names from before 1.0.0 (when the plugin was called «Armazón Site»),
 * as ALIASES that render the same as the new ones.
 *
 * What it covers, and why each thing:
 * - BLOCKS armazon-site/consent and armazon/consent → estrategia-web-site/consent.
 *   An alias is registered by copying the new type (attributes, supports) and,
 *   at render time, it renames the block (and the classes of its saved markup)
 *   and renders it with render_block(): the output is EXACTLY what the new name
 *   would produce. Hidden from the inserter.
 * - BLOCK BINDINGS SOURCES armazon-site/company and armazon-site/location:
 *   the same functions under the old name.
 * - SHORTCODES [armazon_company] and [armazon_consent_settings].
 * - The OPTION: if estrategia_web_site does not exist yet and armazon_site
 *   does, the old one is read (default_option_…). Nothing is written here:
 *   migrating is the migrator's job (class-armazon-migration.php).
 * The consent cookie has its own fallback in Consent::choice() and
 * blocks/consent/view.js.
 *
 * What for: the MIGRATOR rewrites the content with the new names, but old
 * markup may remain where it does not look (a restored revision, a page pasted
 * from another site, an imported pattern). With the alias it still looks the
 * same on the site; in the editor, the old block shows as «unsupported» until
 * the migrator is run or it is changed by hand.
 *
 * How to maintain:
 * - Who: whoever touches the plugin. WHEN IT GOES: in 2.0.0, once the migrator
 *   has run on every site that used Armazón (07-10-2026: only Lavandería
 *   Mallorca). Removing it earlier is a major version anyway.
 * - A new block, source or shortcode does NOT go in here: only the names that
 *   existed with «armazon» are here.
 * - Edge case: an old block INSIDE an old block. The outer one is renamed
 *   whole (its children too) and rendered once; the inner one never goes
 *   through its alias.
 * - The trap: the alias needs the new type to be registered already, that is
 *   why it runs on init with priority 99 (blocks are registered on init, 10).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Legacy_Names {

	/** Old block → new block. */
	public const BLOCKS = array(
		'armazon-site/consent' => 'estrategia-web-site/consent',
		'armazon/consent'      => 'estrategia-web-site/consent',
	);

	/** Classes of the saved markup: old → new (longest first). */
	public const CLASSES = array(
		'wp-block-armazon-site-' => 'wp-block-estrategia-web-site-',
		'wp-block-armazon-'      => 'wp-block-estrategia-web-site-',
		'armazon-consent'        => 'estrategia-web-consent',
	);

	/** Old Block Bindings source → new. */
	public const SOURCES = array(
		'armazon-site/company'  => 'estrategia-web-site/company',
		'armazon-site/location' => 'estrategia-web-site/location',
	);

	/** Old shortcode → new. */
	public const SHORTCODES = array(
		'armazon_company'           => 'estrategia_web_company',
		'armazon_consent_settings'  => 'estrategia_web_consent_settings',
	);

	public const LEGACY_OPTION = 'armazon_site';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 99 );
		add_filter( 'default_option_' . Settings::OPTION, array( self::class, 'legacy_option' ), 10, 1 );
	}

	public static function register(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::BLOCKS as $old => $new ) {
			$type = $registry->get_registered( $new );
			if ( ! $type || $registry->is_registered( $old ) ) {
				continue;
			}
			register_block_type(
				$old,
				array(
					'api_version'     => 3,
					'title'           => $type->title,
					'category'        => $type->category,
					'attributes'      => $type->attributes,
					'uses_context'    => $type->uses_context,
					'supports'        => array_merge( (array) $type->supports, array( 'inserter' => false ) ),
					'render_callback' => static fn( $attributes, $content, $block ) => render_block( self::rename( $block->parsed_block ) ),
				)
			);
		}
		// The old sources call the same functions (WP_Block_Bindings_Source does
		// not let you read its callback: it is private). The locations one also
		// receives the pre-1.0.0 keys (location_phone…) and maps them to the new ones.
		$legacy_key = static function ( array $args ): array {
			$key = (string) ( $args['key'] ?? '' );
			if ( '' !== $key && ! str_starts_with( $key, 'estrategia_web_' ) && in_array( 'estrategia_web_' . $key, array_merge( Content_Types::meta_keys( Location::TYPE ), Location::DERIVED ), true ) ) {
				$args['key'] = 'estrategia_web_' . $key;
			}
			return $args;
		};
		$sources = array(
			'armazon-site/company'  => array( __( 'Company details', 'estrategia-web' ), array( Bindings::class, 'get_value' ), array() ),
			'armazon-site/location' => array( __( 'Location details', 'estrategia-web' ), static fn( $args, $block, $attr ) => Location::get_value( $legacy_key( (array) $args ), $block, $attr ), array( 'postId', 'postType' ) ),
		);
		foreach ( $sources as $old => [ $label, $callback, $context ] ) {
			if ( get_block_bindings_source( $old ) ) {
				continue;
			}
			register_block_bindings_source(
				$old,
				array(
					'label'              => $label,
					'get_value_callback' => $callback,
					'uses_context'       => $context,
				)
			);
		}
		global $shortcode_tags;
		foreach ( self::SHORTCODES as $old => $new ) {
			if ( isset( $shortcode_tags[ $new ] ) && ! shortcode_exists( $old ) ) {
				add_shortcode( $old, $shortcode_tags[ $new ] );
			}
		}
	}

	/**
	 * A block read with old names, with the new ones (itself and its children).
	 *
	 * @param array $parsed Block from parse_blocks().
	 */
	public static function rename( array $parsed ): array {
		if ( isset( self::BLOCKS[ $parsed['blockName'] ?? '' ] ) ) {
			$parsed['blockName'] = self::BLOCKS[ $parsed['blockName'] ];
			$swap                = static fn( $html ) => is_string( $html ) ? strtr( $html, self::CLASSES ) : $html;
			$parsed['innerHTML']    = $swap( $parsed['innerHTML'] ?? '' );
			$parsed['innerContent'] = array_map( $swap, $parsed['innerContent'] ?? array() );
		}
		$parsed['innerBlocks'] = array_map( array( self::class, 'rename' ), $parsed['innerBlocks'] ?? array() );
		return $parsed;
	}

	/**
	 * The old option while the new one does not exist.
	 *
	 * @param mixed $default get_option() default value.
	 */
	public static function legacy_option( $default ) {
		$legacy = get_option( self::LEGACY_OPTION, null );
		return null === $legacy ? $default : $legacy;
	}
}
