<?php
/**
 * WP-CLI: bringing a site's AdRotate ads over.
 *
 *     wp estrategia-web banners migrate-adrotate --zones=1:ample,2:lateral,3:receptes [--dry-run]
 *
 * --zones maps each AdRotate group id to a zone slug (the term is created if
 * it does not exist). A group left out of --zones is not migrated as a zone;
 * an ad in no mapped group becomes a banner WITHOUT a zone (it keeps its
 * history, it shows nowhere).
 *
 * What it carries per ad: title, the code or the image + link
 * (Rules::from_adrotate, whose header has the criterion), dates (the earliest
 * start and the latest end of its schedules), weight, on/off («disabled» →
 * draft; expired ones are published with their past end date, so they read
 * «Expired»), its creation date, its zones and ALL its daily statistics
 * (wp_adrotate_stats and _stats_archive).
 *
 * IDEMPOTENT: an ad already migrated (meta estrategia_web_banner_adrotate) is
 * updated, not duplicated, and its statistics are replaced whole. So it is
 * run on the copy to look at it and again on the day of the move, over
 * production's data.
 *
 * Not migrated: wp_adrotate_tracker (AdRotate's throttle against counting a
 * visitor twice, not statistics), the groups' display settings, geotargeting
 * and the device flags (Gastronòmicament uses none of them).
 *
 * The trap that bit: an image whose URL is not in the media library (moved,
 * or on another domain, like AdRotate's demo ads) cannot be a featured
 * image: that ad stays as code, with its <img> pointing where it pointed. The
 * output says which ones.
 */

namespace EstrategiaWeb\Banners;

defined( 'ABSPATH' ) || exit;

final class Cli {

