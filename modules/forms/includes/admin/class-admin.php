<?php
/**
 * Admin screens: «Forms → Forms» (1.7.0, first: the list of the site's
 * forms, includes/admin/class-forms-list.php), «Forms → Submissions» (list,
 * filter by form, views by notification state and sorting since 2.8.0 —
 * class-submissions-table.php —, view one, export CSV, delete with confirmation) and
 * «Forms → Settings».
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
 * tested in tests/submissions-admin.test.mjs. The trap: a GET link that deletes can be
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
		// 2.11.0: a file of a submission, and the dossier's «Ask the client» and «Revoke link».
		add_action( 'admin_post_estrategia_web_forms_file', array( self::class, 'download_file' ) );
		add_action( 'admin_post_estrategia_web_forms_ask', array( self::class, 'ask_client' ) );
		add_action( 'admin_post_estrategia_web_forms_revoke', array( self::class, 'revoke_link' ) );
		add_action( 'enqueue_block_editor_assets', array( Forms_List::class, 'enqueue_notification_link' ) ); // 2.0.1.
	}

	public static function capability(): string {
		return (string) apply_filters( 'estrategia_web_forms_capability', 'manage_options' );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Form submissions', 'estrategiaweb-toolkit' ),
			__( 'Forms', 'estrategiaweb-toolkit' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render_submissions' ),
			'dashicons-email-alt',
			26
		);
		add_submenu_page( self::PAGE, __( 'Form submissions', 'estrategiaweb-toolkit' ), __( 'Submissions', 'estrategiaweb-toolkit' ), self::capability(), self::PAGE, array( self::class, 'render_submissions' ) );
		add_submenu_page( self::PAGE, __( 'Form settings', 'estrategiaweb-toolkit' ), __( 'Settings', 'estrategiaweb-toolkit' ), 'manage_options', self::SETTINGS_PAGE, array( self::class, 'render_settings' ) );
		// 1.7.0: «Forms» FIRST (position 0). WordPress makes the top-level item open its
		// first submenu item, so «Forms» in the sidebar now opens this list; Submissions
		// keeps its slug, and every link to it (bookmarks, the CSV, «view» links) still works.
		add_submenu_page( self::PAGE, __( 'Forms', 'estrategiaweb-toolkit' ), __( 'Forms', 'estrategiaweb-toolkit' ), self::capability(), Forms_List::PAGE, array( Forms_List::class, 'render' ), 0 );
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
			wp_die( esc_html__( 'You are not allowed to see this page.', 'estrategiaweb-toolkit' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read only; whatever writes goes through admin-post with a nonce.
		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );
		if ( '' === $action || '-1' === $action ) {
			$action = sanitize_key( wp_unslash( $_GET['action2'] ?? '' ) );
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
		$form_id = Definitions::clean_id( sanitize_text_field( wp_unslash( $_GET['form_id'] ?? '' ) ) );
		$deleted = isset( $_GET['deleted'] ) ? (int) $_GET['deleted'] : null;
		// phpcs:enable

		$table = new Submissions_Table( $form_id );
		$table->prepare_items();
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'estrategia_web_forms_export', 'form_id' => $form_id ), admin_url( 'admin-post.php' ) ), 'estrategia_web_forms_export' );
		?>
		<div class="wrap ew-screen">
			<?php \EstrategiaWeb\Admin\Admin_UI::header( __( 'Form submissions', 'estrategiaweb-toolkit' ), __( 'Every message sent through the site\'s forms, with the emails each one sent.', 'estrategiaweb-toolkit' ), '<a class="page-title-action" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'estrategiaweb-toolkit' ) . '</a>' ); ?>
			<?php if ( null !== $deleted ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of deleted submissions. */
					echo esc_html( sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $deleted, 'estrategiaweb-toolkit' ), $deleted ) );
					?>
				</p></div>
			<?php endif; ?>
			<?php $table->views(); // 2.8.0: by notification state (class-submissions-table.php). ?>
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
			echo '<h1>' . esc_html__( 'Submission not found', 'estrategiaweb-toolkit' ) . '</h1>';
			echo '<p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to the list', 'estrategiaweb-toolkit' ) . '</a></p></div>';
			return;
		}
		/* translators: %d: submission number. */
		echo '<h1>' . esc_html( sprintf( __( 'Submission #%d', 'estrategiaweb-toolkit' ), $row['id'] ) ) . '</h1>';
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to the list', 'estrategiaweb-toolkit' ) . '</a></p>';

		self::dossier_notices();
		echo '<h2>' . esc_html__( 'Data', 'estrategiaweb-toolkit' ) . '</h2><table class="widefat striped estrategia-web-forms-data"><tbody>';
		foreach ( $row['data'] as $field ) {
			if ( 'file' === ( $field['type'] ?? '' ) ) {
				printf( '<tr><th scope="row" style="width:25%%">%s</th><td>%s</td></tr>', esc_html( $field['label'] ?: $field['name'] ), wp_kses( self::file_links( (int) $row['id'], (array) ( $field['files'] ?? array() ) ), \EstrategiaWeb\Kses::allowed() ) );
				continue;
			}
			$value = 'checkbox' === ( $field['type'] ?? '' ) ? ( '1' === $field['value'] ? __( 'Yes', 'estrategiaweb-toolkit' ) : __( 'No', 'estrategiaweb-toolkit' ) ) : (string) $field['value'];
			printf( '<tr><th scope="row" style="width:25%%">%s</th><td>%s</td></tr>', esc_html( $field['label'] ?: $field['name'] ), nl2br( esc_html( $value ) ) );
		}
		echo '</tbody></table>';
		if ( Submissions::is_dossier( $row ) ) {
			self::render_dossier( $row );
		}

		echo '<h2>' . esc_html__( 'Notifications', 'estrategiaweb-toolkit' ) . '</h2><table class="widefat striped estrategia-web-forms-notifications"><thead><tr>';
		echo '<th>' . esc_html__( 'Notification', 'estrategiaweb-toolkit' ) . '</th><th>' . esc_html__( 'Recipients', 'estrategiaweb-toolkit' ) . '</th><th>' . esc_html__( 'Result', 'estrategiaweb-toolkit' ) . '</th></tr></thead><tbody>';
		foreach ( $row['notifications'] as $n ) {
			$result = 'sent' === $n['status'] ? __( 'Sent', 'estrategiaweb-toolkit' ) : __( 'Failed', 'estrategiaweb-toolkit' ) . ': ' . $n['error'];
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $n['label'] ), esc_html( $n['to'] ), esc_html( $result ) );
		}
		if ( ! $row['notifications'] ) {
			echo '<tr><td colspan="3">' . esc_html__( 'This form has no notifications.', 'estrategiaweb-toolkit' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Details', 'estrategiaweb-toolkit' ) . '</h2><table class="widefat striped"><tbody>';
		$details = array(
			__( 'Form', 'estrategiaweb-toolkit' )       => $row['form_title'] . ' (' . $row['form_id'] . ')',
			__( 'Date', 'estrategiaweb-toolkit' )       => get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			__( 'Page', 'estrategiaweb-toolkit' )       => $row['page_url'],
			__( 'Language', 'estrategiaweb-toolkit' )   => $row['lang'],
			__( 'IP (truncated)', 'estrategiaweb-toolkit' ) => $row['ip'],
			__( 'User agent', 'estrategiaweb-toolkit' ) => $row['user_agent'],
		);
		foreach ( $details as $label => $value ) {
			printf( '<tr><th scope="row" style="width:25%%">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';
		$delete = add_query_arg( array( 'page' => self::PAGE, 'action' => 'delete', 'id' => $row['id'] ), admin_url( 'admin.php' ) );
		echo '<p><a class="button button-link-delete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Delete this submission', 'estrategiaweb-toolkit' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Where the File fields store what is sent (2.11.0), and the warning when
	 * it is not safe: under the web root a .htaccess may protect nothing
	 * (nginx), see includes/class-files.php.
	 */
	private static function files_status(): string {
		$dir   = Files::dir();
		$limit = Files::max_server_bytes();
		/* translators: %s: maximum upload size the server allows, e.g. «64 MB». */
		$server = $limit ? ' ' . sprintf( __( 'This server accepts uploads of up to %s.', 'estrategiaweb-toolkit' ), size_format( $limit ) ) : '';
		if ( '' === $dir ) {
			return '<p class="description" style="color:#b32d2e"><strong>' . esc_html__( 'No folder can be written for the files: File fields will fail. Define ESTRATEGIA_WEB_FORMS_PRIVATE_DIR in wp-config.php with a writable folder outside the web.', 'estrategiaweb-toolkit' ) . '</strong></p>';
		}
		$out = '<code>' . esc_html( $dir ) . '</code>';
		if ( Files::is_public() ) {
			$out .= '<p class="description" style="color:#b32d2e"><strong>' . esc_html__( 'This folder is inside the web. If the server is nginx, the files could be downloaded by anyone who guesses their name. Define ESTRATEGIA_WEB_FORMS_PRIVATE_DIR in wp-config.php with a folder outside the web.', 'estrategiaweb-toolkit' ) . '</strong></p>';
		} else {
			$out .= '<p class="description">' . esc_html__( 'Outside the web: only the team, and the owner of a dossier with their link, can download them. They are deleted with their submission.', 'estrategiaweb-toolkit' ) . esc_html( $server ) . '</p>';
		}
		return $out;
	}

	/** The files of a field, each a download through admin-post (capability + nonce). «—» if none. */
	private static function file_links( int $id, array $files ): string {
		if ( ! $files ) {
			return '—';
		}
		$out = '<ul style="margin:0">';
		foreach ( $files as $file ) {
			$url  = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'estrategia_web_forms_file',
						'id'     => $id,
						'file'   => (string) $file['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'estrategia_web_forms_file_' . $id
			);
			$out .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( (string) $file['name'] ) . '</a> (' . esc_html( size_format( (int) $file['size'] ) ) . ')</li>';
		}
		return $out . '</ul>';
	}

	/** The result of «Ask the client» / «Revoke link», after their redirect. */
	private static function dossier_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only shows a message.
		$done = sanitize_key( wp_unslash( $_GET['dossier'] ?? '' ) );
		// phpcs:enable
		$messages = array(
			'asked'      => array( 'success', __( 'Sent: the client has received your note with a new link. The previous link no longer works.', 'estrategiaweb-toolkit' ) ),
			'ask_failed' => array( 'error', __( 'The email to the client could not be sent. Nothing has changed for them.', 'estrategiaweb-toolkit' ) ),
			'revoked'    => array( 'success', __( 'The link has been revoked. The client can no longer open the dossier until a new link is sent.', 'estrategiaweb-toolkit' ) ),
		);
		if ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $messages[ $done ][0] ), esc_html( $messages[ $done ][1] ) );
		}
	}

	/**
	 * The dossier box (2.11.0): complete or what is missing, the link, the
	 * history, and the two actions — «Ask the client» (a note and a new
	 * link by email) and «Revoke link». Both are POSTs with their nonce.
	 */
	private static function render_dossier( array $row ): void {
		$definition = Definitions::load( (string) $row['definition_hash'] ) ?? array( 'fields' => array() );
		$missing    = Dossier::labels( $definition, (array) $row['missing'] );
		$format     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo '<h2>' . esc_html__( 'Dossier', 'estrategiaweb-toolkit' ) . '</h2><table class="widefat striped estrategia-web-forms-dossier"><tbody>';
		$rows = array(
			__( 'State', 'estrategiaweb-toolkit' )    => $row['complete'] ? __( 'Complete', 'estrategiaweb-toolkit' ) : __( 'Incomplete', 'estrategiaweb-toolkit' ) . ( $missing ? ': ' . implode( ', ', $missing ) : '' ),
			__( 'Owner', 'estrategiaweb-toolkit' )    => (string) $row['owner_email'],
			__( 'Link', 'estrategiaweb-toolkit' )     => '' === (string) $row['access_hash'] || '' === (string) $row['access_expires']
				? __( 'None (revoked). «Ask the client» sends a new one.', 'estrategiaweb-toolkit' )
				/* translators: %s: date and time. */
				: sprintf( __( 'Valid until %s', 'estrategiaweb-toolkit' ), get_date_from_gmt( (string) $row['access_expires'], $format ) ),
			__( 'Last change', 'estrategiaweb-toolkit' ) => '' !== (string) $row['updated_at'] ? get_date_from_gmt( (string) $row['updated_at'], $format ) : '—',
		);
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row" style="width:25%%">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';

		$what = array(
			'created'   => __( 'Sent', 'estrategiaweb-toolkit' ),
			'updated'   => __( 'Changed', 'estrategiaweb-toolkit' ),
			'requested' => __( 'Asked the client', 'estrategiaweb-toolkit' ),
			'link_sent' => __( 'New link sent', 'estrategiaweb-toolkit' ),
			'revoked'   => __( 'Link revoked', 'estrategiaweb-toolkit' ),
		);
		echo '<h3>' . esc_html__( 'History', 'estrategiaweb-toolkit' ) . '</h3><ul class="estrategia-web-forms-history">';
		foreach ( array_reverse( (array) $row['history'] ) as $entry ) {
			$who    = 'client' === ( $entry['by'] ?? '' ) ? __( 'client', 'estrategiaweb-toolkit' ) : substr( (string) ( $entry['by'] ?? '' ), 5 );
			$detail = '';
			if ( ! empty( $entry['fields'] ) ) {
				$detail = ': ' . implode( ', ', Dossier::labels( $definition, (array) $entry['fields'] ) ?: (array) $entry['fields'] );
			} elseif ( ! empty( $entry['note'] ) ) {
				$detail = ': «' . $entry['note'] . '»';
			}
			printf( '<li>%1$s — %2$s (%3$s)%4$s</li>', esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( (string) $entry['at'] ) ), $format ) ), esc_html( $what[ $entry['what'] ?? '' ] ?? (string) ( $entry['what'] ?? '' ) ), esc_html( $who ), esc_html( $detail ) );
		}
		echo '</ul>';

		$post = admin_url( 'admin-post.php' );
		echo '<h3>' . esc_html__( 'Ask the client', 'estrategiaweb-toolkit' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( $post ) . '">';
		echo '<input type="hidden" name="action" value="estrategia_web_forms_ask"><input type="hidden" name="id" value="' . esc_attr( (string) $row['id'] ) . '">';
		wp_nonce_field( 'estrategia_web_forms_ask_' . $row['id'] );
		echo '<p><label for="af-note">' . esc_html__( 'What you need from them. It goes in the email, with a new link and the list of what is missing.', 'estrategiaweb-toolkit' ) . '</label><br>';
		echo '<textarea id="af-note" name="note" rows="4" class="large-text" required></textarea></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Send to the client', 'estrategiaweb-toolkit' ) . '</button></p></form>';

		if ( '' !== (string) $row['access_hash'] ) {
			echo '<form method="post" action="' . esc_url( $post ) . '">';
			echo '<input type="hidden" name="action" value="estrategia_web_forms_revoke"><input type="hidden" name="id" value="' . esc_attr( (string) $row['id'] ) . '">';
			wp_nonce_field( 'estrategia_web_forms_revoke_' . $row['id'] );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Revoke link', 'estrategiaweb-toolkit' ) . '</button></p></form>';
		}
	}

	public static function download_file(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked right below.
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'estrategiaweb-toolkit' ), 403 );
		}
		check_admin_referer( 'estrategia_web_forms_file_' . $id );
		$row  = Submissions::get( $id );
		$file = $row ? Files::find( $row, sanitize_key( wp_unslash( $_GET['file'] ?? '' ) ) ) : null;
		if ( ! $file ) {
			wp_die( esc_html__( 'The file is no longer available.', 'estrategiaweb-toolkit' ), 404 );
		}
		Files::download( $file );
	}

	public static function ask_client(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked right below.
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'estrategiaweb-toolkit' ), 403 );
		}
		check_admin_referer( 'estrategia_web_forms_ask_' . $id );
		$row  = Submissions::get( $id );
		$note = trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) );
		if ( ! $row || ! Submissions::is_dossier( $row ) || '' === $note ) {
			wp_die( esc_html__( 'Nothing to send.', 'estrategiaweb-toolkit' ), 400 );
		}
		$definition = Definitions::load( (string) $row['definition_hash'] ) ?? array( 'fields' => array() );
		// The mail goes first with a token not yet stored: if it fails, the client's current link keeps working.
		$token = Dossier::new_token();
		$sent  = Dossier::mail_link( $row, $definition, $token, $note );
		$done  = 'ask_failed';
		if ( 'sent' === $sent['status'] ) {
			Submissions::update_dossier(
				$id,
				array(
					'access_hash'    => Dossier::hash( $token ),
					'access_expires' => Dossier::expires( Dossier::days( $definition ) ),
					'updated_at'     => current_time( 'mysql', true ),
					'history'        => Dossier::history_add( (array) $row['history'], Dossier::actor(), 'requested', array( 'note' => $note ) ),
				)
			);
			$done = 'asked';
		}
		wp_safe_redirect( self::view_url( $id, $done ) );
		exit;
	}

	public static function revoke_link(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked right below.
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'estrategiaweb-toolkit' ), 403 );
		}
		check_admin_referer( 'estrategia_web_forms_revoke_' . $id );
		$row = Submissions::get( $id );
		if ( $row ) {
			Dossier::revoke( $row, Dossier::actor() );
		}
		wp_safe_redirect( self::view_url( $id, 'revoked' ) );
		exit;
	}

	private static function view_url( int $id, string $done ): string {
		return add_query_arg(
			array(
				'page'    => self::PAGE,
				'action'  => 'view',
				'id'      => $id,
				'dossier' => $done,
			),
			admin_url( 'admin.php' )
		);
	}

	/** @param int[] $ids */
	private static function render_confirm_delete( array $ids ): void {
		$back = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<div class="wrap"><h1>' . esc_html__( 'Delete submissions', 'estrategiaweb-toolkit' ) . '</h1>';
		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'No submissions selected.', 'estrategiaweb-toolkit' ) . '</p><p><a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to the list', 'estrategiaweb-toolkit' ) . '</a></p></div>';
			return;
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of submissions. */
				_n( 'You are about to permanently delete %d submission. This cannot be undone.', 'You are about to permanently delete %d submissions. This cannot be undone.', count( $ids ), 'estrategiaweb-toolkit' ),
				count( $ids )
			)
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="estrategia_web_forms_delete">';
		wp_nonce_field( 'estrategia_web_forms_delete' );
		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="ids[]" value="' . esc_attr( (string) $id ) . '">';
		}
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Yes, delete', 'estrategiaweb-toolkit' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'estrategiaweb-toolkit' ) . '</a></p></form></div>';
	}

	public static function delete(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'estrategiaweb-toolkit' ), 403 );
		}
		check_admin_referer( 'estrategia_web_forms_delete' );
		$ids     = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$deleted = Submissions::delete( $ids );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'deleted' => $deleted ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function export_csv(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'estrategiaweb-toolkit' ), 403 );
		}
		check_admin_referer( 'estrategia_web_forms_export' );
		$form_id = Definitions::clean_id( sanitize_text_field( wp_unslash( $_GET['form_id'] ?? '' ) ) );
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
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output, not a file. BOM: without it Excel misreads accented characters.

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
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output, not a file.
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
			wp_die( esc_html__( 'You are not allowed to see this page.', 'estrategiaweb-toolkit' ), 403 );
		}
		$s     = Settings::all();
		$name  = Settings::OPTION;
		$purge = get_option( 'estrategia_web_forms_last_purge' );
		?>
		<div class="wrap ew-screen">
			<?php \EstrategiaWeb\Admin\Admin_UI::header( __( 'Form settings', 'estrategiaweb-toolkit' ), __( 'Fields, notifications and messages of each form are set in the form block, in the editor. Here only what is common to all forms.', 'estrategiaweb-toolkit' ) ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'estrategia_web_forms' ); ?>
				<?php \EstrategiaWeb\Admin\Admin_UI::box_open( __( 'Submission log', 'estrategiaweb-toolkit' ), 'ew-box-forms-log' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="af-retention"><?php esc_html_e( 'Keep submissions for (days)', 'estrategiaweb-toolkit' ); ?></label></th>
						<td><input id="af-retention" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '0 keeps them forever. Older submissions are deleted once a day.', 'estrategiaweb-toolkit' ); ?>
							<?php
							if ( is_array( $purge ) ) {
								/* translators: 1: date of the last purge, 2: deleted submissions. */
								echo esc_html( sprintf( __( 'Last purge: %1$s, %2$d deleted.', 'estrategiaweb-toolkit' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $purge['at'] ) ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ), (int) $purge['deleted'] ) );
							}
							?>
							</p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Files', 'estrategiaweb-toolkit' ); ?></th>
						<td><?php echo wp_kses( self::files_status(), \EstrategiaWeb\Kses::allowed() ); ?></td>
					</tr>
				</table>
				<?php \EstrategiaWeb\Admin\Admin_UI::box_close(); ?>
				<?php \EstrategiaWeb\Admin\Admin_UI::box_open( __( 'Anti-spam', 'estrategiaweb-toolkit' ), 'ew-box-forms-spam' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="af-min"><?php esc_html_e( 'Minimum filling time (seconds)', 'estrategiaweb-toolkit' ); ?></label></th>
						<td><input id="af-min" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[min_seconds]" value="<?php echo esc_attr( (string) $s['min_seconds'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="af-limit"><?php esc_html_e( 'Submissions allowed per IP', 'estrategiaweb-toolkit' ); ?></label></th>
						<td><input id="af-limit" type="number" min="0" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $s['rate_limit'] ); ?>" class="small-text">
							<label for="af-window"><?php esc_html_e( 'every (minutes)', 'estrategiaweb-toolkit' ); ?></label>
							<input id="af-window" type="number" min="1" name="<?php echo esc_attr( $name ); ?>[rate_window]" value="<?php echo esc_attr( (string) $s['rate_window'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '0 submissions disables the limit.', 'estrategiaweb-toolkit' ); ?></p></td>
					</tr>
				</table>
				<?php \EstrategiaWeb\Admin\Admin_UI::box_close(); ?>
				<?php \EstrategiaWeb\Admin\Admin_UI::box_open( __( 'Default texts', 'estrategiaweb-toolkit' ), 'ew-box-forms-texts' ); ?>
				<p><?php esc_html_e( 'Leave empty to use the built-in text, already translated. If you fill them in and the site uses WPML, translate them in WPML → String Translation (domain estrategia-web-forms).', 'estrategiaweb-toolkit' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="af-success"><?php esc_html_e( 'Success message', 'estrategiaweb-toolkit' ); ?></label></th>
						<td><textarea id="af-success" name="<?php echo esc_attr( $name ); ?>[success_message]" rows="2" class="large-text"><?php echo esc_textarea( (string) $s['success_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="af-error"><?php esc_html_e( 'Error message', 'estrategiaweb-toolkit' ); ?></label></th>
						<td><textarea id="af-error" name="<?php echo esc_attr( $name ); ?>[error_message]" rows="2" class="large-text"><?php echo esc_textarea( (string) $s['error_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="af-footer"><?php esc_html_e( 'Email footer', 'estrategiaweb-toolkit' ); ?></label></th>
						<td><textarea id="af-footer" name="<?php echo esc_attr( $name ); ?>[email_footer]" rows="3" class="large-text"><?php echo esc_textarea( (string) $s['email_footer'] ); ?></textarea></td>
					</tr>
				</table>
				<?php \EstrategiaWeb\Admin\Admin_UI::box_close(); ?>
				<?php \EstrategiaWeb\Admin\Admin_UI::save(); ?>
			</form>
		</div>
		<?php
	}
}
