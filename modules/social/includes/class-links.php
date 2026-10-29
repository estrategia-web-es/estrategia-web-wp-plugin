<?php
namespace EstrategiaWeb\Social;

defined( 'ABSPATH' ) || exit;

/**
 * The list of networks: option estrategia_web_social_links, its sanitizing
 * and what the block receives already resolved (name, link, icon).
 *
 * Stored shape: an ORDERED list of
 *     { "name": "Instagram", "url": "https://instagram.com/x", "icon": "instagram", "icon_id": 0 }
 * - icon: a collection slug (Icons::labels()) or '' = guess it from the
 *   link when rendering (Icons::guess: instagram.com → instagram; if it is
 *   no known network, “website”).
 * - icon_id: an SVG from the media library; if valid, it overrides icon.
 * - name: what is read out. Empty = the icon's name (“Instagram”).
 * Written from the “Estrategia Web → Social networks” screen or via REST
 * (/wp/v2/settings, administrators only):
 *     wp option update estrategia_web_social_links '[{"name":"Instagram","url":"https://instagram.com/x"}]' --format=json
 *
 * How to maintain:
 * - Who and when: whoever adds a per-network field (e.g. “footer only”),
 *   here (FIELDS, sanitize_item, REST schema), on the screen and in
 *   wpml-config.xml if it is read out.
 * - Named EDGE CASE: a site that already had its networks in “Company
 *   details” (the six fixed fields social.instagram, etc., of the site
 *   module, which since 2026-10-15 no longer appear on that screen).
 *   migrate(), on `init`: if this option does NOT EXIST and any of those
 *   fields has a value, it copies them here ONCE, in their order and with
 *   their icon, with add_option (if another request arrives at the same
 *   time, the second does nothing). The old values stay stored in
 *   estrategia_web_site: no data is deleted. From then on ONLY the list
 *   rules, even if emptied: saving [] does not bring the old fields back.
 *   With no fields holding a value nothing is written, so a content load
 *   that still sends `social` via REST to “Company details” is migrated on
 *   the next request, as long as the list has not been saved; once the list
 *   is saved, that is ignored.
 * - Alias `social.<network>` (Company::value, the Block Bindings source and
 *   the shortcode) UNTIL 2.0.0: url_for() returns the link of the first
 *   network in the list with that icon (chosen or guessed from the link).
 *   In 2.0.0 the social.<network> keys and the `social` object of the site
 *   module's option are removed (major version: it is a contract).
 * - With WPML: name and url are translated as admin texts
 *   (wpml-config.xml, <admin-texts>), by POSITION in the list. The trap:
 *   reordering the list shifts the translations (the 2nd one's becomes the
 *   one for whatever is now 2nd). After reordering, review the translation.
 *   Not tested with WPML (Playground does not have it).
 * - The screen's trap: with an EMPTY list the browser does not send the
 *   field and options.php would save nothing. An empty hidden input goes
 *   first, which sanitize() discards (it is not an array).
 */

use EstrategiaWeb\Site\Company;
use EstrategiaWeb\Site\Settings;

final class Links {

	public const OPTION = 'estrategia_web_social_links';

	/** Settings API group (the screen's settings_fields()). */
	public const GROUP = 'estrategia_web_social';

	/** Cap on networks: a list growing unchecked is a mistake by whoever loads it. */
	public const MAX = 30;

	/** Allowed link schemes. No javascript: or data:, of course. */
	public const PROTOCOLS = array( 'http', 'https', 'mailto', 'tel' );

