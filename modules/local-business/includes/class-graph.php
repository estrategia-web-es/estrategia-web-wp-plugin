<?php
/**
 * THE GRAPH, pure: plain arrays in (the company, its locations — gathered
 * from WordPress by class-data.php), schema.org nodes out, and the merge into
 * an SEO plugin's graph. No WordPress here, so tests/graph.test.mjs (level 1)
 * checks every rule with the machine's PHP.
 *
 * WHAT EACH PAGE GETS:
 *   front page        the Organization: name, url, logo, telephone, email,
 *                     sameAs (the social module's links), and each location
 *                     as a «department» (a whole LocalBusiness node, so Google
 *                     can read it there too).
 *   a location        its LocalBusiness: name, address (PostalAddress by
 *   or a page tied    parts), telephone, email, openingHoursSpecification,
 *   to one            geo, url (THIS page), image, parentOrganization → the
 *                     Organization's @id; and the Organization itself when no
 *                     SEO plugin already has one to point to.
 *
 * WHEN A LOCATION IS LEFT OUT (no node at all, rather than half a one):
 * without a name, or without an address Google can use — a street AND a
 * town or a postal code. A location whose new fields were never filled in
 * (a site that has just got this module) publishes nothing for it: what is
 * missing is listed nowhere but the Rich Results test, on purpose (the
 * fields' help says what they are for).
 *
 * @id, the decision: the Organization is <home>/#organization, the same one
 * Yoast and Rank Math use (when an SEO plugin is there, ITS id is taken —
 * merge()). A location is <its permalink>#localbusiness: the SAME id on its
 * own page and on the pages tied to it, so the search engine reads one
 * business with several pages, while «url» is the page being read (asked
 * for: «url (la página)»).
 *
 * MERGE INTO AN SEO PLUGIN'S GRAPH (merge()), the rules:
 *   1. If the host graph ALREADY has a local business (Rank Math's or
 *      Yoast's local SEO, any @type in Types::ALL), ours steps aside
 *      completely on that page: two local businesses for one page is what
 *      Google calls a duplicate, and theirs was configured on purpose.
 *   2. The host's Organization (the node whose @type has «Organization»;
 *      Rank Math's «publisher» first) is NOT duplicated: our properties are
 *      ADDED to it, only those it does not have (theirs win: they are its
 *      settings), and our locations point to its @id.
 *   3. Without a host Organization, ours is added.
 *   Rank Math's graph is keyed (publisher, WebSite…), Yoast's is a list:
 *   merge() keeps the shape it gets.
 *
 * How to maintain:
 * - Who and when: whoever adds a property. It goes in organization() or
 *   local_business(), with its line in tests/graph.test.mjs and, if it comes
 *   from a field, in Data (class-data.php).
 * - Out, on purpose (decided 22-10-2026): aggregateRating and review (Google
 *   does not show a business's ratings of itself: «self-serving reviews»),
 *   events and offers.
 * - The trap: a property with an EMPTY value ("" or []) is worse than none
 *   — the Rich Results test reports it as invalid. compact() drops them;
 *   never add one after it.
 */

namespace EstrategiaWeb\LocalBusiness;

use EstrategiaWeb\ContentTypes\Hours;

defined( 'ABSPATH' ) || exit;

final class Graph {

	/**
	 * @param array $company id, name, url, logo, telephone, email, same_as[]
	 * @param array $departments LocalBusiness nodes (local_business() of each location, without parent)
	 */
	public static function organization( array $company, array $departments = array() ): array {
		return self::compact(
			array(
				'@type'      => 'Organization',
				'@id'        => (string) ( $company['id'] ?? '' ),
				'name'       => (string) ( $company['name'] ?? '' ),
				'url'        => (string) ( $company['url'] ?? '' ),
				'logo'       => (string) ( $company['logo'] ?? '' ),
				'telephone'  => (string) ( $company['telephone'] ?? '' ),
				'email'      => (string) ( $company['email'] ?? '' ),
				'sameAs'     => array_values( array_unique( array_filter( (array) ( $company['same_as'] ?? array() ), static fn( $u ) => is_string( $u ) && preg_match( '#^https?://#i', $u ) ) ) ),
				'department' => array_values( $departments ),
			)
		);
	}

