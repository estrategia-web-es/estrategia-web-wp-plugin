<?php
namespace EstrategiaWeb\Llms;

use EstrategiaWeb\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * «Estrategia Web › Settings › llms.txt»: one text per language, the link to
 * each file and the «Generate draft» button, with the warnings of
 * class-conflicts.php at the top.
 *
 * It is a section of the Settings page's registry
 * (includes/admin/class-settings-page.php, at the plugin's root) declaring
 * estrategia_web_llms: the page wraps it in the tab's form and saves it
 * through options.php, sanitised by Runtime::sanitize(), which merges.
 * Why a Settings tab and not its own menu page: the criterion of that header,
 * a text that is set once and rarely changed.
 *
 * The draft is built here, when the tab is printed, for every language (its
 * pages and headings in that language), and goes in a textarea without a
 * name next to the real one: the button (assets/admin.js) copies it over, and
 * asks first if there is text. Nothing is saved until «Save».
 *
 * How to maintain:
 * - What the draft takes: draft_data(). A new source (a block of services, a
 *   price list) goes there and in Text::draft().
 * - Criterion for the pages: published, not noindex in Rank Math or Yoast
 *   (a page Google is told to skip is not one an AI should cite), in menu
 *   order. At most PAGES: a site with hundreds is a site whose llms.txt
 *   has to be written by hand anyway.
 * - The trap of the shared form: every language's textarea is printed every
 *   time (the option must have all its fields whenever the section prints,
 *   header of class-settings-page.php). The draft textareas have no name, so
 *   they are never sent.
 */
final class Tab {

	public const TAB = 'llms';

	/** At most this many pages in a draft. */
	public const PAGES = 50;

	public static function boot(): void {
		add_action( Settings_Page::HOOK, array( self::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function register(): void {
		Settings_Page::add_tab( self::TAB, __( 'llms.txt', 'estrategiaweb-toolkit' ), 40 );
		Settings_Page::add_section(
			self::TAB,
			array(
				'id'      => 'llms',
				'options' => array( Runtime::OPTION ),
				'render'  => array( self::class, 'render_section' ),
			)
		);
	}

	public static function enqueue( string $hook ): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab is shown.
		if ( ! str_ends_with( $hook, '_page_' . Settings_Page::SLUG ) || self::TAB !== $tab ) {
			return;
		}
		wp_enqueue_script( 'estrategia-web-llms-admin', plugin_dir_url( dirname( __DIR__ ) ) . 'assets/admin.js', array(), ESTRATEGIA_WEB_VERSION, true );
	}

	public static function render_section(): void {
		$languages = Languages::all();
		$texts     = Runtime::texts();
		$several   = count( $languages ) > 1;
		\EstrategiaWeb\Admin\Admin_UI::box_open( __( 'llms.txt', 'estrategiaweb-toolkit' ), 'ew-box-llms' );
		self::warnings();
		echo '<p>' . esc_html__( 'What AI assistants read to describe this website: what the business is, where, for whom, and the pages that answer the usual questions. Markdown, as on llmstxt.org. A language without text serves nothing.', 'estrategiaweb-toolkit' ) . '</p>';
		if ( $several ) {
			echo '<p>' . esc_html__( 'One per language. The default language\'s file links the others at the end by itself.', 'estrategiaweb-toolkit' ) . '</p>';
		}
		echo '<table class="form-table" role="presentation">';
		foreach ( $languages as $code => $lang ) {
			$id    = 'estrategia-web-llms-' . $code;
			$label = $several ? ( '' !== $lang['name'] ? $lang['name'] : $code ) : __( 'Text', 'estrategiaweb-toolkit' );
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea class="large-text code" rows="18" id="%1$s" name="%3$s">%4$s</textarea><textarea hidden readonly id="%1$s-draft">%5$s</textarea><p><button type="button" class="button" data-ew-llms-draft="%1$s" data-confirm="%6$s">%7$s</button> <a href="%8$s" target="_blank" rel="noopener"><code>%9$s</code></a></p></td></tr>',
				esc_attr( $id ),
				esc_html( $label ),
				esc_attr( Runtime::OPTION . '[' . $code . ']' ),
				esc_textarea( $texts[ $code ] ?? '' ),
				esc_textarea( self::draft( $code ) ),
				esc_attr__( 'Replace the text with the draft? Nothing is saved until you press Save.', 'estrategiaweb-toolkit' ),
				esc_html__( 'Generate draft', 'estrategiaweb-toolkit' ),
				esc_url( Languages::url( $code ) ),
				esc_html( Languages::url( $code ) )
			);
		}
		echo '</table>';
		\EstrategiaWeb\Admin\Admin_UI::box_close();
	}

	private static function warnings(): void {
		$warn = static function ( string $title, string $text ): void {
			echo '<div class="notice notice-warning inline ew-llms-conflict"><p><strong>' . esc_html( $title ) . '</strong> ' . esc_html( $text ) . '</p></div>';
		};
		if ( Conflicts::rank_math() ) {
			$warn( __( 'Rank Math also serves /llms.txt.', 'estrategiaweb-toolkit' ), __( 'Its «LLMS Txt» module is on, and while this module is on, the file served is this one: Rank Math\'s is not seen. Turn one of the two off (in Rank Math → Dashboard → Modules, or in Estrategia Web → Modules).', 'estrategiaweb-toolkit' ) );
		}
		if ( Conflicts::loose_file( ABSPATH ) ) {
			$warn( __( 'There is a llms.txt file in the website\'s folder.', 'estrategiaweb-toolkit' ), __( 'The web server sends that file before WordPress runs, so what is written here is not seen. Delete the file from the server (it is next to wp-config.php).', 'estrategiaweb-toolkit' ) );
		}
	}

	/** The draft of one language, as Text::draft() writes it. */
	public static function draft( string $code ): string {
		return Languages::with( $code, static fn(): string => Text::draft( self::draft_data( $code ) ) );
	}

	/** What the draft is made of, read with WPML and gettext already in $code. */
	private static function draft_data( string $code ): array {
		$company = static function ( string $key ) use ( $code ): string {
			if ( ! class_exists( \EstrategiaWeb\Site\Company::class ) ) {
				return '';
			}
			return Languages::company( $key, \EstrategiaWeb\Site\Company::value( $key ), $code );
		};
		$facts = array( $company( 'legal_name' ), $company( 'tax_id' ), str_replace( "\n", ', ', $company( 'address' ) ), $company( 'phone' ), $company( 'email' ), Languages::home( $code ) );
		$pages = array();
		$posts = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'orderby'          => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'numberposts'      => self::PAGES,
				'suppress_filters' => false, // WPML: the pages of $code.
			)
		);
		foreach ( $posts as $post ) {
			if ( self::noindex( $post->ID ) ) {
				continue;
			}
			$pages[] = array(
				'title'       => get_the_title( $post ),
				'url'         => (string) get_permalink( $post ),
				'description' => self::description( $post ),
			);
		}
		return array(
			'name'    => '' !== $company( 'company_name' ) ? $company( 'company_name' ) : (string) get_bloginfo( 'name' ),
			'summary' => (string) get_bloginfo( 'description' ),
			'facts'   => $facts,
			'heading' => __( 'Pages', 'estrategiaweb-toolkit' ),
			'pages'   => $pages,
		);
	}

	/** Rank Math's or Yoast's description, or the excerpt; '' if it has variables (%excerpt%) or none. */
	private static function description( \WP_Post $post ): string {
		foreach ( array( 'rank_math_description', '_yoast_wpseo_metadesc' ) as $key ) {
			$desc = (string) get_post_meta( $post->ID, $key, true );
			if ( '' !== $desc ) {
				return str_contains( $desc, '%' ) ? '' : $desc;
			}
		}
		return has_excerpt( $post ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : '';
	}

	private static function noindex( int $id ): bool {
		return in_array( 'noindex', (array) get_post_meta( $id, 'rank_math_robots', true ), true ) || '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
	}
}
