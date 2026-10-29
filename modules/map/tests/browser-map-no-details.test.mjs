// NIVEL: 1 — reads the browser scenario «32-map-no-details»: with showDetails:false the address is read once, the frame holds only the image, there is no empty box, and «Ver mapa» paints the map. Opens no browser.
/**
 * Was step 4 of map.test.mjs (level 3) until 29-10-2026; every assertion
 * kept, at 1200 and 402. Seen failing on 03-10-2026 with 0.5.0: the address
 * came out twice. Plugin 1.1.x: two links to different destinations cannot
 * have the same name (Lighthouse); the name starts with the visible text.
 * «Ver mapa» paints the map with its height: the scenario waits for that
 * FACT (the second iframe shown, from google.com, over 100 px tall) and fails
 * naming it if it never comes.
 * Seen red (29-10-2026) with the expected «times» of the address set to 2; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '32-map-no-details' );

for ( const [ width, label ] of [ [ 1200, 'wide' ], [ 402, 'narrow' ] ] ) {
	test( `at ${ width}: the address once, only the image in the frame, the two buttons, no empty box`, () => {
		const v = get( null, label ).values;
		assert.equal( v.times, 1, `at ${ width } px the address comes out ${ v.times } times` );
		assert.ok( ! v.named, `at ${ width } px the location name comes out inside the map` );
		assert.equal( v.firstCards, 0, 'with showDetails:false the card is not painted' );
		assert.ok( Math.abs( v.image.height - v.frame.height ) < 2 && v.image.height > 100, `at ${ width } px the image does not fill the frame (${ v.image.height } of ${ v.frame.height })` );
		assert.ok( v.firstLoad, '«Ver mapa» is not seen' );
		assert.ok( v.firstLink, '«Abrir en Google Maps» is not seen' );
		assert.equal( v.secondCards, 0 );
		assert.equal( v.secondFrameSeen, false, `at ${ width } px without an image an empty box is left before the click` );
		assert.ok( v.secondLink, '«Abrir en Google Maps» of the second map is not seen' );
	} );
}

test( 'link names: never repeated, and starting with the visible text', () => {
	const { names } = get( null, 'wide' ).values;
	assert.equal( new Set( names ).size, names.length, `repeated link names: ${ names.join( ' | ' ) }` );
	for ( const name of names ) {
		assert.ok( name.startsWith( 'Abrir en Google Maps' ), `the name does not start with the visible text: ${ name }` );
	}
} );

test( '«Ver mapa» paints the map (the scenario waited for it), without errors', () => {
	const errors = errorsOf( get( null, 'painted' ) );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
