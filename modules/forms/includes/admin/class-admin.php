<?php
/**
 * Admin screens: «Forms → Submissions» (list, filter by form, view one,
 * export CSV, delete with confirmation) and «Forms → Settings».
 *
 * Notifications are NOT configured here but in the form block (the editor
 * sidebar): see README, «Why the notifications live in the block».
 *
 * Who gets in: the 'manage_options' capability, changeable with the
 * 'estrategia_web_forms_capability' filter (e.g. 'edit_pages' so that an
 * editor can see the submissions). It is the same for viewing, exporting and
 * deleting: whoever can see the personal data can do everything else with
 * it, and splitting it would give a false sense of control.
 *
 * How to maintain: every action that changes something goes through
 * admin-post.php with its nonce and its capability check, and both are
 * tested in tests/admin.test.mjs. The trap: a GET link that deletes can be
 * triggered by an <img> on another site; that is why «Delete» leads to a
 * confirmation screen and the actual deletion is a POST.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const PAGE          = 'estrategia-web-forms';
	public const SETTINGS_PAGE = 'estrategia-web-forms-settings';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_estrategia_web_forms_export', array( self::class, 'export_csv' ) );
		add_action( 'admin_post_estrategia_web_forms_delete', array( self::class, 'delete' ) );
	}

	public static function capability(): string {
		return (string) apply_filters( 'estrategia_web_forms_capability', 'manage_options' );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Form submissions', 'estrategia-web' ),
			__( 'Forms', 'estrategia-web' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render_submissions' ),
			'dashicons-email-alt',
			26
		);
		add_submenu_page( self::PAGE, __( 'Form submissions', 'estrategia-web' ), __( 'Submissions', 'estrategia-web' ), self::capability(), self::PAGE, array( self::class, 'render_submissions' ) );
		add_submenu_page( self::PAGE, __( 'Form settings', 'estrategia-web' ), __( 'Settings', 'estrategia-web' ), 'manage_options', self::SETTINGS_PAGE, array( self::class, 'render_settings' ) );
	}

	public static function register_settings(): void {
		register_setting(
			'estrategia_web_forms',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::DEFAULTS,
			)
		);
	}

	public static function render_submissions(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to see this page.', 'estrategia-web' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read only; whatever writes goes through admin-post with a nonce.
		$action = sanitize_key( $_GET['action'] ?? '' );
		if ( '' === $action || '-1' === $action ) {
			$action = sanitize_key( $_GET['action2'] ?? '' );
		}
		if ( 'view' === $action && isset( $_GET['id'] ) ) {
			self::render_view( (int) $_GET['id'] );
			return;
		}
		if ( 'delete' === $action ) {
			$ids = isset( $_GET['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_GET['ids'] ) ) : array();
			if ( isset( $_GET['id'] ) ) {
				$ids[] = (int) $_GET['id'];
			}
			self::render_confirm_delete( array_values( array_unique( array_filter( $ids ) ) ) );
			return;
		}
		$form_id = Definitions::clean_id( (string) wp_unslash( $_GET['form_id'] ?? '' ) );
		$deleted = isset( $_GET['deleted'] ) ? (int) $_GET['deleted'] : null;
		// phpcs:enable

		$table = new Submissions_Table( $form_id );
		$table->prepare_items();
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'estrategia_web_forms_export', 'form_id' => $form_id ), admin_url( 'admin-post.php' ) ), 'estrategia_web_forms_export' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Form submissions', 'estrategia-web' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'estrategia-web' ); ?></a>
			<hr class="wp-header-end">
			<?php if ( null !== $deleted ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of deleted submissions. */
					echo esc_html( sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $deleted, 'estrategia-web' ), $deleted ) );
					?>
				</p></div>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_view( int $id ): void {
		$row  = Submissions::get( $id );
		$back = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<div class="wrap">';
		if ( ! $row ) {
			echo '<h1>' . esc_html__( 'Submission not found', 'estrategia-web' ) . '</h1>';
			echo '<p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to the list', 'estrategia-web' ) . '</a></p></div>';
			return;
		}
		/* translators: %d: submission number. */
		echo '<h1>' . esc_html( sprintf( __( 'Submission #%d', 'estrategia-web' ), $row['id'] ) ) . '</h1>';
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to the list', 'estrategia-web' ) . '</a></p>';

		echo '<h2>' . esc_html__( 'Data', 'estrategia-web' ) . '</h2><table class="widefat striped estrategia-web-forms-data"><tbody>';
		foreach ( $row['data'] as $field ) {
			$value = 'checkbox' === ( $field['type'] ?? '' ) ? ( '1' === $field['value'] ? __( 'Yes', 'estrategia-web' ) : __( 'No', 'estrategia-web' ) ) : (string) $field['value'];
			printf( '<tr><th scope="row" style="width:25%%">%s</th><td>%s</td></tr>', esc_html( $field['label'] ?: $field['name'] ), nl2br( esc_html( $value ) ) );
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Notifications', 'estrategia-web' ) . '</h2><table class="widefat striped estrategia-web-forms-notifications"><thead><tr>';
		echo '<th>' . esc_html__( 'Notification', 'estrategia-web' ) . '</th><th>' . esc_html__( 'Recipients', 'estrategia-web' ) . '</th><th>' . esc_html__( 'Result', 'estrategia-web' ) . '</th></tr></thead><tbody>';
		foreach ( $row['notifications'] as $n ) {
			$result = 'sent' === $n['status'] ? __( 'Sent', 'estrategia-web' ) : __( 'Failed', 'estrategia-web' ) . ': ' . $n['error'];
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $n['label'] ), esc_html( $n['to'] ), esc_html( $result ) );
		}
		if ( ! $row['notifications'] ) {
			echo '<tr><td colspan="3">' . esc_html__( 'This form has no notifications.', 'estrategia-web' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Details', 'estrategia-web' ) . '</h2><table class="widefat striped"><tbody>';
		$details = array(
			__( 'Form', 'estrategia-web' )       => $row['form_title'] . ' (' . $row['form_id'] . ')',
			__( 'Date', 'estrategia-web' )       => get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			__( 'Page', 'estrategia-web' )       => $row['page_url'],
			__( 'Language', 'estrategia-web' )   => $row['lang'],
			__( 'IP (truncated)', 'estrategia-web' ) => $row['ip'],
			__( 'User agent', 'estrategia-web' ) => $row['user_agent'],
		);
		foreach ( $details as $label => $value ) {
			printf( '<tr><th scope="row" style="width:25%%">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';
		$delete = add_query_arg( array( 'page' => self::PAGE, 'action' => 'delete', 'id' => $row['id'] ), admin_url( 'admin.php' ) );
		echo '<p><a class="button button-link-delete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Delete this submission', 'estrategia-web' ) . '</a></p>';
		echo '</div>';
	}

	/** @param int[] $ids */
	private static function render_confirm_delete( array $ids ): void {
		$back = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<div class="wrap"><h1>' . esc_html__( 'Delete submissions', 'estrategia-web' ) . '</h1>';
		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'No submissions selected.', 'estrategia-web' ) . '</p><p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to the list', 'estrategia-web' ) . '</a></p></div>';
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of submissions. */
				_n( 'You are about to permanently delete %d submission. This cannot be undone.', 'You are about to permanently delete %d submissions. This cannot be undone.', count( $ids ), 'estrategia-web' ),
				count( $ids )
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="estrategia_web_forms_delete">';
		wp_nonce_field( 'estrategia_web_forms_delete' );
		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="ids[]" value="' . esc_attr( (string) $id ) . '">';
		}
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Yes, delete', 'estrategia-web' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'estrategia-web' ) . '</a></p></form></div>';
	}

	public static function delete(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'estrategia-web' ), 403 );
		}
		check_admin_referer( 'estrategia_web_forms_delete' );
		$ids     = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$deleted = Submissions::delete( $ids );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'deleted' => $deleted ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function export_csv(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'estrategia-web' ), 403 );
		}
		check_admin_referer( 'estrategia_web_forms_export' );
		$form_id = Definitions::clean_id( (string) wp_unslash( $_GET['form_id'] ?? '' ) );
		$rows    = Submissions::query( $form_id, 0 )['rows'];

		// Data columns: the union of the fields of all submissions, in order of appearance.
		$fields = array();
		foreach ( $rows as $row ) {
			foreach ( $row['data'] as $field ) {
				$fields[ $field['name'] ] ??= $field['label'] ?: $field['name'];
			}
		}

		/** CSV separator. ';' by default: it is the one Spanish-locale Excel opens without asking. */
		$sep = (string) apply_filters( 'estrategia_web_forms_csv_separator', ';' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="estrategia-web-forms-' . ( $form_id ?: 'all' ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: without it Excel misreads accented characters.

		$header = array( 'id', 'date_utc', 'form_id', 'form', 'page', 'language', 'status', 'notifications', 'ip', 'user_agent' );
		fputcsv( $out, array_merge( $header, array_values( $fields ) ), $sep, '"', '' );
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row['data'] as $field ) {
				$values[ $field['name'] ] = $field['value'];
			}
			$notes = array();
			foreach ( $row['notifications'] as $n ) {
				$notes[] = $n['label'] . ': ' . $n['status'] . ( '' !== $n['error'] ? ' (' . $n['error'] . ')' : '' );
			}
			$line = array( $row['id'], $row['created_at'], $row['form_id'], $row['form_title'], $row['page_url'], $row['lang'], $row['status'], implode( ' | ', $notes ), $row['ip'], $row['user_agent'] );
			foreach ( array_keys( $fields ) as $name ) {
				$line[] = $values[ $name ] ?? '';
			}
			fputcsv( $out, array_map( array( self::class, 'csv_cell' ), $line ), $sep, '"', '' );
		}
		fclose( $out );
		exit;
	}

	/**
	 * CSV injection: a cell starting with = + - @ is run by Excel as a
	 * formula. The visitor types whatever they want, so it is neutralized.
	 */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		return '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to see this page.', 'estrategia-web' ), 403 );
		}
		$s     = Settings::all();
		$name  = Settings::OPTION;
		$purge = get_option( 'estrategia_web_forms_last_purge' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Form settings', 'estrategia-web' ); ?></h1>
			<p><?php esc_html_e( 'Fields, notifications and messages of each form are set in the form block, in the editor. Here only what is common to all forms.', 'estrategia-web' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'estrategia_web_forms' ); ?>
				<h2><?php esc_html_e( 'Submission log', 'estrategia-web' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="af-retention"><?php esc_html_e( 'Keep submissions for (days)', 'estrategia-web' ); ?></label></th>
						<td><input id="af-retention" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '0 keeps them forever. Older submissions are deleted once a day.', 'estrategia-web' ); ?>
							<?php
							if ( is_array( $purge ) ) {
								/* translators: 1: date of the last purge, 2: deleted submissions. */
								echo esc_html( sprintf( __( 'Last purge: %1$s, %2$d deleted.', 'estrategia-web' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $purge['at'] ) ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ), (int) $purge['deleted'] ) );
							}
							?>
							</p></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Anti-spam', 'estrategia-web' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="af-min"><?php esc_html_e( 'Minimum filling time (seconds)', 'estrategia-web' ); ?></label></th>
						<td><input id="af-min" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[min_seconds]" value="<?php echo esc_attr( (string) $s['min_seconds'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="af-limit"><?php esc_html_e( 'Submissions allowed per IP', 'estrategia-web' ); ?></label></th>
						<td><input id="af-limit" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $s['rate_limit'] ); ?>" class="small-text">
							<label for="af-window"><?php esc_html_e( 'every (minutes)', 'estrategia-web' ); ?></label>
							<input id="af-window" type="number" min="1" name="<?php echo esc_attr( $name ); ?>[rate_window]" value="<?php echo esc_attr( (string) $s['rate_window'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '0 submissions disables the limit.', 'estrategia-web' ); ?></p></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Default texts', 'estrategia-web' ); ?></h2>
				<p><?php esc_html_e( 'Leave empty to use the built-in text, already translated. If you fill them in and the site uses WPML, translate them in WPML → String Translation (domain estrategia-web-forms).', 'estrategia-web' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="af-success"><?php esc_html_e( 'Success message', 'estrategia-web' ); ?></label></th>
						<td><textarea id="af-success" name="<?php echo esc_attr( $name ); ?>[success_message]" rows="2" class="large-text"><?php echo esc_textarea( (string) $s['success_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="af-error"><?php esc_html_e( 'Error message', 'estrategia-web' ); ?></label></th>
						<td><textarea id="af-error" name="<?php echo esc_attr( $name ); ?>[error_message]" rows="2" class="large-text"><?php echo esc_textarea( (string) $s['error_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="af-footer"><?php esc_html_e( 'Email footer', 'estrategia-web' ); ?></label></th>
						<td><textarea id="af-footer" name="<?php echo esc_attr( $name ); ?>[email_footer]" rows="3" class="large-text"><?php echo esc_textarea( (string) $s['email_footer'] ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
