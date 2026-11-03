<?php
// Before the header on purpose: Plugin Check only looks for the ABSPATH guard near the top (see module.php).
namespace EstrategiaWeb\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * The newsletter's settings: option estrategia_web_newsletter, edited in
 * «Estrategia Web › Settings › Newsletter» (a section of the Settings page's
 * registry, includes/admin/class-settings-page.php).
 *
 *   api_key   Mailchimp API key (Account → Extras → API keys). The
 *             datacenter is its suffix (-us21). NOT in REST, never printed
 *             back: the field shows only its last four characters.
 *   list_id   audience to copy from. Empty: the last campaign SENT in the
 *             account, whatever its audience.
 *   featured  slug of the category whose posts the screen proposes first
 *             (Gastronòmicament: «inici», its «Portada»; a post is in it
 *             while it is on the front page). Empty: only the latest.
 *   email     the whole email, with {{blocks}} where the news go.
 *   block     one news block: {{link}} {{image}} {{title}} {{excerpt}}
 *             {{label}} {{more}}.
 *
 * WHY TEMPLATES IN SETTINGS AND NOT «THE LAST CAMPAIGN WITH ITS BLOCKS
 * REPLACED» (02-11-2026). Replacing blocks inside someone else's HTML means
 * guessing where each block starts and ends (atlas's montar-butlleti.mjs
 * looked for the three article links and failed if there were not exactly
 * three), and a campaign edited by hand in Mailchimp breaks the guess
 * silently. A template with a marker is the same for any site, any number of
 * news and any design, and what is sent is exactly what the screen previews.
 * Empty fields use DEFAULT_EMAIL / default_block(): a plain email that
 * already works. Gastronòmicament's own two templates (its 2016 design, cut
 * from the real 04-09-2026 butlletí) are tests/fixtures/gastronomicament-
 * email.html and -block.html, and pure.php proves they rebuild it.
 *
 * How to maintain:
 * - Who and when: whoever changes a site's newsletter design pastes the new
 *   templates here; whoever adds a setting adds it to DEFAULTS, sanitize()
 *   and render_section() in the same commit.
 * - Criterion: what changes from one site to another is a setting; what
 *   changes from one week to another (the news) is the screen.
 * - Edge case, by name: saving the tab with the key field EMPTY keeps the
 *   stored key (the field never shows it); «Forget the key» is the only way
 *   to remove it.
 * - The trap: options.php saves every option of the tab's group, and one
 *   missing from the POST arrives as null (header of class-settings-page.php):
 *   sanitize() keeps what is stored for null and for every missing key. And
 *   the templates are NOT run through kses: email HTML is not page HTML
 *   (kses would strip <html>, <style>, the MSO comments). Only manage_options
 *   saves them, and the screen previews them in a sandboxed iframe.
 */

use EstrategiaWeb\Admin\Admin_UI;
use EstrategiaWeb\Admin\Settings_Page;

final class Settings {

	public const OPTION = 'estrategia_web_newsletter';

