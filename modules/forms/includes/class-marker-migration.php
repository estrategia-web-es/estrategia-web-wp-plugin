<?php
namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * THE MARKERS MIGRATOR (2.0.1): the four fixed email markers that were in
 * Spanish until 1.4.0 are renamed in every saved form, so that the read
 * aliases (Markers::ALIASES, removed in 2.0.1) are no longer needed:
 *
 *     {{todos_los_campos}} → {{all_fields}}
 *     {{fecha}}            → {{submission_date}}
 *     {{url_pagina}}       → {{page_url}}
 *     {{nombre_sitio}}     → {{site_name}}
 *
 * (also with spaces or capitals inside the braces: {{ Fecha }}, which the
 * replacement accepted). FIELD markers ({{nombre}}, {{email}}…) are the
 * names of each form's fields and are not touched.
 *
 * How to use it (with 2.0.1 active, after a backup):
 *   wp estrategia-web migrate-form-markers --dry-run   every notification it would change, before → after
 *   wp estrategia-web migrate-form-markers             does it
 * It prints its report as JSON and a summary line. It does NOT run by itself
 * on update: until it runs, a saved {{fecha}} reaches the email VERBATIM
 * (an unknown marker is left as is, Markers' rule), which is why the
 * CHANGELOG puts it first.
 *
 * WHAT IT DOES:
 *   1. posts      every post whose content has a notification block, of ANY
 *                 type but revisions (form posts, and also forms still
 *                 written INSIDE a page or a synced pattern on a site that
 *                 has not run «migrate-forms»: those are migrated too and
 *                 listed under «en_linea» with a reminder, never an error),
 *                 in any status but trash and auto-draft, in EVERY language
 *                 (the query goes straight to the table, without WPML's
 *                 language filter). Only the attributes of the notification
 *                 block's comment change, byte for byte around them; the
 *                 original content is kept in the post meta
 *                 «_eweb_markers_migration_backup». Straight to the table:
 *                 no revision, no save hooks, no kses (as migrate-forms).
 *                 For each changed post it fires the filter
 *                 estrategia_web_markers_migration_post (entry, old, new,
 *                 dry): the contract of estrategia_web_forms_migration_post.
 *   2. snapshots  the definitions table (class-definitions.php) keeps the
 *                 notifications of every version of a form that was ever
 *                 rendered, and a page cached before the migration submits
 *                 with that old snapshot. Its to / reply_to / subject / body
 *                 are renamed too (the hash is only the row's key: nothing
 *                 recomputes it). The old JSON goes to the option
 *                 «estrategia_web_markers_migration_backup» (hash => JSON).
 * The report, per notification: the post, its form identifier, the
 * notification's label and, per attribute, «antes» and «despues».
 *
 * IDEMPOTENT: it looks for the old names; when none are left it says «ya»
 * and changes nothing. A dry run writes nothing.
 *
 * The PURE part (rename_text, rename_markup, rename_definition: no
 * WordPress) is tested at level 1 (tests/php/pure.php); the run, at level 2
 * (tests/marker-migration.test.mjs).
 *
 * How to maintain:
 * - Who: whoever touches the fixed markers (class-markers.php). The old
 *   names live ONLY here (RENAMED) since 2.0.1.
 * - It can go away once every site has run it (28-10-2026: Lavandería,
 *   pending; its deploy runs it).
 * - Edge case, by name: a form with a FIELD called «fecha». Until 2.0.1
 *   {{fecha}} was the submission date (the alias won over the field); the
 *   migration writes {{submission_date}}, so the emails do not change. From
 *   now on a NEW {{fecha}} is the field, which is what anybody writing it
 *   today would expect.
 * - Edge case: WPML. Every language's post is migrated here directly; the
 *   copies WPML keeps of the notification's subject and body in its own
 *   translation memory (they are «gutenberg-block» strings,
 *   wpml-config.xml) are not touched. When the original form is translated
 *   again, the translator gets the new source text.
 * - The trap: a notification's attributes are JSON inside an HTML comment,
 *   and the editor escapes some characters there (<, "…) but not
 *   braces, so the marker is found as it is. The block is found by its
 *   delimiter (the same expression as Form_Migration), never by
 *   parse_blocks() + serialize_blocks(): re-serializing would rewrite the
 *   whole post and every page would come out «changed».
 */

final class Marker_Migration {

	/** Old (Spanish, before 1.4.0) name => current name. */
	public const RENAMED = array(
		'todos_los_campos' => 'all_fields',
		'fecha'            => 'submission_date',
		'url_pagina'       => 'page_url',
		'nombre_sitio'     => 'site_name',
	);

	/** Where the original content of a migrated post is kept. */
	public const BACKUP_META = '_eweb_markers_migration_backup';

	/** Where the original JSON of a migrated snapshot is kept (hash => JSON). */
	public const BACKUP_OPTION = 'estrategia_web_markers_migration_backup';

	/** A notification block delimiter (void or opener) with its attributes. */
	private const NOTIFICATION = '/<!--\s+wp:estrategia-web-forms\/notification\s+(?P<attrs>\{(?:(?:[^}]+|\}+(?=\})|(?!\}\s+\/?-->).)*+)?\})\s+\/?-->/s';

	/** A form opener, to know which form a notification belongs to. */
	private const FORM_OPENER = '/<!--\s+wp:estrategia-web-forms\/form\s+(\{(?:(?:[^}]+|\}+(?=\})|(?!\}\s+\/?-->).)*+)?\})?\s*-->/s';

	/** The attributes of a notification that hold markers (block => snapshot). */
	private const ATTRIBUTES = array(
		'to'      => 'to',
		'replyTo' => 'reply_to',
		'subject' => 'subject',
		'body'    => 'body',
	);

	/** @var array<string, mixed> */
	private static array $report = array();

	// ------------------------------------------------------------------ pure

	/** A text with the old markers renamed ({{ Fecha }} → {{submission_date}}). */
	public static function rename_text( string $text ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*(' . implode( '|', array_keys( self::RENAMED ) ) . ')\s*\}\}/i',
			static fn( array $m ): string => '{{' . self::RENAMED[ strtolower( $m[1] ) ] . '}}',
			$text
		);
	}

	/**
	 * A post's content with the old markers renamed inside its notification
	 * blocks only. $changes gets one entry per changed notification:
	 * form_id, label and, per attribute, [antes, despues].
	 *
	 * @param array<int, array> $changes By reference.
	 */
	public static function rename_markup( string $content, ?array &$changes = null ): string {
		$changes = array();
		if ( ! preg_match_all( self::NOTIFICATION, $content, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}
		$forms = array();
		if ( preg_match_all( self::FORM_OPENER, $content, $openers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $openers as $opener ) {
				$attrs   = isset( $opener[1] ) ? json_decode( (string) $opener[1][0], true ) : array();
				$forms[] = array( (int) $opener[0][1], is_array( $attrs ) ? (string) ( $attrs['formId'] ?? '' ) : '' );
			}
		}
		$out  = '';
		$from = 0;
		foreach ( $found as $match ) {
			$raw   = (string) $match['attrs'][0];
			$start = (int) $match['attrs'][1];
			$new   = self::rename_text( $raw );
			$out  .= substr( $content, $from, $start - $from ) . $new;
			$from  = $start + strlen( $raw );
			if ( $new === $raw ) {
				continue;
			}
			$before  = (array) json_decode( $raw, true );
			$after   = (array) json_decode( $new, true );
			$form_id = '';
			foreach ( $forms as list( $at, $id ) ) {
				if ( $at < $start ) {
					$form_id = $id;
				}
			}
			$entry = array(
				'form_id'  => $form_id,
				'aviso'    => (string) ( $before['label'] ?? '' ),
				'cambios'  => array(),
			);
			foreach ( array_keys( self::ATTRIBUTES ) as $key ) {
				if ( ( $before[ $key ] ?? null ) !== ( $after[ $key ] ?? null ) ) {
					$entry['cambios'][ $key ] = array(
						'antes'   => (string) $before[ $key ],
						'despues' => (string) $after[ $key ],
					);
				}
			}
			$changes[] = $entry;
		}
		return $out . substr( $content, $from );
	}

	/**
	 * A stored snapshot (Definitions) with the old markers renamed in its
	 * notifications. Returns null if nothing changes.
	 */
	public static function rename_definition( array $definition ): ?array {
		$changed = false;
		foreach ( (array) ( $definition['notifications'] ?? array() ) as $i => $notification ) {
			foreach ( self::ATTRIBUTES as $key ) {
				if ( isset( $notification[ $key ] ) && is_string( $notification[ $key ] ) ) {
					$new = self::rename_text( $notification[ $key ] );
					if ( $new !== $notification[ $key ] ) {
						$definition['notifications'][ $i ][ $key ] = $new;
						$changed = true;
					}
				}
			}
		}
		return $changed ? $definition : null;
	}

	// --------------------------------------------------------------- the run

	/**
	 * Runs the migration. Returns what was done (or what it would do, on a dry run).
	 *
	 * @return array{seco:bool, ya:bool, formularios:array, en_linea:array, instantaneas:int, avisos:string[], resumen:string}
	 */
	public static function run( bool $dry_run ): array {
		global $wpdb;
		self::$report = array(
			'seco'         => $dry_run,
			'ya'           => false,
			'formularios'  => array(),
			'en_linea'     => array(),
			'instantaneas' => 0,
			'avisos'       => array(),
			'resumen'      => '',
		);

		$posts = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_type, post_name, post_status, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE %s ORDER BY ID",
				'%' . $wpdb->esc_like( '<!-- wp:estrategia-web-forms/notification' ) . '%'
			)
		);
		if ( ! $dry_run ) {
			kses_remove_filters();
		}
		foreach ( $posts as $post ) {
			$old = (string) $post->post_content;
			$new = self::rename_markup( $old, $changes );
			if ( ! $changes ) {
				continue;
			}
			$inline = Form_Post_Type::TYPE !== $post->post_type;
			$entry  = array(
				'id'      => (int) $post->ID,
				'tipo'    => (string) $post->post_type,
				'slug'    => (string) $post->post_name,
				'estado'  => (string) $post->post_status,
				'idioma'  => self::lang_of( (int) $post->ID ),
				'avisos'  => $changes,
			);
			if ( $inline ) {
				$entry['nota'] = 'form written inside this content (migrate-forms not run yet): its markers are renamed here all the same';
			}
			if ( ! $dry_run ) {
				add_post_meta( (int) $post->ID, self::BACKUP_META, wp_slash( $old ) );
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $post->ID );
			}
			/**
			 * A post whose notifications the markers migrator changes (also on
			 * a dry run, without writing: then $new is null). Whatever the
			 * filter returns shows up in the report. Same contract as
			 * estrategia_web_forms_migration_post.
			 *
			 * @param array       $entry What shows up in the report.
			 * @param string      $old   The content before.
			 * @param string|null $new   The content after (null on a dry run).
			 * @param bool        $dry   Dry run.
			 */
			self::$report[ $inline ? 'en_linea' : 'formularios' ][] = apply_filters( 'estrategia_web_markers_migration_post', $entry, $old, $dry_run ? null : $new, $dry_run );
		}
		if ( ! $dry_run ) {
			kses_init();
		}

		$table = Migrations::definitions_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$backup = array();
			foreach ( (array) $wpdb->get_results( "SELECT hash, definition FROM $table WHERE definition LIKE '%{{%'" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$def = json_decode( (string) $row->definition, true );
				$new = is_array( $def ) ? self::rename_definition( $def ) : null;
				if ( null === $new ) {
					continue;
				}
				++self::$report['instantaneas'];
				if ( ! $dry_run ) {
					$backup[ (string) $row->hash ] = (string) $row->definition;
					$wpdb->update( $table, array( 'definition' => wp_json_encode( $new ) ), array( 'hash' => (string) $row->hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			if ( $backup ) {
				update_option( self::BACKUP_OPTION, array_merge( (array) get_option( self::BACKUP_OPTION, array() ), $backup ), false );
			}
		} else {
			self::$report['avisos'][] = "no definitions table ($table): no snapshot to rename";
		}

		$notifications = 0;
		foreach ( array_merge( self::$report['formularios'], self::$report['en_linea'] ) as $entry ) {
			$notifications += count( (array) ( $entry['avisos'] ?? array() ) );
		}
		if ( self::$report['en_linea'] ) {
			self::$report['avisos'][] = sprintf( '%d post(s) still have their form written inside the content: «wp estrategia-web migrate-forms» is still pending there.', count( self::$report['en_linea'] ) );
		}
		if ( ! $notifications && ! self::$report['instantaneas'] ) {
			self::$report['ya']      = true;
			self::$report['resumen'] = 'ya: no old marker left in any form or snapshot (nothing to do)';
			return self::$report;
		}
		self::$report['resumen'] = sprintf(
			'%s%d notification(s) in %d form post(s) and %d post(s) with an inline form %s; %d snapshot(s) %s',
			$dry_run ? 'DRY RUN: ' : '',
			$notifications,
			count( self::$report['formularios'] ),
			count( self::$report['en_linea'] ),
			$dry_run ? 'to change' : 'changed (original in the meta ' . self::BACKUP_META . ')',
			self::$report['instantaneas'],
			$dry_run ? 'to change' : 'changed (original in the option ' . self::BACKUP_OPTION . ')'
		);
		return self::$report;
	}

	/** WPML language code of a post, or '' (no WPML, or no language). */
	private static function lang_of( int $id ): string {
		if ( ! Language::wpml_active() ) {
			return '';
		}
		$details = apply_filters( 'wpml_post_language_details', null, $id );
		return is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'estrategia-web migrate-form-markers',
		static function ( $args, $assoc ) {
			$report = Marker_Migration::run( ! empty( $assoc['dry-run'] ) );
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			\WP_CLI::line( $report['resumen'] );
		},
		array( 'shortdesc' => 'Renames the old Spanish fixed markers ({{todos_los_campos}}, {{fecha}}, {{url_pagina}}, {{nombre_sitio}}) to their English names in every saved form, inline form and stored snapshot (2.0.1). With --dry-run it only lists every notification it would change, before → after.' )
	);
}
