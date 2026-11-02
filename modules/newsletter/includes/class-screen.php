<?php
/**
 * «Posts → Newsletter» (edit.php?page=estrategia-web-newsletter): where
 * whoever makes the weekly newsletter chooses the posts, orders them, sees
 * the email and either copies its code or creates the Mailchimp draft.
 *
 * The page is plain HTML with empty boxes; assets/newsletter.js fills them
 * through the REST routes (class-rest.php). The proposed posts come inline
 * (no first request), the texts too (already translated here: it is not a
 * block script, so class-blocks.php does not pick it up).
 *
 * Under Posts and not under «Estrategia Web» because it is weekly editorial
 * work, not a setting: an editor reaches it, and the Estrategia Web menu is
 * for administrators. Its settings are a tab of Estrategia Web › Settings.
 *
 * How to maintain:
 * - Who and when: whoever changes the screen. A new text goes in text() and
 *   is used from JS as t.key; a new box follows Admin_UI (header of
 *   includes/admin/class-admin-ui.php).
 * - Criterion: the screen never builds HTML of the email: it shows what
 *   /newsletter/preview returns.
 * - Edge case, by name: no Mailchimp key. The screen works the same and
 *   «Create the draft» is disabled, with a link to the settings: copying the
 *   code is still useful.
 * - The trap: the preview iframe is sandboxed WITHOUT allow-scripts and
 *   allow-same-origin. The templates are raw HTML typed in the settings;
 *   without the sandbox they would run in wp-admin's origin.
 */

namespace EstrategiaWeb\Newsletter;

use EstrategiaWeb\Admin\Admin_UI;
use EstrategiaWeb\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

final class Screen {

	public const PAGE = 'estrategia-web-newsletter';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php', __( 'Newsletter', 'estrategia-web' ), __( 'Newsletter', 'estrategia-web' ), Rest::CAP, self::PAGE, array( self::class, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::PAGE ) ) {
			return;
		}
		$base = plugin_dir_url( __DIR__ );
		wp_enqueue_style( 'estrategia-web-newsletter', $base . 'assets/newsletter.css', array(), ESTRATEGIA_WEB_VERSION );
		wp_enqueue_script( 'estrategia-web-newsletter', $base . 'assets/newsletter.js', array(), ESTRATEGIA_WEB_VERSION, true );
		$data = array(
			'rest'       => esc_url_raw( rest_url( Rest::NS . '/newsletter/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'hasKey'     => '' !== Settings::get()['api_key'],
			'candidates' => Builder::candidates(),
			'text'       => self::text(),
		);
		wp_add_inline_script( 'estrategia-web-newsletter', 'window.estrategiaWebNewsletter = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/** @return array<string, string> */
	private static function text(): array {
		return array(
			'add'        => __( 'Add', 'estrategia-web' ),
			'added'      => __( 'Added', 'estrategia-web' ),
			'up'         => __( 'Move up', 'estrategia-web' ),
			'down'       => __( 'Move down', 'estrategia-web' ),
			'remove'     => __( 'Remove', 'estrategia-web' ),
			'featured'   => __( 'On the front page', 'estrategia-web' ),
			'latest'     => __( 'Latest posts', 'estrategia-web' ),
			'results'    => __( 'Search results', 'estrategia-web' ),
			'none'       => __( 'Nothing found.', 'estrategia-web' ),
			'empty'      => __( 'Add the posts of this week, in the order they will go. The first one gives the subject.', 'estrategia-web' ),
			'subject'    => __( 'Subject:', 'estrategia-web' ),
			'copied'     => __( 'Code copied. Paste it into Mailchimp (code block or «Paste in code»).', 'estrategia-web' ),
			'copyFailed' => __( 'The browser did not let the code be copied: select it below and copy it by hand.', 'estrategia-web' ),
			'working'    => __( 'Creating the draft in Mailchimp…', 'estrategia-web' ),
			/* translators: 1: subject, 2: number of recipients. */
			'created'    => __( 'Draft created: «%1$s», for %2$s recipients. Nothing has been sent.', 'estrategia-web' ),
			'open'       => __( 'Open it in Mailchimp', 'estrategia-web' ),
			'rule'       => __( 'Before sending: send yourself the test from Mailchimp, OPEN it and read the headlines.', 'estrategia-web' ),
			/* translators: 1: subject of the campaign copied, 2: its date. */
			'model'      => __( 'Audience and sender copied from «%1$s» (%2$s).', 'estrategia-web' ),
			'error'      => __( 'It could not be done:', 'estrategia-web' ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Rest::CAP ) ) {
			return;
		}
		$has_key = '' !== Settings::get()['api_key'];
		echo '<div class="wrap ew-screen estrategia-web-newsletter">';
		Admin_UI::header( __( 'Newsletter', 'estrategia-web' ), __( 'Choose the posts, put them in order and check the email. Then copy its code or create the draft in Mailchimp: nothing is sent from here.', 'estrategia-web' ) );
		if ( ! $has_key && current_user_can( 'manage_options' ) ) {
			printf(
				'<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Without a Mailchimp key only the code can be copied.', 'estrategia-web' ),
				esc_url( Settings_Page::url( 'newsletter' ) ),
				esc_html__( 'Add it in the settings', 'estrategia-web' )
			);
		}
		echo '<div class="ew-newsletter">';
		echo '<div class="ew-newsletter__pick">';
		Admin_UI::box_open( __( 'Posts', 'estrategia-web' ), 'ew-box-newsletter-posts' );
		printf(
			'<form class="ew-newsletter__search" role="search"><label class="screen-reader-text" for="ew-newsletter-search">%1$s</label><input type="search" id="ew-newsletter-search" class="regular-text"> <button type="submit" class="button">%2$s</button></form>',
			esc_html__( 'Search posts', 'estrategia-web' ),
			esc_html__( 'Search', 'estrategia-web' )
		);
		echo '<div class="ew-newsletter__candidates" aria-live="polite"></div>';
		Admin_UI::box_close();
		Admin_UI::box_open( __( 'In the newsletter', 'estrategia-web' ), 'ew-box-newsletter-chosen' );
		echo '<ol class="ew-newsletter__chosen"></ol>';
		Admin_UI::box_close();
		echo '</div><div class="ew-newsletter__result">';
		Admin_UI::box_open( __( 'Preview', 'estrategia-web' ), 'ew-box-newsletter-preview' );
		echo '<div class="ew-newsletter__warnings"></div><p class="ew-newsletter__subject"></p>';
		printf( '<iframe class="ew-newsletter__frame" sandbox="" title="%s"></iframe>', esc_attr__( 'Preview of the email', 'estrategia-web' ) );
		printf( '<details class="ew-newsletter__code"><summary>%s</summary><textarea class="large-text code" rows="8" readonly></textarea></details>', esc_html__( 'HTML code', 'estrategia-web' ) );
		Admin_UI::box_close();
		echo '<div class="ew-actions">';
		printf( '<button type="button" class="button button-primary ew-newsletter__copy" disabled>%s</button> ', esc_html__( 'Copy the code', 'estrategia-web' ) );
		printf( '<button type="button" class="button ew-newsletter__draft" disabled%s>%s</button>', $has_key ? '' : ' data-no-key="1"', esc_html__( 'Create the draft in Mailchimp', 'estrategia-web' ) );
		echo '</div><div class="ew-newsletter__status" role="status"></div>';
		echo '</div></div></div>';
	}
}
