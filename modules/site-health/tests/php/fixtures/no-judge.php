<?php
// Fixture for registry.php: no judge(), and «async» as a string.
return array(
	'id'     => 'no-judge',
	'async'  => 'no',
	'title'  => static fn() => 'No judge',
	'gather' => static fn() => array(),
);
