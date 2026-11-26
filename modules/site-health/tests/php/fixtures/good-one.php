<?php
// Fixture for registry.php: a check that follows the contract (includes/class-registry.php).
return array(
	'id'     => 'good-one',
	'async'  => false,
	'title'  => static fn() => 'Good one',
	'gather' => static fn() => array(),
	'judge'  => static fn( array $d ) => array( 'status' => 'good', 'label' => 'Fine' ),
);