	/**
	 * Migrates AdRotate's ads, groups and statistics into banners.
	 *
	 * ## OPTIONS
	 *
	 * --zones=<map>
	 * : AdRotate group id → zone slug, comma separated: 1:ample,2:lateral
	 *
	 * [--dry-run]
	 * : Say what would be done, change nothing.
	 *
	 * @subcommand migrate-adrotate
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function migrate_adrotate( array $args, array $assoc ): void {
		global $wpdb;
		$dry = isset( $assoc['dry-run'] );
		$p   = $wpdb->prefix;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'adrotate' ) ) ) { // phpcs:ignore WordPress.DB
			\WP_CLI::error( 'There are no AdRotate tables on this site.' );
		}

		$zones = array();
		foreach ( array_filter( explode( ',', (string) ( $assoc['zones'] ?? '' ) ) ) as $pair ) {
			list( $group, $slug ) = array_map( 'trim', explode( ':', $pair, 2 ) + array( '', '' ) );
			if ( (int) $group && '' !== sanitize_title( $slug ) ) {
				$zones[ (int) $group ] = sanitize_title( $slug );
			}
		}
		if ( ! $zones ) {
			\WP_CLI::error( 'Say which zone each group goes to: --zones=1:ample,2:lateral' );
		}
		$term_ids = array();
		foreach ( array_unique( $zones ) as $slug ) {
			$term = get_term_by( 'slug', $slug, Post_Type::ZONE );
			if ( ! $term && ! $dry ) {
				$made = wp_insert_term( ucfirst( str_replace( '-', ' ', $slug ) ), Post_Type::ZONE, array( 'slug' => $slug ) );
				$term = is_wp_error( $made ) ? null : get_term( $made['term_id'], Post_Type::ZONE );
			}
			$term_ids[ $slug ] = $term ? (int) $term->term_id : 0;
		}

		$ads   = $wpdb->get_results( "SELECT id, title, bannercode, thetime, image, type, weight FROM {$p}adrotate ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB
		$links = $wpdb->get_results( "SELECT ad, `group`, schedule FROM {$p}adrotate_linkmeta", ARRAY_A ); // phpcs:ignore WordPress.DB
		$sched = $wpdb->get_results( "SELECT id, starttime, stoptime FROM {$p}adrotate_schedule", OBJECT_K ); // phpcs:ignore WordPress.DB
		$stats = $wpdb->get_results( "SELECT ad, thetime, SUM(impressions) AS views, SUM(clicks) AS clicks FROM (SELECT ad, thetime, impressions, clicks FROM {$p}adrotate_stats UNION ALL SELECT ad, thetime, impressions, clicks FROM {$p}adrotate_stats_archive) s GROUP BY ad, thetime", ARRAY_A ); // phpcs:ignore WordPress.DB

		$groups_of = array();
		$times_of  = array();
		foreach ( $links as $l ) {
			if ( (int) $l['group'] > 0 ) {
				$groups_of[ (int) $l['ad'] ][] = (int) $l['group'];
			}
			if ( (int) $l['schedule'] > 0 && isset( $sched[ $l['schedule'] ] ) ) {
				$times_of[ (int) $l['ad'] ][] = $sched[ $l['schedule'] ];
			}
		}
		$stats_of = array();
		foreach ( $stats as $s ) {
			$stats_of[ (int) $s['ad'] ][] = $s;
		}

		$made = 0;
		$updated = 0;
		foreach ( $ads as $ad ) {
			$ad_id = (int) $ad['id'];
			$what  = Rules::from_adrotate( (string) $ad['bannercode'], (string) $ad['image'] );
			$image = 'image' === $what['kind'] ? self::attachment( $what['image'] ) : 0;
			$note  = '';
			if ( 'image' === $what['kind'] && ! $image ) {
				$note = ' (image not in the media library: kept as code)';
			}
			$start = 0;
			$end   = 0;
			foreach ( $times_of[ $ad_id ] ?? array() as $t ) {
				$start = $start ? min( $start, (int) $t->starttime ) : (int) $t->starttime;
				$end   = max( $end, (int) $t->stoptime );
			}
			$slugs = array_values( array_unique( array_filter( array_map( static fn( int $g ) => $zones[ $g ] ?? '', $groups_of[ $ad_id ] ?? array() ) ) ) );
			$days  = $stats_of[ $ad_id ] ?? array();
			$title = stripslashes( html_entity_decode( (string) $ad['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			$existing = get_posts( array( 'post_type' => Post_Type::TYPE, 'post_status' => 'any', 'meta_key' => Post_Type::FIELDS['adrotate'], 'meta_value' => $ad_id, 'fields' => 'ids', 'posts_per_page' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			\WP_CLI::log( sprintf( '%s AdRotate %d «%s» → %s, %s, zones: %s, %d days of statistics%s', $existing ? 'update' : 'create', $ad_id, $title, $image ? 'image' : 'code', 'disabled' === $ad['type'] ? 'off' : 'on', $slugs ? implode( ', ', $slugs ) : '—', count( $days ), $note ) );
			if ( $dry ) {
				continue;
			}

			$post = array(
				'post_type'   => Post_Type::TYPE,
				'post_title'  => $title,
				'post_status' => 'disabled' === $ad['type'] ? 'draft' : 'publish',
				'post_date'   => wp_date( 'Y-m-d H:i:s', (int) $ad['thetime'] ),
			);
			if ( $existing ) {
				$post['ID'] = (int) $existing[0];
				$post_id    = wp_update_post( $post, true );
				++$updated;
			} else {
				$post_id = wp_insert_post( $post, true );
				++$made;
			}
			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::warning( 'AdRotate ' . $ad_id . ': ' . $post_id->get_error_message() );
				continue;
			}
			update_post_meta( $post_id, Post_Type::FIELDS['adrotate'], $ad_id );
			update_post_meta( $post_id, Post_Type::FIELDS['link'], $image ? esc_url_raw( $what['link'] ) : '' );
			update_post_meta( $post_id, Post_Type::FIELDS['code'], $image ? '' : $what['code'] );
			update_post_meta( $post_id, Post_Type::FIELDS['start'], $start );
			update_post_meta( $post_id, Post_Type::FIELDS['end'], $end );
			update_post_meta( $post_id, Post_Type::FIELDS['weight'], min( 10, max( 1, (int) $ad['weight'] ) ) );
			if ( $image ) {
				set_post_thumbnail( $post_id, $image );
			} else {
				delete_post_thumbnail( $post_id );
			}
			wp_set_object_terms( $post_id, array_values( array_filter( array_map( static fn( string $s ) => $term_ids[ $s ] ?? 0, $slugs ) ) ), Post_Type::ZONE );
			Store::forget( (int) $post_id );
			foreach ( $days as $s ) {
				Store::add( (int) $post_id, (int) $s['views'], (int) $s['clicks'], wp_date( 'Y-m-d', (int) $s['thetime'] ) );
			}
		}
		\WP_CLI::success( $dry ? sprintf( 'Dry run: %d ads read, nothing changed.', count( $ads ) ) : sprintf( '%d banners created, %d updated.', $made, $updated ) );
	}

	/** Attachment id of an uploads URL, also when the URL is a resized copy (-300x600). */
	private static function attachment( string $url ): int {
		$id = attachment_url_to_postid( $url );
		if ( ! $id ) {
			$id = attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url ) );
		}
		return (int) $id;
	}
}
