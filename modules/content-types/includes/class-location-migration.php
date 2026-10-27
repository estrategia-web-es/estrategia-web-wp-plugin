<?php
/**
 * THE 1.8.0 DATA MIGRATION: a location's duplicated data → stored once
 * (class-location-data.php says why and what is derived). Per site, once,
 * IDEMPOTENT, with a dry run that says what it would do:
 *
 *     wp estrategia-web migrate-location-data --dry-run     what it would do (writes nothing)
 *     wp estrategia-web migrate-location-data               does it
 *     (without WP-CLI's command:  wp eval 'echo wp_json_encode( EstrategiaWeb\ContentTypes\Location_Migration::run( true ), 448 );')
 *
 * The same convention as the plugin's other migrations
 * (includes/class-prefix-migration.php): it does NOT run by itself on
 * update — it is run after READING the dry run. Until it runs, the site
 * looks exactly as before (Location_Fields shows the old texts while they
 * are there).
 *
 * WHAT IT DOES, per location (every post of the location type but
 * revisions, every language — each WPML translation is its own post):
 *   address   the old text → street (the lines above the postal code; the
 *             text's line breaks are what the page shows), postal code,
 *             locality and region when those are EMPTY (a part already
 *             filled in that says otherwise is kept and reported).
 *   hours     the old text → the «hours» field when it is EMPTY (parsed);
 *             filled already: kept as it is.
 *   map       the old link and embed → the search query («What to search
 *             on Google Maps») when it is EMPTY.
 *   static    the old path/URL → the media library ID when that is EMPTY
 *             (the URL of an image of the library); with an ID: the ID.
 * Then it removes the old meta, after copying it into the post's hidden
 * meta _estrategia_web_location_backup_1_8_0 (JSON, with the date).
 * And for the company (modules/site, Company details): the «schedule» text →
 * «hours», the same way; backup in the option BACKUP.
 * And the DEFINITIONS made from the plugin's templates (the site's stored
 * copies, class-templates.php): location gets the 1.8.0 fields (the old
 * ones out, «What to search on Google Maps» in, the street in two lines and
 * translatable, the groups of the meta box); client gets «editor» in its
 * supports; location, client and testimonial get «fields_box: main». A
 * field the site ADDED stays, at the end. The old definitions go into the
 * BACKUP option too.
 *
 * THE REPORT (JSON), per location: before → after of every value it writes
 * («changes»), and DIFFERENCES: where the text the page will now derive is
 * NOT the text it showed until now (the address, the hours, the link, the
 * embed, the static map's URL). That is what to read before running it for
 * real: a difference means the page will change. «not_parsed»: what it
 * could not read — that old meta is KEPT (and still shown) until the new
 * field is filled in by hand; the next run then removes it.
 *
 * How to maintain:
 * - Who and when: whoever retires another duplicated datum adds its step
 *   here and its case in tests/location-migration.test.mjs. It is removed
 *   in 2.0.0 with Schema::RETIRED_FIELDS and Location_Fields::LEGACY, once
 *   every site has run it (27-10-2026: none yet; Lavandería is the first).
 * - Boundary case, with a name: Lavandería's Son Oms. Its text says «Calle
 *   Camp Franc, 11 Nave 14 / Polígono Son Oms» and its street for Google
 *   said «Calle Camp Franc, 11, Nave 14, Polígono Son Oms». The TEXT wins
 *   (it is what the page showed, line breaks included) and the report lists
 *   the street as a change; Google gets «Calle Camp Franc, 11 Nave 14,
 *   Polígono Son Oms» from then on.
 * - Another: an old text that cannot be read and a new field already filled
 *   in by hand → the old one is removed (backup), and listed as a
 *   DIFFERENCE. That is the way out of «not_parsed».
 * - THE TRAP: a WPML translation reads the ORIGINAL's «copy» fields when its
 *   own are empty (Location_Fields::meta()). The migration decides «empty»
 *   the same way, or it would parse the English text into hours the
 *   Spanish post already has.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Location_Migration {

	public const BACKUP_META = '_estrategia_web_location_backup_1_8_0';
	public const BACKUP      = 'estrategia_web_location_migration_backup';
	public const DONE        = 'estrategia_web_location_data_migrated';

	/** Template key => what 1.8.0 changes in the site's copy besides the fields. */
	private const DEFINITION_KEYS = array( 'location', 'client', 'testimonial' );

	/** Fields 1.8.0 CHANGED (not only grouped): the template's version replaces the site's. */
	private const REPLACED_FIELDS = array( Location_Fields::STREET, Location_Fields::STATIC_ID );

	/**
	 * Fields where a location's data is STORED from 1.8.0: added to a site's
	 * copy even if it never took them (a 1.4 copy without the parts). Every
	 * other template field the site lacks was removed on purpose and stays
	 * out (class-templates.php, «the copy is the site's»).
	 */
	private const STORAGE_FIELDS = array(
		'location' => array( Location_Fields::STREET, Location_Fields::POSTAL_CODE, Location_Fields::LOCALITY, Location_Fields::REGION, Location_Fields::COUNTRY, Location_Fields::HOURS, Location_Fields::QUERY, Location_Fields::STATIC_ID ),
	);

	private static bool $dry = true;

	public static function boot(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/** On the locations screens and Content types, for an administrator, while there is old data. */
	public static function notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_options' ) || ( Location_Fields::type() !== $screen->post_type && ! str_contains( (string) $screen->id, 'estrategia-web-content-types' ) ) || ! self::pending() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p><p><code>wp estrategia-web migrate-location-data --dry-run</code></p></div>',
			esc_html__( 'Estrategia Web 1.8.0: some locations still keep their data twice (address and hours as text, the map as two links). Until the data migration runs they look as before. Read its dry run, check its DIFFERENCES, then run it without --dry-run.', 'estrategia-web' )
		);
	}

	/** @return array The report (see the header). */
	public static function run( bool $dry_run ): array {
		self::$dry = $dry_run;
		$report    = array(
			'dry_run'     => $dry_run,
			'locations'   => array(),
			'company'     => array(),
			'definitions' => array(),
			'summary'     => array( 'locations' => 0, 'changed' => 0, 'differences' => 0, 'not_parsed' => 0, 'definitions' => 0 ),
		);
		$type = Location_Fields::type();
		$ids  = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$entry = self::location( get_post( (int) $id ) );
			++$report['summary']['locations'];
			if ( $entry['changes'] || $entry['removed'] ) {
				++$report['summary']['changed'];
			}
			$report['summary']['differences'] += count( $entry['differences'] );
			$report['summary']['not_parsed']  += count( $entry['not_parsed'] );
			$report['locations'][]             = $entry;
		}
		$report['company']                = self::company();
		$report['summary']['differences'] += count( $report['company']['differences'] ?? array() );
		$report['summary']['not_parsed']  += count( $report['company']['not_parsed'] ?? array() );
		$report['definitions']            = self::definitions();
		$report['summary']['definitions'] = count( $report['definitions'] );
		if ( ! $dry_run ) {
			update_option( self::DONE, array( 'version' => ESTRATEGIA_WEB_VERSION, 'date' => gmdate( 'c' ), 'summary' => $report['summary'] ), false );
			flush_rewrite_rules( false );
		}
		return $report;
	}

	/** Anything left to migrate? (the admin notice; cheap: one query). */
	public static function pending(): bool {
		global $wpdb;
		$keys = "'" . implode( "','", array_map( 'esc_sql', Location_Fields::LEGACY ) ) . "'";
		$meta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ($keys) AND meta_value <> ''" ); // phpcs:ignore WordPress.DB
		$site = get_option( 'estrategia_web_site', array() );
		return $meta > 0 || ( is_array( $site ) && '' !== trim( (string) ( $site['schedule'] ?? '' ) ) );
	}

	/** One location. */
	private static function location( \WP_Post $post ): array {
		$lang  = Location_Fields::lang( $post );
		$fmt   = Location_Fields::formats( $lang );
		$entry = array(
			'id'          => $post->ID,
			'slug'        => $post->post_name,
			'title'       => $post->post_title,
			'status'      => $post->post_status,
			'lang'        => $lang,
			'changes'     => array(),
			'differences' => array(),
			'not_parsed'  => array(),
			'removed'     => array(),
		);
		$own    = static fn( string $key ): string => is_scalar( $v = get_post_meta( $post->ID, $key, true ) ) ? trim( (string) $v ) : '';
		$set    = static function ( string $key, string $before, string $after ) use ( $post, &$entry ): void {
			if ( $before === $after ) {
				return;
			}
			$entry['changes'][] = array( 'field' => $key, 'before' => $before, 'after' => $after );
			if ( ! self::$dry ) {
				self::write( $post, $key, $after );
			}
		};
		$remove = array();

		// Address.
		$old = $own( Location_Fields::LEGACY['address'] );
		if ( '' !== $old ) {
			$parts  = Location_Fields::parts( $post );
			$parsed = Location_Data::parse_address( $old );
			if ( $parsed ) {
				$new = $parts;
				// The street: the text's lines (what the page showed). The rest: only where empty.
				$new['street'] = $parsed['street'];
				foreach ( array( 'postal_code', 'locality', 'region', 'country' ) as $k ) {
					if ( '' === $parts[ $k ] ) {
						$new[ $k ] = $parsed[ $k ];
					} elseif ( '' !== $parsed[ $k ] && $parsed[ $k ] !== $parts[ $k ] ) {
						$entry['differences'][] = array( 'what' => $k, 'before' => $parsed[ $k ], 'after' => $parts[ $k ], 'note' => 'the address text said «' . $parsed[ $k ] . '»; the stored part is kept' );
					}
				}
				$keys = array( 'street' => Location_Fields::STREET, 'postal_code' => Location_Fields::POSTAL_CODE, 'locality' => Location_Fields::LOCALITY, 'region' => Location_Fields::REGION, 'country' => Location_Fields::COUNTRY );
				foreach ( $keys as $k => $meta ) {
					// A part this post does not have and that comes, unchanged, from its WPML original
					// stays unwritten: it keeps being read from there (see «THE TRAP»).
					$after = ( '' === $own( $meta ) && $new[ $k ] === $parts[ $k ] ) ? '' : $new[ $k ];
					$set( $meta, $own( $meta ), $after );
				}
				$derived = Location_Data::address_text( $new, $fmt );
				$remove[] = 'address';
			} elseif ( '' !== $parts['street'] && ( '' !== $parts['locality'] || '' !== $parts['postal_code'] ) ) {
				$derived  = Location_Data::address_text( $parts, $fmt );
				$remove[] = 'address';
			} else {
				$entry['not_parsed'][] = 'address: the text has no «postal code + town» line, kept as it is; fill in the address parts by hand and run again: «' . $old . '»';
				$derived               = null;
			}
			if ( null !== $derived && $derived !== $old ) {
				$entry['differences'][] = array( 'what' => 'address text', 'before' => $old, 'after' => $derived );
			}
		}

		// Hours.
		$old = $own( Location_Fields::LEGACY['schedule'] );
		if ( '' !== $old ) {
			$hours = Location_Fields::meta( $post, Location_Fields::HOURS );
			if ( '' === Hours::sanitize( $hours ) ) {
				$parsed = Location_Data::parse_hours( $old );
				if ( $parsed ) {
					$hours = Hours::sanitize( $parsed );
					$set( Location_Fields::HOURS, $own( Location_Fields::HOURS ), $hours );
				} else {
					$hours                 = '';
					$entry['not_parsed'][] = 'hours: the text could not be read day by day, kept as it is; fill in the opening hours by hand and run again: «' . $old . '»';
				}
			}
			if ( '' !== $hours ) {
				$derived = Location_Data::hours_text( $hours, $fmt );
				if ( $derived !== $old ) {
					$entry['differences'][] = array( 'what' => 'hours text', 'before' => $old, 'after' => $derived );
				}
				$remove[] = 'schedule';
			}
		}

		// Map.
		$url   = $own( Location_Fields::LEGACY['map_url'] );
		$embed = $own( Location_Fields::LEGACY['map_embed'] );
		if ( '' !== $url || '' !== $embed ) {
			$query = Location_Fields::meta( $post, Location_Fields::QUERY );
			if ( '' === $query ) {
				$q1 = Location_Data::query_from_maps_url( $url );
				$q2 = Location_Data::query_from_embed( $embed );
				if ( null !== $q1 && null !== $q2 && $q1 !== $q2 ) {
					$entry['differences'][] = array( 'what' => 'map query', 'before' => $q2, 'after' => $q1, 'note' => 'the link and the embed searched different things; the link’s is kept' );
				}
				$query = (string) ( $q1 ?? $q2 ?? '' );
				if ( '' !== $query ) {
					$set( Location_Fields::QUERY, $own( Location_Fields::QUERY ), $query );
				}
			}
			if ( '' === $query ) {
				$entry['not_parsed'][] = 'map: neither the link nor the embed has a search in it (a short link or a place embed), kept as they are; write «What to search on Google Maps» by hand and run again';
			} else {
				foreach ( array( 'map_url' => array( $url, Location_Data::maps_url( $query ), 'map link' ), 'map_embed' => array( $embed, Location_Data::embed_url( $query ), 'map embed' ) ) as $which => list( $before, $after, $what ) ) {
					if ( '' !== $before && $before !== $after ) {
						$entry['differences'][] = array( 'what' => $what, 'before' => $before, 'after' => $after );
					}
				}
				foreach ( array( 'map_url' => $url, 'map_embed' => $embed ) as $which => $value ) {
					if ( '' !== $value ) {
						$remove[] = $which;
					}
				}
			}
		}

		// Static map.
		$path = $own( Location_Fields::LEGACY['static_map'] );
		if ( '' !== $path ) {
			$id = (int) Location_Fields::meta( $post, Location_Fields::STATIC_ID );
			if ( $id <= 0 ) {
				$found = (int) attachment_url_to_postid( Location_Fields::local_image_url( $path ) );
				if ( $found > 0 ) {
					$set( Location_Fields::STATIC_ID, $own( Location_Fields::STATIC_ID ), (string) $found );
					$id = $found;
				}
			}
			if ( $id > 0 ) {
				$after = (string) wp_get_attachment_image_url( $id, 'full' );
				// By PATH: the same image on a copy of the site under another host (a test copy, staging) is not a difference.
				$same = static fn( string $u ): string => (string) wp_parse_url( $u, PHP_URL_PATH );
				if ( $same( $after ) !== $same( Location_Fields::local_image_url( $path ) ) ) {
					$entry['differences'][] = array( 'what' => 'static map', 'before' => $path, 'after' => $after );
				}
				$remove[] = 'static_map';
			} else {
				$entry['not_parsed'][] = 'static map: «' . $path . '» is not an image of the media library (a theme file?), kept as it is; upload it and choose it in «Static map image», then run again';
			}
		}

		// Backup, then out with the old meta.
		if ( $remove ) {
			$backup = array( 'date' => gmdate( 'c' ) );
			foreach ( $remove as $which ) {
				$backup[ $which ]   = get_post_meta( $post->ID, Location_Fields::LEGACY[ $which ], true );
				$entry['removed'][] = Location_Fields::LEGACY[ $which ];
			}
			if ( ! self::$dry ) {
				$previous = json_decode( (string) get_post_meta( $post->ID, self::BACKUP_META, true ), true );
				// Merged with a previous run's backup: a value kept then (not parsed) and removed now is added, nothing is lost.
				$json = (string) wp_json_encode( array_merge( is_array( $previous ) ? $previous : array(), $backup ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				update_post_meta( $post->ID, self::BACKUP_META, wp_slash( $json ) );
				foreach ( $remove as $which ) {
					delete_post_meta( $post->ID, Location_Fields::LEGACY[ $which ] );
				}
			}
		}
		return $entry;
	}

	/** The company's «schedule» text → «hours» (modules/site). */
	private static function company(): array {
		$option = get_option( 'estrategia_web_site', array() );
		$old    = is_array( $option ) ? trim( (string) ( $option['schedule'] ?? '' ) ) : '';
		$out    = array( 'changes' => array(), 'differences' => array(), 'not_parsed' => array() );
		if ( '' === $old ) {
			return $out;
		}
		$hours = Hours::sanitize( $option['hours'] ?? '' );
		if ( '' === $hours ) {
			$parsed = Location_Data::parse_hours( $old );
			if ( ! $parsed ) {
				$out['not_parsed'][] = 'company hours: the text could not be read day by day, kept as it is; fill in «Opening hours» in Company details by hand and run again: «' . $old . '»';
				return $out;
			}
			$hours            = Hours::sanitize( $parsed );
			$out['changes'][] = array( 'field' => 'estrategia_web_site[hours]', 'before' => '', 'after' => $hours );
		}
		$derived = Location_Data::hours_text( $hours, Location_Fields::formats( Location_Fields::lang() ) );
		if ( $derived !== $old ) {
			$out['differences'][] = array( 'what' => 'company hours text', 'before' => $old, 'after' => $derived );
		}
		$out['changes'][] = array( 'field' => 'estrategia_web_site[schedule]', 'before' => $old, 'after' => '' );
		if ( ! self::$dry ) {
			self::backup( 'schedule', $old );
			update_option( 'estrategia_web_site', array( 'schedule' => '', 'hours' => $hours ) ); // Settings::sanitize() merges with the rest.
		}
		return $out;
	}

	/** The stored definitions made from the location, client and testimonial templates. */
	private static function definitions(): array {
		$out = array();
		foreach ( self::DEFINITION_KEYS as $key ) {
			$template = Templates::get( $key );
			$row      = $template ? Store::get( (string) $template['name'] ) : null;
			if ( ! $row || ! is_array( $row['definition'] ) || ( $row['definition']['template'] ?? null ) !== $key ) {
				continue;
			}
			$before = $row['definition'];
			$after  = self::upgrade( $before, $template );
			if ( $after === $before ) {
				continue;
			}
			$errors = Schema::validate( $after, $template );
			$entry  = array(
				'type'    => $template['name'],
				'changes' => self::definition_diff( $before, $after ),
			);
			if ( $errors ) {
				$entry['not_saved'] = $errors;
			} elseif ( ! self::$dry ) {
				self::backup( 'definition_' . $template['name'], $row['json'] );
				Store::save( $after, $row['active'], $row['id'] );
			}
			$out[] = $entry;
		}
		return $out;
	}

	/** A stored definition with the 1.8.0 template's changes (see the header). */
	private static function upgrade( array $def, array $template ): array {
		$by_name = array();
		foreach ( (array) ( $template['fields'] ?? array() ) as $f ) {
			$by_name[ $f['name'] ] = $f;
		}
		$retired = Schema::RETIRED_FIELDS[ $template['template'] ] ?? array();
		$mine    = array();
		$extra   = array();
		foreach ( (array) ( $def['fields'] ?? array() ) as $f ) {
			$name = (string) ( $f['name'] ?? '' );
			if ( in_array( $name, $retired, true ) ) {
				continue;
			}
			if ( isset( $by_name[ $name ] ) ) {
				$mine[ $name ] = $f;
			} else {
				$extra[] = $f;
			}
		}
		$fields = array();
		foreach ( $by_name as $name => $f ) {
			if ( isset( $mine[ $name ] ) && ! in_array( $name, self::REPLACED_FIELDS, true ) ) {
				$field = $mine[ $name ];
				if ( isset( $f['group'] ) ) {
					$field['group'] = $f['group'];
				}
				$fields[] = $field;
			} elseif ( isset( $mine[ $name ] ) || in_array( $name, self::STORAGE_FIELDS[ $template['template'] ] ?? array(), true ) ) {
				$fields[] = $f; // Changed in 1.8.0, or where the data now LIVES (the map query, the parts).
			}
			// Any other template field the site does not have: it removed it on purpose; not brought back.
		}
		$def['fields'] = array_merge( $fields, $extra );
		foreach ( array( 'fields_box', 'groups' ) as $k ) {
			if ( isset( $template[ $k ] ) && ! isset( $def[ $k ] ) ) {
				$def[ $k ] = $template[ $k ];
			}
		}
		if ( in_array( 'editor', (array) ( $template['supports'] ?? array() ), true ) && isset( $def['supports'] ) && ! in_array( 'editor', $def['supports'], true ) ) {
			array_splice( $def['supports'], 1, 0, array( 'editor' ) );
		}
		return $def;
	}

	/** @return array<int,array{key:string,before:mixed,after:mixed}> */
	private static function definition_diff( array $before, array $after ): array {
		$out = array();
		foreach ( array( 'supports', 'fields_box', 'groups' ) as $k ) {
			if ( ( $before[ $k ] ?? null ) !== ( $after[ $k ] ?? null ) ) {
				$out[] = array( 'key' => $k, 'before' => $before[ $k ] ?? null, 'after' => $after[ $k ] ?? null );
			}
		}
		$names = static fn( array $d ) => array_map( static fn( $f ) => ( $f['name'] ?? '?' ) . ( isset( $f['group'] ) ? ' [' . $f['group'] . ']' : '' ) . ' (' . ( $f['type'] ?? '?' ) . ')', (array) ( $d['fields'] ?? array() ) );
		if ( $names( $before ) !== $names( $after ) || ( $before['fields'] ?? null ) !== ( $after['fields'] ?? null ) ) {
			$out[] = array( 'key' => 'fields', 'before' => $names( $before ), 'after' => $names( $after ) );
		}
		return $out;
	}

	/**
	 * A meta value WITHOUT the sanitizer this request registered. THE TRAP
	 * (seen in the level 2 test's first run): the request that migrates
	 * registered the location's meta from the OLD definition, where the street
	 * is a one-line «text» — sanitize_text_field() turned «Centro Comercial
	 * Maioris\nCalle Imperial…» into one line. The values here are already
	 * clean (they come from the parsers, the Hours sanitizer, or the stored
	 * meta); the street gets sanitize_textarea_field(), as 1.8.0 registers it.
	 */
	private static function write( \WP_Post $post, string $key, string $value ): void {
		if ( Location_Fields::STREET === $key ) {
			$value = sanitize_textarea_field( $value );
		}
		$filters = array( "sanitize_post_meta_{$key}_for_{$post->post_type}", "sanitize_post_meta_{$key}" ); // What register_post_meta() hooks (sanitize_meta()).
		$saved   = array();
		foreach ( $filters as $hook ) {
			if ( isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
				$saved[ $hook ] = $GLOBALS['wp_filter'][ $hook ];
				unset( $GLOBALS['wp_filter'][ $hook ] );
			}
		}
		try {
			update_post_meta( $post->ID, $key, wp_slash( $value ) );
		} finally {
			foreach ( $saved as $hook => $callbacks ) {
				$GLOBALS['wp_filter'][ $hook ] = $callbacks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		}
	}

	private static function backup( string $key, $value ): void {
		$all         = get_option( self::BACKUP, array() );
		$all         = is_array( $all ) ? $all : array();
		$all[ $key ] = array( 'date' => gmdate( 'c' ), 'value' => $value );
		update_option( self::BACKUP, $all, false );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'estrategia-web migrate-location-data',
		static function ( $args, $assoc ) {
			$report = Location_Migration::run( ! empty( $assoc['dry-run'] ) );
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		},
		array( 'shortdesc' => '1.8.0: a location\'s duplicated data → stored once (address, hours, map, static map; the company\'s hours; the template definitions). --dry-run says what it would do.' )
	);
}
