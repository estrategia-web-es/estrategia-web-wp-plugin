<?php
/**
 * The list of «Estrategia Web → Content types» (2.0.0): a WP_List_Table,
 * like the redirects and the forms submissions, one row per type of
 * Rest::index() — from this panel or from a theme file. Painted by PHP, so
 * it is the same markup as every WordPress list (row actions on hover AND
 * on keyboard focus, the mobile «Show more details» toggle) and it is
 * tested at level 2 without a browser (tests/constructor-screens.test.mjs).
 *
 * The row actions that WRITE (Deactivate / Activate, Delete) and Export are
 * <button>s that assets/constructor.js wires to the REST route — nothing is
 * written from PHP here, so the route keeps being the only door (its
 * validation, its «has entries» refusal). Edit and «Copy to this panel» are
 * plain links to the edit screen (Page::url( ['type' => …] )).
 *
 * How to maintain:
 * - Who and when: whoever adds an action per type. A link if it opens a
 *   screen; a button with data-action="<name>" and its handler in
 *   constructor.js (list view) if it writes, and its REST route in
 *   class-rest.php. Its text in this file (it is PHP's) — not in
 *   Page::strings(), which is only what JS paints.
 * - Boundary case, with a name: a type from a FILE (gment_restaurant in the
 *   tests). It cannot be switched off or deleted here (its file decides),
 *   so its row offers «Copy to this panel» and Export only.
 * - The trap: the data-* hooks (tr[data-type], [data-action]) are what the
 *   level 2 and 3 tests and constructor.js look for: renaming one breaks
 *   them silently for a person (a button that does nothing). Keep them.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Types_Table extends \WP_List_Table {

	/** @var array Rest::index(). */
	private array $data;

	public function __construct( array $data ) {
		$this->data = $data;
		parent::__construct(
			array(
				'singular' => 'content_type',
				'plural'   => 'content_types',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'title'   => __( 'Name', 'estrategia-web' ),
			'name'    => __( 'Internal name', 'estrategia-web' ),
			'url'     => __( 'URL', 'estrategia-web' ),
			'fields'  => __( 'Fields', 'estrategia-web' ),
			'source'  => __( 'Where it is defined', 'estrategia-web' ),
			'status'  => __( 'Status', 'estrategia-web' ),
			'entries' => __( 'Entries', 'estrategia-web' ),
		);
	}

	public function prepare_items(): void {
		$this->items           = $this->data['types'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'title' );
	}

	public function no_items(): void {
		esc_html_e( 'There are no content types yet. Add one, start from a template or import a JSON file.', 'estrategia-web' );
	}

	/** No bulk actions, no pagination: the nav bars would be empty. */
	protected function display_tablenav( $which ): void {}

	/** @param array $item */
	public function single_row( $item ): void {
		echo '<tr data-type="' . esc_attr( $item['name'] ) . '" data-source="' . esc_attr( $item['source'] ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	private function lang(): string {
		return $this->data['languages'][0] ?? 'en';
	}

	private static function def( array $item ): array {
		return is_array( $item['definition'] ) ? $item['definition'] : array();
	}

	/** A text key in the site's first language; '' for anything that is not a string or locale → string (a broken file). */
	private function text_of( $value ): string {
		if ( is_array( $value ) && array_filter( $value, static fn( $v ) => ! is_string( $v ) ) ) {
			return '';
		}
		return is_string( $value ) || is_array( $value ) ? Schema::text( $value, $this->lang() ) : '';
	}

	/** The plural label in the site's first language, or the internal name. */
	public function label( array $item ): string {
		$label = $this->text_of( self::def( $item )['labels']['plural'] ?? null );
		return '' !== $label ? $label : $item['name'];
	}

	protected function column_title( array $item ): string {
		$label = $this->label( $item );
		$edit  = Page::url( array( 'type' => $item['name'] ) );
		$data  = ' data-type="' . esc_attr( $item['name'] ) . '" data-label="' . esc_attr( $label ) . '"';
		$link  = static fn( string $action, string $text ) => '<button type="button" class="button-link" data-action="' . $action . '"' . $data . '>' . esc_html( $text ) . '</button>';
		$actions = array();
		if ( 'database' === $item['source'] ) {
			$actions['edit'] = '<a href="' . esc_url( $edit ) . '" data-action="edit">' . esc_html__( 'Edit', 'estrategia-web' ) . '</a>';
			$actions['toggle'] = '<button type="button" class="button-link" data-action="toggle" data-active="' . ( $item['active'] ? '1' : '0' ) . '"' . $data . '>' . esc_html( $item['active'] ? __( 'Deactivate', 'estrategia-web' ) : __( 'Activate', 'estrategia-web' ) ) . '</button>';
		} else {
			$actions['copy'] = '<a href="' . esc_url( $edit ) . '" data-action="copy">' . esc_html__( 'Copy to this panel', 'estrategia-web' ) . '</a>';
		}
		$actions['export'] = $link( 'export', __( 'Export JSON', 'estrategia-web' ) );
		if ( 'database' === $item['source'] ) {
			$actions['delete'] = '<button type="button" class="button-link button-link-delete" data-action="delete"' . $data . '>' . esc_html__( 'Delete', 'estrategia-web' ) . '</button>';
		}
		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $label ) . '</a></strong>' . $this->row_actions( $actions );
	}

	protected function column_name( array $item ): string {
		return '<code>' . esc_html( $item['name'] ) . '</code>';
	}

	protected function column_url( array $item ): string {
		$slug = $this->text_of( self::def( $item )['slug'] ?? null );
		return '' === $slug ? '<span aria-hidden="true">—</span>' : '<code>/' . esc_html( $slug ) . '/</code>';
	}

	protected function column_fields( array $item ): string {
		$fields = self::def( $item )['fields'] ?? array();
		return esc_html( number_format_i18n( is_array( $fields ) ? count( $fields ) : 0 ) );
	}

	protected function column_source( array $item ): string {
		if ( 'file' === $item['source'] ) {
			/* translators: %s: a file path */
			return esc_html( sprintf( __( 'File (read-only): %s', 'estrategia-web' ), $item['file'] ) );
		}
		foreach ( $this->data['templates'] as $template ) {
			if ( $item['template'] && $template['key'] === $item['template'] ) {
				/* translators: %s: a template name, e.g. Testimonials */
				return esc_html( sprintf( __( 'This panel (from the template “%s”)', 'estrategia-web' ), $template['label'] ) );
			}
		}
		return esc_html__( 'This panel', 'estrategia-web' );
	}

	protected function column_status( array $item ): string {
		if ( $item['errors'] ) {
			$list = '<ul class="ul-disc">' . implode( '', array_map( static fn( $e ) => '<li>' . esc_html( (string) $e ) . '</li>', $item['errors'] ) ) . '</ul>';
			return '<strong>' . esc_html__( 'Not registered: it has errors', 'estrategia-web' ) . '</strong>' . $list;
		}
		return $item['active'] ? esc_html__( 'Active', 'estrategia-web' ) : '<strong>' . esc_html__( 'Inactive', 'estrategia-web' ) . '</strong>';
	}

	protected function column_entries( array $item ): string {
		return esc_html( number_format_i18n( (int) $item['entries'] ) );
	}
}
