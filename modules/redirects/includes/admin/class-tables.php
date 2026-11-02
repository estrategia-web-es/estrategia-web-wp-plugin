<?php
/**
 * The screen's two tables: rules and 404 log (WP_List_Table, like the forms
 * submissions one).
 *
 * 2.8.0 (Juan, 02-11-2026: «es tedioso encontrar uno»), the list tools of a
 * WP_List_Table (header of includes/admin/class-admin-list.php):
 * - Rules: views «All (83) | 301 (80) | 302 (1) | 410 Gone (2) | Inactive»,
 *   which are the filter by type — links, not a dropdown, because the
 *   table's form is a POST (the bulk actions) and a dropdown there would
 *   lose the filter on the next page. A view only appears when it has
 *   rules. They count within the search, and the search keeps the view.
 *   Sorting, already there: source, destination, type, hits, last hit.
 * - 404 log: sortable by first seen as well (when a broken address started).
 *
 * How to maintain:
 * - Who and when: whoever adds a column or a kind of rule. A new type in
 *   Rules::TYPES gets its view in Store::VIEWS and its label in views_labels().
 * - Edge case, by name: a rule that NEVER fired (last_hit NULL, «—»). Sorting
 *   by last hit puts those last in BOTH directions (Store::query()).
 * - The trap: ?view= is already the screen's TAB (list, edit, log, import),
 *   so the views use ew_view. Calling it «view» sent the list to the edit tab.
 * - Test: modules/redirects/tests/admin-list.test.mjs (level 2).
 */

namespace EstrategiaWeb\Redirects\Admin;

use EstrategiaWeb\Redirects\Store;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/** The rules. */
final class Rules_Table extends \WP_List_Table {

	/** The query string key of the views (not «view»: that is the screen's tab). */
	public const VIEW_VAR = 'ew_view';

	/** The current view: '' or a key of Store::VIEWS. */
	public static function current_view(): string {
		$view = isset( $_GET[ self::VIEW_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::VIEW_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
		return isset( Store::VIEWS[ $view ] ) ? $view : '';
	}

	private static function views_labels(): array {
		return array(
			''         => _x( 'All', 'list view', 'estrategia-web' ),
			'301'      => '301',
			'302'      => '302',
			'410'      => __( '410 Gone', 'estrategia-web' ),
			'inactive' => __( 'Inactive', 'estrategia-web' ),
		);
	}

	protected function get_views(): array {
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only.
		$counts  = Store::view_counts( $search );
		$current = self::current_view();
		$views   = array();
		foreach ( self::views_labels() as $key => $label ) {
			$key = (string) $key; // PHP turns the key '301' into the int 301: back to a string, or === never matches.
			if ( '' !== $key && empty( $counts[ $key ] ) ) {
				continue;
			}
			$url = Page::url( array_filter( array( self::VIEW_VAR => $key, 's' => $search ), 'strlen' ) );

			$views[ '' === $key ? 'all' : $key ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $key ] ) )
			);
		}
		return $views;
	}

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'redirect',
				'plural'   => 'redirects',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'       => '<input type="checkbox" />',
			'source'   => __( 'Source', 'estrategia-web' ),
			'target'   => __( 'Destination', 'estrategia-web' ),
			'type'     => __( 'Type', 'estrategia-web' ),
			'hits'     => __( 'Hits', 'estrategia-web' ),
			'last_hit' => __( 'Last hit', 'estrategia-web' ),
			'active'   => __( 'Active', 'estrategia-web' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'source'   => array( 'source', false ),
			'target'   => array( 'target', false ),
			'type'     => array( 'type', false ),
			'hits'     => array( 'hits', true ),
			'last_hit' => array( 'last_hit', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'activate'   => __( 'Activate', 'estrategia-web' ),
			'deactivate' => __( 'Deactivate', 'estrategia-web' ),
			'delete'     => __( 'Delete', 'estrategia-web' ),
		);
	}

	public function prepare_items(): void {
		$per_page = 50;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read only: search, order and page.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'source';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'asc';
		// phpcs:enable
		list( $this->items, $total ) = Store::query( $search, $per_page, $this->get_pagenum(), $orderby, $order, self::current_view() );
		$this->_column_headers       = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'source' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items(): void {
		esc_html_e( 'No redirects yet.', 'estrategia-web' );
	}

	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item['id'] . '" />';
	}

	protected function column_source( array $item ): string {
		$edit    = Page::url( array( 'view' => 'edit', 'id' => (int) $item['id'] ) );
		$delete  = wp_nonce_url( Page::url( array( 'do' => 'delete', 'id' => (int) $item['id'] ) ), Page::NONCE );
		$actions = array(
			'edit'   => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'estrategia-web' ) . '</a>',
			'delete' => '<a class="submitdelete" href="' . esc_url( $delete ) . '" onclick="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'Delete this redirect?', 'estrategia-web' ) . '">' . esc_html__( 'Delete', 'estrategia-web' ) . '</a>',
		);
		$note    = '' !== (string) $item['note'] ? '<br><span class="description">' . esc_html( (string) $item['note'] ) . '</span>' : '';
		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '"><code>' . esc_html( (string) $item['source'] ) . '</code></a></strong>' . $note . $this->row_actions( $actions );
	}

	protected function column_target( array $item ): string {
		return '' === (string) $item['target'] ? '<span aria-hidden="true">—</span>' : '<code>' . esc_html( (string) $item['target'] ) . '</code>';
	}

	protected function column_type( array $item ): string {
		return 410 === (int) $item['type'] ? esc_html__( '410 Gone', 'estrategia-web' ) : esc_html( (string) (int) $item['type'] );
	}

	protected function column_hits( array $item ): string {
		return esc_html( number_format_i18n( (int) $item['hits'] ) );
	}

	protected function column_last_hit( array $item ): string {
		return $item['last_hit'] ? esc_html( get_date_from_gmt( (string) $item['last_hit'], 'Y-m-d H:i' ) ) : '—';
	}

	protected function column_active( array $item ): string {
		return $item['active'] ? esc_html__( 'Yes', 'estrategia-web' ) : '<strong>' . esc_html__( 'No', 'estrategia-web' ) . '</strong>';
	}
}

