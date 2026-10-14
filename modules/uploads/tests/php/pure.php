<?php
/**
 * Lo llama modules/uploads/tests/uploads-pure.test.mjs con el PHP de la
 * máquina (sin WordPress). Un caso nuevo es una clave nueva aquí y su assert allí.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/class-uploads.php';

use EstrategiaWeb\Uploads\Uploads;

$mb = 1024 * 1024;
echo json_encode(
	array(
		'defaults'       => Uploads::sanitize( null ),
		'partial'        => Uploads::sanitize( array( 'delete_original' => false ) ),
		'clamped'        => Uploads::sanitize( array( 'max_mb' => -3, 'threshold' => 100 ) ),
		'never'          => Uploads::sanitize( array( 'threshold' => 0 ) ),
		'text'           => Uploads::sanitize( array( 'max_mb' => 'mucho', 'threshold' => '3000', 'delete_original' => '0' ) ),
		'form_unchecked' => Uploads::sanitize_form( array( '_form' => '1', 'max_mb' => '30', 'threshold' => '2560' ) ),
		'form_checked'   => Uploads::sanitize_form( array( '_form' => '1', 'delete_original' => '1' ) ),
		'limit_php_big'  => Uploads::limit_for( 256 * $mb, Uploads::DEFAULTS ) / $mb,
		'limit_php_low'  => Uploads::limit_for( 8 * $mb, Uploads::DEFAULTS ) / $mb,
		'limit_zero'     => Uploads::limit_for( 256 * $mb, array( 'max_mb' => 0 ) + Uploads::DEFAULTS ) / $mb,
	)
);
