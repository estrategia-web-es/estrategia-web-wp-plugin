<?php
/**
 * «Estrategia Web → Cookies» screen (manage_options): the cookie registry
 * (includes/class-cookie-registry.php) and the notice settings that are not
 * code.
 *
 * What is there, top to bottom:
 * 1. Which categories the notice asks about: the ones declared by the CODE
 *    (the theme, hybrid Matomo, google-tags…; they cannot be removed from
 *    here) and the ones the administrator adds with the checkboxes. And the
 *    detector, on or off.
 * 2. Warnings: confirmed cookies in a category the notice does NOT ask about
 *    (they would be set without consent, or they do not belong in the policy).
 * 3. The list: one row per cookie or pattern, each with ITS own form (HTML5
 *    form= attribute: the fields are in the table and the empty <form>
 *    outside). See the trap.
 * 4. Add by hand (third-party cookies the detector does not see).
 * 5. The Open Cookie Database attribution (Apache 2.0).
 *
 * Saves through admin-post.php with a nonce; the same logic as the admin
 * REST route (Cookie_Registry::save / delete).
 *
 * How to maintain:
 * - Who and when: whoever touches the notice. Level 2 test in
 *   modules/consent/tests/cookies.test.mjs (the screen loads and saves).
 * - A new field of a row: its cell here, its key in
 *   Cookie_Registry::ENTRY_DEFAULTS and its sanitisation in
 *   Cookie_Registry::save().
 * - Edge case: the administrator ticks a category the code already declares.
 *   Nothing happens (they are merged without duplicates) and the checkbox
 *   shows ticked and disabled with «declared by the code».
 * - The trap: a single form with all the rows sends 7 fields per row; with
 *   150 rows it goes past max_input_vars (1,000 by default in PHP) and PHP
 *   TRUNCATES the POST silently: the first rows would be saved and the rest
 *   not. Hence one form per row.
 */

namespace EstrategiaWeb\Site;

use EstrategiaWeb\Admin\Modules_Page;

defined( 'ABSPATH' ) || exit;

final class Cookies_Page {

