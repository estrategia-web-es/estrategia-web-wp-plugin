<?php
namespace EstrategiaWeb\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * «Estrategia Web › Settings» (1.7.0): ONE page with WordPress tabs
 * (?page=estrategia-web-settings&tab=…) for the modules' settings that are
 * set once and forgotten — today «Cookies» (consent), «Analytics»
 * (google-tags and matomo) and «Media» (site's SVG and uploads). It knows
 * NO module: each module adds its tab and its section through the registry
 * below. With no section at all, the page is not even registered.
 *
 * =========================================================================
 * HOW A MODULE ADDS ITS SETTINGS
 * =========================================================================
 *
 *     add_action( 'estrategia_web_settings_tabs', static function () {
 *         Settings_Page::add_tab( 'analytics', __( 'Analytics', 'estrategia-web' ), 20 );
 *         Settings_Page::add_section( 'analytics', array(
 *             'id'       => 'google-tags',
 *             'priority' => 10,
 *             'options'  => array( Google_Tags::OPTION ),
 *             'render'   => array( Google_Tags_Page::class, 'render_section' ),
 *         ) );
 *     } );
 *
 * - add_tab( id, label, order ): idempotent. Two modules sharing a tab
 *   (google-tags and matomo on «analytics») both call it; the FIRST call's
 *   label and order win, so both must say the same thing. Today's orders:
 *   cookies 10, analytics 20, media 30.
 * - add_section( tab, args ): what the tab shows, sorted by priority (then
 *   id). A TAB IS SHOWN ONLY IF IT HAS A SECTION, which is how «each tab only
 *   if its module is on» comes out by itself: a module that is off does not
 *   load, so it registers nothing.
 * - 'options': the options the section's fields save through options.php.
 *   The page wraps ALL the sections of a tab that declare options in ONE
 *   <form action="options.php"> with ONE Save button, under the settings
 *   group estrategia-web-settings-<tab> (group()), and adds those options
 *   to that group itself (allowed_options): the section only prints its
 *   rows, named <option>[key], without <form> or settings_fields().
 * - A section WITHOUT 'options' manages its own forms (the cookie list:
 *   one form per row, admin-post.php). It is printed AFTER the shared form,
 *   never inside it (forms cannot be nested).
 * - The hook fires once per request, the first time tabs() is called (from
 *   admin_menu on), so __() in the labels is safe: init has run.
 *
 * =========================================================================
 * THE EDGE CASE AND THE TRAP
 * =========================================================================
 *
 * Edge case, by name: TWO SCREENS SAVING THE SAME OPTION. estrategia_web_site
 * is saved by Company details (company keys) and by Settings › Media
 * (allow_svg) and › Analytics (matomo_*). It works because
 * Site\Settings::sanitize() MERGES with what is stored: a key that does not
 * arrive does not change. tests/settings-page.test.mjs saves each screen and
 * checks the other's keys survive. A new option shared like that needs a
 * sanitize() that merges too.
 *
 * THE TRAP: options.php saves EVERY option of the group, and one that is not
 * in the POST arrives as null (update_option( $option, null )). So every
 * option declared here must (a) have all its fields printed whenever its
 * section is (a section that prints nothing in some case must not declare the
 * option then), and (b) have a sanitize that keeps what is stored for null.
 * It already bit once (1.7.0, uploads): its option was in WordPress's «media»
 * group, so saving Settings › Media of WordPress without its fields would
 * have reset it to the defaults. Its group is its own now.
 *
 * How to maintain:
 * - Who: whoever moves a module's settings here. Criterion: a setting that
 *   is set once and rarely changed goes to a tab; a LIST that grows with use
 *   (redirects, submissions, social networks, content types) is its own page
 *   of the menu.
 * - Old URLs of screens that became tabs redirect from Menu::LEGACY
 *   (class-menu.php): add the old slug there when you move one.
 * - Tests: tests/settings-page.test.mjs (level 1: layout(); level 2: each tab
 *   iff its module is on, saving does not wipe the other screen's keys).
 */

final class Settings_Page {

	public const SLUG = 'estrategia-web-settings';

	/** The action modules hook into to call add_tab() / add_section(). */
	public const HOOK = 'estrategia_web_settings_tabs';

	/** @var array<string, array{label: string, order: int}> */
	private static array $tabs = array();

	/** @var array<int, array<string, mixed>> */
	private static array $sections = array();

	private static bool $collected = false;

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_filter( 'allowed_options', array( self::class, 'allowed_options' ) );
	}

	/** Registers a tab (idempotent: the first call wins). */
	public static function add_tab( string $id, string $label, int $order = 50 ): void {
		if ( ! isset( self::$tabs[ $id ] ) ) {
			self::$tabs[ $id ] = array(
				'label' => $label,
				'order' => $order,
			);
		}
	}

	/**
	 * Adds a section to a tab.
	 *
	 * @param string               $tab  Tab id.
	 * @param array<string, mixed> $args id, priority (10), options (string|string[]), render (callable).
	 */
	public static function add_section( string $tab, array $args ): void {
		self::$sections[] = array_merge( array( 'id' => '', 'priority' => 10, 'options' => array(), 'render' => null ), $args, array( 'tab' => $tab ) );
	}

	/**
	 * The visible tabs, in order, each with its sections in order and the
	 * options its shared form saves. Pure function (no WordPress), so it can
	 * be tested at level 1.
	 *
	 * Rules: a tab without sections is not shown; a section of a tab that was
	 * not registered, or without a callable render, is dropped; tabs by order
	 * then id; sections by priority then id; options without duplicates.
	 *
	 * @param array<string, array{label: string, order: int}> $tabs     Registered tabs.
	 * @param array<int, array<string, mixed>>                $sections Registered sections.
	 * @return array<string, array{label: string, order: int, sections: array<int, array<string, mixed>>, options: string[]}>
	 */
	public static function layout( array $tabs, array $sections ): array {
		$out = array();
		foreach ( $sections as $section ) {
			$tab = (string) ( $section['tab'] ?? '' );
			if ( ! isset( $tabs[ $tab ] ) || ! is_callable( $section['render'] ?? null ) ) {
				continue;
			}
			$section['options'] = array_values( array_filter( array_map( 'strval', (array) ( $section['options'] ?? array() ) ) ) );
			$section['priority'] = (int) ( $section['priority'] ?? 10 );
			$out[ $tab ] ??= array(
				'label'    => (string) $tabs[ $tab ]['label'],
				'order'    => (int) $tabs[ $tab ]['order'],
				'sections' => array(),
				'options'  => array(),
			);
			$out[ $tab ]['sections'][] = $section;
		}
		foreach ( $out as $tab => $data ) {
			usort( $data['sections'], static fn( $a, $b ) => ( $a['priority'] <=> $b['priority'] ) ?: strcmp( (string) $a['id'], (string) $b['id'] ) );
			$data['options'] = array_values( array_unique( array_merge( array(), ...array_column( $data['sections'], 'options' ) ) ) ); // In section order.
			$out[ $tab ]     = $data;
		}
		uksort( $out, static fn( $a, $b ) => ( $out[ $a ]['order'] <=> $out[ $b ]['order'] ) ?: strcmp( $a, $b ) );
		return $out;
	}

	/** The visible tabs of this request (fires the hook the first time). */
	public static function tabs(): array {
		if ( ! self::$collected ) {
			self::$collected = true;
			/**
			 * Modules register their tabs and sections here (see the header).
			 */
			do_action( self::HOOK );
		}
		return self::layout( self::$tabs, self::$sections );
	}

	/** Settings group (option_page) of a tab's shared form. */
	public static function group( string $tab ): string {
		return self::SLUG . '-' . $tab;
	}

	/** URL of the page, of a tab, with extra query args. */
	public static function url( string $tab = '', array $args = array() ): string {
		$query = array( 'page' => self::SLUG );
		if ( '' !== $tab ) {
			$query['tab'] = $tab;
		}
		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/** The tab asked for in ?tab=, or the first one if it is not there (a module switched off since). */
	public static function current_tab( array $tabs ): string {
		$asked = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		return isset( $tabs[ $asked ] ) ? $asked : (string) array_key_first( $tabs );
	}

	/**
	 * Filter allowed_options (options.php): each tab's group may save the
	 * options its sections declared.
	 *
	 * @param array<string, string[]> $allowed Group => options.
	 */
	public static function allowed_options( $allowed ): array {
		$allowed = is_array( $allowed ) ? $allowed : array();
		foreach ( self::tabs() as $id => $tab ) {
			if ( $tab['options'] ) {
				$allowed[ self::group( $id ) ] = $tab['options'];
			}
		}
		return $allowed;
	}

	public static function menu(): void {
		if ( ! self::tabs() ) {
			return; // No module with settings: no page.
		}
		add_submenu_page( Menu::PARENT, __( 'Settings', 'estrategia-web' ), __( 'Settings', 'estrategia-web' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		if ( ! $tabs ) {
			return;
		}
		$current  = self::current_tab( $tabs );
		$sections = $tabs[ $current ]['sections'];
		$in_form  = array_filter( $sections, static fn( $s ) => (bool) $s['options'] );
		$own      = array_filter( $sections, static fn( $s ) => ! $s['options'] );
		?>
		<div class="wrap ew-screen estrategia-web-settings" data-tab="<?php echo esc_attr( $current ); ?>">
			<?php
			Admin_UI::header( __( 'Settings', 'estrategia-web' ), __( 'How the site behaves, by topic: the cookie notice, the statistics and what is accepted when uploading images.', 'estrategia-web' ) );
			$links = array();
			foreach ( $tabs as $id => $tab ) {
				$links[ $id ] = array( $tab['label'], self::url( $id ) );
			}
			Admin_UI::tabs( $links, $current, __( 'Settings sections', 'estrategia-web' ) );
			settings_errors();
			?>
			<?php if ( $in_form ) : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( self::group( $current ) );
					foreach ( $in_form as $section ) {
						call_user_func( $section['render'] );
					}
					Admin_UI::save();
					?>
				</form>
			<?php endif; ?>
			<?php
			foreach ( $own as $section ) {
				call_user_func( $section['render'] );
			}
			?>
		</div>
		<?php
	}
}
