<?php
namespace EstrategiaWeb\Llms;

defined( 'ABSPATH' ) || exit;

/**
 * The option and the request: /llms.txt (and /<code>/llms.txt) answered on
 * parse_request with the language's text. Why parse_request and not a
 * rewrite rule or template_redirect: header of module.php, «The trap».
 *
 * What is sent: 200, «text/plain; charset=utf-8», «X-Robots-Tag: noindex»
 * (the file is for assistants, not a result in Google; Rank Math sends the
 * same) and «X-Content-Type-Options: nosniff» (the text is not escaped: it is
 * served as text and must never be read as HTML). HEAD gets the headers only.
 * A language without text is not answered: the request goes on and ends in
 * WordPress's normal 404.
 *
 * How to maintain: a header to add goes in serve(); what the file says is
 * Text::body().
 */
final class Runtime {

	public const OPTION = 'estrategia_web_llms';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'parse_request', array( self::class, 'maybe_serve' ), 0 );
	}

	public static function register(): void {
		register_setting(
			'estrategia_web_llms',
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'The llms.txt text of each language, by language code.', 'estrategiaweb-toolkit' ),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/** @return array<string, string> */
	public static function sanitize( mixed $value ): array {
		return Text::merge( $value, get_option( self::OPTION, array() ) );
	}

	/** @return array<string, string> The saved texts by language code. */
	public static function texts(): array {
		return Text::merge( array(), get_option( self::OPTION, array() ) );
	}

	public static function maybe_serve(): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with fixed paths.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH );
		$code = Text::language_of( $path, $home, Languages::directory_codes() );
		if ( null === $code ) {
			return;
		}
		$code  = '' === $code ? Languages::current() : $code;
		$texts = self::texts();
		if ( ! isset( $texts[ $code ] ) ) {
			return;
		}
		self::serve( self::body( $code, $texts ) );
	}

	/** The file of a language: its text, and the other languages' links in the default one's. */
	public static function body( string $code, array $texts ): string {
		$others = array();
		if ( Languages::default() === $code ) {
			foreach ( Languages::all() as $other => $lang ) {
				if ( $other !== $code && isset( $texts[ $other ] ) ) {
					$others[] = array(
						'name' => '' !== $lang['name'] ? $lang['name'] : $other,
						'url'  => Languages::url( $other ),
					);
				}
			}
		}
		$heading = $others ? Languages::with( $code, static fn(): string => __( 'Other languages', 'estrategiaweb-toolkit' ) ) : '';
		return Text::body( $texts[ $code ], $heading, $others );
	}

	private static function serve( string $body ): void {
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with a fixed string.
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain with nosniff (header of this class).
		}
		exit;
	}
}
