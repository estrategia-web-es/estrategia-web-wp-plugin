<?php
/**
 * The banner (post type eweb_banner), its zones (taxonomy eweb_banner_zone)
 * and its fields, with the edit screen and the list columns.
 *
 * A banner is EITHER an image with a link (featured image + «Link») OR code
 * (an adserver's script, an iframe). If it has a featured image it is an
 * image banner and the code is ignored; that is the whole rule.
 *
 *   estrategia_web_banner_link     URL the image links to (sponsored, new tab)
 *   estrategia_web_banner_code     raw HTML; only users with unfiltered_html
 *   estrategia_web_banner_start    unix time, 0 = from now
 *   estrategia_web_banner_end      unix time, 0 = no end
 *   estrategia_web_banner_weight   1-10, how often it comes up when a zone shows fewer than it has
 *   estrategia_web_banner_adrotate AdRotate id it came from (the migration's key)
 *
 * Draft = switched off; published = it shows inside its dates. The state
 * (active, scheduled, expired, off) is computed, never stored: Rules::state().
 *
 * The screen is the classic one on purpose: the type has no «editor», so
 * WordPress does not open the block editor, and the fields are a plain
 * meta box. It IS in REST (show_in_rest), so a script can create the
 * week's banners without the panel.
 *
 * How to maintain: a new field goes in FIELDS, in the meta box and in the
 * README's contract table, in the same commit.
 */

namespace EstrategiaWeb\Banners;

defined( 'ABSPATH' ) || exit;

final class Post_Type {

	public const TYPE = 'eweb_banner';
	public const ZONE = 'eweb_banner_zone';

