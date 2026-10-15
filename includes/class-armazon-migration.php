<?php
/**
 * THE MIGRATOR from «Armazón» to «Estrategia Web»: moves a site built with the
 * Armazón parent theme and the armazon-site / armazon-forms plugins to the
 * 1.1.0 structure (estrategia-web parent theme + the SINGLE estrategia-web
 * plugin), IN PLACE, without reloading content. The public names (blocks,
 * options, metas, tables) are those of 1.0.0; what changed in 1.1.0 is only
 * the plugin folder, so a site that was already on 1.0.0
 * (estrategia-web-site + estrategia-web-forms) also goes through here: only
 * step 7 applies to it.
 *
 * What it does, in this order (each step says what it would change and how
 * many):
 *   1. content    post_content of every post (pages, posts, synced patterns
 *                 wp_block, wp_template, wp_template_part, wp_navigation,
 *                 wp_global_styles, the plugin's post types and their WPML
 *                 translations, which are posts): armazon/…, armazon-site/…
 *                 and armazon-forms/… blocks (and armazon/consent →
 *                 estrategia-web-site/consent), their classes, binding
 *                 sources, shortcodes, data-armazon-… attributes,
 *                 "theme":"armazon" of the template parts and the renamed meta
 *                 keys in core/post-meta bindings. Revisions are NOT touched:
 *                 they are history (if one is restored, the aliases render it).
 *   2. metas      those of the client, testimonial and location post types
 *                 (location_static_map… → estrategia_web_location_static_map…).
 *   3. options    armazon_* → estrategia_web_* (company details, forms,
 *                 WhatsApp…), theme_mods_armazon →
 *                 theme_mods_estrategia-web, and inside any other option that
 *                 names something old (WPML stores settings per theme and per
 *                 plugin) the texts and keys that are old names. Old
 *                 transients are deleted (they are cache).
 *   4. tables     {prefix}armazon_forms_submissions and …_definitions →
 *                 estrategia_web_forms_…: ALTER TABLE … RENAME TO, submissions
 *                 are not touched. (Not «RENAME TABLE»: Playground's SQLite
 *                 does not understand it and it failed SILENTLY; ALTER TABLE
 *                 works on both. Afterwards it checks that the new one exists:
 *                 if not, a warning.)
 *                 If the new one already exists (someone activated the new
 *                 plugin first) and is empty, the rows are copied and the old
 *                 one is left.
 *   5. wpml       String Translation strings with an old context or name
 *                 (armazon-forms, [armazon_forms_settings]…), recomputing
 *                 their domain_name_context_md5; their translations hang from
 *                 the id and are not lost.
 *   6. theme      template/stylesheet armazon → estrategia-web and the
 *                 wp_theme term «armazon» of the custom templates.
 *   7. plugins    active_plugins: armazon-site/armazon-site.php,
 *                 armazon-forms/armazon-forms.php (and the two from 1.0.0,
 *                 estrategia-web-site/… and estrategia-web-forms/…) leave the
 *                 list and ONE comes in: estrategia-web/estrategia-web.php.
 *                 Without calling activation or deactivation hooks (options
 *                 and tables are already migrated; the forms tables and their
 *                 cron are recreated by the forms module on every load if
 *                 missing); the old cron event is removed. The old FOLDERS are
 *                 not touched: they are deleted by hand (or with the deployer)
 *                 once the site is already responding with the new plugin.
 *                 The module list (estrategia_web_modules) is NOT written:
 *                 without it, each module's «Default» applies.
 *   8. cache      the object cache and, if present, SiteGround's.
 *   The consent cookie is NOT migrated here (it lives in the browser):
 *   blocks/consent/view.js migrates it the first time each visitor returns.
 *
 * How to use it:
 *   - WP-CLI, with the new plugin active: wp estrategia-web migrate-armazon [--dry-run]
 *   - Without activating anything (what Lavandería does): include this file
 *     from «wp eval-file» and call Armazon_Migration::run( $dry ). See
 *     lavanderia-mallorca/despliegue/migrar-a-estrategia-web.mjs.
 *   It does NOT run by itself when the plugin is activated, on purpose: it
 *   renames tables and rewrites all the content, and that is done with a
 *   backup and after having looked at the dry run first.
 *
 * IDEMPOTENT: each step looks for the old names; running it twice changes
 * nothing the second time (Lavandería's test checks it). A dry run writes
 * nothing. Steps 6 and 7 are skipped if the new folders (themes/estrategia-web,
 * plugins/estrategia-web) are not on the server: the packages are uploaded
 * first.
 *
 * How to maintain:
 * - Who: whoever touches the renaming. When: if another place where an old
 *   name was stored turns up, it is added here and to its test.
 * - It is removed in 2.0.0, along with Legacy_Names, once every site that
 *   used Armazón has gone through it (07-10-2026: only Lavandería Mallorca).
 * - Edge case: a site text that says «armazon-» (not «armazón»). It would
 *   be renamed. Only tokens attached to «-», «_» or «/» are touched, which
 *   do not appear in normal text; the dry run lists them per post so they
 *   can be checked.
 * - The trap: WordPress stores theme_mods PER theme FOLDER. If the parent was
 *   active on its own (no child), its settings live in theme_mods_armazon
 *   and without step 3 they would be lost when the folder changes.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Armazon_Migration {

	public const THEME = array( 'armazon' => 'estrategia-web' );

	/** The single plugin (1.1.0). All the previous ones end up in it. */
	public const PLUGIN = 'estrategia-web/estrategia-web.php';

	public const PLUGINS = array(
		'armazon-site/armazon-site.php'                 => self::PLUGIN,
		'armazon-forms/armazon-forms.php'               => self::PLUGIN,
		'estrategia-web-site/estrategia-web-site.php'   => self::PLUGIN,
		'estrategia-web-forms/estrategia-web-forms.php' => self::PLUGIN,
	);

	/** Metas of the plugin's post types that gained a prefix in 1.0.0. */
	public const META = array( 'client_image_fit', 'testimonial_author', 'testimonial_rating', 'location_address', 'location_phone', 'location_email', 'location_schedule', 'location_map_url', 'location_map_embed', 'location_static_map', 'location_static_map_id' );

	/** Keys that only exist in the bindings (derived, not metas). */
	public const BINDING_KEYS = array( 'location_phone_href', 'location_email_href' );

	public const META_TYPES = array( 'client', 'testimonial', 'location' );

	public const TABLES = array( 'armazon_forms_submissions' => 'estrategia_web_forms_submissions', 'armazon_forms_definitions' => 'estrategia_web_forms_definitions' );

	public const OLD_CRON = array( 'armazon_forms_purge' );

	/** Options that skip the generic renaming (they have their own step). */
	private const OWN_STEP = array( 'active_plugins', 'template', 'stylesheet', 'theme_mods_armazon', 'rewrite_rules', 'cron' );

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
			'seco'      => $dry_run,
			'contenido' => array(),
			'metas'     => array(),
			'opciones'  => array(),
			'tablas'    => array(),
			'wpml'      => array(),
			'tema'      => array(),
			'plugins'   => array(),
			'avisos'    => array(),
		);
		self::content();
		self::metas();
		self::options();
		self::tables();
		self::wpml();
		self::theme();
		self::plugins();
		self::cache();
		return self::$report;
	}

	// ------------------------------------------------------------ the renaming

	/**
	 * A piece of markup with the new names.
	 *
	 * @param array{bloques: int, otros: int} $count Count, by reference.
	 */
	public static function rename_markup( string $html, ?array &$count = null ): string {
		$count = array( 'bloques' => 0, 'otros' => 0 );
		// 1. Block names in the comments (opening ones count as blocks).
		$html = preg_replace_callback(
			'#<!--(\s+)(/?)wp:(armazon(?:-site|-forms)?)/([a-z0-9-]+)#',
			static function ( $m ) use ( &$count ) {
				if ( '' === $m[2] ) {
					++$count['bloques'];
				}
				return '<!--' . $m[1] . $m[2] . 'wp:' . self::block_name( $m[3] . '/' . $m[4] );
			},
			$html
		);
		// 2. The class WordPress derives from the old alias of the cookies link.
		$html = str_replace( 'wp-block-armazon-consent', 'wp-block-estrategia-web-site-consent', $html, $n );
		$count['otros'] += $n;
		// 3. Renamed meta keys, only as the key of a binding.
		$keys = implode( '|', array_merge( self::META, self::BINDING_KEYS ) );
		$html = preg_replace( '#("key"\s*:\s*")(' . $keys . ')"#', '$1estrategia_web_$2"', $html, -1, $n );
		$count['otros'] += $n;
		// 4. Everything else: tokens attached to «-», «_» or «/» and the theme of the template parts.
		$html = preg_replace( '#"theme"\s*:\s*"armazon"#', '"theme":"estrategia-web"', $html, -1, $n );
		$count['otros'] += $n;
		$html = preg_replace_callback(
			'#(?<![\w-])armazon([-_/:])#',
			static fn( $m ) => ( '_' === $m[1] ? 'estrategia_web' : 'estrategia-web' ) . $m[1],
			$html,
			-1,
			$n
		);
		$count['otros'] += $n;
		return $html;
	}

	/** Old block name → new. */
	public static function block_name( string $old ): string {
		if ( 'armazon/consent' === $old ) {
			return 'estrategia-web-site/consent';
		}
		return preg_replace( '#^armazon(-site|-forms)?/#', 'estrategia-web$1/', $old );
	}

	/**
	 * Any value (unserialized option) with the new names, keys included.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function rename_value( $value ) {
		if ( is_string( $value ) ) {
			if ( isset( self::THEME[ $value ] ) ) {
				return self::THEME[ $value ];
			}
			if ( isset( self::PLUGINS[ $value ] ) ) {
				return self::PLUGINS[ $value ];
			}
			return self::rename_markup( $value );
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_string( $key ) ? self::rename_value( $key ) : $key ] = self::rename_value( $item );
			}
			return $out;
		}
		if ( is_object( $value ) && ! $value instanceof \__PHP_Incomplete_Class ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = self::rename_value( $item );
			}
		}
		return $value;
	}

	// ------------------------------------------------------------------- steps

	private static function content(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_type, post_name, post_status, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND post_content LIKE '%armazon%' ORDER BY ID", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $row ) {
			$new = self::rename_markup( $row['post_content'], $count );
			if ( $new === $row['post_content'] ) {
				continue;
			}
			$lang                            = apply_filters( 'wpml_post_language_details', null, (int) $row['ID'] );
			self::$report['contenido'][] = array(
				'id'      => (int) $row['ID'],
				'tipo'    => $row['post_type'],
				'slug'    => $row['post_name'],
				'estado'  => $row['post_status'],
				'idioma'  => is_array( $lang ) ? ( $lang['language_code'] ?? '' ) : '',
				'bloques' => $count['bloques'],
				'otros'   => $count['otros'],
			);
			if ( ! self::$dry ) {
				// Straight to the table: wp_update_post() would create a revision and go
				// through kses and the save hooks (WPML, Rank Math) for nothing.
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row['ID'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $row['ID'] );
			}
		}
	}

	private static function metas(): void {
		global $wpdb;
		// With and without the 1.2.0 prefix (Type_Names): it does not matter which of the two migrators runs first.
		$all   = self::META_TYPES;
		if ( class_exists( Type_Names::class ) ) {
			$all = array_merge( $all, array_map( array( Type_Names::class, 'name' ), self::META_TYPES ) );
		}
		$types = "'" . implode( "','", $all ) . "'";
		foreach ( self::META as $key ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type IN ($types)", $key ) ); // phpcs:ignore
			if ( ! $n ) {
				continue;
			}
			self::$report['metas'][] = sprintf( '%s → estrategia_web_%s: %d', $key, $key, $n );
			if ( ! self::$dry ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id SET m.meta_key = %s WHERE m.meta_key = %s AND p.post_type IN ($types)", 'estrategia_web_' . $key, $key ) ); // phpcs:ignore
			}
		}
		if ( ! self::$dry && self::$report['metas'] ) {
			wp_cache_flush_group( 'post_meta' );
		}
	}

	private static function options(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%armazon%' OR option_value LIKE '%armazon%'", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$name = $row['option_name'];
			if ( in_array( $name, self::OWN_STEP, true ) || str_ends_with( $name, 'theme_roots' ) ) {
				continue;
			}
			// Not theme_roots: it lists the folders in themes/, and the old one stays there until deleted by hand.
			if ( preg_match( '#^_(site_)?transient_#', $name ) && ! str_ends_with( $name, 'theme_roots' ) ) {
				// Caches (WPML's, the theme's patterns…) that name something old:
				// they are deleted and whoever made them rebuilds them with the new names.
				self::$report['opciones'][] = "$name: deleted (cache)";
				if ( ! self::$dry ) {
					$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore
				}
				continue;
			}
			$value    = maybe_unserialize( $row['option_value'] );
			$new_name = preg_match( '#^armazon_#', $name ) ? 'estrategia_web_' . substr( $name, 8 ) : $name;
			$new      = self::rename_value( $value );
			if ( $new_name !== $name ) {
				if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $new_name ) ) ) { // phpcs:ignore
					self::$report['avisos'][] = "$name: $new_name already exists; not overwritten (the old one stays)";
					continue;
				}
				self::$report['opciones'][] = "$name → $new_name";
				if ( ! self::$dry ) {
					$wpdb->update( $wpdb->options, array( 'option_name' => $new_name, 'option_value' => maybe_serialize( $new ) ), array( 'option_name' => $name ) ); // phpcs:ignore
				}
				continue;
			}
			if ( $new !== $value ) {
				self::$report['opciones'][] = "$name: old names inside the value";
				if ( ! self::$dry ) {
					$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $new ) ), array( 'option_name' => $name ) ); // phpcs:ignore
				}
			}
		}
		// theme_mods: stored per theme folder (see the trap in the header).
		$mods = get_option( 'theme_mods_armazon', null );
		if ( null !== $mods ) {
			if ( null !== get_option( 'theme_mods_estrategia-web', null ) ) {
				self::$report['avisos'][] = 'theme_mods_armazon: theme_mods_estrategia-web already exists; not overwritten';
			} else {
				self::$report['opciones'][] = 'theme_mods_armazon → theme_mods_estrategia-web';
				if ( ! self::$dry ) {
					update_option( 'theme_mods_estrategia-web', self::rename_value( $mods ) );
					delete_option( 'theme_mods_armazon' );
				}
			}
		}
		if ( ! self::$dry ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	private static function tables(): void {
		global $wpdb;
		foreach ( self::TABLES as $old => $new ) {
			$old_t = $wpdb->prefix . $old;
			$new_t = $wpdb->prefix . $new;
			$has   = static fn( $t ) => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
			if ( ! $has( $old_t ) ) {
				continue;
			}
			$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$old_t`" ); // phpcs:ignore
			if ( ! $has( $new_t ) ) {
				self::$report['tablas'][] = "$old_t → $new_t (renamed, $rows rows)";
				if ( ! self::$dry ) {
					$wpdb->query( "ALTER TABLE `$old_t` RENAME TO `$new_t`" ); // phpcs:ignore
					if ( ! $has( $new_t ) && self::create_forms_tables() && $has( $new_t ) ) {
						// Plan B (Playground's SQLite does not rename tables): create them with
						// the new plugin's migration and copy the rows. The old one stays.
						$wpdb->query( "INSERT INTO `$new_t` SELECT * FROM `$old_t`" ); // phpcs:ignore
						self::$report['avisos'][] = "$old_t: could not be renamed; $new_t has been created and the rows COPIED (the old one stays: delete it by hand once checked)";
					}
					if ( ! $has( $new_t ) || $rows !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$new_t`" ) ) { // phpcs:ignore
						self::$report['avisos'][] = "$old_t: NOT renamed or copied ({$wpdb->last_error})";
					}
				}
				continue;
			}
			$present = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$new_t`" ); // phpcs:ignore
			if ( $present === $rows ) {
				// Already copied (plan B on a previous run leaves the old one): nothing to do.
				// Idempotence; the old one is deleted by hand, as that run's warning says.
				continue;
			}
			if ( $present ) {
				self::$report['avisos'][] = "$old_t ($rows rows) and $new_t ($present rows) both exist and the new one is not empty: neither is touched, check by hand";
				continue;
			}
			self::$report['tablas'][] = "$old_t → $new_t (the new one existed empty: $rows rows copied; the old one stays)";
			if ( ! self::$dry ) {
				$wpdb->query( "INSERT INTO `$new_t` SELECT * FROM `$old_t`" ); // phpcs:ignore
			}
		}
	}

	/** Creates the new tables with the new forms plugin's migration (plan B of tables()). */
	private static function create_forms_tables(): bool {
		$dir = WP_PLUGIN_DIR . '/' . dirname( self::PLUGIN ) . '/modules/forms/includes/';
		if ( ! is_file( $dir . 'migrations/0001-create-tables.php' ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! class_exists( '\EstrategiaWeb\Forms\Migrations' ) ) {
			require_once $dir . 'class-migrations.php';
		}
		global $wpdb;
		$create = require $dir . 'migrations/0001-create-tables.php';
		$create( $wpdb );
		return true;
	}

	private static function wpml(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_strings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$rows = $wpdb->get_results( "SELECT id, context, name, gettext_context FROM `$table` WHERE context LIKE '%armazon%' OR name LIKE '%armazon%'", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$context = self::rename_value( $row['context'] );
			$name    = self::rename_value( $row['name'] );
			if ( $context === $row['context'] && $name === $row['name'] ) {
				continue;
			}
			self::$report['wpml'][] = sprintf( '#%d %s / %s → %s / %s', $row['id'], $row['context'], $row['name'], $context, $name );
			if ( ! self::$dry ) {
				$wpdb->update(
					$table,
					array(
						'context'                 => $context,
						'name'                    => $name,
						'domain_name_context_md5' => md5( $context . $name . $row['gettext_context'] ),
					),
					array( 'id' => (int) $row['id'] )
				); // phpcs:ignore
			}
		}
	}

	private static function theme(): void {
		global $wpdb;
		$ready = is_file( get_theme_root() . '/estrategia-web/style.css' );
		foreach ( array( 'template', 'stylesheet' ) as $option ) {
			if ( 'armazon' !== get_option( $option ) ) {
				continue;
			}
			self::$report['tema'][] = "$option: armazon → estrategia-web" . ( $ready ? '' : ' (themes/estrategia-web MISSING: not changed)' );
			if ( ! self::$dry && $ready ) {
				update_option( $option, 'estrategia-web' );
			}
		}
		$term = get_term_by( 'slug', 'armazon', 'wp_theme' );
		if ( $term ) {
			self::$report['tema'][] = "wp_theme term armazon (#{$term->term_id}) → estrategia-web";
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->terms, array( 'slug' => 'estrategia-web', 'name' => 'estrategia-web' ), array( 'term_id' => $term->term_id ) ); // phpcs:ignore
				clean_term_cache( $term->term_id, 'wp_theme' );
			}
		}
	}

	private static function plugins(): void {
		$active  = (array) get_option( 'active_plugins', array() );
		$changed = $active;
		foreach ( self::PLUGINS as $old => $new ) {
			$at = array_search( $old, $changed, true );
			if ( false === $at ) {
				continue;
			}
			$ready = is_file( WP_PLUGIN_DIR . '/' . $new );
			self::$report['plugins'][] = "$old → $new" . ( $ready ? '' : ' (new plugin MISSING: not changed)' );
			if ( $ready ) {
				$changed[ $at ] = $new;
			}
		}
		foreach ( self::OLD_CRON as $hook ) {
			if ( wp_next_scheduled( $hook ) ) {
				self::$report['plugins'][] = "cron $hook: removed (the new plugin schedules its own)";
				if ( ! self::$dry ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}
		if ( ! self::$dry && $changed !== $active ) {
			update_option( 'active_plugins', array_values( array_unique( $changed ) ) );
			delete_option( 'rewrite_rules' ); // the plugin's post types get registered again
		}
	}

	private static function cache(): void {
		if ( self::$dry ) {
			return;
		}
		wp_cache_flush();
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'estrategia-web migrate-armazon',
		static function ( $args, $assoc ) {
			$report = Armazon_Migration::run( ! empty( $assoc['dry-run'] ) );
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		},
		array( 'shortdesc' => 'Moves an Armazón (or 1.0.0) site to Estrategia Web 1.1.0 (content, metas, options, tables, WPML, theme and the single plugin). With --dry-run it only counts.' )
	);
}
