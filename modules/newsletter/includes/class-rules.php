<?php
/**
 * The pure part of the newsletter module: no WordPress, no HTTP. Everything
 * here is tested at level 1 with the machine's PHP (tests/php/pure.php),
 * including the build of Gastronòmicament's real 04-09-2026 butlletí from
 * its templates (tests/fixtures/).
 *
 *   excerpt( text )                 the lead: 16 words and «…»
 *   block( template, item )         one news block: placeholders → escaped values
 *   email( template, blocks )       the whole email: {{blocks}} → the blocks
 *   email_image( urls, original )   the photo the email carries: never WebP if there is another
 *   datacenter( key )               «us21» from «…-us21»
 *   campaign_body( model, subject ) the POST /campaigns of a NEW campaign copied from the model
 *
 * How to maintain:
 * - Who and when: whoever changes what a block carries or how the draft is
 *   created. A new placeholder goes in PLACEHOLDERS, in block(), in the
 *   settings help (class-settings.php) and in pure.php.
 * - Criterion: what can be decided without WordPress lives here; what reads
 *   a post, an attachment or the disk lives in class-builder.php.
 * - Edge case, by name: the lead of «La temporada de pesca del raor…» has 9
 *   words. Shorter than 16 → no «…» (measured on 27-08-2026: 16, 16 and 15).
 * - The trap: campaign_body() must NEVER carry settings.template_id. A
 *   campaign with a drag-and-drop template IGNORES the html put through the
 *   API and sends the template's old content (the 04-09-2026 butlletí: new
 *   subject, last week's three news, 348 people). The test checks it.
 */

namespace EstrategiaWeb\Newsletter;

defined( 'ABSPATH' ) || exit;

final class Rules {

	/** Words of the lead (Gastronòmicament, measured 27-08-2026). */
	public const WORDS = 16;

	/** Where the blocks go in the email template. */
	public const BLOCKS = '{{blocks}}';

	/** What a block template may use. */
	public const PLACEHOLDERS = array( '{{link}}', '{{image}}', '{{title}}', '{{excerpt}}', '{{label}}', '{{more}}' );

	/** Settings of the model campaign copied as they are (atlas's `mailchimp.js nueva`, 04-09-2026). */
	private const COPIED = array( 'from_name', 'reply_to', 'to_name', 'authenticate', 'auto_footer', 'inline_css', 'auto_tweet', 'fb_comments' );

	/**
	 * The lead: plain text, the first $words words and «…» if there were more.
	 * A comma, colon or semicolon right before the «…» goes.
	 */
	public static function excerpt( string $text, int $words = self::WORDS ): string {
		$text  = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text  = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
		$parts = '' === $text ? array() : explode( ' ', $text );
		if ( count( $parts ) <= $words ) {
			return implode( ' ', $parts );
		}
		return rtrim( implode( ' ', array_slice( $parts, 0, $words ) ), ',;:' ) . '…';
	}

	/** Escaped for HTML text and double-quoted attributes. A single quote stays as it is (as in the hand-made butlletí). */
	public static function esc( string $value ): string {
		return htmlspecialchars( $value, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false );
	}

	/**
	 * One block. $item: link, image, title, excerpt, label, more (plain text; escaped here).
	 * strtr() replaces in ONE pass, so a title that says «{{link}}» stays text.
	 *
	 * @param array<string, string> $item
	 */
	public static function block( string $template, array $item ): string {
		$pairs = array();
		foreach ( self::PLACEHOLDERS as $placeholder ) {
			$pairs[ $placeholder ] = self::esc( (string) ( $item[ trim( $placeholder, '{}' ) ] ?? '' ) );
		}
		return strtr( $template, $pairs );
	}

	/** The whole email. A template without {{blocks}} is an error (null): the news would go nowhere. */
	public static function email( string $template, string $blocks ): ?string {
		if ( ! str_contains( $template, self::BLOCKS ) ) {
			return null;
		}
		return strtr( $template, array( self::BLOCKS => $blocks ) );
	}

	/**
	 * The photo of a block: Outlook does not paint WebP, and since 01-11-2026
	 * Gastronòmicament stores WebP (webp module). The JPEG is usually still on
	 * disk: each size converted after upload keeps its .jpg with the same
	 * name; a photo uploaded already as WebP keeps only its original
	 * (original_image).
	 *
	 * @param string[] $urls     Preferred first (large, then full).
	 * @param string   $original The original upload's URL, '' if none.
	 * @param callable $exists   fn( string $url ): bool — is that file there.
	 * @return array{url: string, webp_only: bool}
	 */
	public static function email_image( array $urls, string $original, callable $exists ): array {
		$urls = array_values( array_filter( array_map( 'strval', $urls ) ) );
		foreach ( $urls as $url ) {
			if ( ! self::is_webp( $url ) ) {
				return array( 'url' => $url, 'webp_only' => false );
			}
			foreach ( array( 'jpg', 'jpeg', 'png' ) as $ext ) {
				$other = (string) preg_replace( '/\.webp$/i', '.' . $ext, $url );
				if ( $exists( $other ) ) {
					return array( 'url' => $other, 'webp_only' => false );
				}
			}
		}
		if ( '' !== $original && ! self::is_webp( $original ) && $exists( $original ) ) {
			return array( 'url' => $original, 'webp_only' => false );
		}
		return array( 'url' => $urls[0] ?? '', 'webp_only' => '' !== ( $urls[0] ?? '' ) );
	}

	public static function is_webp( string $url ): bool {
		return (bool) preg_match( '/\.webp$/i', (string) strtok( $url, '?#' ) );
	}

	/** The Mailchimp datacenter, from the key's suffix («…-us21» → «us21»); null if it has none. */
	public static function datacenter( string $key ): ?string {
		return preg_match( '/^[0-9a-f]{16,}-([a-z]{2,4}[0-9]{1,3})$/i', trim( $key ), $m ) ? strtolower( $m[1] ) : null;
	}

	/**
	 * The body of POST /campaigns: a REGULAR campaign WITHOUT template, with
	 * the model's audience, segment, sender and flags, and the new subject.
	 * Values the model does not have (null) are not sent.
	 *
	 * @param array<string, mixed> $model A campaign as GET /campaigns returns it.
	 * @return array<string, mixed>
	 */
	public static function campaign_body( array $model, string $subject ): array {
		$recipients = array( 'list_id' => (string) ( $model['recipients']['list_id'] ?? '' ) );
		$segment    = $model['recipients']['segment_opts'] ?? null;
		if ( is_array( $segment ) && array() !== $segment ) {
			$recipients['segment_opts'] = $segment;
		}
		$settings = array(
			'subject_line' => $subject,
			'title'        => mb_substr( $subject, 0, 99 ),
		);
		foreach ( self::COPIED as $key ) {
			if ( isset( $model['settings'][ $key ] ) ) {
				$settings[ $key ] = $model['settings'][ $key ];
			}
		}
		return array(
			'type'       => 'regular',
			'recipients' => $recipients,
			'settings'   => $settings,
		);
	}
}
