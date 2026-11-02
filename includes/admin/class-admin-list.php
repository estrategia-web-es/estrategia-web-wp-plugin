<?php
/**
 * WORDPRESS'S OWN LIST TABLE, USED IN FULL (2.5.0, Juan's review of
 * Gastronòmicament, 02-11-2026: «ninguno de los campos tiene filtro para
 * ordenarlos… está todo agrupado y es tedioso encontrar uno»).
 * A module's post type list (edit.php?post_type=…) already knows how to sort
 * by a column, filter with dropdowns above the table and show views with
 * counts («All (37) | Published (30)»). Nothing new is built: this declares
 * those three things through WordPress's hooks, so each module does it in
 * three calls instead of copying the same fifty lines:
 *   views( type, var, views )         more views next to WordPress's, with
 *                                     their count: «Showing (12) | Expired
 *                                     (20)». Each view is a status plus a
 *                                     meta_query, computed when shown.
 *   sortable( type, column => meta )  a column sorts by a numeric meta;
 *                                     rows without it are kept (last).
 *   sortable_sql( type, column, fn )  a column sorts by something that is
 *                                     not one meta (2.6.0): a computed state,
 *                                     totals in the module's own table.
 *   taxonomy_filter( type, taxonomy ) the dropdown «All zones / Lateral…».
 *                                     Several on one list ADD UP (2.8.0):
 *                                     «Italian» and «Palma» is both.
 * Who uses it, and the hand-built lists: the docblock of the class, below.
 * How to maintain:
 * - Who: whoever gives a module a list of records, in the same commit as
 *   its level 2 test (modules/banners/tests/admin-list.test.mjs is the model).
 * - Criterion: only what WordPress's list table can show, and only what
 *   helps someone FIND a record. A summary or a chart is not a list: it goes
 *   on the module's own screen. Why no «dropdown per select field»: below.
 * - Edge case, by name: a view whose condition is «no end date». The meta
 *   is 0 or missing, so the meta_query needs NOT EXISTS as well as = 0, or
 *   the old banners without the field disappear from «Showing».
 * - Edge case, by name (2.8.0): gment_cuisine, ONE taxonomy shared by
 *   restaurants and wines. A term's count is of every type, so «Italian (5)»
 *   on the wines list would count restaurants: the dropdown shows counts
 *   only when the taxonomy belongs to that type alone.
 * - The trap: sorting by a meta with orderby=meta_value_num and meta_key
 *   hides every post that does not have that meta. Hence the named clause
 *   with EXISTS / NOT EXISTS in sortable().
 * - The other trap (2.8.0): each taxonomy_filter() used to SET tax_query,
 *   so with two dropdowns only the last one filtered. It appends now.
 * - This header's trap: Plugin Check missed the ABSPATH line at byte 3409
 *   (found at 3098). More text goes in the class docblock.
 */