	public const DEFAULTS = array(
		'api_key'  => '',
		'list_id'  => '',
		'featured' => '',
		'email'    => '',
		'block'    => '',
	);

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( Settings_Page::HOOK, array( self::class, 'register_section' ) );
	}

	public static function register(): void {
		register_setting(
			Settings_Page::group( 'newsletter' ),
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::DEFAULTS,
				'show_in_rest'      => false, // It holds the API key.
			)
		);
	}

	/** The stored values, complete. */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, array_intersect_key( is_array( $saved ) ? $saved : array(), self::DEFAULTS ) );
	}

	/** The email template in use: the saved one, or the plain default. */
	public static function email_template(): string {
		$s = self::get();
		return '' !== trim( $s['email'] ) ? $s['email'] : self::default_email();
	}

	public static function block_template(): string {
		$s = self::get();
		return '' !== trim( $s['block'] ) ? $s['block'] : self::default_block();
	}

	/** Plain email, 600 px, with Mailchimp's footer tags (unsubscribe and address are compulsory). */
	public static function default_email(): string {
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>*|MC:SUBJECT|*</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f4f4;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;"><tr><td align="center" style="padding:16px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;font-family:Helvetica,Arial,sans-serif;color:#202020;">'
			. '<tr><td style="padding:18px;">' . Rules::BLOCKS . '</td></tr>'
			. '<tr><td style="padding:18px;font-size:12px;line-height:150%;color:#656565;text-align:center;">*|LIST:ADDRESSLINE|*<br><a href="*|UNSUB|*" style="color:#656565;">*|UNSUB|*</a></td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** Plain block. «Read more» in the SITE's language (the email's), not the user's. */
	public static function default_block(): string {
		return '<a href="{{link}}"><img width="564" src="{{image}}" alt="{{title}}" style="display:block;width:100%;max-width:564px;height:auto;border:0;"></a>'
			. '<h3 style="margin:12px 0 0;font-size:20px;line-height:125%;"><a href="{{link}}" style="color:#202020;text-decoration:none;">{{title}}</a></h3>'
			. '<p style="margin:10px 0;font-size:16px;line-height:150%;">{{excerpt}}</p>'
			. '<p style="margin:10px 0;font-size:16px;line-height:150%;">{{label}} – <a href="{{link}}">{{more}}</a></p><hr>';
	}

	/** «Read more» in the site's language. */
	public static function more(): string {
		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_locale() );
		$text     = __( 'Read more', 'estrategiaweb-toolkit' );
		if ( $switched ) {
			restore_previous_locale();
		}
		return $text;
	}

	/**
	 * Merges with what is stored (see the trap in the header).
	 *
	 * @param mixed $value What options.php posts.
	 */
	public static function sanitize( $value ): array {
		$stored = self::get();
		if ( ! is_array( $value ) ) {
			return $stored;
		}
		$out = $stored;
		if ( isset( $value['api_key'] ) && '' !== trim( (string) $value['api_key'] ) ) {
			$key = trim( sanitize_text_field( (string) $value['api_key'] ) );
			if ( null === Rules::datacenter( $key ) ) {
				add_settings_error( self::OPTION, 'api_key', __( 'The Mailchimp key must end in its datacenter (for example -us21). It was not saved.', 'estrategiaweb-toolkit' ) );
			} else {
				$out['api_key'] = $key;
			}
		}
		if ( ! empty( $value['forget_key'] ) ) {
			$out['api_key'] = '';
		}
		foreach ( array( 'list_id', 'featured' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$out[ $key ] = 'featured' === $key ? sanitize_title( (string) $value[ $key ] ) : preg_replace( '/[^a-z0-9]/i', '', (string) $value[ $key ] );
			}
		}
		if ( array_key_exists( 'email', $value ) ) {
			$email = trim( (string) $value['email'] );
			if ( '' !== $email && ! str_contains( $email, Rules::BLOCKS ) ) {
				add_settings_error( self::OPTION, 'email', __( 'The email template must contain {{blocks}}, where the news go. It was not saved.', 'estrategiaweb-toolkit' ) );
			} else {
				$out['email'] = $email;
			}
		}
		if ( array_key_exists( 'block', $value ) ) {
			$out['block'] = trim( (string) $value['block'] );
		}
		return $out;
	}

	/** On Settings_Page::HOOK: the Newsletter tab. */
	public static function register_section(): void {
		Settings_Page::add_tab( 'newsletter', __( 'Newsletter', 'estrategiaweb-toolkit' ), 40 );
		Settings_Page::add_section(
			'newsletter',
			array(
				'id'      => 'newsletter',
				'options' => array( self::OPTION ),
				'render'  => array( self::class, 'render_section' ),
			)
		);
	}

	public static function render_section(): void {
		$s    = self::get();
		$name = static fn( string $key ): string => self::OPTION . '[' . $key . ']';
		Admin_UI::box_open( __( 'Mailchimp', 'estrategiaweb-toolkit' ), 'ew-box-newsletter-mailchimp' );
		?>
		<p><?php esc_html_e( 'The «Newsletter» screen (under Posts) creates the campaign as a DRAFT in Mailchimp, never sends it. Audience, segment and sender are copied from the last campaign sent.', 'estrategiaweb-toolkit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ew-newsletter-key"><?php esc_html_e( 'API key', 'estrategiaweb-toolkit' ); ?></label></th>
				<td>
					<input type="password" class="regular-text code" id="ew-newsletter-key" name="<?php echo esc_attr( $name( 'api_key' ) ); ?>" value="" autocomplete="off" placeholder="<?php echo esc_attr( '' !== $s['api_key'] ? '…' . substr( $s['api_key'], -8 ) : 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21' ); ?>">
					<p class="description">
						<?php
						echo esc_html( '' !== $s['api_key'] ? __( 'Saved. Leave it empty to keep it.', 'estrategiaweb-toolkit' ) : __( 'Mailchimp → Account → Extras → API keys. Without it, the screen only copies the code.', 'estrategiaweb-toolkit' ) );
						?>
					</p>
					<?php if ( '' !== $s['api_key'] ) : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'forget_key' ) ); ?>" value="1"> <?php esc_html_e( 'Forget the key', 'estrategiaweb-toolkit' ); ?></label>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ew-newsletter-list"><?php esc_html_e( 'Audience ID', 'estrategiaweb-toolkit' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="ew-newsletter-list" name="<?php echo esc_attr( $name( 'list_id' ) ); ?>" value="<?php echo esc_attr( $s['list_id'] ); ?>">
					<p class="description"><?php esc_html_e( 'Optional. Empty: the audience of the last campaign sent from the account.', 'estrategiaweb-toolkit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ew-newsletter-featured"><?php esc_html_e( 'Featured category', 'estrategiaweb-toolkit' ); ?></label></th>
				<td>
					<input type="text" class="regular-text code" id="ew-newsletter-featured" name="<?php echo esc_attr( $name( 'featured' ) ); ?>" value="<?php echo esc_attr( $s['featured'] ); ?>">
					<p class="description"><?php esc_html_e( 'Slug of the category whose posts are proposed first (the front page one). Empty: only the latest posts.', 'estrategiaweb-toolkit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		Admin_UI::box_close();
		Admin_UI::box_open( __( 'Templates', 'estrategiaweb-toolkit' ), 'ew-box-newsletter-templates' );
		?>
		<p><?php esc_html_e( 'Empty fields use a plain design. Email: the whole HTML, with {{blocks}} where the news go. Block: one piece of news, with {{link}}, {{image}}, {{title}}, {{excerpt}}, {{label}} and {{more}} («Read more» in the site language).', 'estrategiaweb-toolkit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ew-newsletter-email"><?php esc_html_e( 'Email', 'estrategiaweb-toolkit' ); ?></label></th>
				<td><textarea class="large-text code" rows="8" id="ew-newsletter-email" name="<?php echo esc_attr( $name( 'email' ) ); ?>"><?php echo esc_textarea( $s['email'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="ew-newsletter-block"><?php esc_html_e( 'Block', 'estrategiaweb-toolkit' ); ?></label></th>
				<td><textarea class="large-text code" rows="6" id="ew-newsletter-block" name="<?php echo esc_attr( $name( 'block' ) ); ?>"><?php echo esc_textarea( $s['block'] ); ?></textarea></td>
			</tr>
		</table>
		<?php
		Admin_UI::box_close();
	}
}
