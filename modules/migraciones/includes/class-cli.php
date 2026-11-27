<?php
namespace EstrategiaWeb\Migraciones;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the child theme's content migrations.
 */
final class Cli {

	/**
	 * Lists applied and pending migrations.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estrategia estado
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Unused.
	 */
	public function estado( $args, $assoc ) {
		$files = Runner::files();
		$done  = Runner::done();
		$rows  = array();
		foreach ( $files as $name => $path ) {
			$d      = $done[ $name ] ?? null;
			$rows[] = array(
				'migracion' => $name,
				'estado'    => $d ? ( 'ok' === $d['resultado'] ? 'aplicada' : 'FALLIDA' ) : 'pendiente',
				'fecha'     => $d['fecha'] ?? '',
				'detalle'   => $d['detalle'] ?? '',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'migracion', 'estado', 'fecha', 'detalle' ) );
	}

	/**
	 * Applies the pending migrations, in order, stopping at the first failure (exit code 1, so the deploy goes red). Safe to repeat: what is applied is not applied again.
	 *
	 * ## OPTIONS
	 *
	 * [--seco]
	 * : Only lists what would be applied.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estrategia migrar --seco
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Flags.
	 */
	public function migrar( $args, $assoc ) {
		$dry = ! empty( $assoc['seco'] );
		$res = Runner::run( $dry );
		foreach ( $res['applied'] as $name ) {
			\WP_CLI::log( ( $dry ? 'Se aplicaría: ' : 'Aplicada: ' ) . $name );
		}
		if ( 'locked' === $res['status'] ) {
			\WP_CLI::error( 'Otra ejecución tiene el bloqueo (caduca a los 5 minutos).' );
		}
		if ( 'error' === $res['status'] ) {
			\WP_CLI::error( sprintf( 'Falló %s: %s', $res['failed'], $res['detail'] ) );
		}
		\WP_CLI::success( $dry ? 'Fin del ensayo.' : 'Hecho.' );
	}
}