	public const PAGE   = 'estrategia-web-cookies';
	public const ACTION = 'estrategia_web_cookies';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 ); // After the «Estrategia Web» menu (10).
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function menu(): void {
		add_submenu_page( Modules_Page::MENU, __( 'Cookies', 'estrategia-web' ), __( 'Cookies', 'estrategia-web' ), 'manage_options', self::PAGE, array( self::class, 'render' ) );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** Saves whatever comes from any of the screen's forms. */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'estrategia-web' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$notice = 'saved';

		if ( 'settings' === $do ) {
			update_option(
				Cookie_Registry::SETTINGS,
				Cookie_Registry::sanitize_settings(
					array(
						'detector' => ! empty( $_POST['detector'] ),
						'ask'      => array_map( 'sanitize_key', (array) wp_unslash( $_POST['ask'] ?? array() ) ),
					)
				),
				false
			);
		} elseif ( 'row' === $do || 'add' === $do ) {
			$name = trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
			if ( ! empty( $_POST['delete'] ) ) {
				Cookie_Registry::delete( $name );
				$notice = 'deleted';
			} else {
				$data = array();
				foreach ( array( 'provider', 'category', 'duration', 'status' ) as $field ) {
					if ( isset( $_POST[ $field ] ) ) {
						$data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
					}
				}
				if ( isset( $_POST['purpose'] ) ) {
					$data['purpose'] = sanitize_textarea_field( wp_unslash( $_POST['purpose'] ) );
				}
				if ( 'add' === $do ) {
					$data['status'] = $data['status'] ?? 'confirmed';
				}
				$result = Cookie_Registry::save( $name, $data );
				$notice = is_wp_error( $result ) ? 'error-' . $result->get_error_code() : 'saved';
			}
		} elseif ( 'confirm-all' === $do ) {
			foreach ( Cookie_Registry::all() as $key => $entry ) {
				if ( 'pending' === $entry['status'] ) {
					Cookie_Registry::save( $key, array( 'status' => 'confirmed' ) );
				}
			}
		}
		wp_safe_redirect( self::url( array( 'notice' => $notice ) ) );
		exit;
	}

	private static function category_select( string $name, string $current, string $form = '' ): string {
		$labels = Cookie_Table::category_labels();
		$html   = sprintf( '<select name="%s"%s>', esc_attr( $name ), $form ? ' form="' . esc_attr( $form ) . '"' : '' );
		foreach ( Cookie_Registry::CATEGORIES as $slug ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $slug, $current, false ), esc_html( $labels[ $slug ]['label'] ?? $slug ) );
		}
		return $html . '</select>';
	}

	private static function status_labels(): array {
		return array(
			'pending'   => __( 'Pending', 'estrategia-web' ),
			'confirmed' => __( 'Confirmed', 'estrategia-web' ),
			'ignored'   => __( 'Ignored', 'estrategia-web' ),
		);
	}

	private static function hidden( string $do ): void {
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="do" value="%s">', esc_attr( self::ACTION ), esc_attr( $do ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Cookie_Registry::settings();
		$labels   = Cookie_Table::category_labels();
		$code     = Consent::code_categories();
		$asked    = Consent::categories();
		$entries  = Cookie_Registry::all();
		$ocd      = Cookie_Registry::ocd();
		$notice   = sanitize_key( wp_unslash( $_GET['notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$date     = static fn( int $t ): string => $t ? wp_date( get_option( 'date_format' ), $t ) : '—';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cookies', 'estrategia-web' ); ?></h1>
			<?php if ( 'saved' === $notice || 'deleted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo 'deleted' === $notice ? esc_html__( 'Cookie deleted.', 'estrategia-web' ) : esc_html__( 'Changes saved.', 'estrategia-web' ); ?></p></div>
			<?php elseif ( str_starts_with( $notice, 'error-' ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Not saved: check the cookie name (exact name, or a prefix ending in *) and that the list is not full.', 'estrategia-web' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! Consent::active() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'The active theme brings its own cookie notice: these settings do not apply to it.', 'estrategia-web' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Cookie notice', 'estrategia-web' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php self::hidden( 'settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Categories the notice asks about', 'estrategia-web' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( Consent::display_order( array_keys( Consent::known_categories() ) ) as $slug ) : ?>
									<?php $by_code = in_array( $slug, $code, true ); ?>
									<label style="display:block;margin:.4em 0">
										<input type="checkbox" name="ask[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $by_code || in_array( $slug, $settings['ask'], true ) ); ?> <?php disabled( $by_code ); ?>>
										<strong><?php echo esc_html( $labels[ $slug ]['label'] ); ?></strong>
										<?php if ( $by_code ) : ?>
											— <?php esc_html_e( 'declared by the code (theme or a module)', 'estrategia-web' ); ?>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Changing the categories shows the notice again to every visitor: they are being asked about something new. Without any category, the notice does not appear.', 'estrategia-web' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cookie detector', 'estrategia-web' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="detector" value="1" <?php checked( $settings['detector'] ); ?>>
								<?php esc_html_e( 'When a visitor accepts cookies, their browser sends the NAMES (never the values) of the cookies it sees on this site, at most once a week or when the list changes. New names appear below as pending.', 'estrategia-web' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save notice settings', 'estrategia-web' ) ); ?>
			</form>

			<?php
			$unasked = array();
			foreach ( $entries as $key => $entry ) {
				if ( 'confirmed' === $entry['status'] && 'necessary' !== $entry['category'] && ! in_array( $entry['category'], $asked, true ) ) {
					$unasked[ $entry['category'] ][] = $key;
				}
			}
			?>
			<?php foreach ( $unasked as $category => $keys ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: 1: category name, 2: list of cookie names. */
						esc_html__( 'The notice does not ask about «%1$s», but these confirmed cookies belong to it: %2$s. Either the notice should ask about it (above), or the cookies should not be set.', 'estrategia-web' ),
						esc_html( $labels[ $category ]['label'] ?? $category ),
						'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $keys ) ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput
					);
					?>
				</p></div>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Cookies of this site', 'estrategia-web' ); ?></h2>
			<p><?php esc_html_e( 'Only confirmed cookies appear in the cookie table of the policy (block «Cookie table» or shortcode [estrategia_web_cookie_table]). Proposals come from our own list and from the Open Cookie Database: check them. A name ending in * covers every cookie that starts like that.', 'estrategia-web' ); ?></p>
			<?php if ( in_array( 'pending', array_column( $entries, 'status' ), true ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:.5em 0">
					<?php self::hidden( 'confirm-all' ); ?>
					<?php submit_button( __( 'Confirm all pending proposals', 'estrategia-web' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Cookie', 'estrategia-web' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'estrategia-web' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Category', 'estrategia-web' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Provider', 'estrategia-web' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Purpose', 'estrategia-web' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Duration', 'estrategia-web' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Seen', 'estrategia-web' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'estrategia-web' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $entries ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No cookies yet. They appear when a visitor accepts the notice, or add them below.', 'estrategia-web' ); ?></td></tr>
					<?php endif; ?>
					<?php
					$i = 0;
					foreach ( $entries as $key => $entry ) :
						$form = 'estrategia-web-cookie-' . ( ++$i );
						?>
						<tr>
							<td>
								<code><?php echo esc_html( (string) $key ); ?></code>
								<?php if ( '' !== $entry['example'] && $entry['example'] !== $key ) : ?>
									<br><small><?php /* translators: %s: a concrete cookie name. */ printf( esc_html__( 'e.g. %s', 'estrategia-web' ), '<code>' . esc_html( $entry['example'] ) . '</code>' ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<select name="status" form="<?php echo esc_attr( $form ); ?>">
									<?php foreach ( self::status_labels() as $status => $status_label ) : ?>
										<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $status, $entry['status'] ); ?>><?php echo esc_html( $status_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><?php echo self::category_select( 'category', $entry['category'], $form ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></td>
							<td><input type="text" class="regular-text" style="width:10em" name="provider" form="<?php echo esc_attr( $form ); ?>" value="<?php echo esc_attr( $entry['provider'] ); ?>"></td>
							<td><textarea rows="2" style="width:100%;min-width:14em" name="purpose" form="<?php echo esc_attr( $form ); ?>"><?php echo esc_textarea( $entry['purpose'] ); ?></textarea></td>
							<td><input type="text" style="width:7em" name="duration" form="<?php echo esc_attr( $form ); ?>" value="<?php echo esc_attr( $entry['duration'] ); ?>"></td>
							<td>
								<small>
									<?php echo 'manual' === $entry['source'] ? esc_html__( 'Added by hand', 'estrategia-web' ) : esc_html( $date( (int) $entry['last_seen'] ) ); ?>
									<?php if ( 'ocd' === $entry['suggested'] ) : ?>
										<br><?php esc_html_e( 'Proposal: Open Cookie Database', 'estrategia-web' ); ?>
									<?php elseif ( 'base' === $entry['suggested'] ) : ?>
										<br><?php esc_html_e( 'Proposal: Estrategia Web', 'estrategia-web' ); ?>
									<?php endif; ?>
								</small>
							</td>
							<td>
								<form id="<?php echo esc_attr( $form ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php self::hidden( 'row' ); ?>
									<input type="hidden" name="name" value="<?php echo esc_attr( (string) $key ); ?>">
									<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'estrategia-web' ); ?></button>
									<button type="submit" class="button-link button-link-delete" name="delete" value="1"><?php esc_html_e( 'Delete', 'estrategia-web' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add a cookie by hand', 'estrategia-web' ); ?></h2>
			<p><?php esc_html_e( 'For third-party cookies the detector cannot see (for example, the ones Google sets on its own domain when a map loads). Empty fields are filled with the proposal, if there is one.', 'estrategia-web' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php self::hidden( 'add' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="estrategia-web-cookie-name"><?php esc_html_e( 'Name', 'estrategia-web' ); ?></label></th><td><input type="text" class="regular-text" id="estrategia-web-cookie-name" name="name" required maxlength="129"></td></tr><?php // No «pattern»: browsers compile it in «v» mode and an unescaped «|» inside [] silently invalidates it. The server validates (Cookie_Registry::name_ok). ?>
					<tr><th scope="row"><label for="estrategia-web-cookie-category"><?php esc_html_e( 'Category', 'estrategia-web' ); ?></label></th><td><?php echo str_replace( '<select ', '<select id="estrategia-web-cookie-category" ', self::category_select( 'category', 'maps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
					<tr><th scope="row"><label for="estrategia-web-cookie-provider"><?php esc_html_e( 'Provider', 'estrategia-web' ); ?></label></th><td><input type="text" class="regular-text" id="estrategia-web-cookie-provider" name="provider"></td></tr>
					<tr><th scope="row"><label for="estrategia-web-cookie-purpose"><?php esc_html_e( 'Purpose', 'estrategia-web' ); ?></label></th><td><textarea class="large-text" rows="2" id="estrategia-web-cookie-purpose" name="purpose"></textarea></td></tr>
					<tr><th scope="row"><label for="estrategia-web-cookie-duration"><?php esc_html_e( 'Duration', 'estrategia-web' ); ?></label></th><td><input type="text" id="estrategia-web-cookie-duration" name="duration"><p class="description"><?php esc_html_e( 'Like «2 years», «30 minutes» or «session» to have it translated automatically; anything else is shown as written.', 'estrategia-web' ); ?></p></td></tr>
				</table>
				<?php submit_button( __( 'Add cookie', 'estrategia-web' ) ); ?>
			</form>

			<p class="description">
				<?php
				printf(
					/* translators: 1: link to the Open Cookie Database, 2: licence name, 3: date. */
					esc_html__( 'Proposals use data from the %1$s, licensed under %2$s (extract of %3$s).', 'estrategia-web' ),
					'<a href="' . esc_url( (string) ( $ocd['source'] ?? '' ) ) . '">Open Cookie Database</a>', // phpcs:ignore WordPress.Security.EscapeOutput
					'Apache 2.0',
					esc_html( (string) ( $ocd['generated'] ?? '' ) )
				);
				?>
			</p>
		</div>
		<?php
	}
}
