<?php
/**
 * RECOMMENDED: locations without coordinates, without a Google Business
 * profile or without a page of their own. Lists which ones.
 * Only registered when the site has the location type (content-types).
 *
 * Criterion, per published location (the «location» type, eweb_location):
 * - coordinates (estrategia_web_location_latitude / _longitude, non-zero):
 *   without them the LocalBusiness schema has no geo and the map guesses
 *   from the address;
 * - Google Business profile (estrategia_web_location_google_profile, 2.18.0):
 *   it becomes the location's sameAs and hasMap, the link Google uses to tie
 *   the site to its Maps listing;
 * - a page: a type with public pages has its own; one WITHOUT them (Llar
 *   d'Estels, «public»: false) needs a published page tied to it (a block
 *   bound to its slug), or Site\Location_Pages::url() falls back to the
 *   home page and every «see this location» button goes there.
 * The first two are fields of the local-business module: they are only
 * asked for while that module is on (with it off nobody reads them, and the
 * fields are not even in the editor).
 *
 * Edge case, by name: a location tied only through a SYNCED PATTERN reads
 * as «no page» (Location_Pages::url() reads the page's own content; see the
 * header of modules/site/includes/class-location-pages.php). Bind the page
 * itself, or ignore this line for that location.
 *
 * Real case: the Llar d'Estels audit (25-26/11/2026): its locations (the
 * «sedes») are a type without public pages, which is what 2.18.0's tied
 * page and Google profile were made for.
 *
 * The trap: with WPML, get_posts() returns the locations of the admin's
 * current language; with «All languages» each translation is listed too.
 * At most 50 locations are read.
 */

defined( 'ABSPATH' ) || exit;

use EstrategiaWeb\SiteHealth\Probe;

return array(
	'id'      => 'locations',
	'async'   => false,
	'title'   => static fn() => __( 'Locations are complete', 'estrategiaweb-toolkit' ),
	'applies' => static fn() => post_type_exists( class_exists( \EstrategiaWeb\Site\Type_Names::class ) ? \EstrategiaWeb\Site\Type_Names::name( 'location' ) : 'eweb_location' ),
	'gather'  => static function (): array {
		$type  = class_exists( \EstrategiaWeb\Site\Type_Names::class ) ? \EstrategiaWeb\Site\Type_Names::name( 'location' ) : 'eweb_location';
		$posts = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'numberposts'      => 50,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		$home  = (string) apply_filters( 'wpml_home_url', home_url( '/' ) );
		$out   = array();
		foreach ( $posts as $post ) {
			$page  = class_exists( \EstrategiaWeb\Site\Location_Pages::class ) ? \EstrategiaWeb\Site\Location_Pages::url( $post ) : '';
			$out[] = array(
				'title'     => (string) get_the_title( $post ),
				'latitude'  => (string) get_post_meta( $post->ID, 'estrategia_web_location_latitude', true ),
				'longitude' => (string) get_post_meta( $post->ID, 'estrategia_web_location_longitude', true ),
				'google'    => (string) get_post_meta( $post->ID, 'estrategia_web_location_google_profile', true ),
				'page'      => '' !== $page && untrailingslashit( $page ) !== untrailingslashit( $home ),
			);
		}
		return array(
			'locations'      => $out,
			'local_business' => Probe::module_on( 'local-business' ),
			'type'           => $type,
		);
	},
	'judge'   => static function ( array $d ): array {
		$schema = ! empty( $d['local_business'] );
		$items  = array();
		foreach ( (array) ( $d['locations'] ?? array() ) as $l ) {
			$lack = array();
			$lat  = is_numeric( $l['latitude'] ?? null ) ? (float) $l['latitude'] : 0.0;
			$lon  = is_numeric( $l['longitude'] ?? null ) ? (float) $l['longitude'] : 0.0;
			if ( $schema && ( 0.0 === $lat || 0.0 === $lon ) ) {
				$lack[] = __( 'no coordinates', 'estrategiaweb-toolkit' );
			}
			if ( $schema && '' === trim( (string) ( $l['google'] ?? '' ) ) ) {
				$lack[] = __( 'no Google Business profile', 'estrategiaweb-toolkit' );
			}
			if ( empty( $l['page'] ) ) {
				$lack[] = __( 'no page of its own', 'estrategiaweb-toolkit' );
			}
			if ( $lack ) {
				/* translators: 1: a location's name, 2: what it lacks, comma-separated. */
				$items[] = sprintf( __( '%1$s: %2$s', 'estrategiaweb-toolkit' ), (string) ( $l['title'] ?? '' ), implode( ', ', $lack ) );
			}
		}
		if ( ! $items ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Locations are complete', 'estrategiaweb-toolkit' ),
				'description' => __( 'Every location has what search engines and the site\'s buttons need.', 'estrategiaweb-toolkit' ),
			);
		}
		return array(
			'status'      => 'recommended',
			'label'       => __( 'Some locations are incomplete', 'estrategiaweb-toolkit' ),
			'description' => __( 'Coordinates and the Google Business profile tie each location to its place on Google Maps; a location without a page sends its «see more» button to the home page.', 'estrategiaweb-toolkit' ),
			'items'       => $items,
			'actions'     => array(
				array(
					'path' => 'edit.php?post_type=' . rawurlencode( (string) ( $d['type'] ?? 'eweb_location' ) ),
					'text' => __( 'Locations', 'estrategiaweb-toolkit' ),
				),
			),
		);
	},
);
