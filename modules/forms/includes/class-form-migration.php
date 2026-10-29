<?php
namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * THE FORMS MIGRATOR (2.0.0): every form written INSIDE a post (a page, a
 * synced pattern, a template part saved in the database…) becomes a form
 * post of its own (eweb_form, class-form-post-type.php) and, where it was,
 * the «Formulario» reference block pointing to it is left. The page looks
 * and submits exactly the same (the reference renders the same tree; see
 * Blocks::render_form_ref()).
 *
 * How to use it (with the plugin 2.0.0 active, after a backup):
 *   wp estrategia-web migrate-forms --dry-run     what it would create and replace
 *   wp estrategia-web migrate-forms               does it
 *   wp estrategia-web migrate-forms --ids=14,190  only those posts (to try one page first)
 * It prints its report as JSON. It does NOT run by itself on activation or
 * update: until it runs, the inline forms keep working as in 1.7.0 (the
 * form block still renders anywhere), «Forms → Forms» lists them with a
 * notice, and the site just has the old editing model.
 *
 * WHAT IT DOES, in this order:
 *   1. Reads every post of any type but revisions and form posts, in
 *      publish, draft, pending, future or private (Forms_List::STATUSES),
 *      whose content has a form block (estrategia-web-forms/form, or its
 *      pre-1.0.0 name armazon-forms/form, which is renamed on the way).
 *   2. Groups the trees by IDENTIFIER (formId) AND LANGUAGE (WPML's of the
 *      post; one bucket without WPML). One form post per group:
 *      - Two DIFFERENT identifiers are two form posts, even if the fields
 *        match (David, 27-10-2026: «sin unificar»).
 *      - The SAME identifier in two places of the same language (a form
 *        copied into two pages) is ONE form post; the first by post id
 *        gives the content, and the report says whether the copies were
 *        identical («iguales»). If they were not, the other page will show
 *        the first one's version: check it in the dry run.
 *      - No identifier: each tree is its own form post.
 *      - An eweb_form that ALREADY has that identifier and language (a
 *        previous run that stopped half-way, or one made by hand) is
 *        reused, not duplicated.
 *   3. WPML: the groups of the same identifier in different languages are
 *      linked as translations of the default language's (or of the first
 *      one, if that language has none). Before creating anything the real
 *      run asks WPML to re-read its config (WPML_Config::import_now), so
 *      that eweb_form is already a translatable type; the report says
 *      whether it is («wpml.traducible»).
 *   4. Creates the form posts (published; title = the block's «title», or
 *      the identifier, or the post's title).
 *   5. In each post, replaces each form tree with
 *      <!-- wp:estrategia-web-forms/form-ref {"ref":ID} /-->, byte for byte
 *      around it: nothing else of the post changes. The ORIGINAL content is
 *      first saved in the post meta «_eweb_forms_migration_backup» (one row
 *      per run that changed the post; to undo: wp post meta get <id>
 *      _eweb_forms_migration_backup > old.html and wp post update <id>
 *      old.html). Straight to the table: no revision, no save hooks, no
 *      kses. For each changed post it fires the filter
 *      estrategia_web_forms_migration_post (entry, old, new, dry): a site
 *      that keeps fingerprints of its content load refreshes them there.
 * The submissions are not touched: they are grouped by identifier, and the
 * identifiers do not change (the old snapshots of cached pages also keep
 * validating; class-definitions.php).
 *
 * IDEMPOTENT: it looks for inline form blocks; when there are none left it
 * says «ya» and changes nothing. A dry run writes nothing.
 *
 * The PURE part (find_trees, plan, replace: no WordPress) is tested at
 * level 1 with the machine's PHP (tests/php/migration-plan.php); the run,
 * with WordPress and WPML at level 2 (tests/form-migration.test.mjs,
 * tests/form-migration-wpml.test.mjs).
 *
 * How to maintain:
 * - Who: whoever touches where forms can live. It goes away in 2.0.0, when
 *   the inline form block stops rendering outside a form post (if that is
 *   ever decided) and every site has run it.
 * - Edge case, by name: a pattern INSIDE another pattern. Each wp_block is
 *   a post and is migrated on its own; the outer one only has <!-- wp:block
 *   -->, which is not touched.
 * - Edge case: a form in a theme FILE (a PHP/HTML pattern or template). It
 *   is not in the database and is not migrated; it keeps rendering inline.
 * - Edge case: a dropdown preselected per page through the synced block's
 *   «content» ({"servicio":{"defaultOption":2}}) keeps working: the
 *   reference block passes the context on (Blocks::render_form_ref()).
 * - The trap: a tree is found by its comment delimiters, not by
 *   parse_blocks() + serialize_blocks(): re-serializing a page rewrites
 *   attribute JSON and whitespace, and every page would come out «changed».
 *   A form never contains another form, so the first closer after an opener
 *   is its own. The opener «form» must be followed by a space: «form-ref» is
 *   another block.
 */

final class Form_Migration {

	/** Where the original content of a migrated post is kept. */
	public const BACKUP_META = '_eweb_forms_migration_backup';

	/** The form block's namespaces: today's and the pre-1.0.0 one. */
	private const NAMESPACES = array( 'estrategia-web-forms', 'armazon-forms' );

	/** A form block delimiter (opener, closer or void), as WordPress's parser reads them. */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<ns>estrategia-web-forms|armazon-forms)\/form\s+(?P<attrs>\{(?:(?:[^}]+|\}+(?=\})|(?!\}\s+\/?-->).)*+)?\}\s+)?(?P<void>\/)?-->/s';

	private static bool $dry = true;

	/** @var array<string, mixed> */
	private static array $report = array();

	// ------------------------------------------------------------------ pure

	/**
	 * The form trees of a post's content, in order. Each one: start, length
	 * (byte offsets into $content), markup (the tree as it is), attrs (the
	 * opener's JSON) and form_id (clean). Unclosed openers go to $errors.
	 *
	 * @param string[] $errors By reference.
	 * @return list<array{start:int,length:int,markup:string,attrs:array,form_id:string}>
	 */
	public static function find_trees( string $content, ?array &$errors = null ): array {
		$errors = array();
		if ( ! preg_match_all( self::DELIMITER, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$trees = array();
		$open  = null;
		foreach ( $m as $d ) {
			$at     = $d[0][1];
			$closer = '' !== ( $d['closer'][0] ?? '' );
			$void   = '' !== ( $d['void'][0] ?? '' );
			if ( $closer ) {
				if ( null === $open ) {
					$errors[] = "a form closer with no opener at byte $at";
					continue;
				}
				$end     = $at + strlen( $d[0][0] );
				$trees[] = self::tree( $content, $open, $end );
				$open    = null;
				continue;
			}
			if ( null !== $open ) {
				$errors[] = 'a form opener with no closer at byte ' . $open[0][1];
			}
			if ( $void ) {
				$trees[] = self::tree( $content, $d, $at + strlen( $d[0][0] ) );
				$open    = null;
				continue;
			}
			$open = $d;
		}
		if ( null !== $open ) {
			$errors[] = 'a form opener with no closer at byte ' . $open[0][1];
		}
		return $trees;
	}

	private static function tree( string $content, array $opener, int $end ): array {
		$start = $opener[0][1];
		$json  = trim( (string) ( $opener['attrs'][0] ?? '' ) );
		$attrs = '' !== $json ? json_decode( $json, true ) : array();
		$attrs = is_array( $attrs ) ? $attrs : array();
		return array(
			'start'   => $start,
			'length'  => $end - $start,
			'markup'  => self::rename_legacy( substr( $content, $start, $end - $start ) ),
			'attrs'   => $attrs,
			'form_id' => substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $attrs['formId'] ?? '' ) ) ), 0, 64 ),
		);
	}

	/** armazon-forms/… → estrategia-web-forms/… in the delimiters only. */
	public static function rename_legacy( string $markup ): string {
		return (string) preg_replace( '#<!--(\s+/?)wp:armazon-forms/#', '<!--$1wp:estrategia-web-forms/', $markup );
	}

	/** For «are the copies the same?»: whitespace between delimiters does not count. */
	public static function normalize( string $markup ): string {
		return trim( (string) preg_replace( '/-->\s+<!--/', '--><!--', $markup ) );
	}

	/**
	 * THE PLAN, without WordPress.
	 *
	 * @param list<array{id:int,type:string,title:string,lang:string,content:string}> $posts    Posts with forms, in id order.
	 * @param list<array{id:int,form_id:string,lang:string}>                          $existing Form posts that already exist.
	 * @param string                                                                  $default  Default language ('' without WPML).
	 * @return array{forms: array<string, array>, posts: array<int, array>, errors: string[]}
	 *   forms: key («formId|lang», or «#post-index|lang» without identifier) =>
	 *          form_id, lang, title, markup, source (post id), copies (other
	 *          post ids), identical, existing (id or 0), original (the key it
	 *          is a translation of, or '').
	 *   posts: id => trees (start, length, key).
	 */
	public static function plan( array $posts, array $existing, string $default ): array {
		$forms  = array();
		$plan   = array();
		$errors = array();
		usort( $posts, static fn( $a, $b ) => $a['id'] <=> $b['id'] );
		foreach ( $posts as $post ) {
			$trees = self::find_trees( (string) $post['content'], $found_errors );
			foreach ( $found_errors as $e ) {
				$errors[] = "post {$post['id']}: $e (not migrated)";
			}
			if ( $found_errors ) {
				continue; // A post with broken delimiters is left as it is: replacing around them could cut a block.
			}
			foreach ( $trees as $i => $tree ) {
				$lang = (string) $post['lang'];
				$key  = ( '' !== $tree['form_id'] ? $tree['form_id'] : '#' . $post['id'] . '-' . $i ) . '|' . $lang;
				if ( ! isset( $forms[ $key ] ) ) {
					$title         = trim( (string) ( $tree['attrs']['title'] ?? '' ) );
					$forms[ $key ] = array(
						'form_id'   => $tree['form_id'],
						'lang'      => $lang,
						'title'     => '' !== $title ? $title : ( '' !== $tree['form_id'] ? $tree['form_id'] : (string) $post['title'] ),
						'markup'    => $tree['markup'],
						'source'    => (int) $post['id'],
						'copies'    => array(),
						'identical' => true,
						'existing'  => 0,
						'original'  => '',
					);
				} else {
					if ( ! in_array( (int) $post['id'], $forms[ $key ]['copies'], true ) && (int) $post['id'] !== $forms[ $key ]['source'] ) {
						$forms[ $key ]['copies'][] = (int) $post['id'];
					}
					if ( self::normalize( $tree['markup'] ) !== self::normalize( $forms[ $key ]['markup'] ) ) {
						$forms[ $key ]['identical'] = false;
					}
				}
				$plan[ (int) $post['id'] ]['trees'][] = array(
					'start'  => $tree['start'],
					'length' => $tree['length'],
					'key'    => $key,
				);
			}
		}
		foreach ( $existing as $form ) {
			$key = $form['form_id'] . '|' . $form['lang'];
			if ( '' !== $form['form_id'] && isset( $forms[ $key ] ) && ! $forms[ $key ]['existing'] ) {
				$forms[ $key ]['existing'] = (int) $form['id'];
			}
		}
		// Translations: per identifier, the default language's group is the original.
		$by_id = array();
		foreach ( $forms as $key => $form ) {
			if ( '' !== $form['form_id'] && '' !== $form['lang'] ) {
				$by_id[ $form['form_id'] ][] = $key;
			}
		}
		foreach ( $by_id as $keys ) {
			if ( count( $keys ) < 2 ) {
				continue;
			}
			$original = $keys[0];
			foreach ( $keys as $key ) {
				if ( $forms[ $key ]['lang'] === $default ) {
					$original = $key;
					break;
				}
			}
			foreach ( $keys as $key ) {
				if ( $key !== $original ) {
					$forms[ $key ]['original'] = $original;
				}
			}
		}
		return array(
			'forms'  => $forms,
			'posts'  => $plan,
			'errors' => $errors,
		);
	}

	/**
	 * The content with each tree replaced by its reference block. From the
	 * last tree to the first, so the offsets stay right.
	 *
	 * @param list<array{start:int,length:int,key:string}> $trees
	 * @param array<string, int>                          $ids   key => form post id.
	 */
	public static function replace( string $content, array $trees, array $ids ): string {
		usort( $trees, static fn( $a, $b ) => $b['start'] <=> $a['start'] );
		foreach ( $trees as $tree ) {
			$ref     = '<!-- wp:' . Form_Post_Type::REF_BLOCK . ' {"ref":' . (int) $ids[ $tree['key'] ] . '} /-->';
			$content = substr_replace( $content, $ref, $tree['start'], $tree['length'] );
		}
		return $content;
	}

	// --------------------------------------------------------------- the run

	/**
	 * Runs the migration (or, dry, says what it would do).
	 *
	 * @param int[] $only Only these post ids (empty: all).
	 * @return array<string, mixed>
	 */
	public static function run( bool $dry_run, array $only = array() ): array {
		global $wpdb;
		self::$dry    = $dry_run;
		self::$report = array(
			'seco'         => $dry_run,
			'ya'           => false,
			'resumen'      => '',
			'wpml'         => null,
			'formularios'  => array(),
			'contenido'    => array(),
			'avisos'       => array(),
		);
		$wpml    = Language::wpml_active();
		$default = $wpml ? (string) apply_filters( 'wpml_default_language', null ) : '';

		$posts = array();
		foreach ( self::candidates( $only ) as $row ) {
			$posts[] = array(
				'id'      => (int) $row->ID,
				'type'    => (string) $row->post_type,
				'title'   => (string) $row->post_title,
				'slug'    => (string) $row->post_name,
				'status'  => (string) $row->post_status,
				'lang'    => self::lang_of( (int) $row->ID ),
				'content' => (string) $row->post_content,
			);
		}
		$plan = self::plan( $posts, self::existing(), $default );
		foreach ( $plan['errors'] as $error ) {
			self::$report['avisos'][] = $error;
		}
		if ( ! $plan['posts'] ) {
			self::$report['ya']      = true;
			self::$report['resumen'] = 'ya: no form left inside the content (nothing to do)';
			return self::$report;
		}

		if ( $wpml && ! $dry_run && class_exists( '\WPML_Config' ) && method_exists( '\WPML_Config', 'import_now' ) ) {
			\WPML_Config::import_now(); // eweb_form must be translatable BEFORE the translations are linked (header, step 3).
		}
		if ( $wpml ) {
			self::$report['wpml'] = array(
				'idioma_por_defecto' => $default,
				'traducible'         => (bool) apply_filters( 'wpml_is_translated_post_type', false, Form_Post_Type::TYPE ),
			);
			if ( ! self::$report['wpml']['traducible'] ) {
				self::$report['avisos'][] = $dry_run
					? 'WPML does not translate eweb_form yet: the real run asks WPML to re-read its config first (or open WPML › Settings once).'
					: 'WPML still does not translate eweb_form after re-reading its config: the forms were created and linked, but every language will show the original until WPML › Settings › Post types translation says «Translatable».';
			}
		}

		// Originals first: a translation needs its original's id (and trid).
		$keys = array_keys( $plan['forms'] );
		usort( $keys, static fn( $a, $b ) => ( '' === $plan['forms'][ $a ]['original'] ? 0 : 1 ) <=> ( '' === $plan['forms'][ $b ]['original'] ? 0 : 1 ) );
		$ids    = array();
		$failed = array();
		$by_id  = array_column( $posts, null, 'id' );
		if ( ! $dry_run ) {
			kses_remove_filters();
		}
		foreach ( $keys as $key ) {
			$form  = $plan['forms'][ $key ];
			$entry = array(
				'form_id'   => $form['form_id'],
				'idioma'    => $form['lang'],
				'titulo'    => $form['title'],
				'accion'    => $form['existing'] ? 'reuse' : 'create',
				'id'        => $form['existing'] ? $form['existing'] : null,
				'origen'    => $form['source'],
				'copias'    => $form['copies'],
				'iguales'   => $form['identical'],
				'traduccion_de' => '' !== $form['original'] ? $plan['forms'][ $form['original'] ]['form_id'] . '|' . $plan['forms'][ $form['original'] ]['lang'] : null,
			);
			if ( $form['copies'] ) {
				self::$report['avisos'][] = sprintf(
					'«%s»%s is in %d posts (%s): ONE form post%s.',
					'' !== $form['form_id'] ? $form['form_id'] : '(no identifier)',
					'' !== $form['lang'] ? " ({$form['lang']})" : '',
					1 + count( $form['copies'] ),
					implode( ', ', array_merge( array( $form['source'] ), $form['copies'] ) ),
					$form['identical'] ? ', the copies are identical' : ", BUT THE COPIES DIFFER: post {$form['source']}'s version is kept, check the others"
				);
			}
			if ( $form['existing'] ) {
				$ids[ $key ] = $form['existing'];
			} elseif ( ! $dry_run ) {
				$id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => Form_Post_Type::TYPE,
							'post_status'  => 'publish',
							'post_title'   => $form['title'],
							'post_content' => $form['markup'],
						)
					),
					true
				);
				if ( is_wp_error( $id ) || ! $id ) {
					$failed[ $key ]           = true;
					self::$report['avisos'][] = "could not create the form «{$key}»: " . ( is_wp_error( $id ) ? $id->get_error_message() : 'unknown error' ) . ' (its posts are left as they are)';
				} else {
					$ids[ $key ] = (int) $id;
					$entry['id'] = (int) $id;
					if ( $wpml && '' !== $form['lang'] ) {
						self::link_language( (int) $id, $form['lang'], '' !== $form['original'] ? ( $ids[ $form['original'] ] ?? 0 ) : 0, '' !== $form['original'] ? $plan['forms'][ $form['original'] ]['lang'] : null );
					}
				}
			}
			self::$report['formularios'][] = $entry;
		}

		foreach ( $plan['posts'] as $post_id => $item ) {
			$post   = $by_id[ $post_id ];
			$blocked = array_filter( $item['trees'], static fn( $t ) => isset( $failed[ $t['key'] ] ) );
			if ( $blocked ) {
				continue;
			}
			$new   = $dry_run ? null : self::replace( $post['content'], $item['trees'], $ids );
			$entry = array(
				'id'          => $post_id,
				'tipo'        => $post['type'],
				'slug'        => $post['slug'],
				'estado'      => $post['status'],
				'idioma'      => $post['lang'],
				'formularios' => array_values( array_unique( array_map( static fn( $t ) => $t['key'], $item['trees'] ) ) ),
			);
			if ( ! $dry_run ) {
				add_post_meta( $post_id, self::BACKUP_META, wp_slash( $post['content'] ) );
				$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( $post_id );
			}
			/**
			 * A post whose content the forms migrator changes (also on a dry
			 * run, without writing: then $new is null). Whatever the filter
			 * returns shows up in the report.
			 *
			 * @param array       $entry What shows up in the report.
			 * @param string      $old   The content before.
			 * @param string|null $new   The content after (null on a dry run).
			 * @param bool        $dry   Dry run.
			 */
			self::$report['contenido'][] = apply_filters( 'estrategia_web_forms_migration_post', $entry, $post['content'], $new, $dry_run );
		}
		if ( ! $dry_run ) {
			kses_init();
		}

		$create = count( array_filter( self::$report['formularios'], static fn( $f ) => 'create' === $f['accion'] ) );
		$reuse  = count( self::$report['formularios'] ) - $create;
		self::$report['resumen'] = sprintf(
			'%s%d form post(s) %s, %d reused; %d post(s) %s',
			$dry_run ? 'DRY RUN: ' : '',
			$create,
			$dry_run ? 'to create' : 'created',
			$reuse,
			count( self::$report['contenido'] ),
			$dry_run ? 'to change' : 'changed (original in the meta ' . self::BACKUP_META . ')'
		);
		return self::$report;
	}

	/** Posts whose content has a form block (the LIKE narrows; find_trees() decides). */
	private static function candidates( array $only ): array {
		global $wpdb;
		$likes = array();
		$args  = Forms_List::STATUSES;
		foreach ( self::NAMESPACES as $ns ) {
			$likes[] = 'post_content LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( '<!-- wp:' . $ns . '/form' ) . '%';
		}
		$statuses = implode( ',', array_fill( 0, count( Forms_List::STATUSES ), '%s' ) );
		$sql      = "SELECT ID, post_type, post_title, post_name, post_status, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', '" . Form_Post_Type::TYPE . "') AND post_status IN ($statuses) AND (" . implode( ' OR ', $likes ) . ')';
		$only     = array_values( array_filter( array_map( 'intval', $only ) ) );
		if ( $only ) {
			$sql .= ' AND ID IN (' . implode( ',', $only ) . ')';
		}
		return (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY ID', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** @return list<array{id:int,form_id:string,lang:string}> The form posts that exist (not in the trash). */
	private static function existing(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft') ORDER BY ID", Form_Post_Type::TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( (array) $rows as $row ) {
			foreach ( self::find_trees( (string) $row->post_content ) as $tree ) {
				$out[] = array(
					'id'      => (int) $row->ID,
					'form_id' => $tree['form_id'],
					'lang'    => self::lang_of( (int) $row->ID ),
				);
			}
		}
		return $out;
	}

	/** WPML language code of a post, or '' (no WPML, or no language). */
	private static function lang_of( int $id ): string {
		if ( ! Language::wpml_active() ) {
			return '';
		}
		$details = apply_filters( 'wpml_post_language_details', null, $id );
		return is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
	}

	/** Gives a new form post its language and, for a translation, its original's trid. */
	private static function link_language( int $id, string $lang, int $original_id, ?string $source_lang ): void {
		$element_type = 'post_' . Form_Post_Type::TYPE;
		$trid         = $original_id ? apply_filters( 'wpml_element_trid', null, $original_id, $element_type ) : null;
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $id,
				'element_type'         => $element_type,
				'trid'                 => $trid ? (int) $trid : false,
				'language_code'        => $lang,
				'source_language_code' => $trid ? $source_lang : null,
			)
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'estrategia-web migrate-forms',
		static function ( $args, $assoc ) {
			$only   = isset( $assoc['ids'] ) ? array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) : array();
			$report = Form_Migration::run( ! empty( $assoc['dry-run'] ), $only );
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			\WP_CLI::line( $report['resumen'] );
		},
		array( 'shortdesc' => 'Moves every form written inside a post (page, synced pattern…) to a form post of its own (eweb_form) and leaves the «Form» reference block where it was (2.0.0). With --dry-run it only says what it would do; --ids=1,2 limits it to those posts.' )
	);
}