	/** The networks that lived in “Company details” (Settings::SOCIAL of the site module) → icon. */
	private const COMPANY = array( 'instagram', 'facebook', 'linkedin', 'x', 'youtube', 'tiktok' );

	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'Social network links, in order.', 'estrategia-web' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'    => array( 'type' => 'string' ),
								'url'     => array( 'type' => 'string' ),
								'icon'    => array( 'type' => 'string' ),
								'icon_id' => array( 'type' => 'integer' ),
							),
						),
					),
				),
			)
		);
	}

	/** @param mixed $input What arrives from the form or from REST. */
	public static function sanitize( $input ): array {
		$out = array();
		foreach ( is_array( $input ) ? $input : array() as $item ) {
			$item = self::sanitize_item( $item );
			if ( $item ) {
				$out[] = $item;
			}
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/** One sanitized network, or null if it has no valid link (an empty row on the screen). */
	public static function sanitize_item( $item ): ?array {
		if ( is_object( $item ) ) {
			$item = (array) $item;
		}
		if ( ! is_array( $item ) ) {
			return null;
		}
		$url = trim( (string) ( $item['url'] ?? '' ) );
		if ( is_email( $url ) ) {
			$url = 'mailto:' . $url; // Whoever types just the email address wants a mailto.
		}
		$url = '' === $url ? '' : esc_url_raw( $url, self::PROTOCOLS );
		if ( '' === $url ) {
			return null;
		}
		$icon    = sanitize_key( (string) ( $item['icon'] ?? '' ) );
		$icon_id = (int) ( $item['icon_id'] ?? 0 );
		return array(
			'name'    => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
			'url'     => $url,
			'icon'    => Icons::exists( $icon ) ? $icon : '',
			'icon_id' => ( $icon_id > 0 && 'image/svg+xml' === get_post_mime_type( $icon_id ) ) ? $icon_id : 0,
		);
	}

	/** Whether the list has ever been saved (the migration counts). */
	public static function saved(): bool {
		return null !== get_option( self::OPTION, null );
	}

	/** The saved list; empty if none (the `init` migration already brought the company ones). */
	public static function items(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Migration of the fixed fields of “Company details” (see the edge case
	 * in the header). Idempotent: with the list already saved, or with no
	 * fields holding a value, it does nothing.
	 */
	public static function migrate(): void {
		if ( self::saved() ) {
			return;
		}
		$items = self::from_company();
		if ( $items ) {
			add_option( self::OPTION, $items );
		}
	}

	/**
	 * The `social.<network>` alias until 2.0.0: the link of the first network
	 * in the list with that icon (chosen, or guessed from the link if automatic).
	 * Before migrating (a read earlier than `init`), the old field.
	 */
	public static function url_for( string $network ): string {
		if ( ! self::saved() ) {
			return self::legacy()[ $network ] ?? '';
		}
		foreach ( self::items() as $item ) {
			$slug = '' !== $item['icon'] ? $item['icon'] : Icons::guess( $item['url'] );
			if ( $slug === $network ) {
				return $item['url'];
			}
		}
		return '';
	}

	/** The networks from the six fixed fields of “Company details”, in their order. */
	private static function from_company(): array {
		$legacy = self::legacy();
		$out    = array();
		foreach ( self::COMPANY as $network ) {
			$url = (string) ( $legacy[ $network ] ?? '' );
			if ( '' !== $url ) {
				$out[] = array( 'name' => '', 'url' => $url, 'icon' => $network, 'icon_id' => 0 );
			}
		}
		return $out;
	}

	/**
	 * What is stored in the fixed fields, read from Settings and NOT from
	 * Company::value(), which now calls url_for(): that would be a loop.
	 * @return array<string,string>
	 */
	private static function legacy(): array {
		if ( ! class_exists( Settings::class ) ) {
			return array();
		}
		$social = Settings::all()['social'] ?? array();
		return is_array( $social ) ? array_map( 'strval', $social ) : array();
	}

	/**
	 * What the block renders: each network with its name already decided, its
	 * icon as <svg> and whether it opens in a new tab. Those left without an
	 * icon are skipped.
	 *
	 * @return array<int,array{name:string,url:string,icon:string,svg:string,new_tab:bool}>
	 */
	public static function resolved( string $svg_class = '' ): array {
		$labels = Icons::labels();
		$out    = array();
		foreach ( self::items() as $item ) {
			$slug = '' !== $item['icon'] ? $item['icon'] : Icons::guess( $item['url'] );
			$svg  = $item['icon_id'] ? Icons::attachment_svg( (int) $item['icon_id'], $svg_class ) : '';
			if ( '' === $svg ) {
				$svg = Icons::svg( $slug, $svg_class );
			}
			if ( '' === $svg ) {
				continue;
			}
			$out[] = array(
				'name'    => '' !== $item['name'] ? $item['name'] : ( $labels[ $slug ] ?? $item['url'] ),
				'url'     => $item['url'],
				'icon'    => $slug,
				'svg'     => $svg,
				// New tab only for web links: a mailto: or a tel: opens no page.
				'new_tab' => (bool) preg_match( '#^https?://#i', $item['url'] ),
			);
		}
		return $out;
	}

	/** The company name for “Instagram of <Company>”: the one in “Company details” or the site's. */
	public static function owner(): string {
		$name = class_exists( Company::class ) ? Company::value( 'company_name' ) : '';
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}
}