/** The 404 log. */
final class Log_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'not_found',
				'plural'   => 'not_found',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'path'       => __( 'Path', 'estrategia-web' ),
			'hits'       => __( 'Hits', 'estrategia-web' ),
			'first_seen' => __( 'First seen', 'estrategia-web' ),
			'last_seen'  => __( 'Last seen', 'estrategia-web' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'path'       => array( 'path', false ),
			'hits'       => array( 'hits', true ),
			'first_seen' => array( 'first_seen', true ),
			'last_seen'  => array( 'last_seen', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return array( 'log_delete' => __( 'Delete', 'estrategia-web' ) );
	}

	public function prepare_items(): void {
		$per_page = 50;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read only.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'hits';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc';
		// phpcs:enable
		list( $this->items, $total ) = Store::log_query( $search, $per_page, $this->get_pagenum(), $orderby, $order );
		$this->_column_headers       = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'path' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items(): void {
		esc_html_e( 'No 404 errors recorded.', 'estrategia-web' );
	}

	protected function column_cb( $item ): string {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item['id'] . '" />';
	}

	protected function column_path( array $item ): string {
		$create  = Page::url( array( 'view' => 'edit', 'source' => (string) $item['path'] ) );
		$actions = array(
			'create' => '<a href="' . esc_url( $create ) . '">' . esc_html__( 'Create redirect', 'estrategia-web' ) . '</a>',
		);
		return '<code>' . esc_html( (string) $item['path'] ) . '</code>' . $this->row_actions( $actions, true );
	}

	protected function column_hits( array $item ): string {
		return esc_html( number_format_i18n( (int) $item['hits'] ) );
	}

	protected function column_first_seen( array $item ): string {
		return esc_html( get_date_from_gmt( (string) $item['first_seen'], 'Y-m-d H:i' ) );
	}

	protected function column_last_seen( array $item ): string {
		return esc_html( get_date_from_gmt( (string) $item['last_seen'], 'Y-m-d H:i' ) );
	}
}
