<?php
/**
 * CRITICAL: a legal page is missing or not linked from any menu or footer.
 * RECOMMENDED: one was last modified more than 12 months ago.
 *
 * Criterion: a Spanish business site needs a legal notice (LSSI art. 10), a
 * privacy policy (GDPR art. 13) and a cookie policy (LSSI art. 22.2), and
 * a visitor has to be able to reach them from every page: a page that exists
 * but is not linked does not count. A text untouched for a year probably
 * predates the last change in what the site collects (a form, a map, an
 * analytics tag), so it is worth a look, not an alarm.
 *
 * How each page is found:
 * - privacy policy: Settings › Privacy (wp_page_for_privacy_policy), published;
 * - cookie policy: a published page with the estrategia-web/cookie-table
 *   block, else a published page whose slug contains «cookie»;
 * - legal notice: the filter estrategia_web_site_health_legal_notice (a page
 *   ID), else a published page whose slug is aviso-legal, avis-legal,
 *   nota-legal, informacion-legal, legal-notice, impressum or legal.
 * «Linked» = its ID («"id":N»), its path («/slug/») or a classic menu item
 * pointing at it appears in a navigation menu, a template part, a template
 * or a synced pattern in the database, or in the active theme's parts/,
 * templates/ and patterns/ files.
 *
 * Edge case, by name: a legal notice with any other slug («condiciones»,
 * «legal-y-privacidad») is not found and reads as missing; the site says
 * which page it is with the filter (one line in its functions.php). And a
 * link built in PHP by the theme (wp_footer, a shortcode) is not seen: same
 * fix, or it reads as «not linked».
 *
 * Real case: the Llar d'Estels audit (25-26/11/2026) — legal pages are the
 * first thing checked before a site goes live, and the easiest to lose in a
 * redesign (a new footer without the links).
 *
 * The trap: WPML keeps a page per language with its own ID and slug; any one
 * of them being linked counts (the footer of each language links its own).
 */

defined( 'ABSPATH' ) || exit;

use EstrategiaWeb\SiteHealth\Probe;

