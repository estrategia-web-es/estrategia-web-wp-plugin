// NIVEL: 1 — reads the browser scenario «29-map-plain»: the map without the notice does not shrink in a vertical Group, shows its details card, and asks Google for nothing until «Ver mapa». Opens no browser.
/**
 * Was step 1 of map.test.mjs (level 3) until 29-10-2026; every assertion kept.
 * - map-in-flex: inside a vertical Group (column flex, automatic height) with
 *   overflow:hidden the map does NOT shrink. The bug, 03-10-2026: a flex
 *   child with overflow other than visible has a minimum size of 0 and the
 *   map ended up 2 px tall; the fix is `inline-size: 100%` on
 *   .estrategia-web-map (blocks/map/style.css). THE TRAP, which cost a
 *   version: a container with a FIXED height makes a different bug
 *   (shrinking) and passed with a fix that fixed nothing. The scenario wraps
 *   the map, measures and undoes it (values.measure).
 * - map-card: no <img> without an image chosen; a card with the DETAILS in
 *   real text (name, address, phone with tel:, hours with their line breaks)
 *   and «Abrir en Google Maps».
 * - without the consent module: no notice and no window.estrategiaWebConsent;
 *   no REQUEST to Google before the click (requests, not the text
 *   «google.com»: the «Abrir en Google Maps» link is there by design); after
 *   «Ver mapa» the embed is requested (the scenario waits for that request to
 *   finish) and the iframe shows, the button hides. Seen failing on
 *   05-10-2026: view.js calling estrategiaWebConsent.grant() without «?.»
 *   blew up on click (the console check).
 * Seen red (29-10-2026) with the expected phone of the card changed; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get, base } = artifacts( '29-map-plain' );
const isGoogle = ( url ) => /google\.|gstatic\.|googleapis\./.test( new URL( url ).hostname );

test( 'map-in-flex: the map does not shrink inside a vertical Group with overflow:hidden', () => {
	const { measure } = get( null, 'flex' ).values;
	assert.ok( measure.frame > 100 && measure.figure > 100, `the map gets squashed inside a vertical Group: figure ${ measure.figure } px, frame ${ measure.frame } px` );
	assert.ok( measure.figure >= measure.frame, `the map shrinks inside a flex: the figure is ${ measure.figure } px and its frame ${ measure.frame } px` );
} );

test( 'without the consent module: neither notice nor API', () => {
	const { values } = get( null, 'flex' );
	assert.equal( values.notices, 0, 'the parent no longer paints the cookie notice' );
	assert.equal( values.api, 'undefined', 'without the plugin there should be no window.estrategiaWebConsent' );
} );

test( 'map-card: the details card in real text, the phone with tel:, «Abrir en Google Maps»', () => {
	const { values } = get( null, 'card' );
	assert.equal( values.images, 0, 'with no image chosen there should be no <img>' );
	assert.ok( values.cardSeen, 'the details card is missing' );
	for ( const expected of [ 'Ayuntamiento de prueba', 'Plaça de Cort 1, 07001 Palma', '+34 971 22 59 00', 'De lunes a viernes\nDe 9:00 a 14:00' ] ) {
		assert.ok( values.cardText.includes( expected ), `the card does not say "${ expected }":\n${ values.cardText }` );
	}
	assert.equal( values.tel, 1, 'the phone does not link to tel:' );
	assert.ok( values.openSeen, '«Abrir en Google Maps» is missing' );
} );

test( 'nothing to Google before the click; the embed and the iframe after it', () => {
	const card = get( null, 'card' );
	const google = card.network.filter( ( r ) => r.url.startsWith( 'http' ) && new URL( r.url ).host !== base.host && isGoogle( r.url ) ).map( ( r ) => r.url );
	assert.deepEqual( google, [], `requests to Google before the click:\n${ google.join( '\n' ) }` );
	// No src or src="" (about:blank): hydration leaves "" — see blocks/map/view.js.
	assert.ok( ! card.values.srcBefore, `the iframe already has a src before the click: ${ card.values.srcBefore }` );
	const shown = get( null, 'shown' );
	assert.ok( shown.network.some( ( r ) => /google\.com\/maps\/embed/.test( r.url ) ), 'the embed was not requested after the click' );
	assert.ok( shown.values.iframeSeen, 'the iframe is not visible after the click' );
	assert.equal( shown.values.buttonSeen, false, 'the button is still visible' );
	const errors = errorsOf( shown );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
