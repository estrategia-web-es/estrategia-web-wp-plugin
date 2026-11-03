<?php
namespace EstrategiaWeb\Llms;

defined( 'ABSPATH' ) || exit;

/**
 * The pure part of llms.txt: no WordPress, so level 1 tests it with the
 * machine's PHP (tests/php/text.php).
 *
 * - language_of(): is this request a llms.txt, and of which language?
 * - body(): what is served (the text plus «Other languages»).
 * - draft(): the first draft from Company details and the pages.
 * - clean() / merge(): what is saved.
 *
 * How to maintain: a new public function gets its case in tests/php/text.php
 * and its test in tests/text.test.mjs. The trap of clean(): the text is
 * Markdown served as text/plain, so it is NOT run through
 * sanitize_textarea_field(), which deletes every «%xx» (it would break a
 * percent-encoded URL such as /dise%C3%B1o/) and every tag-looking «<…>».
 * It is escaped where it is printed (the textarea), and served with nosniff.
 */
final class Text {

	/** A WPML language code: es, en, pt-br, zh-hans. */
	public const CODE = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/';

	/**
	 * Which llms.txt a request asks for.
	 *
	 * @param string   $path      The request's path, without the query (/en/llms.txt).
	 * @param string   $home_path The path of the home URL ('' or '/sub').
	 * @param string[] $codes     Language codes that may come as a first directory (WPML in directories), [] otherwise.
	 * @return string|null '' for the root /llms.txt, the code for /<code>/llms.txt, null if it is not a llms.txt.
	 */
	public static function language_of( string $path, string $home_path, array $codes ): ?string {
		$home_path = rtrim( $home_path, '/' );
		if ( '' !== $home_path ) {
			if ( ! str_starts_with( $path, $home_path . '/' ) ) {
				return null;
			}
			$path = substr( $path, strlen( $home_path ) );
		}
		if ( '/llms.txt' === $path ) {
			return '';
		}
		if ( preg_match( '#^/([^/]+)/llms\.txt$#', $path, $m ) && in_array( $m[1], $codes, true ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * The file served: the text, and, when $others is not empty, a last
	 * section linking the other languages' files.
	 *
	 * @param string                                   $text    The language's text.
	 * @param string                                   $heading «Other languages», in the file's language.
	 * @param array<int, array{name: string, url: string}> $others The other languages that have a text.
	 */
	public static function body( string $text, string $heading, array $others ): string {
		$out = rtrim( $text ) . "\n";
		if ( $others ) {
			$out .= "\n## " . $heading . "\n\n";
			foreach ( $others as $other ) {
				$out .= '- [' . $other['name'] . '](' . $other['url'] . ")\n";
			}
		}
		return $out;
	}

	/**
	 * The first draft, in llmstxt.org's shape: «# name», «> summary», the
	 * company's facts on one line, and the pages as a list of links.
	 *
	 * @param array{name: string, summary: string, facts: string[], heading: string, pages: array<int, array{title: string, url: string, description: string}>} $d
	 */
	public static function draft( array $d ): string {
		$line = static fn( string $s ): string => trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
		$out  = '# ' . $line( $d['name'] ) . "\n";
		if ( '' !== $line( $d['summary'] ) ) {
			$out .= "\n> " . $line( $d['summary'] ) . "\n";
		}
		$facts = array_values( array_filter( array_map( $line, $d['facts'] ), 'strlen' ) );
		if ( $facts ) {
			$out .= "\n" . implode( ' · ', $facts ) . "\n";
		}
		if ( $d['pages'] ) {
			$out .= "\n## " . $d['heading'] . "\n\n";
			foreach ( $d['pages'] as $page ) {
				$desc = $line( $page['description'] );
				$out .= '- [' . $line( $page['title'] ) . '](' . $page['url'] . ')' . ( '' !== $desc ? ': ' . $desc : '' ) . "\n";
			}
		}
		return $out;
	}

	/** One language's text as it is saved: valid UTF-8, \n line ends, no NUL, trimmed. */
	public static function clean( mixed $value ): string {
		$text = is_string( $value ) ? $value : '';
		if ( ! preg_match( '//u', $text ) ) {
			return '';
		}
		$text = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $text );
		return trim( $text );
	}

	/**
	 * What is saved: the stored texts with the ones that arrive on top. A
	 * language that does not arrive keeps its text (options.php sends null
	 * for an option whose fields were not printed, header of
	 * includes/admin/class-settings-page.php); an empty one is removed.
	 *
	 * @param mixed $input What arrived ({code: text}).
	 * @param mixed $saved What is stored.
	 * @return array<string, string>
	 */
	public static function merge( mixed $input, mixed $saved ): array {
		$out = array();
		foreach ( array( $saved, $input ) as $source ) {
			foreach ( is_array( $source ) ? $source : array() as $code => $text ) {
				if ( ! is_string( $code ) || ! preg_match( self::CODE, $code ) ) {
					continue;
				}
				$out[ $code ] = self::clean( $text );
			}
		}
		return array_filter( $out, 'strlen' );
	}
}
