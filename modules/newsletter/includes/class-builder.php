<?php
/**
 * The WordPress side of the newsletter: which posts the screen proposes, what
 * each chosen post becomes (link, photo, title, lead, label) and the email
 * built with the settings' templates (Rules does the pure part).
 *
 *   candidates( search )  the featured category's posts and the latest, or a search
 *   item( post )          one block's data, with the photo already chosen (never WebP if avoidable)
 *   build( ids )          { html, subject, items, warnings } — what the screen previews,
 *                         what «Copy the code» copies and what goes to Mailchimp: ONE function
 *
 * The label of a block («Actualitat – Llegeix-ne més»): for a post, its
 * first category by name that is not the featured one (Gastronòmicament's
 * posts are always Actualitat, Portada and «Reportatges i novetats», so
 * «Actualitat»); for any other type (a restaurant or recipe sheet), the
 * type's singular name. Filter estrategia_web_newsletter_label( label, post ).
 * The types offered: every public one but pages and attachments, filter
 * estrategia_web_newsletter_post_types.
 *
 * How to maintain:
 * - Who and when: whoever changes what a block shows. The screen, «Copy the
 *   code» and the draft all go through build(): never build HTML elsewhere.
 * - Criterion for the photo: the featured image's «large» size, then the
 *   full one, each without WebP if a JPEG/PNG of it is on disk (Rules::email_image).
 * - Edge case, by name: a post without featured image. Its block goes
 *   without photo and the screen warns («has no featured image»): the email
 *   would show a broken image otherwise, so it is said, not hidden.
 * - The trap: the lead comes from the post's EXCERPT and, if it has none,
 *   from its content — with blocks and shortcodes removed first, or a
 *   gallery's markup ends up counted as words.
 */

namespace EstrategiaWeb\Newsletter;

defined( 'ABSPATH' ) || exit;

final class Builder {

	/** The most posts a newsletter can carry: a safety net, not a design rule. */
	public const MAX = 20;

