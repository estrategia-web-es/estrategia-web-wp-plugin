<?php
/**
 * 0001 — Crea las dos tablas: los envíos y las definiciones de formulario.
 *
 * estrategia_web_forms_submissions: un envío por fila. data y notifications son
 * JSON; format_version dice con qué forma se escribió cada fila (fila 14:
 * cada documento sabe su versión), hoy 1.
 *
 * estrategia_web_forms_definitions: la foto, tomada en el servidor al pintar el
 * formulario, de sus campos y sus avisos. El visitante solo manda el hash; el
 * servidor valida y avisa con lo que hay aquí (ver class-definitions.php).
 */

defined( 'ABSPATH' ) || exit;

return static function ( \wpdb $wpdb ): string {
	$charset     = $wpdb->get_charset_collate();
	$submissions = \EstrategiaWeb\Forms\Migrations::submissions_table();
	$definitions = \EstrategiaWeb\Forms\Migrations::definitions_table();

	$result = dbDelta(
		array(
			"CREATE TABLE $submissions (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
form_id varchar(64) NOT NULL DEFAULT '',
form_title varchar(191) NOT NULL DEFAULT '',
definition_hash char(32) NOT NULL DEFAULT '',
post_id bigint(20) unsigned NOT NULL DEFAULT 0,
page_url varchar(2048) NOT NULL DEFAULT '',
lang varchar(20) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
status varchar(20) NOT NULL DEFAULT '',
data longtext NOT NULL,
notifications longtext NOT NULL,
ip varchar(45) NOT NULL DEFAULT '',
user_agent varchar(255) NOT NULL DEFAULT '',
format_version smallint(5) unsigned NOT NULL DEFAULT 1,
PRIMARY KEY  (id),
KEY form_id (form_id),
KEY created_at (created_at)
) $charset;",
			"CREATE TABLE $definitions (
hash char(32) NOT NULL,
form_id varchar(64) NOT NULL DEFAULT '',
form_title varchar(191) NOT NULL DEFAULT '',
post_id bigint(20) unsigned NOT NULL DEFAULT 0,
lang varchar(20) NOT NULL DEFAULT '',
definition longtext NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (hash),
KEY form_id (form_id)
) $charset;",
		)
	);

	$exists = 0;
	foreach ( array( $submissions, $definitions ) as $table ) {
		$exists += (int) ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	}
	return sprintf( 'dbDelta: %d cambios; tablas presentes: %d de 2', count( $result ), $exists );
};