return array(
	'id'     => 'legal-pages',
	'async'  => false,
	'title'  => static fn() => __( 'Legal pages exist and are linked', 'estrategiaweb-toolkit' ),
	'gather' => static function (): array {
		global $wpdb;
		$row = static function ( $post ): ?array {
			$post = $post ? get_post( $post ) : null;
			if ( ! $post || 'publish' !== $post->post_status ) {
				return null;
			}
			return array(
				'id'       => (int) $post->ID,
				'title'    => (string) $post->post_title,
				'slug'     => (string) $post->post_name,
				'modified' => (int) strtotime( (string) $post->post_modified_gmt . ' UTC' ),
			);
		};
		// The slugs once, matched in PHP: REGEXP is not in every database WordPress runs on (SQLite).
		$slugs   = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one capped lookup on the Site Health screen.
			"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' ORDER BY ID ASC LIMIT 1000"
		);
		$by_slug = static function ( string $regexp ) use ( $slugs ): int {
			foreach ( $slugs as $s ) {
				if ( preg_match( '/' . $regexp . '/', (string) $s->post_name ) ) {
					return (int) $s->ID;
				}
			}
			return 0;
		};
		$tables  = array_filter( Probe::pages_with( '<!-- wp:estrategia-web/cookie-table' ), static fn( $p ) => 'page' === $p->post_type );
		$notice  = (int) apply_filters( 'estrategia_web_site_health_legal_notice', 0 );
		$pages   = array(
			'notice'  => $row( $notice ?: $by_slug( '^(aviso|avis|nota|informacion|informacio)-legal|^legal-notice|^impressum|^legal$' ) ),
			'privacy' => $row( (int) get_option( 'wp_page_for_privacy_policy' ) ),
			'cookies' => $row( $tables ? reset( $tables ) : $by_slug( 'cookie' ) ),
		);
		// Where links live: menus, template parts, templates and synced patterns in the database…
		$hay = implode(
			"\n",
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- capped, Site Health screen only.
				"SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('wp_navigation','wp_template_part','wp_template','wp_block') ORDER BY ID DESC LIMIT 200"
			)
		);
		// …and the theme's own files (a footer nobody customised lives only there).
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			foreach ( array_merge( glob( $dir . '/parts/*.html' ) ?: array(), glob( $dir . '/templates/*.html' ) ?: array(), glob( $dir . '/patterns/*.php' ) ?: array() ) as $file ) {
				$hay .= "\n" . (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local theme file.
			}
		}
		$menu_ids = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- classic menus' targets, capped.
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_object_id' LIMIT 500"
			)
		);
		return array(
			'pages'    => $pages,
			'haystack' => $hay,
			'menu_ids' => $menu_ids,
			'now'      => time(),
		);
	},
	'judge'  => static function ( array $d ): array {
		$names   = array(
			'notice'  => __( 'Legal notice', 'estrategiaweb-toolkit' ),
			'privacy' => __( 'Privacy policy', 'estrategiaweb-toolkit' ),
			'cookies' => __( 'Cookie policy', 'estrategiaweb-toolkit' ),
		);
		$hay     = (string) ( $d['haystack'] ?? '' );
		$menu    = array_map( 'intval', (array) ( $d['menu_ids'] ?? array() ) );
		$now     = (int) ( $d['now'] ?? time() );
		$missing = array();
		$hidden  = array();
		$old     = array();
		foreach ( $names as $key => $name ) {
			$page = $d['pages'][ $key ] ?? null;
			if ( ! $page ) {
				$missing[] = $name;
				continue;
			}
			$id     = (int) $page['id'];
			$slug   = preg_quote( (string) $page['slug'], '/' );
			$linked = in_array( $id, $menu, true )
				|| preg_match( '/"id":\s*' . $id . '(?!\d)/', $hay )
				|| ( '' !== $slug && preg_match( '/\/' . $slug . '(\/|"|\?|#)/', $hay ) );
			if ( ! $linked ) {
				/* translators: 1: kind of legal page (Legal notice…), 2: the page's title. */
				$hidden[] = sprintf( __( '%1$s («%2$s») is not linked from any menu or footer', 'estrategiaweb-toolkit' ), $name, $page['title'] );
			} elseif ( $now - (int) $page['modified'] > 365 * 86400 ) {
				/* translators: 1: kind of legal page (Legal notice…), 2: the page's title. */
				$old[] = sprintf( __( '%1$s («%2$s») was last changed more than 12 months ago', 'estrategiaweb-toolkit' ), $name, $page['title'] );
			}
		}
		$actions = array(
			array(
				'path' => 'edit.php?post_type=page',
				'text' => __( 'Pages', 'estrategiaweb-toolkit' ),
			),
			array(
				'path' => 'options-privacy.php',
				'text' => __( 'Settings › Privacy', 'estrategiaweb-toolkit' ),
			),
			array(
				'path' => 'site-editor.php?postType=wp_template_part',
				'text' => __( 'Footer and menus (Site Editor)', 'estrategiaweb-toolkit' ),
			),
		);
		if ( $missing || $hidden ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'A legal page is missing or cannot be reached', 'estrategiaweb-toolkit' ),
				'description' => __( 'A business site needs a legal notice, a privacy policy and a cookie policy, linked from every page (usually the footer). A page that exists but is not linked does not count.', 'estrategiaweb-toolkit' ),
				'items'       => array_merge(
					/* translators: %s: kind of legal page (Legal notice…). */
					array_map( static fn( $n ) => sprintf( __( '%s: no published page found', 'estrategiaweb-toolkit' ), $n ), $missing ),
					$hidden
				),
				'actions'     => $actions,
			);
		}
		if ( $old ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'A legal page has not been reviewed for a year', 'estrategiaweb-toolkit' ),
				'description' => __( 'Legal texts should describe what the site does today. If a form, a map or an analytics tag was added since, the text may be out of date.', 'estrategiaweb-toolkit' ),
				'items'       => $old,
				'actions'     => array_slice( $actions, 0, 1 ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Legal pages exist and are linked', 'estrategiaweb-toolkit' ),
			'description' => __( 'The legal notice, the privacy policy and the cookie policy are published, linked and reviewed in the last 12 months.', 'estrategiaweb-toolkit' ),
		);
	},
);
