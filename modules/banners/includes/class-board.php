<?php
/**
 * The banners by state (Juan, 04-11-2026): one box per state, «Showing» first, so it is seen at a glance which
 * are on and which are not. It is what the «Banners» menu opens; WordPress's list stays as «All banners», for
 * searching, filtering by zone and bulk actions.
 *
 * - The boxes read the STORED state (class-state.php), like the list. «Scheduled» only gets a box when there is
 *   one: most weeks there is none, and an empty box would be noise. The other three always show, empty or not.
 * - Inside each box, the order that box is looked at for: showing and scheduled by start date, expired by end
 *   date (the most recently ended first), off by last modified.
 * - How the menu opens here: the bare list URL (edit.php?post_type=eweb_banner, what the menu links to) redirects
 *   to this page; «All banners» and the list's own «All» view carry all_posts=1, which WordPress already
 *   understands as «all», so they stay on the list.
 *
 * How to maintain: a new state goes in State::ORDER and in BOXES here. The trap: a link to the list without
 * any other argument lands here; add all_posts=1 to it.
 */

namespace EstrategiaWeb\Banners;

defined( 'ABSPATH' ) || exit;

final class Board {

	public const PAGE = 'eweb-banners-board';

	public static function boot(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'load-edit.php', array( self::class, 'redirect' ) );
		add_filter( 'views_edit-' . Post_Type::TYPE, array( self::class, 'all_view' ), 20 );
	}

	/** The list URL that stays on the list. */
	public static function list_url(): string {
		return admin_url( 'edit.php?post_type=' . Post_Type::TYPE . '&all_posts=1' );
	}

	public static function menu(): void {
		global $submenu;
		$parent = 'edit.php?post_type=' . Post_Type::TYPE;
		add_submenu_page( $parent, __( 'Banners', 'estrategiaweb-toolkit' ), __( 'By state', 'estrategiaweb-toolkit' ), 'edit_posts', self::PAGE, array( self::class, 'render' ), 0 );
		// WordPress's own «All banners» entry: it keeps its place, pointing at the list that does not redirect.
		foreach ( $submenu[ $parent ] ?? array() as $i => $item ) {
			if ( $parent === $item[2] ) {
				$submenu[ $parent ][ $i ][0] = __( 'All banners', 'estrategiaweb-toolkit' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the documented way to rename a submenu entry.
				$submenu[ $parent ][ $i ][2] = $parent . '&all_posts=1'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
	}

	/** The bare list URL (the menu) opens the boxes. */
	public static function redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which screen was asked for.
		if ( Post_Type::TYPE === ( $_GET['post_type'] ?? '' ) && 1 === count( $_GET ) && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Type::TYPE . '&page=' . self::PAGE ) );
			exit;
		}
	}

	/**
	 * The list's «All» view with all_posts=1, so it does not bounce back here.
	 *
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public static function all_view( array $views ): array {
		if ( isset( $views['all'] ) ) {
			$views['all'] = preg_replace( '/href=(["\'])[^"\']*\1/', 'href="' . esc_url( self::list_url() ) . '"', $views['all'], 1 );
		}
		return $views;
	}

	/** The boxes: state => [label, sort meta or '' for modified date, order, always shown]. */
	private static function boxes(): array {
		return array(
			Rules::ACTIVE    => array( __( 'Showing', 'estrategiaweb-toolkit' ), Post_Type::FIELDS['start'], 'DESC', true ),
			Rules::SCHEDULED => array( __( 'Scheduled', 'estrategiaweb-toolkit' ), Post_Type::FIELDS['start'], 'ASC', false ),
			Rules::EXPIRED   => array( __( 'Expired', 'estrategiaweb-toolkit' ), Post_Type::FIELDS['end'], 'DESC', true ),
			Rules::OFF       => array( __( 'Off', 'estrategiaweb-toolkit' ), '', 'DESC', true ),
		);
	}

	public static function render(): void {
		$all = array();
		foreach ( self::boxes() as $state => $box ) {
			$args = array(
				'post_type'      => Post_Type::TYPE,
				'post_status'    => Rules::OFF === $state ? array( 'draft', 'pending', 'future', 'private' ) : 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => $box[1] ? 'meta_value_num' : 'modified',
				'order'          => $box[2],
				'meta_query'     => array( array( 'key' => State::META, 'value' => $state ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin, a few dozen banners.
			);
			if ( $box[1] ) {
				$args['meta_key'] = $box[1]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			}
			$all[ $state ] = get_posts( $args );
		}
		$totals = Store::totals( wp_list_pluck( array_merge( ...array_values( $all ) ), 'ID' ) );
		?>
		<div class="wrap ewb-board">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Banners', 'estrategiaweb-toolkit' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Post_Type::TYPE ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add banner', 'estrategiaweb-toolkit' ); ?></a>
			<a href="<?php echo esc_url( self::list_url() ); ?>" class="page-title-action"><?php esc_html_e( 'All banners', 'estrategiaweb-toolkit' ); ?></a>
			<hr class="wp-header-end">
			<?php
			foreach ( self::boxes() as $state => $box ) {
				if ( ! $box[3] && ! $all[ $state ] ) {
					continue;
				}
				self::box( $state, $box[0], $all[ $state ], $totals );
			}
			?>
		</div>
		<style>
			.ewb-board__box{background:#fff;border:1px solid #c3c4c7;border-left-width:6px;margin:20px 0;max-width:1200px}
			.ewb-board__box--active{border-left-color:#00a32a}.ewb-board__box--scheduled{border-left-color:#2271b1}
			.ewb-board__box--expired{border-left-color:#dba617}.ewb-board__box--off{border-left-color:#8c8f94}
			.ewb-board__head{display:flex;align-items:center;gap:10px;margin:0;padding:12px 16px;font-size:15px;border-bottom:1px solid #f0f0f1}
			.ewb-board__count{display:inline-block;min-width:22px;padding:0 8px;border-radius:11px;background:#f0f0f1;color:#50575e;font-size:12px;line-height:22px;text-align:center}
			.ewb-board__box--active .ewb-board__count{background:#edfaef;color:#00650f}
			.ewb-board__box--scheduled .ewb-board__count{background:#f0f6fc;color:#0a4b78}
			.ewb-board__box--expired .ewb-board__count{background:#fcf9e8;color:#614a00}
			.ewb-board__empty{margin:0;padding:14px 16px;color:#646970}
			.ewb-board table{border:0;box-shadow:none}
			.ewb-board td{vertical-align:middle}
			.ewb-board__img{width:150px}
			.ewb-board__img img{display:block;max-width:140px;max-height:60px;width:auto;height:auto}
			.ewb-board__code{display:inline-block;padding:2px 8px;border-radius:3px;background:#f0f0f1;font-family:monospace;font-size:12px}
			.ewb-board__num{width:80px;text-align:right}
			.ewb-board__box--expired tbody,.ewb-board__box--off tbody{opacity:.75}
			@media (max-width:782px){.ewb-board__img{width:90px}.ewb-board__img img{max-width:80px}.ewb-board .ewb-board__hide{display:none}}
		</style>
		<?php
	}

	/**
	 * @param \WP_Post[]                                      $posts
	 * @param array<int, array{views: int, clicks: int}> $totals
	 */
	private static function box( string $state, string $label, array $posts, array $totals ): void {
		$fmt = get_option( 'date_format' );
		printf( '<section class="ewb-board__box ewb-board__box--%1$s" aria-labelledby="ewb-board-%1$s">', esc_attr( $state ) );
		printf( '<h2 class="ewb-board__head" id="ewb-board-%s">%s <span class="ewb-board__count">%s</span></h2>', esc_attr( $state ), esc_html( $label ), esc_html( number_format_i18n( count( $posts ) ) ) );
		if ( ! $posts ) {
			echo '<p class="ewb-board__empty">' . esc_html__( 'No banners found.', 'estrategiaweb-toolkit' ) . '</p></section>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th class="ewb-board__img"></th><th>' . esc_html__( 'Title', 'estrategiaweb-toolkit' ) . '</th>';
		echo '<th class="ewb-board__hide">' . esc_html__( 'Zones', 'estrategiaweb-toolkit' ) . '</th><th>' . esc_html__( 'Dates', 'estrategiaweb-toolkit' ) . '</th>';
		echo '<th class="ewb-board__num ewb-board__hide">' . esc_html__( 'Views', 'estrategiaweb-toolkit' ) . '</th><th class="ewb-board__num ewb-board__hide">' . esc_html__( 'Clicks', 'estrategiaweb-toolkit' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $posts as $post ) {
			$d     = Post_Type::data( $post );
			$edit  = get_edit_post_link( $post->ID );
			$zones = get_the_terms( $post->ID, Post_Type::ZONE );
			$title = get_the_title( $post ) ?: __( '(no title)', 'estrategiaweb-toolkit' );
			echo '<tr><td class="ewb-board__img">';
			echo $d['image'] ? wp_get_attachment_image( $d['image'], 'medium', false, array( 'alt' => '' ) ) : '<span class="ewb-board__code">' . esc_html__( 'Code', 'estrategiaweb-toolkit' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image.
			printf( '</td><td><strong><a href="%s">%s</a></strong></td>', esc_url( (string) $edit ), esc_html( $title ) );
			printf( '<td class="ewb-board__hide">%s</td>', esc_html( is_array( $zones ) ? implode( ', ', wp_list_pluck( $zones, 'name' ) ) : '—' ) );
			printf( '<td>%s</td>', esc_html( ( $d['start'] ? wp_date( $fmt, $d['start'] ) : '…' ) . ' – ' . ( $d['end'] ? wp_date( $fmt, $d['end'] ) : '…' ) ) );
			printf( '<td class="ewb-board__num ewb-board__hide">%s</td>', esc_html( number_format_i18n( $totals[ $post->ID ]['views'] ?? 0 ) ) );
			printf( '<td class="ewb-board__num ewb-board__hide">%s</td></tr>', esc_html( number_format_i18n( $totals[ $post->ID ]['clicks'] ?? 0 ) ) );
		}
		echo '</tbody></table></section>';
	}
}
