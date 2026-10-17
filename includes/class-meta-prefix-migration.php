<?php
/**
 * THE META PREFIX MIGRATOR (unreleased): the three meta keys from before
 * 1.0.0 that still had no prefix (client_url, testimonial_role,
 * testimonial_client) go to estrategia_web_… IN PLACE. The map is
 * Unprefixed_Meta::MAP (modules/site/includes/class-unprefixed-meta.php):
 * it lives only there.
 *
 * The same problem the post types had in 1.2.0 (includes/class-prefix-migration.php),
 * in a class of its own on purpose: the deployer of a site carries the
 * migrator over stdin to the server WHILE THE OLD PLUGIN IS STILL RUNNING
 * (Lavandería: despliegue/prefijo-tipos.mjs), and a class the server already
 * has cannot be loaded again. With a new class, the DRY RUN works against
 * 1.4.3; as a step inside Prefix_Migration it would run the server's old copy,
 * which does not know these metas.
 *
 * What it does (each step says what it would change and how many):
 *   1. metas      wp_postmeta.meta_key, ONLY for posts of the client and
 *                 testimonial types (with their new name or the pre-1.2.0
 *                 one: on a site that jumps from 1.1.0 this may run before
 *                 the types migrator). Revisions are not touched. A post that
 *                 already has the new key keeps it: its old row is left and
 *                 a warning is emitted.
 *   2. content    the core/post-meta Block Bindings saved in post_content
 *                 (pages, templates, template parts, synced patterns…:
 *                 everything except revisions and attachments) with
 *                 "key":"client_url" → the new key. Only inside a binding
 *                 whose source is core/post-meta; straight to the table.
 * Both steps put every post they change in «entradas» (id, tipo, slug,
 * estado, what), also on a dry run: a site that keeps fingerprints of its
 * content load (Lavandería, despliegue/prefijo-metas.php) reads them from
 * the dry run, checks which posts were intact BEFORE and refreshes those
 * after. No hook here: a renamed meta also changes a fingerprint, and the
 * site knows better than this file what its fingerprint counts.
 * WPML needs no step: wpml-config.xml declares the new names, and the old
 * ones that WPML remembers in its settings stay there unused.
 *
 * How to use it:
 *   - WP-CLI: wp estrategia-web migrate-meta-prefix [--dry-run]
 *   - From «wp eval-file» (Lavandería): include this file,
 *     class-type-names.php and class-unprefixed-meta.php and call
 *     Meta_Prefix_Migration::run( $dry ).
 *   It does NOT run on activation or update: with a backup and after reading
 *   the dry run. Until it runs, the alias (Unprefixed_Meta) makes the new
 *   keys read the old rows, so the site looks the same.
 *
 * IDEMPOTENT: it looks for the old keys; the second run changes nothing. A
 * dry run writes nothing.
 *
 * How to maintain:
 * - Who: whoever touches the metas. It is removed in 2.0.0 with
 *   Unprefixed_Meta and Prefix_Migration, once every site has gone through
 *   it (17-10-2026: only Lavandería, with no rows to rename).
 * - Edge case, by name: ANOTHER PLUGIN WITH «client_url» ON ITS OWN POSTS.
 *   Not touched: the rename is limited to our two types.
 * - Edge case: a paragraph whose text says "key":"client_url". Not touched:
 *   only the args of a core/post-meta binding inside a block comment.
 * - The trap: the editor writes a binding as {"source":…,"args":{…}}, but
 *   nothing stops {"args":…,"source":…}. Both orders are handled
 *   (rename_markup); a binding with more args than «key» is also renamed.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Type_Names::class ) ) {
	require_once dirname( __DIR__ ) . '/modules/site/includes/class-type-names.php';
}
if ( ! class_exists( Unprefixed_Meta::class ) ) {
	require_once dirname( __DIR__ ) . '/modules/site/includes/class-unprefixed-meta.php';
}

final class Meta_Prefix_Migration {

	private static bool $dry = true;

	/** @var array<string, mixed> */
	private static array $report = array();

	/**
	 * Runs the migration. Returns what was done (or what it would do, on a dry run).
	 *
	 * @return array{seco:bool, metas:string[], contenido:array, entradas:array, avisos:string[]}
	 */
	public static function run( bool $dry_run ): array {
		self::$dry    = $dry_run;
		self::$report = array(
			'seco'      => $dry_run,
			'metas'     => array(),
			'contenido' => array(),
			'entradas'  => array(),
			'avisos'    => array(),
		);
		self::metas();
		self::content();
		if ( ! self::$dry ) {
			wp_cache_flush();
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
			}
		}
		self::$report['entradas'] = array_values( self::$report['entradas'] );
		return self::$report;
	}

	/**
	 * Block markup with the new keys, only in core/post-meta bindings inside
	 * a block's comment.
	 *
	 * @param int|null $count Keys changed, by reference.
	 */
	public static function rename_markup( string $html, ?int &$count = null ): string {
		$count = 0;
		if ( ! str_contains( $html, 'core/post-meta' ) ) {
			return $html;
		}
		$names  = implode( '|', array_map( static fn( $k ) => preg_quote( $k, '#' ), array_keys( Unprefixed_Meta::MAP ) ) );
		$in_arg = static function ( string $args ) use ( $names, &$count ): string {
			return (string) preg_replace_callback(
				'#("key"\s*:\s*")(' . $names . ')"#',
				static function ( $k ) use ( &$count ) {
					++$count;
					return $k[1] . Unprefixed_Meta::MAP[ $k[2] ] . '"';
				},
				$args
			);
		};
		return (string) preg_replace_callback(
			'#<!--\s+wp:[a-z0-9/-]+\s+(\{.*?\})\s+/?-->#s',
			static function ( $m ) use ( $in_arg ) {
				if ( ! str_contains( $m[1], 'core/post-meta' ) ) {
					return $m[0];
				}
				$json = (string) preg_replace_callback(
					'#\{\s*"source"\s*:\s*"core/post-meta"\s*,\s*"args"\s*:\s*\{[^{}]*\}\s*\}|\{\s*"args"\s*:\s*\{[^{}]*\}\s*,\s*"source"\s*:\s*"core/post-meta"\s*\}#',
					static fn( $b ) => $in_arg( $b[0] ),
					$m[1]
				);
				return str_replace( $m[1], $json, $m[0] );
			},
			$html
		);
	}

	// ------------------------------------------------------------------- steps

	private static function metas(): void {
		global $wpdb;
		foreach ( Unprefixed_Meta::MAP as $old => $new ) {
			$types = Unprefixed_Meta::post_types( $old );
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_id, p.ID, p.post_type, p.post_name, p.post_status, ( SELECT COUNT(*) FROM {$wpdb->postmeta} n WHERE n.post_id = p.ID AND n.meta_key = %s ) AS has_new FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type IN ($in) ORDER BY p.ID", $new, $old, ...$types ), ARRAY_A );
			$ids  = array();
			foreach ( $rows as $row ) {
				if ( (int) $row['has_new'] ) {
					self::$report['avisos'][] = "#{$row['ID']} {$row['post_name']}: it has both «{$old}» and «{$new}»; the new one stays, the old row is left";
					continue;
				}
				$ids[] = (int) $row['meta_id'];
				self::entry( $row, "meta $old" );
			}
			if ( ! $ids ) {
				continue;
			}
			self::$report['metas'][] = "$old → $new: " . count( $ids ) . ' rows';
			if ( ! self::$dry ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_id IN (" . implode( ',', $ids ) . ')', $new ) );
				wp_cache_flush_group( 'post_meta' );
				foreach ( $rows as $row ) {
					wp_cache_delete( (int) $row['ID'], 'post_meta' );
				}
			}
		}
	}

	private static function content(): void {
		global $wpdb;
		$like = array();
		foreach ( array_keys( Unprefixed_Meta::MAP ) as $old ) {
			$like[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( '"' . $old . '"' ) . '%' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT ID, post_type, post_name, post_status, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND post_content LIKE '%core/post-meta%' AND ( " . implode( ' OR ', $like ) . ' ) ORDER BY ID', ARRAY_A );
		foreach ( $rows as $row ) {
			$new = self::rename_markup( $row['post_content'], $count );
			if ( $new === $row['post_content'] ) {
				continue;
			}
			self::$report['contenido'][] = "#{$row['ID']} {$row['post_type']} {$row['post_name']}: $count bindings";
			self::entry( $row, 'content' );
			if ( ! self::$dry ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row['ID'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $row['ID'] );
			}
		}
	}

	/** A changed post into «entradas» (once per post, with every reason). */
	private static function entry( array $row, string $what ): void {
		$id = (int) $row['ID'];
		if ( ! isset( self::$report['entradas'][ $id ] ) ) {
			self::$report['entradas'][ $id ] = array(
				'id'     => $id,
				'tipo'   => $row['post_type'],
				'slug'   => $row['post_name'],
				'estado' => $row['post_status'],
				'que'    => array(),
			);
		}
		self::$report['entradas'][ $id ]['que'][] = $what;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'estrategia-web migrate-meta-prefix',
		static function ( $args, $assoc ) {
			$report = Meta_Prefix_Migration::run( ! empty( $assoc['dry-run'] ) );
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		},
		array( 'shortdesc' => 'Renames the three unprefixed metas of clients and testimonials (client_url, testimonial_role, testimonial_client) to estrategia_web_…, in wp_postmeta and in the core/post-meta bindings of the content. With --dry-run it only counts.' )
	);
}
