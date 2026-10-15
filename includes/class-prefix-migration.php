<?php
/**
 * THE PREFIX MIGRATOR (1.2.0): the post types and taxonomies of the site
 * module go from «client», «client_sector»… to «eweb_client»,
 * «eweb_client_sector»… (the whole map comes from Type_Names,
 * modules/site/includes/class-type-names.php: the prefix lives ONLY there).
 * IN PLACE and without reloading content. Public URLs do not change (they
 * come from the slug, not the name).
 *
 * What it does, in this order (each step says what it would change and how
 * many):
 *   1. types       wp_posts.post_type, every row (drafts and trash too;
 *                  revisions are «revision» and are not touched).
 *   2. taxonomies  wp_term_taxonomy.taxonomy. The term_ids do NOT change.
 *   3. content     post_content of everything except revisions and
 *                  attachments, ONLY inside a block's comment: core/query
 *                  ("postType" and the "taxQuery" keys, with or without
 *                  "include"/"exclude"), core/post-terms ("term") and
 *                  core/navigation-link/-submenu ("type"). Straight to the
 *                  table, with no revision or save hooks. For each changed
 *                  post it fires estrategia_web_prefix_migration_post
 *                  (id, before, after, dry): that way a site that stores
 *                  fingerprints of its content load (Lavandería:
 *                  despliegue/prefijo-tipos.php) refreshes them and the
 *                  change does NOT count as a client edit.
 *   4. templates   wp_template / wp_template_part named single-client,
 *                  archive-client, taxonomy-client_sector(-slug)…
 *   5. menus       classic menu items pointing to a post type, its archive
 *                  or a taxonomy (_menu_item_object).
 *   6. wpml        icl_translations (element_type post_client → post_eweb_client,
 *                  tax_client_sector → tax_eweb_client_sector), the settings
 *                  for translatable post types and taxonomies
 *                  (SETTINGS_SECTIONS, only KEYS and only in those sections)
 *                  in the THREE places where WPML 5.1 stores them: the whole
 *                  icl_sitepress_settings, its split copies
 *                  icl_sitepress_settings#<section> and WPML(setup…)
 *                  (WPML_OPTIONS); and the slug translation strings
 *                  («URL slug: client»).
 *   7. rank math   the rank-math-options-* options per post type and taxonomy
 *                  (pt_client_… / tax_client_sector_… and the value of
 *                  pt_…_primary_taxonomy), rank_math_known_post_types and the
 *                  rank_math_primary_<tax> meta.
 *   8. screens     each user's per-type preferences (hidden columns, items
 *                  per page, closed boxes): cosmetic.
 *   9. warnings    what it CANNOT migrate and names an old type: options with
 *                  the name as a serialized string. They are listed to be
 *                  checked by hand; they are not touched.
 *  10. permalinks  the URL rules are rebuilt in the same run (see the /en/
 *                  trap in run()) and the cache is flushed.
 * The estrategia_web_site option does NOT change: enabled_types and
 * rewrite_slugs speak in KEYS (see the header of Type_Names).
 *
 * How to use it:
 *   - WP-CLI, with plugin 1.2.0 active: wp estrategia-web migrate-prefix [--dry-run]
 *   - Without its own WP-CLI command (Lavandería): include this file and
 *     class-type-names.php from «wp eval-file» and call
 *     Prefix_Migration::run( $dry ). See
 *     lavanderia-mallorca/despliegue/prefijo-tipos.php, which also refreshes
 *     the content-load fingerprints.
 *   It does NOT run by itself on activation or update: it is run with a
 *   backup and after having looked at the dry run first. Until it runs, the
 *   site with plugin 1.2.0 cannot find its clients, testimonials or
 *   locations (the deployer runs it right after uploading the plugin).
 *
 * IDEMPOTENT: each step looks for the old names; the second run changes
 * nothing. A dry run writes nothing.
 *
 * How to maintain:
 * - Who: whoever touches the post types. When: if another place where a
 *   type name is stored turns up (step 9 will say so in a site's dry run),
 *   it is added as a step and to its test
 *   (modules/site/tests/prefix-migration.test.mjs).
 * - It is removed in 2.0.0, along with Unprefixed_Names, once every site has
 *   gone through it (15-10-2026: only Lavandería Mallorca).
 * - Edge case, by name: ANOTHER PLUGIN WITH A «client» POST TYPE. If, when
 *   migrating for real, the old name is still registered (not by us: plugin
 *   1.2.0 no longer registers it), its rows belong to that plugin: the type
 *   or taxonomy is skipped and a warning is emitted. On a dry run this is not
 *   checked, because there the old plugin is still running, and it does
 *   register it.
 * - Edge case: a post whose content says «client» in the text. It is not
 *   touched: changes are made only inside the JSON of the comment of the four
 *   blocks, and only in the keys listed.
 * - The trap: WPML stores its settings as a big array with keys that may be
 *   named the same as a post type in another section (e.g. «location» in the
 *   language switcher one). That is why keys are only renamed inside
 *   SETTINGS_SECTIONS, and never values.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Type_Names::class ) ) {
	require_once dirname( __DIR__ ) . '/modules/site/includes/class-type-names.php';
}

final class Prefix_Migration {

	/** Sections of icl_sitepress_settings whose KEYS are post types or taxonomies. */
	public const SETTINGS_SECTIONS = array(
		'custom_posts_sync_option',
		'custom_posts_unlocked_option',
		'taxonomies_sync_option',
		'taxonomies_unlocked_option',
	);

	/**
	 * Other WPML 5 options with post types or taxonomies as KEYS: option =>
	 * [ path inside the value => 'types' | 'taxes' ]. Measured on Lavandería on
	 * 15-10-2026: WPML 5.1 stores its settings TWICE, whole in
	 * icl_sitepress_settings and split in icl_sitepress_settings#<section>
	 * ({"v": …}); and the «translate everything» settings in WPML(setup) and
	 * WPML(setup/…). All of them are migrated.
	 */
	private const WPML_OPTIONS = array(
		'WPML(setup)'                                  => array( 'translate-everything-taxonomies' => 'taxes', 'translate-everything-posts' => 'types' ),
		'WPML(setup/translate-everything-taxonomies)' => array( 'v' => 'taxes' ),
		'WPML(setup/translate-everything-posts)'      => array( 'v' => 'types' ),
	);

	/** @return array<string, array<string,string>> WPML option => [ path => types|taxes ]. */
	private static function wpml_targets(): array {
		$out = array( 'icl_sitepress_settings' => array() );
		foreach ( self::SETTINGS_SECTIONS as $section ) {
			$kind                                       = str_starts_with( $section, 'taxonomies' ) ? 'taxes' : 'types';
			$out['icl_sitepress_settings'][ $section ]  = $kind;
			$out[ 'icl_sitepress_settings#' . $section ] = array( 'v' => $kind );
		}
		return $out + self::WPML_OPTIONS;
	}

	/**
	 * Renames the KEYS that are old names inside an option, at the given
	 * paths. Reads and writes the row as is (no get_option/update_option:
	 * WPML filters its own). Idempotent; if both the old and the new one are
	 * there, the new one stays and a warning is emitted.
	 *
	 * @param array<string,string> $paths Path (top-level key) => 'types' | 'taxes'.
	 * @param array<string,string> $types
	 * @param array<string,string> $taxes
	 * @param string               $step  Where it shows up in the report.
	 * @param bool                 $values Also the VALUES equal to the key (rank_math_known_post_types: «client» => «client»).
	 */
	private static function rename_keys_in_option( string $option, array $paths, array $types, array $taxes, string $step, bool $values = false ): void {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore
		if ( null === $raw ) {
			return;
		}
		$value = maybe_unserialize( $raw );
		if ( ! is_array( $value ) ) {
			return;
		}
		$new = $value;
		foreach ( $paths as $path => $kind ) {
			$map  = 'taxes' === $kind ? $taxes : $types;
			$list = '' === $path ? $value : ( $value[ $path ] ?? null );
			if ( ! is_array( $list ) ) {
				continue;
			}
			$out = array();
			foreach ( $list as $key => $item ) {
				if ( is_string( $key ) && isset( $map[ $key ] ) ) {
					if ( array_key_exists( $map[ $key ], $list ) ) {
						self::$report['avisos'][] = "$option" . ( '' === $path ? '' : "[$path]" ) . ": both «{$key}» and «{$map[ $key ]}» are present; the new one stays";
						continue;
					}
					self::$report[ $step ][]   = "$option" . ( '' === $path ? '' : "[$path]" ) . ": $key → {$map[ $key ]}";
					$out[ $map[ $key ] ]       = ( $values && $item === $key ) ? $map[ $key ] : $item;
					continue;
				}
				$out[ $key ] = $item;
			}
			if ( '' === $path ) {
				$new = $out;
			} else {
				$new[ $path ] = $out;
			}
		}
		if ( $new !== $value && ! self::$dry ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $new ) ), array( 'option_name' => $option ) ); // phpcs:ignore
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/** Blocks, and attributes of their JSON, that store a post type or taxonomy name. */
	private const BLOCK_KEYS = array(
		'query'              => array( 'postType' ),
		'post-terms'         => array( 'term' ),
		'navigation-link'    => array( 'type' ),
		'navigation-submenu' => array( 'type' ),
	);

	/** Per-type screen preferences (usermeta); %s = the post type. */
	private const USER_META = array( 'edit_%s_per_page', 'manageedit-%scolumnshidden', 'meta-box-order_%s', 'closedpostboxes_%s', 'metaboxhidden_%s', 'screen_layout_%s' );

	private static bool $dry = true;

	/** @var array<string, mixed> */
	private static array $report = array();

	/**
	 * Runs the migration. Returns what was done (or what it would do, on a dry run).
	 *
	 * @return array<string, mixed>
	 */
	public static function run( bool $dry_run ): array {
		self::$dry    = $dry_run;
		self::$report = array(
			'seco'       => $dry_run,
			'prefijo'    => Type_Names::PREFIX,
			'tipos'      => array(),
			'taxonomias' => array(),
			'contenido'  => array(),
			'plantillas' => array(),
			'menus'      => array(),
			'wpml'       => array(),
			'rankmath'   => array(),
			'pantallas'  => array(),
			'avisos'     => array(),
		);
		$types = self::types();
		$taxes = self::taxonomies();
		self::content( $types + $taxes );
		self::templates( $types, $taxes );
		self::menus( $types + $taxes );
		self::wpml( $types, $taxes );
		self::rank_math( $types, $taxes );
		self::screens( $types );
		self::leftovers( $types + $taxes );
		if ( ! self::$dry ) {
			// The rules are REBUILT here, not deleted: if they are deleted, the next
			// request rebuilds them, and if it is an /en/ one (WPML switches the
			// language) the translated slugs come out in English (/locations/) and
			// /sedes/ gives a 404 for everyone. Measured in the Lavandería rehearsal
			// of 15-10-2026. Here they run with the site language (WP-CLI) and with
			// the new post types already registered.
			flush_rewrite_rules( false );
			wp_cache_flush();
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
			}
		}
		return self::$report;
	}

	// ------------------------------------------------------------ the renaming

	/**
	 * Block markup with the new names, only inside the JSON of the blocks in
	 * BLOCK_KEYS.
	 *
	 * @param array<string,string> $map   Old → new.
	 * @param int|null             $count Changes made, by reference.
	 */
	public static function rename_markup( string $html, array $map, ?int &$count = null ): string {
		$count = 0;
		if ( ! $map || ! str_contains( $html, '<!-- wp:' ) ) {
			return $html;
		}
		$names = implode( '|', array_map( 'preg_quote', array_keys( $map ) ) );
		return (string) preg_replace_callback(
			'#<!--\s+wp:(?:core/)?(query|post-terms|navigation-link|navigation-submenu)\s+(\{.*?\})\s+(/?)-->#s',
			static function ( $m ) use ( $map, $names, &$count ) {
				$json = $m[2];
				foreach ( self::BLOCK_KEYS[ $m[1] ] as $attr ) {
					$json = preg_replace_callback(
						'#("' . $attr . '"\s*:\s*")(' . $names . ')"#',
						static function ( $k ) use ( $map, &$count ) {
							++$count;
							return $k[1] . $map[ $k[2] ] . '"';
						},
						$json
					);
				}
				if ( 'query' === $m[1] ) {
					// The taxQuery keys: "client_sector":[…] (also inside "include"/"exclude").
					$json = preg_replace_callback(
						'#("taxQuery"\s*:\s*\{)(.*?)(\}\s*[,}])#s',
						static function ( $t ) use ( $map, $names, &$count ) {
							$inner = preg_replace_callback(
								'#"(' . $names . ')"(\s*:\s*\[)#',
								static function ( $k ) use ( $map, &$count ) {
									++$count;
									return '"' . $map[ $k[1] ] . '"' . $k[2];
								},
								$t[2]
							);
							return $t[1] . $inner . $t[3];
						},
						$json
					);
				}
				return str_replace( $m[2], $json, $m[0] );
			},
			$html
		);
	}

	// ------------------------------------------------------------------- steps

	/** @return array<string,string> The post types being migrated (old → new). */
	private static function types(): array {
		global $wpdb;
		$done = array();
		foreach ( Type_Names::renamed_types() as $old => $new ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $n ) {
				$done[ $old ] = $new; // Nothing in wp_posts, but it may remain in settings (WPML, Rank Math).
				continue;
			}
			if ( ! self::$dry && post_type_exists( $old ) ) {
				self::$report['avisos'][] = "post type $old: there are $n posts but «$old» is registered by another plugin; not touched";
				continue;
			}
			self::$report['tipos'][] = "$old → $new: $n entradas";
			$done[ $old ]            = $new;
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->posts, array( 'post_type' => $new ), array( 'post_type' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		return $done;
	}

	/** @return array<string,string> The taxonomies being migrated (old → new). */
	private static function taxonomies(): array {
		global $wpdb;
		$done = array();
		foreach ( Type_Names::renamed_taxonomies() as $old => $new ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $n ) {
				$done[ $old ] = $new;
				continue;
			}
			if ( ! self::$dry && taxonomy_exists( $old ) ) {
				self::$report['avisos'][] = "taxonomy $old: there are $n terms but «$old» is registered by another plugin; not touched";
				continue;
			}
			self::$report['taxonomias'][] = "$old → $new: $n términos";
			$done[ $old ]                 = $new;
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => $new ), array( 'taxonomy' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		if ( ! self::$dry ) {
			wp_cache_flush_group( 'terms' );
			wp_cache_delete( 'last_changed', 'terms' );
		}
		return $done;
	}

	/** @param array<string,string> $map */
	private static function content( array $map ): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_type, post_name, post_status, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND ( post_content LIKE '%wp:query%' OR post_content LIKE '%wp:post-terms%' OR post_content LIKE '%wp:navigation-%' ) ORDER BY ID", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$new = self::rename_markup( $row['post_content'], $map, $count );
			if ( $new === $row['post_content'] ) {
				continue;
			}
			$lang                        = apply_filters( 'wpml_post_language_details', null, (int) $row['ID'] );
			$entry                       = array(
				'id'      => (int) $row['ID'],
				'tipo'    => $row['post_type'],
				'slug'    => $row['post_name'],
				'estado'  => $row['post_status'],
				'idioma'  => is_array( $lang ) ? ( $lang['language_code'] ?? '' ) : '',
				'cambios' => $count,
			);
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row['ID'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $row['ID'] );
			}
			/**
			 * A post whose content the migrator changes (also on a dry run,
			 * without writing). Whatever the hook returns in 'nota' shows up in
			 * the report.
			 *
			 * @param array  $entry What shows up in the report (id, tipo, slug…).
			 * @param string $old   The content before.
			 * @param string $new   The content after.
			 * @param bool   $dry   Dry run.
			 */
			self::$report['contenido'][] = apply_filters( 'estrategia_web_prefix_migration_post', $entry, $row['post_content'], $new, self::$dry );
		}
	}

	/**
	 * @param array<string,string> $types
	 * @param array<string,string> $taxes
	 */
	private static function templates( array $types, array $taxes ): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_type, post_name FROM {$wpdb->posts} WHERE post_type IN ('wp_template','wp_template_part') AND ( post_name LIKE 'single-%' OR post_name LIKE 'archive-%' OR post_name LIKE 'taxonomy-%' )", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$new = $row['post_name'];
			foreach ( array( 'single' => $types, 'archive' => $types, 'taxonomy' => $taxes ) as $kind => $map ) {
				foreach ( $map as $old => $to ) {
					if ( preg_match( '#^' . $kind . '-' . preg_quote( $old, '#' ) . '(-.*)?$#', $row['post_name'], $m ) ) {
						$new = $kind . '-' . $to . ( $m[1] ?? '' );
					}
				}
			}
			if ( $new === $row['post_name'] ) {
				continue;
			}
			self::$report['plantillas'][] = "#{$row['ID']} {$row['post_type']} {$row['post_name']} → $new";
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->posts, array( 'post_name' => $new ), array( 'ID' => (int) $row['ID'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $row['ID'] );
			}
		}
	}

	/** @param array<string,string> $map */
	private static function menus( array $map ): void {
		global $wpdb;
		foreach ( $map as $old => $new ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object' AND meta_value = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $ids ) {
				continue;
			}
			self::$report['menus'][] = "_menu_item_object $old → $new: " . count( $ids ) . ' menu items';
			if ( ! self::$dry ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_menu_item_object' AND meta_value = %s", $new, $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				wp_cache_flush_group( 'post_meta' );
			}
		}
	}

	/**
	 * @param array<string,string> $types
	 * @param array<string,string> $taxes
	 */
	private static function wpml( array $types, array $taxes ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			foreach ( array( 'post_' => $types, 'tax_' => $taxes ) as $kind => $map ) {
				foreach ( $map as $old => $new ) {
					$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE element_type = %s", $kind . $old ) ); // phpcs:ignore
					if ( ! $n ) {
						continue;
					}
					self::$report['wpml'][] = "icl_translations {$kind}{$old} → {$kind}{$new}: $n rows";
					if ( ! self::$dry ) {
						$wpdb->update( $table, array( 'element_type' => $kind . $new ), array( 'element_type' => $kind . $old ) ); // phpcs:ignore
					}
				}
			}
		}
		// WPML settings: keys that are post types or taxonomies, in every place
		// where WPML 5 stores them (see WPML_OPTIONS).
		foreach ( self::wpml_targets() as $option => $paths ) {
			self::rename_keys_in_option( $option, $paths, $types, $taxes, 'wpml' );
		}
		// WPML slug translation: «URL slug: client» strings in the WordPress context.
		$strings = $wpdb->prefix . 'icl_strings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $strings ) ) === $strings ) {
			foreach ( $types + $taxes as $old => $new ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, context, name, gettext_context FROM `$strings` WHERE name = %s", 'URL slug: ' . $old ), ARRAY_A ); // phpcs:ignore
				foreach ( $rows as $row ) {
					$name                   = 'URL slug: ' . $new;
					self::$report['wpml'][] = "icl_strings #{$row['id']} «{$row['name']}» → «{$name}»";
					if ( ! self::$dry ) {
						$wpdb->update( $strings, array( 'name' => $name, 'domain_name_context_md5' => md5( $row['context'] . $name . $row['gettext_context'] ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore
					}
				}
			}
		}
	}

	/**
	 * @param array<string,string> $types
	 * @param array<string,string> $taxes
	 */
	private static function rank_math( array $types, array $taxes ): void {
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rank-math-options-%'" ); // phpcs:ignore
		foreach ( $names as $option ) {
			$value = get_option( $option );
			if ( ! is_array( $value ) ) {
				continue;
			}
			$out = array();
			$n   = 0;
			foreach ( $value as $key => $item ) {
				$new_key = $key;
				foreach ( array( 'pt_' => $types, 'tax_' => $taxes ) as $kind => $map ) {
					foreach ( $map as $old => $new ) {
						if ( str_starts_with( (string) $key, $kind . $old . '_' ) ) {
							$new_key = $kind . $new . substr( (string) $key, strlen( $kind . $old ) );
						}
					}
				}
				if ( str_ends_with( (string) $new_key, '_primary_taxonomy' ) && is_string( $item ) && isset( $taxes[ $item ] ) ) {
					$item = $taxes[ $item ];
					++$n;
				}
				if ( $new_key !== $key ) {
					if ( array_key_exists( $new_key, $value ) ) {
						continue; // The new one is already there: the new one stays.
					}
					++$n;
				}
				$out[ $new_key ] = $item;
			}
			if ( ! $n ) {
				continue;
			}
			self::$report['rankmath'][] = "$option: $n per-type or per-taxonomy settings";
			if ( ! self::$dry ) {
				update_option( $option, $out );
			}
		}
		// The post types Rank Math already knows («client» => «client»): without this
		// it would see the new ones as newcomers and give them its default values.
		self::rename_keys_in_option( 'rank_math_known_post_types', array( '' => 'types' ), $types, $taxes, 'rankmath', true );
		foreach ( $taxes as $old => $new ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", 'rank_math_primary_' . $old ) ); // phpcs:ignore
			if ( ! $n ) {
				continue;
			}
			self::$report['rankmath'][] = "meta rank_math_primary_$old → rank_math_primary_$new: $n";
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_key' => 'rank_math_primary_' . $new ), array( 'meta_key' => 'rank_math_primary_' . $old ) ); // phpcs:ignore
				wp_cache_flush_group( 'post_meta' );
			}
		}
	}

	/** @param array<string,string> $types */
	private static function screens( array $types ): void {
		global $wpdb;
		foreach ( $types as $old => $new ) {
			foreach ( self::USER_META as $pattern ) {
				$from = sprintf( $pattern, $old );
				$to   = sprintf( $pattern, $new );
				$n    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $from ) ); // phpcs:ignore
				if ( ! $n ) {
					continue;
				}
				self::$report['pantallas'][] = "$from → $to: $n users";
				if ( ! self::$dry ) {
					$wpdb->update( $wpdb->usermeta, array( 'meta_key' => $to ), array( 'meta_key' => $from ) ); // phpcs:ignore
					wp_cache_flush_group( 'user_meta' );
				}
			}
		}
	}

	/**
	 * Options that name an old post type as a serialized string and that this
	 * migrator CANNOT migrate: they are only listed (step 9).
	 *
	 * @param array<string,string> $map
	 */
	private static function leftovers( array $map ): void {
		global $wpdb;
		// estrategia_web_site speaks in keys on purpose (Type_Names): it is not a leftover.
		// Nor are the ones migrated by steps 6 and 7: on a dry run they still name them.
		$skip  = array_merge( array( 'rewrite_rules', 'estrategia_web_site', 'rank_math_known_post_types' ), array_keys( self::wpml_targets() ) );
		$like  = array();
		$regex = array();
		foreach ( array_keys( $map ) as $old ) {
			$like[]  = $wpdb->prepare( 'option_value LIKE %s', '%"' . $wpdb->esc_like( $old ) . '"%' );
			$regex[] = preg_quote( $old, '#' );
		}
		if ( ! $like ) {
			return;
		}
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '%transient%' AND option_name NOT LIKE 'rank-math-options-%' AND ( " . implode( ' OR ', $like ) . ' )', ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			if ( in_array( $row['option_name'], $skip, true ) ) {
				continue;
			}
			if ( preg_match_all( '#s:\d+:"(' . implode( '|', $regex ) . ')";#', $row['option_value'], $m ) ) {
				self::$report['avisos'][] = "option {$row['option_name']}: names " . implode( ', ', array_unique( $m[1] ) ) . ' (not migrated; check it)';
			}
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'estrategia-web migrate-prefix',
		static function ( $args, $assoc ) {
			$report = Prefix_Migration::run( ! empty( $assoc['dry-run'] ) );
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		},
		array( 'shortdesc' => 'Moves the post types and taxonomies of the site module to the 1.2.0 prefixed names (wp_posts, terms, content, templates, menus, WPML, Rank Math). With --dry-run it only counts.' )
	);
}