	/** @return string[] */
	public static function post_types(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'page', 'attachment' ) ) );
		return (array) apply_filters( 'estrategia_web_newsletter_post_types', $types );
	}

	/**
	 * What the screen proposes.
	 *
	 * @return array{featured: array<int, array<string, mixed>>, latest: array<int, array<string, mixed>>, results: array<int, array<string, mixed>>}
	 */
	public static function candidates( string $search = '' ): array {
		$base = array(
			'post_type'           => self::post_types(),
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'orderby'             => 'date',
			'order'               => 'DESC',
		);
		$out = array( 'featured' => array(), 'latest' => array(), 'results' => array() );
		if ( '' !== $search ) {
			$out['results'] = self::rows( get_posts( $base + array( 's' => $search, 'posts_per_page' => 20 ) ) );
			return $out;
		}
		$featured = Settings::get()['featured'];
		if ( '' !== $featured ) {
			$out['featured'] = self::rows( get_posts( $base + array( 'category_name' => $featured, 'posts_per_page' => 15 ) ) );
		}
		$out['latest'] = self::rows( get_posts( $base + array( 'posts_per_page' => 15 ) ) );
		return $out;
	}

	/**
	 * @param \WP_Post[] $posts
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( array $posts ): array {
		return array_map(
			static fn( \WP_Post $p ): array => array(
				'id'    => $p->ID,
				'title' => self::title( $p ),
				'date'  => get_the_date( '', $p ),
				'label' => self::label( $p ),
			),
			$posts
		);
	}

	private static function title( \WP_Post $post ): string {
		return trim( html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	public static function label( \WP_Post $post ): string {
		$label = '';
		if ( 'post' === $post->post_type ) {
			$featured = Settings::get()['featured'];
			$names    = array();
			foreach ( get_the_category( $post->ID ) as $term ) {
				if ( $term->slug !== $featured ) {
					$names[] = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
			sort( $names, SORT_LOCALE_STRING );
			$label = (string) ( $names[0] ?? '' );
		}
		if ( '' === $label ) {
			$type  = get_post_type_object( $post->post_type );
			$label = $type ? (string) $type->labels->singular_name : '';
		}
		return (string) apply_filters( 'estrategia_web_newsletter_label', $label, $post );
	}

	/**
	 * One block's data.
	 *
	 * @return array<string, mixed>
	 */
	public static function item( \WP_Post $post, string $more ): array {
		$source  = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : strip_shortcodes( excerpt_remove_blocks( $post->post_content ) );
		$image   = array( 'url' => '', 'webp_only' => false );
		$thumb   = (int) get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$large = wp_get_attachment_image_src( $thumb, 'large' );
			$full  = wp_get_attachment_image_src( $thumb, 'full' );
			$meta  = wp_get_attachment_metadata( $thumb );
			$orig  = is_array( $meta ) && ! empty( $meta['original_image'] ) && $full ? (string) preg_replace( '#[^/]+$#', rawurlencode( (string) $meta['original_image'] ), $full[0] ) : '';
			$image = Rules::email_image( array( $large ? $large[0] : '', $full ? $full[0] : '' ), $orig, array( self::class, 'exists' ) );
		}
		return array(
			'id'        => $post->ID,
			'link'      => (string) get_permalink( $post ),
			'title'     => self::title( $post ),
			'excerpt'   => Rules::excerpt( $source ),
			'label'     => self::label( $post ),
			'more'      => $more,
			'image'     => $image['url'],
			'webp_only' => $image['webp_only'],
		);
	}

	/** Is the file behind an uploads URL on disk. A URL outside uploads: no. */
	public static function exists( string $url ): bool {
		$uploads = wp_get_upload_dir();
		$base    = (string) $uploads['baseurl'];
		$path    = (string) strtok( $url, '?#' );
		foreach ( array( $base, set_url_scheme( $base, 'http' ), set_url_scheme( $base, 'https' ) ) as $prefix ) {
			if ( str_starts_with( $path, $prefix . '/' ) ) {
				return file_exists( $uploads['basedir'] . rawurldecode( substr( $path, strlen( $prefix ) ) ) );
			}
		}
		return false;
	}

	/**
	 * The newsletter for these posts, in this order.
	 *
	 * @param int[] $ids
	 * @return array{html: string, subject: string, items: array<int, array<string, mixed>>, warnings: string[]}|\WP_Error
	 */
	public static function build( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( count( $ids ) > self::MAX ) {
			/* translators: %d: the maximum. */
			return new \WP_Error( 'estrategia_web_newsletter', sprintf( __( 'A newsletter carries at most %d posts.', 'estrategiaweb-toolkit' ), self::MAX ), array( 'status' => 400 ) );
		}
		$types    = self::post_types();
		$more     = Settings::more();
		$template = Settings::block_template();
		$items    = array();
		$warnings = array();
		$blocks   = '';
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, $types, true ) ) {
				/* translators: %d: post ID. */
				return new \WP_Error( 'estrategia_web_newsletter', sprintf( __( 'Post %d is not a published post that can go in the newsletter.', 'estrategiaweb-toolkit' ), $id ), array( 'status' => 400 ) );
			}
			$item = self::item( $post, $more );
			if ( '' === $item['image'] ) {
				/* translators: %s: post title. */
				$warnings[] = sprintf( __( '«%s» has no featured image: its block goes without photo.', 'estrategiaweb-toolkit' ), $item['title'] );
			} elseif ( $item['webp_only'] ) {
				/* translators: %s: post title. */
				$warnings[] = sprintf( __( '«%s»: its photo only exists in WebP, which Outlook does not show. Upload it again as JPEG.', 'estrategiaweb-toolkit' ), $item['title'] );
			}
			$items[] = $item;
			$blocks .= Rules::block( $template, $item );
		}
		$html = Rules::email( Settings::email_template(), $blocks );
		if ( null === $html ) {
			return new \WP_Error( 'estrategia_web_newsletter', __( 'The email template has no {{blocks}}.', 'estrategiaweb-toolkit' ), array( 'status' => 500 ) );
		}
		return array(
			'html'     => $html,
			'subject'  => (string) ( $items[0]['title'] ?? '' ),
			'items'    => $items,
			'warnings' => $warnings,
		);
	}
}
