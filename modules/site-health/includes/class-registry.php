<?php
namespace EstrategiaWeb\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * The registry: loads the checks of checks/ and hands them to Site Health.
 *
 * THE CONTRACT OF A CHECK. A file checks/<id>.php returns an array:
 *
 *     return array(
 *         'id'      => 'company-details',            // = the file name, [a-z0-9-]
 *         'async'   => false,                        // true if gather() leaves the request
 *         'title'   => static fn() => __( '…' ),     // the test's name (Site Health lists it)
 *         'applies' => static fn() => true,          // optional: false = not registered at all
 *         'gather'  => static function (): array {}, // reads the site; the ONLY part that touches WordPress
 *         'judge'   => static function ( array $data ): array {
 *             return array(
 *                 'status'      => 'good' | 'recommended' | 'critical',
 *                 'label'       => '…',            // the result's title, plain text
 *                 'description' => '…',            // why it matters, plain text
 *                 'items'       => array( '…' ),   // optional: what exactly is wrong, plain text
 *                 'actions'     => array( array( 'path' => 'options-reading.php', 'text' => '…' ) ),
 *             );
 *         },
 *     );
 *
 * judge() is pure (only __() and friends): the level 1 tests call it with
 * arrays (tests/judge.test.mjs). An action's «path» is relative to wp-admin
 * (admin_url()); «url» instead of «path» is an absolute link, «path» and
 * «url» both missing is a plain sentence (DMARC: the fix is in the DNS).
 * Everything is escaped HERE (format()): a check returns plain text, never
 * HTML, so a check cannot open an XSS hole on the Site Health screen.
 *
 * Site Health's names: the test is «estrategia_web_<id with _>», direct ones
 * in $tests['direct'] with a callable, async ones in $tests['async'] with
 * «has_rest» and the URL of OUR route (GET estrategia-web/v1/site-health/<id>,
 * capability view_site_health_checks, the one core's own routes ask), plus
 * «async_direct_test» so WordPress' weekly cron can run them without
 * JavaScript.
 *
 * How to maintain:
 * - Who and when: whoever changes the contract changes it here and in EVERY
 *   check file in the same commit; tests/registry.test.mjs reads every file
 *   and fails on one that does not follow it.
 * - Edge case, by name: a check file that is not an array, has an id that is
 *   not its file name or no judge() is SKIPPED (not fatal) and
 *   registry.test.mjs goes red: a broken check must not take the screen down
 *   on a real site, but it must not reach a release either.
 * - The trap: Site Health renders «description» and «actions» as HTML and
 *   expects «test» to be the same key as the one registered; a result whose
 *   «test» differs is shown but its status is not counted in the summary.
 */
final class Registry {

	public const NS    = 'estrategia-web/v1';
	public const ROUTE = '/site-health/(?P<check>[a-z0-9-]+)';
	public const BADGE = 'Estrategia Web'; // A brand: not translated.

	/** @var array<string, array>|null */
	private static ?array $checks = null;

	public static function hook(): void {
		add_filter( 'site_status_tests', static fn( $tests ) => self::tests( (array) $tests, self::checks() ) );
		add_action( 'rest_api_init', array( self::class, 'rest' ) );
	}

	/** The valid checks of checks/, by id, in file name order. @return array<string, array> */
	public static function checks( ?string $dir = null ): array {
		if ( null !== self::$checks && null === $dir ) {
			return self::$checks;
		}
		$out   = array();
		$files = glob( ( $dir ?? dirname( __DIR__ ) . '/checks' ) . '/*.php' ) ?: array();
		sort( $files );
		foreach ( $files as $file ) {
			$check = require $file;
			if ( self::valid( $check, basename( $file, '.php' ) ) ) {
				$out[ $check['id'] ] = $check;
			}
		}
		if ( null === $dir ) {
			self::$checks = $out;
		}
		return $out;
	}

	/** Whether a check file follows the contract (see the header). */
	public static function valid( mixed $check, string $file_id ): bool {
		return is_array( $check )
			&& ( $check['id'] ?? null ) === $file_id
			&& preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $file_id )
			&& is_bool( $check['async'] ?? null )
			&& is_callable( $check['title'] ?? null )
			&& is_callable( $check['gather'] ?? null )
			&& is_callable( $check['judge'] ?? null )
			&& ( ! isset( $check['applies'] ) || is_callable( $check['applies'] ) );
	}

	/** Site Health's test key for a check id. */
	public static function key( string $id ): string {
		return 'estrategia_web_' . str_replace( '-', '_', $id );
	}

	/**
	 * Adds the checks that apply to Site Health's list.
	 *
	 * @param array $tests  What site_status_tests receives (direct and async).
	 * @param array $checks Checks by id.
	 */
	public static function tests( array $tests, array $checks ): array {
		foreach ( $checks as $id => $check ) {
			if ( isset( $check['applies'] ) && ! ( $check['applies'] )() ) {
				continue;
			}
			$key = self::key( $id );
			if ( $check['async'] ) {
				$tests['async'][ $key ] = array(
					'label'             => ( $check['title'] )(),
					'test'              => rest_url( self::NS . '/site-health/' . $id ),
					'has_rest'          => true,
					'async_direct_test' => static fn() => self::result( $check ),
				);
			} else {
				$tests['direct'][ $key ] = array(
					'label' => ( $check['title'] )(),
					'test'  => static fn() => self::result( $check ),
				);
			}
		}
		return $tests;
	}

	/** Runs one check: gather, judge, format. A check that throws is «could not check». */
	public static function result( array $check ): array {
		try {
			$verdict = ( $check['judge'] )( (array) ( $check['gather'] )() );
		} catch ( \Throwable $e ) {
			$verdict = array(
				'status'      => 'recommended',
				/* translators: %s: the name of a Site Health check. */
				'label'       => sprintf( __( 'Could not check: %s', 'estrategiaweb-toolkit' ), ( $check['title'] )() ),
				'description' => __( 'The check stopped with an error. Nothing on the site was changed; the error is below.', 'estrategiaweb-toolkit' ),
				'items'       => array( $e->getMessage() ),
			);
		}
		return self::format( $check['id'], (array) $verdict );
	}

	/** A check's verdict in Site Health's format, escaped. */
	public static function format( string $id, array $verdict ): array {
		$status = in_array( $verdict['status'] ?? '', array( 'good', 'recommended', 'critical' ), true ) ? $verdict['status'] : 'recommended';
		$html   = '<p>' . esc_html( (string) ( $verdict['description'] ?? '' ) ) . '</p>';
		$items  = array_filter( array_map( 'strval', (array) ( $verdict['items'] ?? array() ) ), 'strlen' );
		if ( $items ) {
			$html .= '<ul>' . implode( '', array_map( static fn( $i ) => '<li>' . esc_html( $i ) . '</li>', $items ) ) . '</ul>';
		}
		$actions = '';
		foreach ( (array) ( $verdict['actions'] ?? array() ) as $action ) {
			$text = esc_html( (string) ( $action['text'] ?? '' ) );
			$url  = isset( $action['path'] ) ? admin_url( (string) $action['path'] ) : (string) ( $action['url'] ?? '' );
			$actions .= '' === $url ? '<p>' . $text . '</p>' : '<p><a href="' . esc_url( $url ) . '">' . $text . '</a></p>';
		}
		return array(
			'label'       => (string) ( $verdict['label'] ?? '' ),
			'status'      => $status,
			'badge'       => array(
				'label' => self::BADGE,
				'color' => 'blue',
			),
			'description' => $html,
			'actions'     => $actions,
			'test'        => self::key( $id ),
		);
	}

	public static function rest(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn() => current_user_can( 'view_site_health_checks' ),
				'callback'            => static function ( \WP_REST_Request $request ) {
					$check = self::checks()[ (string) $request['check'] ] ?? null;
					if ( ! $check || ! $check['async'] || ( isset( $check['applies'] ) && ! ( $check['applies'] )() ) ) {
						return new \WP_Error( 'rest_not_found', 'No such check.', array( 'status' => 404 ) );
					}
					return self::result( $check );
				},
			)
		);
	}
}
