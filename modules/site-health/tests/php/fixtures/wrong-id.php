<?php
// Fixture for registry.php: its id is not its file name (a file renamed without its id). Not «good-one»:
// a duplicate id would be overwritten by the good one and hide the bug (seen green that way, 26-11-2026).
return array(
	'id'     => 'renamed-check',
	'async'  => false,
	'title'  => static fn() => 'Copied',
	'gather' => static fn() => array(),
	'judge'  => static fn( array $d ) => array( 'status' => 'good' ),
);
