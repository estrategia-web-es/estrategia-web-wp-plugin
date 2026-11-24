<?php
/**
 * 0003 — context_post_id: the ENTRY a submission was sent from (2.14.0,
 * 24-11-2026; the entry markers, class-markers.php).
 *
 * It is the id that came SIGNED with the form (Token::context()) and still
 * pointed to a public entry when the submission arrived (Rest::context_post()).
 * 0 = no context: the existing rows, forms outside a singular view, and a
 * signature that did not verify. The detail of a submission links to it
 * (admin/class-admin.php).
 *
 * Why a column and not the existing post_id: post_id is the definition's
 * (the queried object when the form was rendered, which on an archive is a
 * TERM id) and the dossier uses it to rebuild its link. The context is
 * another thing, checked on arrival; mixing them would make one of the two
 * lie on the day they differ.
 *
 * Same CREATE TABLE as 0002 plus the new column, because that is how dbDelta
 * adds columns (the trap, in class-migrations.php: if its format is not
 * followed it changes nothing without an error; the summary checks).
 */

defined( 'ABSPATH' ) || exit;

return static function ( \wpdb $wpdb ): string {
	$charset     = $wpdb->get_charset_collate();
	$submissions = \EstrategiaWeb\Forms\Migrations::submissions_table();

	$result = dbDelta(
		"CREATE TABLE $submissions (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
form_id varchar(64) NOT NULL DEFAULT '',
form_title varchar(191) NOT NULL DEFAULT '',
definition_hash char(32) NOT NULL DEFAULT '',
post_id bigint(20) unsigned NOT NULL DEFAULT 0,
page_url varchar(2048) NOT NULL DEFAULT '',
lang varchar(20) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
updated_at datetime NULL DEFAULT NULL,
status varchar(20) NOT NULL DEFAULT '',
data longtext NOT NULL,
notifications longtext NOT NULL,
ip varchar(45) NOT NULL DEFAULT '',
user_agent varchar(255) NOT NULL DEFAULT '',
format_version smallint(5) unsigned NOT NULL DEFAULT 1,
access_hash char(64) NOT NULL DEFAULT '',
access_expires datetime NULL DEFAULT NULL,
owner_email varchar(254) NOT NULL DEFAULT '',
complete tinyint(1) unsigned NOT NULL DEFAULT 1,
missing text NULL,
history longtext NULL,
context_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY form_id (form_id),
KEY created_at (created_at),
KEY access_hash (access_hash),
KEY owner_email (owner_email)
) $charset;"
	);

	$columns = $wpdb->get_col( "SHOW COLUMNS FROM $submissions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return sprintf( 'dbDelta: %d changes; context_post_id present: %s', count( $result ), in_array( 'context_post_id', (array) $columns, true ) ? 'yes' : 'NO' );
};
