<?php
/**
 * The submissions list (WP_List_Table): filter by form, pagination and the
 * «Delete» bulk action, which leads to the confirmation screen.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Submissions_Table extends \WP_List_Table {

	public function __construct( private string $form_id = '' ) {
		parent::__construct(
			array(
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			)
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

	public function get_bulk_actions(): array {
		return array( 'delete' => __( 'Delete', 'estrategiaweb-toolkit' ) );
	}

	public function prepare_items(): void {
		$per_page              = 20;
		$result                = Submissions::query( $this->form_id, $per_page, $this->get_pagenum() );
		$this->items           = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), array() );
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
		$labels = array(
			'sent'    => __( 'All sent', 'estrategiaweb-toolkit' ),
			'partial' => __( 'Some failed', 'estrategiaweb-toolkit' ),
			'failed'  => __( 'Failed', 'estrategiaweb-toolkit' ),
			'pending' => __( 'Pending', 'estrategiaweb-toolkit' ),
		);
		return esc_html( $labels[ $item['status'] ] ?? $item['status'] );
	}
}
