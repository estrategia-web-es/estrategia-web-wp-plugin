<?php
/**
 * Called by modules/newsletter/tests/pure.test.mjs with the machine's PHP (no
 * WordPress). Runs includes/class-rules.php and writes the results as JSON;
 * the assertions are in the .mjs.
 *
 * The «real» case rebuilds the blocks of Gastronòmicament's butlletí of
 * 04-09-2026 (butlleti-0409.html, made by hand) from its two templates in
 * tests/fixtures/ and the data of its three posts. The leads given here are
 * longer than what was sent, so the 16-word cut is what makes them equal.
 *
 * How to maintain: a new case is a new key here and its assert there. If
 * Gastronòmicament's design changes, its templates in fixtures/ change and
 * this case gets a new expected file next to the old one.
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/includes/class-rules.php';

use EstrategiaWeb\Newsletter\Rules;

$fixtures = dirname( __DIR__ ) . '/fixtures/';
$block    = file_get_contents( $fixtures . 'gastronomicament-block.html' );
$email    = file_get_contents( $fixtures . 'gastronomicament-email.html' );
$posts    = array(
	array(
		'link'    => 'https://gastronomicament.cat/ca/pep-socies-lescandall-es-loxigen-de-la-restauracio-el-95-dels-restaurants-tanquen-perque-no-el-fan-be',
		'image'   => 'https://gastronomicament.cat/wp-content/uploads/2026/09/Pep-Socies-1024x576.jpg',
		'title'   => 'Pep Socies: «L\'escandall és l\'oxigen de la restauració; el 95% dels restaurants tanquen perquè no el fan bé»',
		'excerpt' => Rules::excerpt( "<p>El gastrònom repassa les claus del seu èxit: l'honestedat amb el producte, el respecte pel personal i la formació&nbsp;contínua.</p>" ),
		'label'   => 'Actualitat',
	),
	array(
		'link'    => 'https://gastronomicament.cat/ca/el-tie-break-vins-i-tapes-al-rafa-nadal-open',
		'image'   => 'https://gastronomicament.cat/wp-content/uploads/2026/08/Tie-Break-Vins-i-Tapes-Manacor-Rafa-Nadal-Open-2026-1024x645.jpg',
		'title'   => "El Tie-Break: vins i tapes al\u{00A0}Rafa Nadal Open", // The post's title carries a no-break space, and the email keeps it.
		'excerpt' => Rules::excerpt( "Sis restaurants de prestigi i sis cellers de referència s'instal•laran a Manacor el divendres 4 de setembre a la tarda." ),
		'label'   => 'Actualitat',
	),
	array(
		'link'    => 'https://gastronomicament.cat/ca/la-temporada-de-pesca-del-raor-amb-una-poblacio-plenament-recuperada',
		'image'   => 'https://gastronomicament.cat/wp-content/uploads/2026/09/Raor-peix-Mallorca-Menorca-Eivissa-Formentera-CAIB.jpg',
		'title'   => 'La temporada de pesca del raor amb una població plenament recuperada',
		'excerpt' => Rules::excerpt( 'A les Illes Balears ha començat l\'1 de setembre' ),
		'label'   => 'Actualitat',
	),
);
$blocks = '';
foreach ( $posts as $p ) {
	$blocks .= Rules::block( $block, $p );
}

// email_image(): a fake disk.
$disk   = array(
	'https://x.test/up/a-1024x576.jpg' => true,
	'https://x.test/up/b.jpg'          => true,
	'https://x.test/up/c.png'          => true,
);
$exists = static fn( string $url ): bool => isset( $disk[ $url ] );

$model = array(
	'id'         => 'abc',
	'recipients' => array(
		'list_id'      => 'L1',
		'segment_opts' => array( 'saved_segment_id' => 0, 'match' => 'all', 'conditions' => array( array( 'field' => 'interests-1', 'op' => 'interestcontains', 'value' => array( 'x' ) ) ) ),
	),
	'settings'   => array(
		'subject_line' => 'old',
		'from_name'    => 'Gastronòmicament',
		'reply_to'     => 'info@gastronomicament.cat',
		'template_id'  => 350969,
		'drag_and_drop' => true,
		'auto_tweet'   => true,
		'inline_css'   => null,
		'authenticate' => true,
	),
);

echo json_encode(
	array(
		'real'      => array(
			'blocks'   => $blocks,
			'expected' => file_get_contents( $fixtures . 'gastronomicament-0409-blocks.html' ),
			'email'    => Rules::email( $email, $blocks ),
			'wrapper'  => $email,
		),
		'excerpt'   => array(
			'short'      => Rules::excerpt( 'Una dues tres' ),
			'exact16'    => Rules::excerpt( 'u d t q c s s v n d o d t c q s' ),
			'seventeen'  => Rules::excerpt( 'u d t q c s s v n d o d t c q s x' ),
			'comma'      => Rules::excerpt( 'u d t q c s s v n d o d t c q setze, disset' ),
			'html'       => Rules::excerpt( "<p>Un <strong>bon</strong>\n\n vi &amp; tapes</p>" ),
			'empty'      => Rules::excerpt( '   ' ),
		),
		'block'     => array(
			'escaped'     => Rules::block( '<a href="{{link}}" title="{{title}}">{{title}}</a>', array( 'link' => 'https://x.test/?a=1&b=2', 'title' => 'Vi "negre" & <b>' ) ),
			'no_recursion' => Rules::block( '{{title}}|{{link}}', array( 'title' => '{{link}}', 'link' => 'L' ) ),
			'missing'     => Rules::block( '[{{label}}]', array() ),
		),
		'email'     => array(
			'no_marker' => Rules::email( '<html></html>', 'x' ),
		),
		'image'     => array(
			'jpeg'          => Rules::email_image( array( 'https://x.test/up/a-1024x576.jpg', 'https://x.test/up/a.jpg' ), '', $exists ),
			'webp_sibling'  => Rules::email_image( array( 'https://x.test/up/a-1024x576.webp', 'https://x.test/up/a.webp' ), '', $exists ),
			'webp_original' => Rules::email_image( array( 'https://x.test/up/b-1024x576.webp', 'https://x.test/up/b.webp' ), 'https://x.test/up/b.jpg', $exists ),
			'webp_png'      => Rules::email_image( array( 'https://x.test/up/c.webp' ), '', $exists ),
			'webp_only'     => Rules::email_image( array( 'https://x.test/up/z-1024x576.webp', 'https://x.test/up/z.webp' ), 'https://x.test/up/z.jpg', $exists ),
			'none'          => Rules::email_image( array( '', '' ), '', $exists ),
		),
		'dc'        => array(
			'us21'   => Rules::datacenter( '0123456789abcdef0123456789abcdef-us21' ),
			'spaces' => Rules::datacenter( ' 0123456789abcdef0123456789abcdef-US6 ' ),
			'none'   => Rules::datacenter( '0123456789abcdef0123456789abcdef' ),
			'bad'    => Rules::datacenter( 'hola-us21' ),
		),
		'body'      => Rules::campaign_body( $model, str_repeat( 'Títol ', 30 ) ),
		'body_bare' => Rules::campaign_body( array( 'recipients' => array( 'list_id' => 'L2', 'segment_opts' => array() ), 'settings' => array() ), 'S' ),
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
