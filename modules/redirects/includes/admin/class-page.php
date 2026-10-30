<?php
/**
 * The «Estrategia Web → Redirects» screen. Four tabbed views: rules (list
 * with search and bulk actions), add/edit, 404 log (with «Create redirect»
 * on each path) and CSV import/export.
 *
 * Everything that writes is processed in load-<page> (before anything is
 * rendered), with nonce and manage_options, and ends in a redirect with
 * ?ew_msg=… (POST → redirect → GET pattern: reloading doesn't repeat the
 * action). The exception is a failed save: the form is rendered again with
 * what was typed and the error, without redirecting, so input isn't lost.
 *
 * How to maintain: a new message after an action is a code in notice() and
 * the redirect with ew_msg=<code>. The import report doesn't fit in the URL:
 * it goes in a per-user transient lasting 5 minutes.
 */

namespace EstrategiaWeb\Redirects\Admin;

use EstrategiaWeb\Admin\Menu;
use EstrategiaWeb\Redirects\Conflicts;
use EstrategiaWeb\Redirects\Importer;
use EstrategiaWeb\Redirects\Rules;
use EstrategiaWeb\Redirects\Settings;
use EstrategiaWeb\Redirects\Store;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG  = 'estrategia-web-redirects';
	public const NONCE = 'estrategia_web_redirects';

	/** @var array{0: array<string, mixed>, 1: string}|null What was typed and the error, if saving failed. */
	private static ?array $failed = null;

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 ); // After the «Estrategia Web» menu (priority 10).
	}

	public static function menu(): void {
		$parent = class_exists( Menu::class ) ? Menu::PARENT : 'tools.php';
		$hook   = add_submenu_page( $parent, __( 'Redirects', 'estrategia-web' ), __( 'Redirects', 'estrategia-web' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'handle' ) );
		}
	}

	/** @param array<string, scalar> $args */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function back( array $args ): void {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/** Processes the actions. Runs before rendering. */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- verified below, before each action.
		$do    = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		$nonce = self::NONCE;
		if ( '' === $do ) {
			// WP_List_Table bulk action. Its form already carries the table's
			// «bulk-<plural>» nonce (display_tablenav) named _wpnonce: our own
			// in the same form would be overwritten.
			foreach ( array( 'action', 'action2' ) as $field ) {
				if ( ! empty( $_POST[ $field ] ) && '-1' !== $_POST[ $field ] ) {
					$do    = sanitize_key( $_POST[ $field ] );
					$nonce = 'log_delete' === $do ? 'bulk-not_found' : 'bulk-redirects';
				}
			}
		}
		if ( '' === $do || '-1' === $do ) {
			return;
		}
		check_admin_referer( $nonce );
		$ids = isset( $_REQUEST['ids'] ) ? array_map( 'intval', (array) $_REQUEST['ids'] ) : ( isset( $_REQUEST['id'] ) ? array( (int) $_REQUEST['id'] ) : array() );

		switch ( $do ) {
			case 'save':
				$input = array(
					'source' => isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '',
					'target' => isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '',
					'type'   => isset( $_POST['type'] ) ? (int) $_POST['type'] : 301,
					'note'   => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
					'active' => ! empty( $_POST['active'] ),
				);
				$id                 = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
				list( $saved, $err ) = Importer::save_one( $input, $id );
				if ( ! $saved ) {
					self::$failed = array( $input + array( 'id' => $id ), $err );
					return; // The form is rendered again with what was typed.
				}
				self::back( array( 'ew_msg' => self::exists( $input['source'] ) ? 'saved_exists' : 'saved' ) );
				break;
			case 'delete':
				Store::delete( $ids );
				self::back( array( 'ew_msg' => 'deleted' ) );
				break;
			case 'activate':
			case 'deactivate':
				Store::set_active( $ids, 'activate' === $do );
				self::back( array( 'ew_msg' => 'updated' ) );
				break;
			case 'import':
				$file = $_FILES['csv']['tmp_name'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( ! $file || ! is_uploaded_file( $file ) ) {
					self::back( array( 'view' => 'import', 'ew_msg' => 'no_file' ) );
				}
				$report = Importer::import( (string) file_get_contents( $file ), ! empty( $_POST['dry_run'] ) );
				$report['dry_run'] = ! empty( $_POST['dry_run'] );
				set_transient( 'estrategia_web_redirects_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
				self::back( array( 'view' => 'import', 'ew_msg' => 'imported' ) );
				break;
			case 'export':
				nocache_headers();
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="redirecciones-' . gmdate( 'Y-m-d' ) . '.csv"' );
				echo "\xEF\xBB\xBF" . Importer::export(); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV. The BOM is so Excel reads accented characters.
				exit;
			case 'log_settings':
				Settings::save( ! empty( $_POST['log_404'] ), isset( $_POST['log_days'] ) ? (int) $_POST['log_days'] : 90 );
				self::back( array( 'view' => 'log', 'ew_msg' => 'settings' ) );
				break;
			case 'log_clear':
				Store::log_purge( 0 );
				self::back( array( 'view' => 'log', 'ew_msg' => 'log_cleared' ) );
				break;
			case 'log_delete':
				Store::log_delete( $ids );
				self::back( array( 'view' => 'log', 'ew_msg' => 'deleted' ) );
				break;
		}
		// phpcs:enable
	}

	/** Is the source a page that exists today? Then the rule won't fire (see class-runtime.php). */
	private static function exists( string $source ): bool {
		list( $path, $params ) = Rules::split( $source, Store::base() );
		if ( $params || '/' === $path ) {
			return false;
		}
		return url_to_postid( untrailingslashit( (string) get_option( 'home' ) ) . $path . '/' ) > 0;
	}

	private static function notice(): void {
		$msg = isset( $_GET['ew_msg'] ) ? sanitize_key( $_GET['ew_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$map = array(
			'saved'        => array( 'success', __( 'Redirect saved.', 'estrategia-web' ) ),
			'saved_exists' => array( 'warning', __( 'Redirect saved, but that source is a page that exists on the website: the redirect only works on addresses that give a 404. It will start working when the page is deleted or unpublished.', 'estrategia-web' ) ),
			'deleted'      => array( 'success', __( 'Deleted.', 'estrategia-web' ) ),
			'updated'      => array( 'success', __( 'Updated.', 'estrategia-web' ) ),
			'no_file'      => array( 'error', __( 'Choose a CSV file.', 'estrategia-web' ) ),
			'settings'     => array( 'success', __( 'Settings saved.', 'estrategia-web' ) ),
			'log_cleared'  => array( 'success', __( 'The 404 log is empty.', 'estrategia-web' ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( self::$failed ) {
			$view = 'edit';
		}
		$tabs = array(
			'list'   => __( 'Redirects', 'estrategia-web' ),
			'edit'   => __( 'Add new', 'estrategia-web' ),
			'log'    => __( '404 log', 'estrategia-web' ),
			'import' => __( 'Import / export', 'estrategia-web' ),
		);
		echo '<div class="wrap ew-screen estrategia-web-redirects">';
		\EstrategiaWeb\Admin\Admin_UI::header( __( 'Redirects', 'estrategia-web' ), __( 'Old addresses that send visitors to the new ones (301, 302), or say a page is gone for good (410).', 'estrategia-web' ) );

		$conflicts = Conflicts::detect();
		if ( $conflicts ) {
			echo '<div class="notice notice-warning ew-redirects-conflict"><p><strong>' . esc_html__( 'There are two redirect systems on this website.', 'estrategia-web' ) . '</strong> ';
			/* translators: %s: names of other active redirect plugins, e.g. "Rank Math". */
			echo esc_html( sprintf( __( 'Also active: %s. Both act on the same addresses and the other one usually goes first. Keep the rules in only one of them, or turn this module off in Estrategia Web → Modules.', 'estrategia-web' ), implode( ', ', $conflicts ) ) );
			echo '</p></div>';
		}
		self::notice();

		$links   = array();
		$editing = 'edit' === $view && ( isset( $_GET['id'] ) || ( self::$failed && ! empty( self::$failed[0]['id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $tabs as $key => $label ) {
			if ( 'edit' === $key && $editing ) {
				$label = __( 'Edit redirect', 'estrategia-web' );
			}
			$links[ $key ] = array( $label, self::url( 'list' === $key ? array() : array( 'view' => $key ) ) );
		}
		\EstrategiaWeb\Admin\Admin_UI::tabs( $links, $view, __( 'Redirects sections', 'estrategia-web' ) );

		switch ( $view ) {
			case 'edit':
				self::render_edit();
				break;
			case 'log':
				self::render_log();
				break;
			case 'import':
				self::render_import();
				break;
			default:
				self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		$table = new Rules_Table();
		$table->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		$table->search_box( __( 'Search redirects', 'estrategia-web' ), 'ew-redirects' );
		echo '</form><form method="post" action="' . esc_url( self::url() ) . '">';
		$table->display(); // Carries its bulk-redirects nonce (see handle()).
		echo '</form>';
	}

	private static function render_edit(): void {
		$rule = array(
			'id'     => 0,
			'source' => isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification -- prefilled from the 404 log.
			'target' => '',
			'type'   => 301,
			'note'   => '',
			'active' => true,
		);
		$error = '';
		if ( self::$failed ) {
			list( $rule, $error ) = self::$failed;
		} elseif ( ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$row = Store::get( (int) $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $row ) {
				$rule = array(
					'id'     => (int) $row['id'],
					'source' => (string) $row['source'],
					'target' => (string) $row['target'],
					'type'   => (int) $row['type'],
					'note'   => (string) $row['note'],
					'active' => (bool) $row['active'],
				);
			}
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error ew-redirects-error"><p>' . esc_html( Importer::message( $error ) ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="ew-redirects-edit">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="do" value="save" />
			<input type="hidden" name="id" value="<?php echo (int) $rule['id']; ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ew-source"><?php esc_html_e( 'Source', 'estrategia-web' ); ?></label></th>
					<td><input name="source" id="ew-source" type="text" class="large-text code" required value="<?php echo esc_attr( $rule['source'] ); ?>" placeholder="/old-page/" />
					<p class="description"><?php /* translators: "%3F" is the URL-encoded "?", not a placeholder: keep it as is. */ esc_html_e( 'The old path, without the domain. Upper case, a final slash and any extra ?parameters are ignored when matching; ?p=123 and ?page_id=123 are the same, and so are %3F and ?.', 'estrategia-web' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ew-type"><?php esc_html_e( 'Type', 'estrategia-web' ); ?></label></th>
					<td><select name="type" id="ew-type">
						<option value="301" <?php selected( 301, (int) $rule['type'] ); ?>><?php esc_html_e( '301 — moved permanently', 'estrategia-web' ); ?></option>
						<option value="302" <?php selected( 302, (int) $rule['type'] ); ?>><?php esc_html_e( '302 — moved temporarily', 'estrategia-web' ); ?></option>
						<option value="410" <?php selected( 410, (int) $rule['type'] ); ?>><?php esc_html_e( '410 — gone, no destination', 'estrategia-web' ); ?></option>
					</select></td>
				</tr>
				<tr>
					<th scope="row"><label for="ew-target"><?php esc_html_e( 'Destination', 'estrategia-web' ); ?></label></th>
					<td><input name="target" id="ew-target" type="text" class="large-text code" value="<?php echo esc_attr( $rule['target'] ); ?>" placeholder="/new-page/" />
					<p class="description"><?php esc_html_e( 'A path on this website (/new-page/) or a full address (https://…). Empty for 410.', 'estrategia-web' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="ew-note"><?php esc_html_e( 'Note', 'estrategia-web' ); ?></label></th>
					<td><input name="note" id="ew-note" type="text" class="large-text" value="<?php echo esc_attr( $rule['note'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Active', 'estrategia-web' ); ?></th>
					<td><label><input name="active" type="checkbox" value="1" <?php checked( $rule['active'] ); ?> /> <?php esc_html_e( 'This redirect is working', 'estrategia-web' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button( $rule['id'] ? __( 'Save redirect', 'estrategia-web' ) : __( 'Add redirect', 'estrategia-web' ) ); ?>
		</form>
		<?php
	}

	private static function render_log(): void {
		$settings = Settings::get();
		?>
		<form method="post" action="<?php echo esc_url( self::url( array( 'view' => 'log' ) ) ); ?>" class="ew-redirects-log-settings">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="do" value="log_settings" />
			<p><label><input type="checkbox" name="log_404" value="1" <?php checked( $settings['log_404'] ); ?> /> <?php esc_html_e( 'Record the addresses that give a 404 error', 'estrategia-web' ); ?></label></p>
			<p><label><?php esc_html_e( 'Forget addresses not seen for', 'estrategia-web' ); ?> <input type="number" name="log_days" min="1" max="3650" class="small-text" value="<?php echo (int) $settings['log_days']; ?>" /> <?php esc_html_e( 'days', 'estrategia-web' ); ?></label></p>
			<p class="description"><?php esc_html_e( 'Only the path is recorded (and ?p= of old WordPress addresses), with a counter and dates: no IP address, browser or other personal data.', 'estrategia-web' ); ?></p>
			<?php submit_button( __( 'Save settings', 'estrategia-web' ), 'secondary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( self::url( array( 'view' => 'log' ) ) ); ?>" style="margin-top:1em">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="do" value="log_clear" />
			<?php submit_button( __( 'Empty the 404 log', 'estrategia-web' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
		$table = new Log_Table();
		$table->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="view" value="log" />';
		$table->search_box( __( 'Search paths', 'estrategia-web' ), 'ew-404' );
		echo '</form><form method="post" action="' . esc_url( self::url( array( 'view' => 'log' ) ) ) . '">';
		$table->display(); // Carries its bulk-not_found nonce (see handle()).
		echo '</form>';
	}

	private static function render_import(): void {
		$report = get_transient( 'estrategia_web_redirects_report_' . get_current_user_id() );
		if ( is_array( $report ) && isset( $_GET['ew_msg'] ) && 'imported' === $_GET['ew_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_transient( 'estrategia_web_redirects_report_' . get_current_user_id() );
			$class = $report['errors'] ? 'warning' : 'success';
			echo '<div class="notice notice-' . esc_attr( $class ) . ' ew-redirects-report"><p>';
			if ( ! empty( $report['dry_run'] ) ) {
				echo '<strong>' . esc_html__( 'Test only, nothing was saved.', 'estrategia-web' ) . '</strong> ';
			}
			/* translators: 1: new rules, 2: updated rules, 3: unchanged rules, 4: rows with errors. */
			echo esc_html( sprintf( __( '%1$d new, %2$d updated, %3$d unchanged, %4$d with errors.', 'estrategia-web' ), $report['created'], $report['updated'], $report['unchanged'], count( $report['errors'] ) ) );
			echo '</p>';
			if ( $report['errors'] ) {
				echo '<ul>';
				foreach ( $report['errors'] as $e ) {
					/* translators: 1: line number in the CSV, 2: source path, 3: error message. */
					echo '<li>' . esc_html( sprintf( __( 'Line %1$d (%2$s): %3$s', 'estrategia-web' ), $e['line'], $e['source'], $e['message'] ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}
		?>
		<h2><?php esc_html_e( 'Import', 'estrategia-web' ); ?></h2>
		<p><?php esc_html_e( 'A CSV with the columns origen, destino, tipo and motivo (or source, target, type, note). A source that already has a redirect is updated; nothing is deleted.', 'estrategia-web' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::url( array( 'view' => 'import' ) ) ); ?>" class="ew-redirects-import">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="do" value="import" />
			<p><input type="file" name="csv" accept=".csv,text/csv" required /></p>
			<p><label><input type="checkbox" name="dry_run" value="1" /> <?php esc_html_e( 'Test only: show what would happen without saving', 'estrategia-web' ); ?></label></p>
			<?php submit_button( __( 'Import CSV', 'estrategia-web' ), 'primary', 'submit', false ); ?>
		</form>
		<h2><?php esc_html_e( 'Export', 'estrategia-web' ); ?></h2>
		<p><a class="button ew-redirects-export" href="<?php echo esc_url( wp_nonce_url( self::url( array( 'do' => 'export' ) ), self::NONCE ) ); ?>"><?php esc_html_e( 'Download CSV', 'estrategia-web' ); ?></a></p>
		<?php
	}
}
