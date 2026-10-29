// NIVEL: 1 — reads the browser scenario «30-map-query»: inside a Query each map has its post's address and featured image, or the details card. Opens no browser.
/**
 * Was step 2 of map.test.mjs (level 3) until 29-10-2026; every assertion
 * kept: one map per post; with an image the address goes in the map's
 * caption, without one in the card (0.3.0); the first post's featured image
 * is the map's; «Abrir en Google Maps» comes from the bound address.
 * Seen red (29-10-2026) with the expected address of the second post changed; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '30-map-query' );

test( 'inside a Query each map has its post\'s address and featured image (or the card)', () => {
	const c = get( null, 'query' );
	const v = c.values;
	assert.equal( v.maps, 2, 'there should be one map per post' );
	assert.deepEqual( v.addresses, [ 'Calle de Prueba 1, Palma', 'Calle de Prueba 2, Palma' ], 'each map should carry its post\'s address' );
	assert.equal( v.firstImages, 1, 'the first post has a featured image: the map should use it' );
	assert.ok( ( v.firstSrc || '' ).includes( 'mapa-estatico-prueba' ), 'the image is not the featured one' );
	assert.equal( v.secondCards, 1, 'without a featured image the details card should come out' );
	assert.ok( ( v.secondHref || '' ).includes( encodeURIComponent( 'Calle de Prueba 2, Palma' ) ), `link without the address: ${ v.secondHref }` );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
