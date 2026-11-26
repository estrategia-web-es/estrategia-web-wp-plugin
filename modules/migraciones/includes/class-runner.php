<?php
namespace EstrategiaWeb\Migraciones;

defined( 'ABSPATH' ) || exit;

/**
 * Finds, orders and applies the migrations of the active theme. The what, why
 * and how to maintain: modules/migraciones/module.php.
 */
final class Runner {

	const OPT_DONE    = 'estrategia_web_migraciones';
	const OPT_VERSION = 'estrategia_web_migraciones_version';
	const OPT_LOCK    = 'estrategia_web_migraciones_lock';
	const LOCK_TTL    = 300;

	/**
	 * Hooked to init: runs when the theme's version changed.
	 */
	public static function on_init(): void {
		$version = (string) wp_get_theme()->get( 'Version' );
		if ( '' === $version || get_option( self::OPT_VERSION ) === $version ) {
			return;
		}
		$res = self::run();
		if ( 'locked' !== $res['status'] ) {
			update_option( self::OPT_VERSION, $version, false );
		}
	}

	/**
	 * Migration files of the active theme, in name order: name => path.
	 *
	 * @return array<string,string>
	 */
	public static function files( ?string $dir = null ): array {
		$dir   = $dir ?? get_stylesheet_directory() . '/migraciones';
		$found = array();
		foreach ( (array) glob( rtrim( $dir, '/' ) . '/*.php' ) as $path ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}-[a-z0-9-]+\.php$/', basename( $path ) ) ) {
				$found[ basename( $path, '.php' ) ] = $path;
			}
		}
		ksort( $found, SORT_STRING );
		return $found;
	}

	/**
	 * What is stored: name => array( fecha, resultado ok|error, detalle ).
	 */
	public static function done(): array {
		return (array) get_option( self::OPT_DONE, array() );
	}

	/**
	 * Pending files: those never applied successfully, in order.
	 */
	public static function pending( array $files, array $done ): array {
		return array_diff_key( $files, array_filter( $done, static fn( $d ) => 'ok' === ( $d['resultado'] ?? '' ) ) );
	}

	/**
	 * Applies the pending ones in order; stops at the first failure.
	 *
	 * @return array{status:string,applied:string[],failed:string,detail:string}
	 */
	public static function run( bool $dry = false, ?array $files = null ): array {
		$out     = array( 'status' => 'ok', 'applied' => array(), 'failed' => '', 'detail' => '' );
		$pending = self::pending( $files ?? self::files(), self::done() );
		if ( $dry || ! $pending ) {
			$out['applied'] = array_keys( $pending );
			return $out;
		}
		if ( ! self::lock() ) {
			$out['status'] = 'locked';
			return $out;
		}
		try {
			foreach ( $pending as $name => $path ) {
				$res = self::apply_one( $name, $path );
				if ( 'ok' !== $res[0] ) {
					$out['status'] = 'error';
					$out['failed'] = $name;
					$out['detail'] = $res[1];
					break;
				}
				$out['applied'][] = $name;
			}
		} finally {
			self::unlock();
		}
		if ( $out['applied'] && function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		return $out;
	}

	/**
	 * Applies one file and records it.
	 *
	 * @return array{0:string,1:string} result and detail.
	 */
	private static function apply_one( string $name, string $path ): array {
		try {
			$m = require $path;
			if ( ! $m instanceof Migracion ) {
				throw new \RuntimeException( 'The file must return an object that extends Migracion.' );
			}
			$detail = $m->aplicar();
			if ( false === $detail ) {
				throw new \RuntimeException( 'aplicar() returned false.' );
			}
			$result = array( 'ok', (string) $detail );
		} catch ( \Throwable $e ) {
			$result = array( 'error', $e->getMessage() );
		}
		$done          = self::done();
		$done[ $name ] = array(
			'fecha'     => gmdate( 'Y-m-d H:i:s' ),
			'resultado' => $result[0],
			'detalle'   => $result[1],
		);
		update_option( self::OPT_DONE, $done, false );
		error_log( sprintf( 'estrategia-web migraciones: %s %s %s', $name, $result[0], $result[1] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		do_action( 'estrategia_web_migracion', $name, $result[0], $result[1] );
		return $result;
	}

	/**
	 * Atomic lock: add_option fails if the row exists. A lock older than
	 * LOCK_TTL is taken as left by a dead request and replaced.
	 */
	private static function lock(): bool {
		if ( add_option( self::OPT_LOCK, time(), '', false ) ) {
			return true;
		}
		if ( time() - (int) get_option( self::OPT_LOCK ) > self::LOCK_TTL ) {
			delete_option( self::OPT_LOCK );
			return add_option( self::OPT_LOCK, time(), '', false );
		}
		return false;
	}

	private static function unlock(): void {
		delete_option( self::OPT_LOCK );
	}
}
