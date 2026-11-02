<?php
defined( 'ABSPATH' ) || exit;

/**
 * When the plugin is DELETED (not deactivated). The Mailchimp key goes
 * ALWAYS: a credential with no plugin to use it is only a risk. The rest of
 * the option (audience, featured category, the site's templates) is the
 * site's design and stays unless wp-config.php asks for it:
 *
 *     define( 'ESTRATEGIA_WEB_NEWSLETTER_REMOVE_DATA', true );
 *
 * How to maintain: a new option of the module is deleted here, in the same
 * commit; a new secret goes in the «always» part.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ESTRATEGIA_WEB_NEWSLETTER_REMOVE_DATA' ) && true === ESTRATEGIA_WEB_NEWSLETTER_REMOVE_DATA ) {
	delete_option( 'estrategia_web_newsletter' );
	return;
}

$estrategia_web_newsletter = get_option( 'estrategia_web_newsletter' );
if ( is_array( $estrategia_web_newsletter ) && isset( $estrategia_web_newsletter['api_key'] ) ) {
	$estrategia_web_newsletter['api_key'] = '';
	update_option( 'estrategia_web_newsletter', $estrategia_web_newsletter );
}
