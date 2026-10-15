<?php
/**
 * estrategia-web/social-links — the site's social networks.
 *
 * What it renders: a list (<ul>) with one link per network, in the order of
 * “Estrategia Web → Social networks” (option estrategia_web_social_links; see
 * modules/social/includes/class-links.php). No JavaScript. If there are no
 * networks, it renders nothing on the site; in the editor, a notice saying
 * where to add them.
 *
 * Attributes:
 * - display: “icons” (icons only) or “icons-names” (icon and name).
 * - size: small / medium / large → 1.25em / 1.5em / 2em, in the variable
 *   --estrategia-web-social-size (a theme changes it without touching this).
 * Plus the built-in ones: text colour (the icons take it, see below), font
 * size (the em values above follow it) and margin.
 *
 * Decisions:
 * - COLOUR: icons are <svg fill="currentColor"> and the link has
 *   color: inherit, so they take the text colour wherever the block is
 *   placed (white in a dark footer) with no configuration. They are not
 *   painted in each brand's colours: that is a site's design, not the plugin's.
 * - ACCESSIBLE: each link's name is “Instagram of <Company>” (the name from
 *   “Company details”, or the site's) plus “(opens in a new tab)”, in
 *   visually hidden text and not in aria-label, so automatic translators
 *   translate it (like the WhatsApp button). In “icons-names” the visible
 *   name is aria-hidden so it is not read twice; the hidden one starts with
 *   it (WCAG 2.5.3, label in name).
 * - New tab with rel="noopener noreferrer" only on http(s) links: a mailto:
 *   or a tel: opens no page.
 * - Touch target of at least 44 px (WCAG 2.5.8 asks for 24; 44 is Apple's
 *   and what mobiles expect), even if the icon is small.
 *
 * How to maintain:
 * - Edge case: “white in the footer and in brand colour on the contact
 *   page” is design: block colour in the footer, and for the brand colour,
 *   theme CSS via .is-network-<slug>. No per-brand attribute is added.
 * - The trap: the editor preview is rendered by THIS file via REST
 *   (autoRegister → ServerSideRender). A change here shows in both places;
 *   an error here breaks the editor too.
 *
 * @package estrategia-web
 *
 * @var array $attributes Block attributes.
 */

use EstrategiaWeb\Social\Links;

defined( 'ABSPATH' ) || exit;

$estrategia_web_items = Links::resolved( 'estrategia-web-social-links__icon' );

if ( ! $estrategia_web_items ) {
	if ( wp_is_serving_rest_request() ) {
		printf(
			'<p %s>%s</p>',
			get_block_wrapper_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html__( 'Social networks: add them in Estrategia Web › Social networks.', 'estrategia-web' )
		);
	}
	return;
}

$estrategia_web_display = in_array( $attributes['display'] ?? '', array( 'icons', 'icons-names' ), true ) ? $attributes['display'] : 'icons';
$estrategia_web_size    = in_array( $attributes['size'] ?? '', array( 'small', 'medium', 'large' ), true ) ? $attributes['size'] : 'medium';
$estrategia_web_owner   = Links::owner();
?>
<ul <?php echo get_block_wrapper_attributes( array( 'class' => 'estrategia-web-social-links is-display-' . $estrategia_web_display . ' is-size-' . $estrategia_web_size ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php foreach ( $estrategia_web_items as $estrategia_web_item ) : ?>
		<?php
		/* translators: 1: network name («Instagram»), 2: company name. */
		$estrategia_web_label = sprintf( __( '%1$s of %2$s', 'estrategia-web' ), $estrategia_web_item['name'], $estrategia_web_owner );
		if ( $estrategia_web_item['new_tab'] ) {
			$estrategia_web_label .= ' ' . __( '(opens in a new tab)', 'estrategia-web' );
		}
		?>
		<li class="estrategia-web-social-links__item is-network-<?php echo esc_attr( $estrategia_web_item['icon'] ); ?>">
			<a class="estrategia-web-social-links__link" href="<?php echo esc_url( $estrategia_web_item['url'], Links::PROTOCOLS ); ?>"<?php echo $estrategia_web_item['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
				<?php echo $estrategia_web_item['svg']; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG from the collection or sanitized by Svg_Sanitizer (Icons). ?>
				<?php if ( 'icons-names' === $estrategia_web_display ) : ?>
					<span class="estrategia-web-social-links__name" aria-hidden="true"><?php echo esc_html( $estrategia_web_item['name'] ); ?></span>
				<?php endif; ?>
				<span class="estrategia-web-social-links__sr"><?php echo esc_html( $estrategia_web_label ); ?></span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