	/**
	 * One location as a LocalBusiness, or null if it cannot be one (see the header).
	 *
	 * @param array $location id, type, name, url, telephone, email, image, street,
	 *                        postal_code, locality, region, country, hours (stored
	 *                        JSON), latitude, longitude, parent (the Organization's @id or "")
	 */
	public static function local_business( array $location ): ?array {
		$s       = static fn( string $key ): string => trim( (string) ( $location[ $key ] ?? '' ) );
		$country = strtoupper( $s( 'country' ) );
		$address = self::compact(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $s( 'street' ),
				'postalCode'      => $s( 'postal_code' ),
				'addressLocality' => $s( 'locality' ),
				'addressRegion'   => $s( 'region' ),
				'addressCountry'  => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			)
		);
		if ( '' === $s( 'name' ) || '' === $s( 'street' ) || ( '' === $s( 'locality' ) && '' === $s( 'postal_code' ) ) ) {
			return null;
		}
		$lat = is_numeric( $location['latitude'] ?? null ) ? (float) $location['latitude'] : 0.0;
		$lon = is_numeric( $location['longitude'] ?? null ) ? (float) $location['longitude'] : 0.0;
		// 0 is «not given» (the field's default): 0,0 is in the Gulf of Guinea, no business is there.
		$geo = ( 0.0 !== $lat && 0.0 !== $lon && abs( $lat ) <= 90 && abs( $lon ) <= 180 )
			? array( '@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lon )
			: array();
		$parent = $s( 'parent' );
		return self::compact(
			array(
				'@type'                     => Types::valid( $location['type'] ?? null ) ?? Types::DEFAULT,
				'@id'                       => $s( 'id' ),
				'name'                      => $s( 'name' ),
				'url'                       => $s( 'url' ),
				'image'                     => $s( 'image' ),
				'telephone'                 => $s( 'telephone' ),
				'email'                     => $s( 'email' ),
				'address'                   => $address,
				'geo'                       => $geo,
				'openingHoursSpecification' => Hours::specification( $location['hours'] ?? '' ),
				'parentOrganization'        => '' !== $parent ? array( '@id' => $parent ) : array(),
			)
		);
	}

	/**
	 * Our nodes into a host graph (Rank Math's keyed array or Yoast's list).
	 * See «MERGE» in the header.
	 *
	 * @param array      $host      The SEO plugin's graph as it comes.
	 * @param array|null $org       Our Organization node (null: we have none to give).
	 * @param array[]    $locations Our LocalBusiness nodes, parentOrganization = our Organization's @id.
	 * @return array The host graph with ours in, same shape.
	 */
	public static function merge( array $host, ?array $org, array $locations ): array {
		foreach ( $host as $node ) {
			if ( is_array( $node ) && Types::is_local( $node['@type'] ?? null ) ) {
				return $host; // 1. Theirs is already a local business: ours steps aside.
			}
		}
		$host_org = self::host_organization( $host );
		if ( null !== $host_org && null !== $org ) {
			// 2. Added to theirs, only what they do not have; our locations point to their @id.
			$theirs = $host[ $host_org ];
			foreach ( $org as $key => $value ) {
				if ( '@id' !== $key && '@type' !== $key && ! array_key_exists( $key, $theirs ) ) {
					$theirs[ $key ] = $value;
				}
			}
			$host[ $host_org ] = $theirs;
			$id                = (string) ( $theirs['@id'] ?? '' );
			$locations         = array_map( static fn( $n ) => self::with_parent( $n, $id ), $locations );
		} elseif ( null !== $org ) {
			self::add( $host, 'ewebOrganization', $org ); // 3.
		}
		foreach ( array_values( $locations ) as $i => $node ) {
			self::add( $host, 'ewebLocalBusiness' . ( $i ? $i + 1 : '' ), $node );
		}
		return $host;
	}

	/** The graph of a page WITHOUT an SEO plugin: what our own <script> prints. */
	public static function standalone( ?array $org, array $locations ): array {
		$graph = array();
		if ( null !== $org ) {
			$graph[] = $org;
		}
		foreach ( $locations as $node ) {
			$graph[] = $node;
		}
		return $graph ? array( '@context' => 'https://schema.org', '@graph' => $graph ) : array();
	}

	/** The key of the host's Organization node, or null. Rank Math's «publisher» first. */
	private static function host_organization( array $host ): int|string|null {
		$keys = array_keys( $host );
		usort( $keys, static fn( $a, $b ) => ( 'publisher' === $b ) <=> ( 'publisher' === $a ) );
		foreach ( $keys as $key ) {
			$node = $host[ $key ];
			if ( is_array( $node ) && in_array( 'Organization', (array) ( $node['@type'] ?? array() ), true ) ) {
				return $key;
			}
		}
		return null;
	}

	private static function with_parent( array $node, string $id ): array {
		if ( '' === $id ) {
			unset( $node['parentOrganization'] );
		} else {
			$node['parentOrganization'] = array( '@id' => $id );
		}
		return $node;
	}

	/** Keyed host (Rank Math): under our key; list (Yoast) or empty: at the end. */
	private static function add( array &$host, string $key, array $node ): void {
		if ( array_is_list( $host ) ) {
			$host[] = $node;
		} else {
			$host[ $key ] = $node;
		}
	}

	/** Without empty values ("", [], null): an empty property is reported as invalid. */
	private static function compact( array $node ): array {
		return array_filter( $node, static fn( $v ) => ! ( '' === $v || null === $v || array() === $v ) );
	}
}
