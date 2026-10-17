<?php
/**
 * «Estrategia Web → Content types»: READ-ONLY. What is declared, where it was
 * read from, and every file that was rejected with its errors. Nothing can be
 * edited here on purpose: the definitions are code, versioned in the site's
 * repo and deployed (see module.php). A client cannot break a type from the
 * admin.
 */

namespace EstrategiaWeb\ContentTypes;

use EstrategiaWeb\Admin\Modules_Page;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG = 'estrategia-web-content-types';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
	}

	public static function menu(): void {
		add_submenu_page( Modules_Page::MENU, __( 'Content types', 'estrategia-web' ), __( 'Content types', 'estrategia-web' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$loaded = Loader::load();
		$locale = determine_locale();
		echo '<div class="wrap estrategia-web-content-types"><h1>' . esc_html__( 'Content types', 'estrategia-web' ) . '</h1>';
		echo '<p>' . esc_html__( 'Content types, taxonomies and fields declared in JSON files of the site. This screen only shows them: they are changed in the files, in the site’s repository.', 'estrategia-web' ) . '</p>';

		echo '<h2>' . esc_html__( 'Folders searched', 'estrategia-web' ) . '</h2><ul class="ul-disc">';
		foreach ( Loader::dirs() as $dir ) {
			$state = is_dir( $dir ) ? __( 'exists', 'estrategia-web' ) : __( 'does not exist', 'estrategia-web' );
			echo '<li><code>' . esc_html( $dir ) . '</code> — ' . esc_html( $state ) . '</li>';
		}
		echo '</ul>';

		if ( $loaded['errors'] ) {
			echo '<h2>' . esc_html__( 'Files with errors (not registered)', 'estrategia-web' ) . '</h2>';
			foreach ( $loaded['errors'] as $file => $errors ) {
				echo '<div class="notice notice-error inline" data-file="' . esc_attr( basename( $file ) ) . '"><p><strong><code>' . esc_html( $file ) . '</code></strong></p><ul class="ul-disc">';
				foreach ( $errors as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			}
		}

		echo '<h2>' . esc_html__( 'Declared types', 'estrategia-web' ) . '</h2>';
		if ( ! $loaded['types'] ) {
			echo '<p>' . esc_html__( 'None. Add a JSON file to one of the folders above (see the example in modules/content-types/examples/).', 'estrategia-web' ) . '</p></div>';
			return;
		}
		$wpml_modes = array(
			'copy'      => __( 'copy', 'estrategia-web' ),
			'translate' => __( 'translate', 'estrategia-web' ),
			'ignore'    => __( 'ignore', 'estrategia-web' ),
		);
		foreach ( $loaded['types'] as $name => $def ) {
			$registered = in_array( $name, Registrar::registered(), true );
			echo '<div class="card" style="max-width:none" data-type="' . esc_attr( $name ) . '">';
			printf( '<h3>%s <code>%s</code></h3>', esc_html( Schema::text( $def['labels']['plural'], $locale ) ), esc_html( $name ) );
			echo '<p>' . esc_html__( 'File:', 'estrategia-web' ) . ' <code>' . esc_html( $loaded['files'][ $name ] ) . '</code><br>';
			echo esc_html__( 'URL:', 'estrategia-web' ) . ' <code>/' . esc_html( Registrar::slug( $def['slug'] ) ) . '/</code>';
			if ( is_array( $def['slug'] ) ) {
				// The per-language ones: WPML slug translations (class-wpml.php sync_slugs()).
				$per_lang = array();
				foreach ( $def['slug'] as $lang => $slug ) {
					$per_lang[] = $lang . ': /' . $slug . '/';
				}
				echo ' (' . esc_html( implode( ' · ', $per_lang ) ) . ')';
			}
			echo ' · ' . esc_html__( 'WPML:', 'estrategia-web' ) . ' ' . esc_html( $def['wpml'] );
			if ( ! $registered ) {
				echo '<br><strong>' . esc_html__( 'Not registered (see the errors above).', 'estrategia-web' ) . '</strong>';
			}
			echo '</p>';
			$taxes = array_map( static fn( $t ) => is_string( $t ) ? $t : $t['name'], $def['taxonomies'] );
			if ( $taxes ) {
				echo '<p>' . esc_html__( 'Taxonomies:', 'estrategia-web' ) . ' <code>' . esc_html( implode( '</code>, <code>', $taxes ) ) . '</code></p>';
			}
			if ( $def['fields'] ) {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Field', 'estrategia-web' ) . '</th><th>' . esc_html__( 'Meta key', 'estrategia-web' ) . '</th><th>' . esc_html__( 'Type', 'estrategia-web' ) . '</th><th>' . esc_html__( 'Required', 'estrategia-web' ) . '</th><th>' . esc_html__( 'WPML', 'estrategia-web' ) . '</th></tr></thead><tbody>';
				foreach ( $def['fields'] as $field ) {
					printf(
						'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
						esc_html( Schema::text( $field['label'], $locale ) ),
						esc_html( $field['name'] ),
						esc_html( $field['type'] ),
						esc_html( $field['required'] ? __( 'yes', 'estrategia-web' ) : '' ),
						esc_html( $wpml_modes[ $field['wpml'] ] )
					);
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}
		echo '</div>';
	}
}
