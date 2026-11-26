<?php
namespace EstrategiaWeb\Migraciones;

defined( 'ABSPATH' ) || exit;

/**
 * Base of every migration. aplicar() returns a sentence saying what it did
 * (it is stored); deshacer() is optional. The helpers are the usual things.
 */
abstract class Migracion {

	/**
	 * Does the change. Return what it did; throw or return false to fail.
	 *
	 * @return string|false
	 */
	abstract public function aplicar();

	/**
	 * Undoes it, if it can.
	 *
	 * @return string|false
	 */
	public function deshacer() {
		return false;
	}

	/**
	 * Creates or updates a post by slug and type. $datos: wp_insert_post args.
	 *
	 * @return int The post id.
	 */
	protected function entrada( string $slug, string $tipo, array $datos ): int {
		$ids  = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => $tipo,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$args = array_merge( $datos, array( 'post_name' => $slug, 'post_type' => $tipo ) );
		if ( $ids ) {
			$args['ID'] = (int) $ids[0];
		} elseif ( ! isset( $args['post_status'] ) ) {
			$args['post_status'] = 'publish';
		}
		$id = wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return (int) $id;
	}

	/**
	 * Imports a file (path or URL) into the media library, with alt text.
	 * Run twice it does not duplicate: it reuses the attachment imported from
	 * the same source (meta _estrategia_web_migracion_origen).
	 *
	 * @return int The attachment id.
	 */
	protected function medio( string $origen, string $alt = '', int $entrada = 0 ): int {
		$previo = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_estrategia_web_migracion_origen', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $origen, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( $previo ) {
			$id = (int) $previo[0];
		} else {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$id = preg_match( '#^https?://#', $origen )
				? media_sideload_image( $origen, $entrada, null, 'id' )
				: $this->copiar_local( $origen, $entrada );
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( $id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			update_post_meta( $id, '_estrategia_web_migracion_origen', $origen );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return (int) $id;
	}

	private function copiar_local( string $ruta, int $entrada ) {
		if ( ! is_readable( $ruta ) ) {
			return new \WP_Error( 'migracion_medio', 'File not readable: ' . $ruta );
		}
		$tmp = wp_tempnam( $ruta );
		copy( $ruta, $tmp );
		$id = media_handle_sideload( array( 'name' => basename( $ruta ), 'tmp_name' => $tmp ), $entrada );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
		}
		return $id;
	}

	/**
	 * Sets post meta (several at once).
	 */
	protected function meta( int $entrada, array $campos ): void {
		foreach ( $campos as $clave => $valor ) {
			update_post_meta( $entrada, $clave, $valor );
		}
	}

	/**
	 * Sets menu_order of posts: $ids in the order they should have (10, 20…).
	 */
	protected function orden( array $ids ): void {
		$n = 0;
		foreach ( $ids as $id ) {
			$n += 10;
			wp_update_post( array( 'ID' => (int) $id, 'menu_order' => $n ) );
		}
	}
}
