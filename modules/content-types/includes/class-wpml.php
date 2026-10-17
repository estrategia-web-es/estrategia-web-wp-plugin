<?php
/**
 * WPML FOR THE DECLARED TYPES: the equivalent of a wpml-config.xml, built
 * from the definitions, plus the copy on save that WPML misses.
 *
 * THE DECISION (15-10-2026): the config goes in through WPML's filter
 * `wpml_config_array`, NOT by writing an XML file. Read in WPML 5.0.2
 * (classes/xml-config/class-wpml-config.php, parse_wpml_config_files): WPML
 * gathers every wpml-config.xml of active plugins and the theme, merges them
 * into one array and passes it through `wpml_config_array` before importing
 * it. An entry added there is treated exactly like one read from a file:
 * read-only in «Settings › Custom fields translation», same import code.
 * Why not generate the XML: WPML only reads it from the ROOT of a plugin or
 * theme, so it would be the shared wpml-config.xml of this plugin (written
 * at runtime into the plugin folder: lost on every update, and the folder
 * may not be writable) or a file in the site's theme that someone has to
 * keep in step with the JSON by hand. The filter has neither problem.
 *
 * What is mapped:
 *   type   wpml: translate → <custom-type translate="1">,
 *          display-as-translated → translate="1" display-as-translated="1"
 *          (NOT translate="2": WPML's XSD only takes 0 or 1, and in a file a
 *          "2" drops the whole file; measured 16-10-2026), ignore → translate="0".
 *          Default for types and taxonomies: display-as-translated (David,
 *          16-10-2026): a list of restaurants or recipes must not go empty in a
 *          language until every item is translated.
 *   taxonomy, the same with <taxonomy>
 *   field  wpml: copy | translate | ignore → <custom-field action="…">.
 *          image and relation with copy also get type="post-ids" (and the
 *          sub-type when there is one target): WPML then copies the ID of the
 *          TRANSLATED post/image, not the original's.
 *
 * WHEN WPML READS IT (the trap, the same as in modules/site): WPML does not
 * re-read its config on every request, only when an admin opens one of its
 * screens or Plugins (WPML_Config::load_config). When the definitions change
 * (Registrar::maybe_flush) this module asks for the import on the next admin
 * request (WPML_Config::import_now, public). Until then a NEW «copy» field is
 * not copied: on a fresh test site, open /wp-admin/plugins.php before
 * measuring.
 *
 * THE COPY ON SAVE (the fix of a59bf65, generalized): WPML copies «copy»
 * fields in save_post, but the REST API — the block editor — saves the meta
 * AFTER save_post, so the translation kept the OLD value. Every time a
 * «copy» field of a declared type is added, changed or deleted in the
 * ORIGINAL, sync() calls wpml_sync_custom_field (WPML's public API). The
 * write in the translation fires updated_post_meta again; that post is a
 * translation and is skipped, and $syncing cuts any other loop.
 *
 * MEASURED WITH REAL WPML (15-10-2026, WPML 5.0.2 in Lavandería local, the
 * example in the theme's content-types/): the filter made gment_restaurant
 * and gment_cuisine translatable (custom_posts_sync_option = 1) and the four
 * fields read-only copy/translate in WPML's settings; changing phone and
 * price of the Spanish original by REST put them in the English translation,
 * address untouched; in the translation's editor the three «copy» fields are
 * locked. CONTROL: with sync() unhooked, the translation kept the old phone.
 * THE TRAP of that measurement: with WPML's setup NOT completed (a fresh
 * Playground), WPML does not load its API hooks at all — has_action(
 * 'wpml_sync_custom_field' ) is false — and nothing is copied. It looks like
 * this fix failing. Complete the setup first (Lavandería: semilla/v1/idiomas
 * accion=activar).
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Wpml {

	public const PENDING_OPTION = 'estrategia_web_content_types_wpml_pending';
	/** Fingerprint of the slug translations last written into WPML (sync_slugs()). */
	public const SLUGS_OPTION = 'estrategia_web_content_types_wpml_slugs';

	private const MODE = array(
		'translate'             => array( 'translate' => '1' ),
		'display-as-translated' => array( 'translate' => '1', 'display-as-translated' => '1' ),
		'ignore'                => array( 'translate' => '0' ),
	);

	private static bool $syncing = false;

	public static function boot(): void {
		add_filter( 'wpml_config_array', array( self::class, 'filter_config' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'sync' ), 10, 3 );
		}
		add_action( 'admin_init', array( self::class, 'import_if_pending' ), 99 );
		// After Registrar (init 5) and its flush (99): the types exist and WPML ST is up (it starts at init -1000).
		add_action( 'init', array( self::class, 'sync_slugs' ), 100 );
	}

	/**
	 * What sync_slugs() writes into WPML, from the definitions. Pure.
	 *
	 * One entry per type or taxonomy THIS module registered whose slug is an
	 * object (a plain string says nothing per language and is left alone) and
	 * whose wpml is not «ignore» (WPML only translates the slug of a
	 * translatable type). original: the slug in the default language, the one
	 * Registrar::slug() registers. translations: language code → slug, only
	 * the languages the file names (Schema::slug_in(), no fallback) and not
	 * the default one; a language the file does not name is left to WPML's
	 * screen, where someone may have typed it.
	 *
	 * @param array<string,array>  $types      Normalized definitions.
	 * @param string[]             $registered Registrar::registered().
	 * @param array<string,string> $languages  WPML active language code => its locale.
	 * @return list<array{kind:string,name:string,original:string,translations:array<string,string>}>
	 */
	public static function slug_plan( array $types, array $registered, string $default_lang, array $languages ): array {
		$plan  = array();
		$items = array();
		foreach ( $types as $name => $def ) {
			$items[] = array( 'post', $name, $def['slug'], $def['wpml'] );
			foreach ( $def['taxonomies'] as $tax ) {
				if ( is_array( $tax ) ) {
					$items[] = array( 'taxonomy', $tax['name'], $tax['slug'], $tax['wpml'] );
				}
			}
		}
		$default_locale = $languages[ $default_lang ] ?? $default_lang;
		foreach ( $items as [ $kind, $name, $slug, $mode ] ) {
			if ( ! is_array( $slug ) || 'ignore' === $mode || ! in_array( $name, $registered, true ) ) {
				continue;
			}
			$translations = array();
			foreach ( $languages as $code => $locale ) {
				$value = $code === $default_lang ? null : Schema::slug_in( $slug, array( $code, $locale ) );
				if ( null !== $value ) {
					$translations[ $code ] = $value;
				}
			}
			$plan[] = array(
				'kind'         => $kind,
				'name'         => $name,
				'original'     => Schema::text( $slug, $default_locale ),
				'translations' => $translations,
			);
		}
		return $plan;
	}

	/**
	 * SLUGS PER LANGUAGE (17-10-2026): the slug objects of the definitions
	 * become WPML slug translations, so /es/restaurantes/… and
	 * /en/restaurants/… are both real URLs and the language switcher links
	 * each language's.
	 *
	 * HOW WPML KEEPS THEM (read in WPML String Translation 3.x, classes/
	 * slug-translation/): a string in the «WordPress» context named
	 * «URL slug: <type>» (a taxonomy: «URL <taxonomy> tax slug») whose value is
	 * the REGISTERED slug and whose language is the original's, with one
	 * translation per language; plus the switches — the option
	 * wpml_base_slug_translation (global), sitepress setting
	 * posts_slug_translation[types][<type>] and the option
	 * wpml_tax_slug_translation_settings[types][<taxonomy>]. WPML then
	 * rewrites the permalinks (post_type_link, pre_term_link), the archive
	 * link and the stored rewrite rules on the fly (option_rewrite_rules):
	 * no flush is needed for a translation.
	 *
	 * THE WAY IN: WPML's public action wpml_activate_slug_translation (it
	 * turns the switches on and registers the string if it is missing) and
	 * icl_register_string / icl_add_string_translation, String Translation's
	 * functions, for the values. NOT the screen (WPML › Settings › Slug
	 * translations): that one is a form post. The string is registered first
	 * with the DEFAULT language as source, because for post types WPML's own
	 * registration leaves the source to the «strings language» setting, which
	 * on a site whose default is Catalan may be English.
	 *
	 * WHEN: only when what would be written changes (hash in SLUGS_OPTION:
	 * the plan and the default language), so a normal request writes nothing.
	 * A change in WPML's screen is kept until the JSON changes; then the JSON
	 * wins for the languages it names.
	 *
	 * Needs String Translation: without it WPML has no slug translation, and
	 * every language uses the registered (default language's) slug.
	 */
	public static function sync_slugs(): void {
		if ( ! self::active() || ! function_exists( 'icl_register_string' ) || ! function_exists( 'icl_add_string_translation' ) || ! has_action( 'wpml_activate_slug_translation' ) ) {
			return;
		}
		$default = (string) apply_filters( 'wpml_default_language', null );
		$langs   = array();
		foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $lang ) {
			$langs[ (string) $code ] = is_array( $lang ) ? (string) ( $lang['default_locale'] ?? '' ) : '';
		}
		if ( '' === $default || ! $langs ) {
			return;
		}
		$plan = self::slug_plan( Loader::types(), Registrar::registered(), $default, $langs );
		$hash = md5( (string) wp_json_encode( array( $plan, $default ) ) );
		if ( get_option( self::SLUGS_OPTION ) === $hash ) {
			return;
		}
		foreach ( $plan as $item ) {
			$string = 'post' === $item['kind'] ? 'URL slug: ' . $item['name'] : 'URL ' . $item['name'] . ' tax slug';
			$id     = (int) icl_register_string( 'WordPress', $string, $item['original'], false, $default );
			do_action( 'wpml_activate_slug_translation', $item['name'], $item['original'], $item['kind'] );
			foreach ( $id ? $item['translations'] : array() as $code => $slug ) {
				icl_add_string_translation( $id, $code, $slug, defined( 'ICL_TM_COMPLETE' ) ? ICL_TM_COMPLETE : 10 );
			}
			// WPML caches each slug with its translations (per request, or for longer with an object cache).
			if ( class_exists( 'WPML_Slug_Translation_Records_Factory' ) ) {
				$records = ( new \WPML_Slug_Translation_Records_Factory() )->create( $item['kind'] );
				if ( $records && method_exists( $records, 'flush_cache' ) ) {
					$records->flush_cache();
				}
			}
		}
		update_option( self::SLUGS_OPTION, $hash, true );
	}

	/**
	 * The WPML config of the declared types, in the shape WPML builds from
	 * the XML (WPML_XML2Array): each entry is { value, attr }. Pure.
	 *
	 * @param array<string,array> $types Normalized definitions.
	 * @return array{custom-fields:array{custom-field:array},custom-types:array{custom-type:array},taxonomies:array{taxonomy:array}}
	 */
	public static function config( array $types ): array {
		$fields = array();
		$ctypes = array();
		$taxes  = array();
		foreach ( $types as $name => $def ) {
			$ctypes[] = array( 'value' => $name, 'attr' => self::MODE[ $def['wpml'] ] );
			foreach ( $def['taxonomies'] as $tax ) {
				if ( is_array( $tax ) ) {
					$taxes[] = array( 'value' => $tax['name'], 'attr' => self::MODE[ $tax['wpml'] ] );
				}
			}
			foreach ( $def['fields'] as $field ) {
				$attr = array( 'action' => $field['wpml'] );
				if ( 'copy' === $field['wpml'] && in_array( $field['type'], array( 'image', 'relation' ), true ) ) {
					$attr['type'] = 'post-ids';
					$target       = 'image' === $field['type'] ? array( 'attachment' ) : $field['post_type'];
					if ( 1 === count( $target ) ) {
						$attr['sub-type'] = $target[0];
					}
				}
				// The same meta key in two types: one entry (WPML's setting is per key, not per type).
				$fields[ $field['name'] ] ??= array( 'value' => $field['name'], 'attr' => $attr );
			}
		}
		return array(
			'custom-fields' => array( 'custom-field' => array_values( $fields ) ),
			'custom-types'  => array( 'custom-type' => $ctypes ),
			'taxonomies'    => array( 'taxonomy' => $taxes ),
		);
	}

	/**
	 * Adds config() to what WPML gathered from the XML files. Pure apart from
	 * Loader::types() (the second argument is for the test).
	 *
	 * @param mixed $config_all ['wpml-config' => [ 'custom-fields' => [ 'custom-field' => [ … ] ], … ]]
	 */
	public static function filter_config( $config_all, ?array $types = null ) {
		if ( ! is_array( $config_all ) ) {
			return $config_all;
		}
		// Only what this module registered: a type rejected because someone else had the name is not ours to configure.
		$ours = self::config( $types ?? array_intersect_key( Loader::types(), array_flip( Registrar::registered() ) ) );
		foreach ( $ours as $plural => $section ) {
			foreach ( $section as $singular => $entries ) {
				$current = $config_all['wpml-config'][ $plural ][ $singular ] ?? array();
				// One entry from a single XML element arrives as the entry itself, not a list.
				if ( isset( $current['value'] ) ) {
					$current = array( $current );
				}
				$config_all['wpml-config'][ $plural ][ $singular ] = array_merge( (array) $current, $entries );
			}
		}
		return $config_all;
	}

	/**
	 * See the header: a «copy» field changed in the ORIGINAL is copied now.
	 *
	 * @param int|int[] $meta_id Unused (an array in deleted_post_meta).
	 */
	public static function sync( $meta_id, $post_id, $meta_key ): void {
		if ( self::$syncing || ! self::active() ) {
			return;
		}
		$type = (string) get_post_type( (int) $post_id );
		$def  = Loader::type( $type );
		if ( ! $def || ! in_array( $meta_key, self::copy_keys( $def ), true ) || self::is_translation( $type, (int) $post_id ) ) {
			return;
		}
		self::$syncing = true;
		try {
			do_action( 'wpml_sync_custom_field', (int) $post_id, $meta_key );
		} finally {
			self::$syncing = false;
		}
	}

	/** @return string[] The «copy» fields of a type. */
	public static function copy_keys( array $def ): array {
		return array_column( array_filter( $def['fields'], static fn( $f ) => 'copy' === $f['wpml'] ), 'name' );
	}

	/** Registrar::maybe_flush: the definitions changed, WPML has to read them again. */
	public static function definitions_changed(): void {
		if ( self::active() ) {
			update_option( self::PENDING_OPTION, 1, false );
		}
	}

	public static function import_if_pending(): void {
		if ( ! get_option( self::PENDING_OPTION ) || ! class_exists( 'WPML_Config' ) || ! method_exists( 'WPML_Config', 'import_now' ) ) {
			return;
		}
		delete_option( self::PENDING_OPTION );
		\WPML_Config::import_now();
	}

	public static function active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/** With WPML: whether this post is the translation of another (not the original). */
	public static function is_translation( string $type, int $post_id ): bool {
		if ( ! self::active() || $post_id <= 0 ) {
			return false;
		}
		$details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $post_id, 'element_type' => 'post_' . $type ) );
		return is_object( $details ) && ! empty( $details->source_language_code );
	}
}