namespace EstrategiaWeb\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Who uses it (2.8.0): banners (Post_Type::list_tools()) and the content
 * types' entries (modules/content-types/includes/admin/class-list-tools.php,
 * one dropdown per taxonomy of each type).
 *
 * The list tables built by hand (forms' submissions, redirects) are
 * WP_List_Table subclasses: there the same three things are its own methods
 * (get_views, get_sortable_columns, extra_tablenav), not this. Since 2.8.0
 * both have them: Submissions_Table and Rules_Table / Log_Table.
 *
 * There is no generic «dropdown per select field»: a field that only says
 * how something LOOKS (a client's «image fit») would be noise next to one
 * that classifies (a restaurant's price range), and nothing tells them apart.
 */
final class Admin_List {

	/** Is $query the main query of the list screen of $type? */
	private static function is_list( \WP_Query $query, string $type ): bool {
		global $pagenow;
		return is_admin() && $query->is_main_query() && 'edit.php' === $pagenow && $type === $query->get( 'post_type' );
	}

	/**
	 * Views with a count next to WordPress's own («All | Published | Draft»).
	 *
	 * @param string                                   $type  Post type.
	 * @param string                                   $var   Query string key, e.g. ewb_state.
	 * @param array<string, array{label: string, args: callable(): array}> $views slug => label and a callable returning WP_Query args (post_status, meta_query…).
	 */
	public static function views( string $type, string $var, array $views ): void {
		add_filter(
			'views_edit-' . $type,
			static function ( array $links ) use ( $type, $var, $views ): array {
				$current = isset( $_GET[ $var ] ) ? sanitize_key( wp_unslash( $_GET[ $var ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
				foreach ( $views as $slug => $view ) {
					$count = ( new \WP_Query(
						array_merge(
							array(
								'post_type'      => $type,
								'fields'         => 'ids',
								'posts_per_page' => 1,
								'no_found_rows'  => false,
							),
							( $view['args'] )()
						)
					) )->found_posts;
					$links[ $var . '-' . $slug ] = sprintf(
						'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
						esc_url( add_query_arg( array( 'post_type' => $type, $var => $slug ), admin_url( 'edit.php' ) ) ),
						$current === $slug ? ' class="current" aria-current="page"' : '',
						esc_html( $view['label'] ),
						esc_html( number_format_i18n( $count ) )
					);
				}
				if ( '' !== $current && isset( $links['all'] ) ) {
					$links['all'] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $links['all'] );
				}
				return $links;
			}
		);
		add_action(
			'pre_get_posts',
			static function ( \WP_Query $query ) use ( $type, $var, $views ): void {
				$slug = isset( $_GET[ $var ] ) ? sanitize_key( wp_unslash( $_GET[ $var ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
				if ( '' === $slug || ! isset( $views[ $slug ] ) || ! self::is_list( $query, $type ) ) {
					return;
				}
				foreach ( ( $views[ $slug ]['args'] )() as $key => $value ) {
					$query->set( $key, $value );
				}
			}
		);
	}

	/**
	 * Columns that sort by a numeric meta.
	 *
	 * @param string                $type    Post type.
	 * @param array<string, string> $columns column key => meta key.
	 */
	public static function sortable( string $type, array $columns ): void {
		add_filter(
			'manage_edit-' . $type . '_sortable_columns',
			static function ( array $sortable ) use ( $columns ): array {
				foreach ( array_keys( $columns ) as $column ) {
					$sortable[ $column ] = array( $column, true ); // true: the first click sorts descending (newest first).
				}
				return $sortable;
			}
		);
		add_action(
			'pre_get_posts',
			static function ( \WP_Query $query ) use ( $type, $columns ): void {
				$orderby = (string) $query->get( 'orderby' );
				if ( ! isset( $columns[ $orderby ] ) || ! self::is_list( $query, $type ) ) {
					return;
				}
				$meta_query   = (array) $query->get( 'meta_query' );
				$meta_query[] = array(
					'relation'      => 'OR',
					'ew_sort_value' => array(
						'key'  => $columns[ $orderby ],
						'type' => 'NUMERIC',
					),
					array(
						'key'     => $columns[ $orderby ],
						'compare' => 'NOT EXISTS',
					),
				);
				$query->set( 'meta_query', $meta_query );
				$query->set( 'orderby', array( 'ew_sort_value' => 'asc' === strtolower( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC' ) );
			}
		);
	}

	/**
	 * A column that sorts by something computed or stored outside the post (2.6.0): a state worked out
	 * from several metas, totals in a module's own table. $clauses receives WordPress's SQL clauses of
	 * the list query and the direction, and returns them with its JOIN and ORDER BY.
	 * Aliases in the JOIN must be the module's own (ewb_…): the views' meta_query joins postmeta too.
	 *
	 * @param callable(array<string, string>, string): array<string, string> $clauses
	 */
	public static function sortable_sql( string $type, string $column, callable $clauses ): void {
		add_filter(
			'manage_edit-' . $type . '_sortable_columns',
			static function ( array $sortable ) use ( $column ): array {
				$sortable[ $column ] = array( $column, true );
				return $sortable;
			}
		);
		add_filter(
			'posts_clauses',
			static function ( array $sql, \WP_Query $query ) use ( $type, $column, $clauses ): array {
				if ( $column !== $query->get( 'orderby' ) || ! self::is_list( $query, $type ) ) {
					return $sql;
				}
				return $clauses( $sql, 'asc' === strtolower( (string) $query->get( 'order' ) ) ? 'ASC' : 'DESC' );
			},
			10,
			2
		);
	}

	/** The dropdown of a taxonomy above the list («All zones», then each term). */
	public static function taxonomy_filter( string $type, string $taxonomy ): void {
		add_action(
			'restrict_manage_posts',
			static function ( string $post_type ) use ( $type, $taxonomy ): void {
				$tax = get_taxonomy( $taxonomy );
				if ( $type !== $post_type || ! $tax ) {
					return;
				}
				wp_dropdown_categories(
					array(
						'taxonomy'        => $taxonomy,
						'name'            => $taxonomy,
						'value_field'     => 'slug',
						'show_option_all' => $tax->labels->all_items,
						'selected'        => isset( $_GET[ $taxonomy ] ) ? sanitize_title( wp_unslash( $_GET[ $taxonomy ] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
						'hide_empty'      => false,
						'hierarchical'    => true,
						'show_count'      => 1 === count( (array) $tax->object_type ), // A shared taxonomy's counts are of every type (header).
					)
				);
			}
		);
		// A taxonomy that is not public has no query var: the filter is applied here, not by WordPress.
		add_action(
			'pre_get_posts',
			static function ( \WP_Query $query ) use ( $type, $taxonomy ): void {
				$term = isset( $_GET[ $taxonomy ] ) ? sanitize_title( wp_unslash( $_GET[ $taxonomy ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
				if ( '' === $term || '0' === $term || ! self::is_list( $query, $type ) ) {
					return;
				}
				// Appended, not set: another dropdown of the same list may have added its own (the trap in the header).
				$tax_query   = (array) ( $query->get( 'tax_query' ) ?: array() ); // get() gives '' when unset, and (array) '' is [''].
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $term,
				);
				$query->set( 'tax_query', $tax_query );
			}
		);
	}
}
