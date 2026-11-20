<?php
/**
 * 0002 — The columns of the dossier mode (2.11.0) and of the files.
 *
 * A «dossier» is a submission its sender can come back to with a private
 * link and complete later (Blocks / Dossier). Every new column has a default
 * that means «not a dossier», so the rows that already exist, and every
 * form that does not switch the mode on, stay exactly as they were:
 *
 * - updated_at: last change by anyone (the client saving, the team asking
 *   for something). NULL = never changed after being sent. The retention
 *   counts from here when it is set (Submissions::purge_older_than()).
 * - access_hash: sha256 of the private link's token, hex. The token itself
 *   is never stored: whoever copies the table cannot open any dossier.
 *   '' = no link (not a dossier, or revoked).
 * - access_expires: when the link stops working (UTC).
 * - owner_email: where the link goes, and the only address the «send me my
 *   link» form will ever send it to.
 * - complete: 1 = nothing required is missing. Plain forms are always 1.
 * - missing: JSON list of the field names still missing.
 * - history: JSON list of {at, by, what, fields}.
 *
 * Same CREATE TABLE as 0001 plus the new columns, because that is how
 * dbDelta adds columns. The trap (in class-migrations.php): its format is
 * picky, and if it is not followed it changes nothing without an error;
 * that is why the summary counts the columns that are really there.
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
PRIMARY KEY  (id),
KEY form_id (form_id),
KEY created_at (created_at),
KEY access_hash (access_hash),
KEY owner_email (owner_email)
) $charset;"
	);

	$columns = $wpdb->get_col( "SHOW COLUMNS FROM $submissions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wanted  = array( 'updated_at', 'access_hash', 'access_expires', 'owner_email', 'complete', 'missing', 'history' );
	$present = count( array_intersect( $wanted, (array) $columns ) );
	return sprintf( 'dbDelta: %d changes; dossier columns present: %d of %d', count( $result ), $present, count( $wanted ) );
};
