<?php
namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

/**
 * WebP (0.5.1): the sizes WordPress generates from an uploaded JPEG or PNG
 * are saved as WebP.
 *
 * Why: the Lavandería audit (2026-10-06) flagged “next-generation formats”
 * in 8 of 10 runs (up to 366 KiB on the home page): the server delivered
 * image/jpeg even when the browser accepted WebP. It lives in the plugin and
 * not in the theme because it is what gets SAVED in the media library:
 * switching themes must not change it. And in code, not in the SiteGround
 * panel, so it travels with the site.
 *
 * How: WordPress's image_editor_output_format filter (5.8+), with
 * image/jpeg → image/webp and image/png → image/webp. MEASURED (2026-10-06,
 * tests/webp.test.mjs): WordPress converts ALL the files it generates,
 * including the main one: when uploading foto.jpg, the media library serves
 * foto.webp (source_url, the “full”) and its sizes foto-1024x640.webp…; the
 * uploaded .jpg stays on disk as original_image and is not served. Above
 * 2560 px, foto-scaled.webp. The srcset WordPress prints when serving the
 * page comes from the meta, so in NEW content everything is WebP with no
 * extra work.
 * - Only if the server's image editor can write WebP
 *   (wp_image_editor_supports). If it cannot, it does nothing: sizes stay
 *   JPEG/PNG and no upload breaks.
 * - To switch off (on by default), in the theme's functions.php:
 *       add_filter( 'estrategia_web_site_webp', '__return_false' );
 *   A filter and not an option, like “Powered by”: it is a decision for
 *   whoever builds the site, not data the client touches.
 * - PNG with transparency: WebP keeps it. GIF is not touched (animations).
 *
 * THE TRAP: WHAT IS ALREADY UPLOADED DOES NOT CHANGE BY ITSELF, AND
 * REGENERATING MAKES IT WORSE UNLESS THE CONTENT IS TOUCHED. You need to
 * regenerate (`wp media regenerate --yes`) or upload the photo again. On
 * regenerating, the META switches to .webp (the main file too), but the URLs
 * stored in page content do NOT change (nobody rewrites post_content):
 * - a photo inserted with the main file's URL (foto.jpg: the “full”, or the
 *   source_url that Lavandería's content load uses for ALL of them) still
 *   serves, because the .jpg remains as original_image, but it no longer
 *   matches the meta: WordPress gives it no srcset and the browser downloads
 *   the whole JPEG at any width. Worse than before regenerating.
 * - one inserted with an intermediate size's URL (foto-1024x683.jpg, what the
 *   editor stores when choosing “Large”) points to a file that
 *   `wp media regenerate` DELETES: broken image. With --skip-delete it does
 *   not break, but stays JPEG and without srcset.
 * The fix: after regenerating, write back into the content the URL the media
 * library now gives (in Lavandería, run its content load again, which sets
 * the source_url). Tested by tests/webp.test.mjs (level 2), which imitates
 * WP-CLI's regenerate with a test route (WP-CLI is not in Playground: that
 * it behaves the same on the real server is the hypothesis to check on the
 * first run).
 *
 * How to maintain:
 * - Who: whoever touches the plugin. When: if WordPress changes the default
 *   format (it already happened: 6.7 added HEIC → JPEG in this same filter;
 *   that is why here we ADD to the array, never replace it).
 * - Edge case: a theme that wants AVIF is a tweak of the same WordPress
 *   filter in its functions.php, with priority higher than 10; this is not touched.
 */

final class Webp {

	/** Source formats that become WebP. */
	private const FROM = array( 'image/jpeg', 'image/png' );

	private static ?bool $supported = null;

	/** Default WebP quality (0.5.4). WordPress uses 82 for every format. */
	public const QUALITY = 75;

	public static function boot(): void {
		add_filter( 'image_editor_output_format', array( self::class, 'output_format' ), 10, 3 );
		add_filter( 'wp_editor_set_quality', array( self::class, 'quality' ), 10, 2 );
	}

	/**
	 * Quality of the WebPs the image editor generates (0.5.4). Only
	 * image/webp: a JPEG or PNG still saved as such does not change.
	 *
	 * Why 75: Lavandería's PageSpeed (2026-10-06), “improve image delivery”,
	 * still reported savings from more compression on the home page photos even
	 * with the size well chosen. 75 is Google's default (cwebp); visually, on photos,
	 * it is indistinguishable from 82 and weighs about 20 % less.
	 *
	 * To adjust (like WebP itself, a filter and not an option: it belongs to
	 * whoever builds the site), in the theme's functions.php:
	 *     add_filter( 'estrategia_web_site_webp_quality', fn() => 82 );
	 * Returning 0 or less keeps WordPress's.
	 *
	 * THE TRAP: it only applies to what is generated SINCE it is active. What
	 * is already uploaded does not change until regenerated (in Lavandería, the
	 * “webp” step of despliegue/desplegar.mjs, which does it without breaking
	 * the content).
	 *
	 * @param int|mixed $quality   Quality coming from WordPress (82) or another filter.
	 * @param string    $mime_type Format about to be saved.
	 */
	public static function quality( $quality, $mime_type = '' ): int {
		if ( 'image/webp' !== $mime_type ) {
			return (int) $quality;
		}
		/**
		 * Quality (1-100) of the WebPs the image editor generates. 0: WordPress's.
		 *
		 * @param int $quality
		 */
		$ours = (int) apply_filters( 'estrategia_web_site_webp_quality', self::QUALITY );
		return $ours > 0 ? min( 100, $ours ) : (int) $quality;
	}

	/**
	 * Whether this server's image editor writes WebP. Asked once per
	 * request: choosing the editor loads and probes Imagick/GD.
	 */
	public static function supported(): bool {
		self::$supported ??= (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		return self::$supported;
	}

	/**
	 * @param array<string, string> $formats  Source => target (WordPress ships HEIC → JPEG).
	 * @param string                $filename Image path.
	 * @param string                $mime     Source image type.
	 * @return array<string, string>
	 */
	public static function output_format( $formats, $filename = '', $mime = '' ): array {
		$formats = (array) $formats;
		/**
		 * Whether the sizes of uploaded images are saved as WebP. Yes by default.
		 *
		 * @param bool $enabled
		 */
		if ( ! apply_filters( 'estrategia_web_site_webp', true ) || ! self::supported() ) {
			return $formats;
		}
		foreach ( self::FROM as $from ) {
			$formats[ $from ] = 'image/webp';
		}
		return $formats;
	}
}
