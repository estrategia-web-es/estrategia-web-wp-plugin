<?php
/**
 * RECOMMENDED: on a site that is not in English, texts still at their
 * English factory value — Yoast SEO's title templates and breadcrumbs
 * (option wpseo_titles), the timezone (UTC) or the date format («F j, Y»).
 * Not registered on an English site (get_locale() en*).
 *
 * Criterion: Yoast writes its defaults ONCE, in the site's language at that
 * moment. A site installed in English and switched to Spanish afterwards
 * keeps «Page not found | Site» as the title of its 404 and «You searched
 * for» on its search results, in every language, forever. The same with
 * WordPress' own install defaults: UTC (so every scheduled post and every
 * form's date is one or two hours off in Spain) and «November 26, 2026».
 *
 * The values compared (exact, trimmed), the factory English of Yoast's
 * WPSEO_Option_Titles:
 *   title-404-wpseo          Page not found %%sep%% %%sitename%%
 *   title-search-wpseo       You searched for %%searchphrase%% %%page%% %%sep%% %%sitename%%
 *   title-author-wpseo       %%name%%, Author at %%sitename%% %%page%%
 *   breadcrumbs-404crumb     Error 404: Page not found
 *   breadcrumbs-searchprefix You searched for
 *   breadcrumbs-archiveprefix Archives for
 *   breadcrumbs-home         Home
 * A key that is not in the option (Yoast not installed, or another version)
 * is not reported: only a value that IS the English one.
 *
 * Edge case, by name: a Spanish site that chose «Home» on purpose for its
 * breadcrumb gets the line anyway; it is one line among the others, and the
 * description says it is a factory value, not a mistake by definition.
 * UTC is only reported when timezone_string is empty or «UTC» AND gmt_offset
 * is 0: «Europe/London» or «Atlantic/Canary» are real choices.
 *
 * Real case: the Llar d'Estels audit (25-26/11/2026): Yoast's title templates
 * still in English on a site that is not.
 *
 * The trap: with WPML these templates are translated through String
 * Translation, but the ORIGINAL (what this reads) is still the English
 * one, and that is what the site's default language shows.
 */

defined( 'ABSPATH' ) || exit;

use EstrategiaWeb\SiteHealth\Probe;

return array(
	'id'      => 'english-defaults',
	'async'   => false,
	'title'   => static fn() => __( 'No texts left in English', 'estrategiaweb-toolkit' ),
	'applies' => static fn() => ! Probe::english_site(),
	'gather'  => static fn() => array(
		'yoast'       => (array) get_option( 'wpseo_titles', array() ),
		'timezone'    => (string) get_option( 'timezone_string', '' ),
		'gmt_offset'  => (float) get_option( 'gmt_offset', 0 ),
		'date_format' => (string) get_option( 'date_format', '' ),
	),
	'judge'   => static function ( array $d ): array {
		$english = array(
			'title-404-wpseo'           => 'Page not found %%sep%% %%sitename%%',
			'title-search-wpseo'        => 'You searched for %%searchphrase%% %%page%% %%sep%% %%sitename%%',
			'title-author-wpseo'        => '%%name%%, Author at %%sitename%% %%page%%',
			'breadcrumbs-404crumb'      => 'Error 404: Page not found',
			'breadcrumbs-searchprefix'  => 'You searched for',
			'breadcrumbs-archiveprefix' => 'Archives for',
			'breadcrumbs-home'          => 'Home',
		);
		$yoast   = (array) ( $d['yoast'] ?? array() );
		$items   = array();
		foreach ( $english as $key => $value ) {
			if ( isset( $yoast[ $key ] ) && trim( (string) $yoast[ $key ] ) === $value ) {
				/* translators: 1: a Yoast SEO setting key, 2: its value. */
				$items[] = sprintf( __( 'Yoast SEO, %1$s: «%2$s»', 'estrategiaweb-toolkit' ), $key, $value );
			}
		}
		$yoast_bad = (bool) $items;
		$count     = count( $items );
		$tz        = (string) ( $d['timezone'] ?? '' );
		if ( ( '' === $tz || 'UTC' === $tz ) && 0.0 === (float) ( $d['gmt_offset'] ?? 0 ) ) {
			$items[] = __( 'Timezone: UTC (scheduled posts and dates are off by the local difference)', 'estrategiaweb-toolkit' );
		}
		if ( 'F j, Y' === trim( (string) ( $d['date_format'] ?? '' ) ) ) {
			$items[] = __( 'Date format: «F j, Y», the English one (November 26, 2026)', 'estrategiaweb-toolkit' );
		}
		if ( ! $items ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'No texts left in English', 'estrategiaweb-toolkit' ),
				'description' => __( 'The SEO title templates, the timezone and the date format are not the English factory values.', 'estrategiaweb-toolkit' ),
			);
		}
		$actions = array();
		if ( $yoast_bad ) {
			$actions[] = array(
				'path' => 'admin.php?page=wpseo_page_settings',
				'text' => __( 'Yoast SEO › Settings', 'estrategiaweb-toolkit' ),
			);
		}
		if ( count( $items ) > $count ) { // A WordPress setting, not only Yoast's.
			$actions[] = array(
				'path' => 'options-general.php',
				'text' => __( 'Settings › General', 'estrategiaweb-toolkit' ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'Some settings are still the English factory values', 'estrategiaweb-toolkit' ),
			'description' => __( 'These values were written when the site was installed in English and did not change with the site\'s language. Visitors and search engines see them as they are.', 'estrategiaweb-toolkit' ),
			'items'       => $items,
			'actions'     => $actions,
		);
	},
);