	public const FIELDS = array(
		'link'     => 'estrategia_web_banner_link',
		'code'     => 'estrategia_web_banner_code',
		'start'    => 'estrategia_web_banner_start',
		'end'      => 'estrategia_web_banner_end',
		'weight'   => 'estrategia_web_banner_weight',
		'adrotate' => 'estrategia_web_banner_adrotate',
	);

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . self::TYPE, array( self::class, 'meta_boxes' ) );
			add_action( 'save_post_' . self::TYPE, array( self::class, 'save' ), 10, 2 );
			add_filter( 'manage_' . self::TYPE . '_posts_columns', array( self::class, 'columns' ) );
			add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		}
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Banners', 'estrategia-web' ),
					'singular_name'      => __( 'Banner', 'estrategia-web' ),
					'add_new'            => __( 'Add banner', 'estrategia-web' ),
					'add_new_item'       => __( 'Add banner', 'estrategia-web' ),
					'edit_item'          => __( 'Edit banner', 'estrategia-web' ),
					'new_item'           => __( 'New banner', 'estrategia-web' ),
					'search_items'       => __( 'Search banners', 'estrategia-web' ),
					'not_found'          => __( 'No banners found.', 'estrategia-web' ),
					'not_found_in_trash' => __( 'No banners found in Trash.', 'estrategia-web' ),
					'featured_image'     => __( 'Banner image', 'estrategia-web' ),
					'set_featured_image' => __( 'Set banner image', 'estrategia-web' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-megaphone',
				'show_in_nav_menus'   => false,
				'show_in_rest'        => true,
				'rest_base'           => 'eweb-banners',
				'query_var'           => false,
				'rewrite'             => false,
				'has_archive'         => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'thumbnail', 'custom-fields' ),
			)
		);
		register_taxonomy(
			self::ZONE,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Banner zones', 'estrategia-web' ),
					'singular_name' => __( 'Banner zone', 'estrategia-web' ),
					'menu_name'     => __( 'Zones', 'estrategia-web' ),
					'add_new_item'  => __( 'Add zone', 'estrategia-web' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true, // Checkboxes on the edit screen; zones have no parents.
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
		$text = static fn() => current_user_can( 'edit_posts' );
		foreach ( array( 'link' => 'string', 'start' => 'integer', 'end' => 'integer', 'weight' => 'integer', 'adrotate' => 'integer' ) as $key => $type ) {
			register_post_meta( self::TYPE, self::FIELDS[ $key ], array( 'type' => $type, 'single' => true, 'show_in_rest' => true, 'auth_callback' => $text ) );
		}
		register_post_meta(
			self::TYPE,
			self::FIELDS['code'],
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static fn() => current_user_can( 'unfiltered_html' ),
			)
		);
	}

	/**
	 * A banner's data, ready to render.
	 *
	 * @return array{id: int, kind: string, link: string, image: int, code: string, start: int, end: int, weight: int, state: string}
	 */
	public static function data( \WP_Post $post ): array {
		$get   = static fn( string $key ) => get_post_meta( $post->ID, self::FIELDS[ $key ], true );
		$image = (int) get_post_thumbnail_id( $post );
		$start = (int) $get( 'start' );
		$end   = (int) $get( 'end' );
		return array(
			'id'     => $post->ID,
			'kind'   => $image ? 'image' : 'code',
			'link'   => (string) $get( 'link' ),
			'image'  => $image,
			'code'   => (string) $get( 'code' ),
			'start'  => $start,
			'end'    => $end,
			'weight' => max( 1, (int) ( $get( 'weight' ) ?: 5 ) ),
			'state'  => Rules::state( $post->post_status, $start, $end, time() ),
		);
	}

	public static function meta_boxes(): void {
		add_meta_box( 'estrategia-web-banner', __( 'Banner', 'estrategia-web' ), array( self::class, 'box' ), self::TYPE, 'normal', 'high' );
		add_meta_box( 'estrategia-web-banner-stats', __( 'Statistics', 'estrategia-web' ), array( self::class, 'stats_box' ), self::TYPE, 'normal', 'default' );
	}

	/** Unix time → value of a datetime-local input, in the site's time zone. */
	private static function local( int $time ): string {
		return $time ? wp_date( 'Y-m-d\TH:i', $time ) : '';
	}

	/** Value of a datetime-local input (site's time zone) → unix time; '' → 0. */
	private static function unix( string $value ): int {
		if ( '' === trim( $value ) ) {
			return 0;
		}
		$date = date_create_immutable( $value, wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	public static function box( \WP_Post $post ): void {
		$d = self::data( $post );
		wp_nonce_field( 'estrategia_web_banner', 'estrategia_web_banner_nonce' );
		?>
		<p><?php esc_html_e( 'With a banner image (box on the right) it is an image banner with its link; without one, the code below is shown.', 'estrategia-web' ); ?></p>
		<p><label for="ewb-link"><strong><?php esc_html_e( 'Link', 'estrategia-web' ); ?></strong></label><br>
			<input type="url" id="ewb-link" name="ewb[link]" value="<?php echo esc_attr( $d['link'] ); ?>" class="widefat" placeholder="https://"></p>
		<p><label for="ewb-code"><strong><?php esc_html_e( 'Code (adserver, iframe)', 'estrategia-web' ); ?></strong></label><br>
			<textarea id="ewb-code" name="ewb[code]" rows="6" class="widefat code" <?php disabled( ! current_user_can( 'unfiltered_html' ) ); ?>><?php echo esc_textarea( $d['code'] ); ?></textarea></p>
		<p style="display:flex;gap:24px;flex-wrap:wrap">
			<label><strong><?php esc_html_e( 'Starts', 'estrategia-web' ); ?></strong><br><input type="datetime-local" name="ewb[start]" value="<?php echo esc_attr( self::local( $d['start'] ) ); ?>"></label>
			<label><strong><?php esc_html_e( 'Ends', 'estrategia-web' ); ?></strong><br><input type="datetime-local" name="ewb[end]" value="<?php echo esc_attr( self::local( $d['end'] ) ); ?>"></label>
			<label><strong><?php esc_html_e( 'Weight (1-10)', 'estrategia-web' ); ?></strong><br><input type="number" min="1" max="10" name="ewb[weight]" value="<?php echo esc_attr( (string) $d['weight'] ); ?>" style="width:6em"></label>
		</p>
		<p class="description"><?php esc_html_e( 'Empty dates: no limit. The weight only matters when a zone shows fewer banners than it has.', 'estrategia-web' ); ?></p>
		<?php
	}

	public static function stats_box( \WP_Post $post ): void {
		$days   = Store::days( $post->ID, 60 );
		$totals = Store::totals( array( $post->ID ) )[ $post->ID ] ?? array( 'views' => 0, 'clicks' => 0 );
		/* translators: 1: views, 2: clicks. */
		echo '<p><strong>' . esc_html( sprintf( __( 'Total: %1$s views, %2$s clicks', 'estrategia-web' ), number_format_i18n( $totals['views'] ), number_format_i18n( $totals['clicks'] ) ) ) . '</strong></p>';
		if ( ! $days ) {
			echo '<p>' . esc_html__( 'No statistics yet.', 'estrategia-web' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:420px"><thead><tr><th>' . esc_html__( 'Day', 'estrategia-web' ) . '</th><th>' . esc_html__( 'Views', 'estrategia-web' ) . '</th><th>' . esc_html__( 'Clicks', 'estrategia-web' ) . '</th></tr></thead><tbody>';
		foreach ( $days as $row ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( mysql2date( get_option( 'date_format' ), $row['day'] ) ), esc_html( number_format_i18n( $row['views'] ) ), esc_html( number_format_i18n( $row['clicks'] ) ) );
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Last 60 days with activity. A view is counted when the banner is on screen; code banners (adservers) count their clicks on their own side.', 'estrategia-web' ) . '</p>';
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['estrategia_web_banner_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['estrategia_web_banner_nonce'] ) ), 'estrategia_web_banner' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['ewb'] ) && is_array( $_POST['ewb'] ) ? wp_unslash( $_POST['ewb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key sanitized below.
		update_post_meta( $post_id, self::FIELDS['link'], esc_url_raw( (string) ( $in['link'] ?? '' ) ) );
		update_post_meta( $post_id, self::FIELDS['start'], self::unix( sanitize_text_field( (string) ( $in['start'] ?? '' ) ) ) );
		update_post_meta( $post_id, self::FIELDS['end'], self::unix( sanitize_text_field( (string) ( $in['end'] ?? '' ) ) ) );
		update_post_meta( $post_id, self::FIELDS['weight'], min( 10, max( 1, (int) ( $in['weight'] ?? 5 ) ) ) );
		if ( current_user_can( 'unfiltered_html' ) && isset( $in['code'] ) ) {
			update_post_meta( $post_id, self::FIELDS['code'], (string) $in['code'] );
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['ewb_dates']  = __( 'Dates', 'estrategia-web' );
				$out['ewb_state']  = __( 'State', 'estrategia-web' );
				$out['ewb_views']  = __( 'Views', 'estrategia-web' );
				$out['ewb_clicks'] = __( 'Clicks', 'estrategia-web' );
				continue;
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	public static function column( string $column, int $post_id ): void {
		static $totals = null;
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$d = self::data( $post );
		switch ( $column ) {
			case 'ewb_dates':
				$fmt = get_option( 'date_format' );
				echo esc_html( ( $d['start'] ? wp_date( $fmt, $d['start'] ) : '…' ) . ' – ' . ( $d['end'] ? wp_date( $fmt, $d['end'] ) : '…' ) );
				break;
			case 'ewb_state':
				$labels = array(
					Rules::ACTIVE    => __( 'Showing', 'estrategia-web' ),
					Rules::SCHEDULED => __( 'Scheduled', 'estrategia-web' ),
					Rules::EXPIRED   => __( 'Expired', 'estrategia-web' ),
					Rules::OFF       => __( 'Off', 'estrategia-web' ),
				);
				echo esc_html( $labels[ $d['state'] ] );
				break;
			case 'ewb_views':
			case 'ewb_clicks':
				if ( null === $totals ) {
					global $wp_query;
					$totals = Store::totals( wp_list_pluck( $wp_query->posts ?? array(), 'ID' ) );
				}
				echo esc_html( number_format_i18n( $totals[ $post_id ][ 'ewb_views' === $column ? 'views' : 'clicks' ] ?? 0 ) );
				break;
		}
	}
}
