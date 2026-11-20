<?php
/**
 * The files sent with a form (2.11.0, the «File» field).
 *
 * WHERE THEY LIVE, and why there. A file is never reachable by URL: it is
 * written OUTSIDE the web root (by default the folder above WordPress,
 * dirname( ABSPATH ) . '/estrategia-web-forms-private') with a random name
 * and no extension, and it only leaves through download(), which checks who
 * is asking: the site's team (capability + nonce) or the owner of the
 * dossier (its link's token). The emails never attach it: they say its name.
 *
 * Why not wp-content/uploads with a «deny» .htaccess: on SiteGround, and on
 * any host with nginx in front, static files are served by nginx, which
 * does NOT read .htaccess. The rule would be there and protect nothing.
 * Only when the folder above WordPress cannot be written does it fall back
 * to wp-content/uploads/estrategia-web-forms-private (with .htaccess,
 * web.config and index.php, for the hosts that do read them), and the
 * settings screen says so in red: is_public().
 *
 * What is accepted: only the types in TYPES, checked by the CONTENT (the
 * first bytes, sniff()), not by the name or by what the browser says. A
 * «factura.pdf» that is really an HTML page is rejected.
 *
 * How to maintain:
 * - Who and when: whoever adds a type adds its magic bytes to sniff() and
 *   its entry to TYPES, and a case to tests/php/pure.php. In the same commit.
 * - A site that wants another folder defines ESTRATEGIA_WEB_FORMS_PRIVATE_DIR
 *   in wp-config.php (absolute path). Moving it later means moving the
 *   folder too: the rows store the path RELATIVE to it.
 * - Edge case: a file field in a form WITHOUT dossier mode works the same
 *   (the team downloads it from the submission), only the sender cannot see
 *   it again.
 * - The trap: PHP's own limits (upload_max_filesize, post_max_size) go
 *   BEFORE this code. If the request is bigger than post_max_size, PHP
 *   empties $_POST and $_FILES and what arrives is «unknown form». The
 *   field's maxMb cannot be higher than what the server lets through;
 *   max_server_bytes() says how much that is, and the editor shows it.
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Files {

	/** extension => MIME. The extensions are also what the field's «accept» lists. */
	public const TYPES = array(
		'pdf' => 'application/pdf',
		'jpg' => 'image/jpeg',
		'png' => 'image/png',
	);

	public const DEFAULT_MAX_MB = 10;
	public const MAX_FILES      = 10;
	public const FOLDER         = 'estrategia-web-forms-private';

	/**
	 * The type of a file by its first bytes. PURE (level 1).
	 *
	 * @return string A key of TYPES, or '' if it is none of them.
	 */
	public static function sniff( string $head ): string {
		if ( str_starts_with( $head, '%PDF-' ) ) {
			return 'pdf';
		}
		if ( str_starts_with( $head, "\xFF\xD8\xFF" ) ) {
			return 'jpg';
		}
		if ( str_starts_with( $head, "\x89PNG\r\n\x1A\n" ) ) {
			return 'png';
		}
		return '';
	}

	/**
	 * The accepted types of a field, from its «accept» attribute ("pdf,jpg").
	 * PURE. Unknown ones are dropped; none left = all of TYPES. «jpeg» is jpg.
	 *
	 * @return string[]
	 */
	public static function accepted( string $accept ): array {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( $accept ) ) as $ext ) {
			$ext = 'jpeg' === ltrim( $ext, '.' ) ? 'jpg' : ltrim( $ext, '.' );
			if ( isset( self::TYPES[ $ext ] ) && ! in_array( $ext, $out, true ) ) {
				$out[] = $ext;
			}
		}
		return $out ? $out : array_keys( self::TYPES );
	}

	/** The HTML accept attribute: extensions and MIME types, for every browser. PURE. */
	public static function accept_attribute( array $types ): string {
		$parts = array();
		foreach ( $types as $ext ) {
			$parts[] = '.' . $ext;
			if ( 'jpg' === $ext ) {
				$parts[] = '.jpeg';
			}
			$parts[] = self::TYPES[ $ext ];
		}
		return implode( ',', array_unique( $parts ) );
	}

	/**
	 * The name shown to people: the sender's, without path, control
	 * characters or anything a header could choke on, and with the extension
	 * of what it REALLY is. PURE.
	 */
	public static function display_name( string $name, string $ext ): string {
		$name = basename( str_replace( '\\', '/', $name ) );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F"<>\/\\\\:*?|]+/u', '', $name );
		$base = trim( (string) preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', $name ), " .\t" );
		if ( '' === $base ) {
			$base = 'file';
		}
		return mb_substr( $base, 0, 120 ) . '.' . $ext;
	}

	/**
	 * $_FILES['files'] as PHP builds it for name="files[field][]" (five
	 * parallel arrays) turned into field => list of uploads. PURE.
	 *
	 * @return array<string, array<int, array{name: string, tmp: string, size: int, error: int}>>
	 */
	public static function normalize( array $files ): array {
		$out = array();
		foreach ( (array) ( $files['name'] ?? array() ) as $field => $names ) {
			foreach ( (array) $names as $i => $name ) {
				$error = (int) ( $files['error'][ $field ][ $i ] ?? UPLOAD_ERR_NO_FILE );
				if ( UPLOAD_ERR_NO_FILE === $error ) {
					continue;
				}
				$out[ (string) $field ][] = array(
					'name'  => (string) $name,
					'tmp'   => (string) ( $files['tmp_name'][ $field ][ $i ] ?? '' ),
					'size'  => (int) ( $files['size'][ $field ][ $i ] ?? 0 ),
					'error' => $error,
				);
			}
		}
		return $out;
	}

	/** The smallest of PHP's two limits, in bytes (0 = no limit known). */
	public static function max_server_bytes(): int {
		$limits = array_filter( array( wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) ), wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) ) );
		return $limits ? (int) min( $limits ) : 0;
	}

	/** The private folder, created on first use. '' if it cannot be created anywhere. */
	public static function dir(): string {
		static $dir = null;
		if ( null !== $dir ) {
			return $dir;
		}
		foreach ( self::candidates() as $candidate ) {
			if ( self::prepare( $candidate ) ) {
				return $dir = $candidate;
			}
		}
		return $dir = '';
	}

	/** True when the files had to go under the web root (the fallback). */
	public static function is_public(): bool {
		$dir = self::dir();
		return '' !== $dir && str_starts_with( wp_normalize_path( $dir ), wp_normalize_path( ABSPATH ) );
	}

	/** @return string[] */
	private static function candidates(): array {
		if ( defined( 'ESTRATEGIA_WEB_FORMS_PRIVATE_DIR' ) && '' !== (string) ESTRATEGIA_WEB_FORMS_PRIVATE_DIR ) {
			return array( untrailingslashit( (string) ESTRATEGIA_WEB_FORMS_PRIVATE_DIR ) );
		}
		$uploads = wp_upload_dir( null, false );
		return array(
			untrailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . '/' . self::FOLDER,
			untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::FOLDER,
		);
	}

	private static function prepare( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! wp_is_writable( $dir ) ) {
			return false;
		}
		// For the hosts that read them (Apache, IIS). Useless with nginx: see the header.
		$guards = array(
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			'index.php'  => "<?php\n// Silence.\n",
		);
		foreach ( $guards as $file => $content ) {
			if ( ! file_exists( $dir . '/' . $file ) ) {
				file_put_contents( $dir . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return true;
	}

	/**
	 * Checks the uploads of every file field of the definition. Nothing is
	 * written here: store() does it once the whole submission is valid.
	 *
	 * @param array<string, array<int, array>> $uploads  normalize()'d.
	 * @param array<string, array<int, array>> $existing field => files already in the dossier (after removals).
	 * @return array{accepted: array<string, array<int, array>>, errors: array<string, string>}
	 */
	public static function validate( array $definition, array $uploads, array $existing ): array {
		$accepted = array();
		$errors   = array();
		foreach ( (array) $definition['fields'] as $field ) {
			if ( 'file' !== $field['type'] ) {
				continue;
			}
			$name  = (string) $field['name'];
			$list  = $uploads[ $name ] ?? array();
			$types = (array) ( $field['types'] ?? array_keys( self::TYPES ) );
			$max   = (int) ( $field['max_bytes'] ?? self::DEFAULT_MAX_MB * MB_IN_BYTES );
			$limit = ! empty( $field['multiple'] ) ? self::MAX_FILES : 1;
			if ( empty( $field['multiple'] ) && $list ) {
				$list = array( end( $list ) ); // One file: the new one replaces (store() drops the old one).
			}
			if ( count( $list ) + ( empty( $field['multiple'] ) ? 0 : count( $existing[ $name ] ?? array() ) ) > $limit ) {
				/* translators: %d: maximum number of files. */
				$errors[ $name ] = sprintf( __( 'You can attach up to %d files here.', 'estrategiaweb-toolkit' ), $limit );
				continue;
			}
			foreach ( $list as $upload ) {
				$error = self::check( $upload, $types, $max );
				if ( '' !== $error ) {
					$errors[ $name ] = $error;
					continue 2;
				}
				$upload['ext']       = self::sniff( self::head( $upload['tmp'] ) );
				$accepted[ $name ][] = $upload;
			}
		}
		return array(
			'accepted' => $accepted,
			'errors'   => $errors,
		);
	}

	private static function check( array $upload, array $types, int $max ): string {
		$problem = self::problem( $upload['error'], $upload['size'], self::is_upload( $upload['tmp'] ), self::head( $upload['tmp'] ), $types, $max );
		return match ( $problem ) {
			/* translators: %s: maximum size, e.g. «10 MB». */
			'too_big'      => sprintf( __( 'The file is too big. The maximum is %s.', 'estrategiaweb-toolkit' ), size_format( $max ) ),
			'not_received' => __( 'The file could not be received. Please try again.', 'estrategiaweb-toolkit' ),
			/* translators: %s: accepted types, e.g. «PDF, JPG». */
			'type'         => sprintf( __( 'This type of file is not accepted. Accepted: %s.', 'estrategiaweb-toolkit' ), strtoupper( implode( ', ', $types ) ) ),
			default        => '',
		};
	}

	/**
	 * What is wrong with one upload, in this order: too big (also PHP's own
	 * limit), not received, not an accepted type. PURE (level 1).
	 *
	 * @return string '' | 'too_big' | 'not_received' | 'type'
	 */
	public static function problem( int $error, int $size, bool $is_upload, string $head, array $types, int $max ): string {
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error || $size > $max ) {
			return 'too_big';
		}
		if ( UPLOAD_ERR_OK !== $error || ! $is_upload ) {
			return 'not_received';
		}
		return in_array( self::sniff( $head ), $types, true ) ? '' : 'type';
	}

	/**
	 * The files each field keeps after a save, and the ones that go away.
	 * PURE (level 1). Removed boxes go first; then, in a one-file field, a
	 * new file replaces what was there; in a several-files field it is added.
	 *
	 * @param array<string, array<int, array>> $before  field => files the dossier had.
	 * @param string[]                         $removed ids ticked «Remove».
	 * @param array<string, array<int, array>> $new     field => files just stored.
	 * @param array<string, bool>              $multiple field => several files allowed.
	 * @return array{files: array<string, array<int, array>>, dropped: array<int, array>}
	 */
	public static function merge( array $before, array $removed, array $new, array $multiple ): array {
		$files   = array();
		$dropped = array();
		foreach ( $before as $field => $list ) {
			foreach ( $list as $file ) {
				if ( in_array( (string) $file['id'], $removed, true ) ) {
					$dropped[] = $file;
				} else {
					$files[ $field ][] = $file;
				}
			}
		}
		foreach ( $new as $field => $list ) {
			if ( $list && empty( $multiple[ $field ] ) ) {
				$dropped          = array_merge( $dropped, $files[ $field ] ?? array() );
				$files[ $field ] = array();
			}
			$files[ $field ] = array_merge( $files[ $field ] ?? array(), $list );
		}
		return array(
			'files'   => $files,
			'dropped' => $dropped,
		);
	}

	/**
	 * A real HTTP upload. The test mu-plugin can widen it (its uploads are
	 * built in PHP, not received), but only under the testing constant.
	 */
	private static function is_upload( string $tmp ): bool {
		if ( is_uploaded_file( $tmp ) ) {
			return true;
		}
		return defined( 'ESTRATEGIA_WEB_FORMS_TESTING' ) && (bool) apply_filters( 'estrategia_web_forms_test_is_upload', false, $tmp );
	}

	private static function head( string $path ): string {
		$handle = '' !== $path && is_readable( $path ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return '';
		}
		$head = (string) fread( $handle, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $head;
	}

	/**
	 * Moves the accepted uploads into the private folder.
	 *
	 * @return array<string, array<int, array{id: string, name: string, size: int, type: string, path: string, at: string}>>|null null if the folder is unusable.
	 */
	public static function store( array $accepted ): ?array {
		if ( ! $accepted ) {
			return array();
		}
		$dir = self::dir();
		if ( '' === $dir ) {
			return null;
		}
		$sub = gmdate( 'Y/m' );
		if ( ! self::prepare( $dir . '/' . gmdate( 'Y' ) ) || ! wp_mkdir_p( $dir . '/' . $sub ) ) {
			return null;
		}
		$out = array();
		foreach ( $accepted as $field => $list ) {
			foreach ( $list as $upload ) {
				$id   = bin2hex( random_bytes( 16 ) );
				$path = $sub . '/' . $id;
				$ok   = is_uploaded_file( $upload['tmp'] ) ? move_uploaded_file( $upload['tmp'], $dir . '/' . $path ) : rename( $upload['tmp'], $dir . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- only for the test's built uploads (is_upload()).
				if ( ! $ok ) {
					return null;
				}
				$out[ $field ][] = array(
					'id'   => $id,
					'name' => self::display_name( $upload['name'], $upload['ext'] ),
					'size' => (int) $upload['size'],
					'type' => $upload['ext'],
					'path' => $path,
					'at'   => gmdate( 'c' ),
				);
			}
		}
		return $out;
	}

	/** Deletes the stored files of a list (from the data of a submission). */
	public static function delete( array $files ): void {
		$dir = self::dir();
		if ( '' === $dir ) {
			return;
		}
		foreach ( $files as $file ) {
			$path = (string) ( $file['path'] ?? '' );
			if ( preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}$#', $path ) && is_file( $dir . '/' . $path ) ) {
				wp_delete_file( $dir . '/' . $path );
			}
		}
	}

	/** Every stored file of a submission row (decoded), whatever field it is in. */
	public static function of_row( array $row ): array {
		$files = array();
		foreach ( (array) $row['data'] as $field ) {
			if ( 'file' === ( $field['type'] ?? '' ) ) {
				$files = array_merge( $files, (array) ( $field['files'] ?? array() ) );
			}
		}
		return $files;
	}

	/** One file of a row by its id, or null. */
	public static function find( array $row, string $id ): ?array {
		foreach ( self::of_row( $row ) as $file ) {
			if ( hash_equals( (string) $file['id'], $id ) ) {
				return $file;
			}
		}
		return null;
	}

	/** Sends the file to the browser as a download and ends the request. */
	public static function download( array $file ): void {
		$path = self::dir() . '/' . (string) $file['path'];
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}$#', (string) $file['path'] ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'The file is no longer available.', 'estrategiaweb-toolkit' ), 404 );
		}
		$name = (string) $file['name'];
		nocache_headers();
		header( 'Content-Type: ' . ( self::TYPES[ $file['type'] ] ?? 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', remove_accents( $name ) ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
