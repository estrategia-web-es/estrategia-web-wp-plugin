<?php
/**
 * The submissions list (WP_List_Table): filter by form, views by
 * notification state, sorting, pagination and the «Delete» bulk action,
 * which leads to the confirmation screen.
 *
 * 2.8.0 (Juan, 02-11-2026: «es tedioso encontrar uno»), the list's own
 * methods, as the header of includes/admin/class-admin-list.php says for a
 * WP_List_Table:
 * - get_views(): «All (40) | Failed (2) | Some failed (1) | Pending | All
 *   sent», counted within the form chosen in the dropdown. A view only
 *   appears when it has submissions (WordPress does the same with Draft or
 *   Trash). «Failed» first: they are the messages nobody received.
 * - get_sortable_columns(): date (newest first on the first click) and form.
 * - extra_tablenav(): the form dropdown, which keeps the view and the order
 *   it was in (hidden fields: the form is a GET).
 * There is no «read / unread»: the log does not store who opened what, and
 * adding it would be a column in the table (a migration), not a list tool.
 *
 * How to maintain:
 * - Who and when: whoever adds a column or a state. A new state goes in
 *   Submissions::STATUSES and labels(); a new sortable column in
 *   Submissions::ORDER_BY — the SQL lives there, never built from the request.
 * - Edge case, by name: a submission whose form had NO title (form_title
 *   ''). The column shows its id, and sorting by form sorts by what is shown
 *   (COALESCE(NULLIF(form_title, ''), form_id)), or those would all go first.
 * - The trap: the list's form is a GET, so a dropdown submit only carries
 *   the fields inside the form. Without the hidden ew_status / orderby /
 *   order, choosing a form silently dropped the view and the sorting.
 * - Test: modules/forms/tests/submissions-list.test.mjs (level 2).
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Submissions_Table extends \WP_List_Table {

	/** The query string key of the views. */
	public const STATUS_VAR = 'ew_status';

	private string $status;
	private string $orderby;
	private string $order;

	public function __construct( private string $form_id = '' ) {
		parent::__construct(
			array(
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			)
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read only: the view and the order.
		$status        = isset( $_GET[ self::STATUS_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATUS_VAR ] ) ) : '';
		$orderby       = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order         = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		// phpcs:enable
		$this->status  = in_array( $status, Submissions::STATUSES, true ) ? $status : '';
		$this->orderby = isset( Submissions::ORDER_BY[ $orderby ] ) ? $orderby : '';
		$this->order   = in_array( $order, array( 'asc', 'desc' ), true ) ? $order : '';
	}

	/** What each state is called, in the column and in the views. */
	private static function labels(): array {
		return array(
			'sent'    => __( 'All sent', 'estrategiaweb-toolkit' ),
			'partial' => __( 'Some failed', 'estrategiaweb-toolkit' ),
			'failed'  => __( 'Failed', 'estrategiaweb-toolkit' ),
			'pending' => __( 'Pending', 'estrategiaweb-toolkit' ),
		);
	}

	public function get_columns(): array {
		return array(
			'cb'      => '<input type="checkbox">',
			'date'    => __( 'Date', 'estrategiaweb-toolkit' ),
			'form'    => __( 'Form', 'estrategiaweb-toolkit' ),
			'summary' => __( 'Data', 'estrategiaweb-toolkit' ),
			'lang'    => __( 'Language', 'estrategiaweb-toolkit' ),
			'status'  => __( 'Notifications', 'estrategiaweb-toolkit' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'date' => array( 'date', true ), // true: the first click sorts descending (newest first).
			'form' => array( 'form', false ),
		);
	}

	protected function get_views(): array {
		$counts = Submissions::count_by_status( $this->form_id );
		$base   = add_query_arg( array_filter( array( 'page' => Admin::PAGE, 'form_id' => $this->form_id ) ), admin_url( 'admin.php' ) );
		$link   = static fn( string $url, string $label, int $count, bool $current ): string => sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
		$views  = array( 'all' => $link( $base, _x( 'All', 'list view', 'estrategiaweb-toolkit' ), array_sum( $counts ), '' === $this->status ) );
		$labels = self::labels();
		foreach ( Submissions::STATUSES as $status ) {
			if ( ! empty( $counts[ $status ] ) ) {
				$views[ $status ] = $link( add_query_arg( self::STATUS_VAR, $status, $base ), $labels[ $status ], $counts[ $status ], $this->status === $status );
			}
		}
		return $views;
	}

	public function get_bulk_actions(): array {
		return array( 'delete' => __( 'Delete', 'estrategiaweb-toolkit' ) );
	}

	public function prepare_items(): void {
		$per_page              = 20;
		$result                = Submissions::query( $this->form_id, $per_page, $this->get_pagenum(), $this->status, $this->orderby ?: 'date', $this->order ?: 'desc' );
		$this->items           = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'date' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	public function no_items(): void {
		esc_html_e( 'No submissions yet.', 'estrategiaweb-toolkit' );
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$forms = Submissions::forms();
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="af-filter-form">' . esc_html__( 'Filter by form', 'estrategiaweb-toolkit' ) . '</label>';
		echo '<select name="form_id" id="af-filter-form"><option value="">' . esc_html__( 'All forms', 'estrategiaweb-toolkit' ) . '</option>';
		foreach ( $forms as $id => $title ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $id ), selected( $this->form_id, $id, false ), esc_html( $title ) );
		}
		echo '</select>';
		// The view and the order travel with the dropdown (the trap in the header).
		foreach ( array( self::STATUS_VAR => $this->status, 'orderby' => $this->orderby, 'order' => $this->order ) as $name => $value ) {
			if ( '' !== $value ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( $value ) );
			}
		}
		submit_button( __( 'Filter', 'estrategiaweb-toolkit' ), '', 'filter_action', false );
		echo '</div>';
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d">', (int) $item['id'] );
	}

	protected function column_date( $item ): string {
		$view   = add_query_arg( array( 'page' => Admin::PAGE, 'action' => 'view', 'id' => $item['id'] ), admin_url( 'admin.php' ) );
		$delete = add_query_arg( array( 'page' => Admin::PAGE, 'action' => 'delete', 'id' => $item['id'] ), admin_url( 'admin.php' ) );
		$date   = get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		return sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( $view ), esc_html( $date ) )
			. $this->row_actions(
				array(
					'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'View', 'estrategiaweb-toolkit' ) ),
					'delete' => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete ), esc_html__( 'Delete', 'estrategiaweb-toolkit' ) ),
				)
			);
	}

	protected function column_form( $item ): string {
		return esc_html( '' !== $item['form_title'] ? $item['form_title'] : $item['form_id'] );
	}

	protected function column_summary( $item ): string {
		$parts = array();
		foreach ( array_slice( $item['data'], 0, 3 ) as $field ) {
			if ( 'checkbox' === ( $field['type'] ?? '' ) || '' === (string) $field['value'] ) {
				continue;
			}
			$parts[] = wp_html_excerpt( (string) $field['value'], 60, '…' );
		}
		return esc_html( implode( ' · ', $parts ) );
	}

	protected function column_lang( $item ): string {
		return esc_html( $item['lang'] );
	}

	protected function column_status( $item ): string {
		return esc_html( self::labels()[ $item['status'] ] ?? $item['status'] );
	}
}
