// NIVEL: 1 — reads the browser scenario «31-map-static»: the static map is the site's own image under the card, nothing goes to other domains, the card goes below when narrow, and «Ver mapa» loads only that map. Opens no browser.
/**
 * Was step 3 of map.test.mjs (level 3) until 29-10-2026; every assertion kept.
 * Seen failing on 17-10-2026: in Twenty Twenty-Five the card measured 42 %
 * PLUS its padding and reached the marker (box-sizing: border-box in
 * blocks/map/style.css): «the card covers the centre».
 * Seen red (29-10-2026) expecting the card wider than half the image; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get, base } = artifacts( '31-map-static' );

test( 'wide: the site\'s own image, loaded, with the card on top on the left; nothing to other domains', () => {
	const c = get( null, 'wide' );
	const v = c.values;
	assert.equal( new URL( v.src, base ).host, base.host, `the image is not from the site: ${ v.src }` );
	assert.match( v.alt, /Pla(ç|c)a de Cort/, 'the alternative text does not give the address' );
	assert.ok( v.cardSeen, 'with a static map the details card must stay' );
	assert.match( v.cardText, /Sede con mapa estático/ );
	assert.ok( v.card.y < v.image.y + v.image.height && v.card.y >= v.image.y, 'when wide, the card goes ON TOP of the map' );
	assert.ok( v.card.x + v.card.width < v.image.x + v.image.width / 2, 'the card covers the centre (the marker)' );
	assert.equal( v.secondImages, 0, 'a staticMapUrl from another domain is not discarded' );
	const foreign = c.network.filter( ( r ) => r.url.startsWith( 'http' ) && new URL( r.url ).host !== base.host ).map( ( r ) => r.url );
	assert.deepEqual( foreign, [], `third-party requests without consent: ${ foreign.join( ' ' ) }` );
} );

test( 'narrow (375 px): the card below the map', () => {
	const { image: a, card: b } = get( null, 'narrow' ).values;
	assert.ok( a.height > 100, `when narrow the map is ${ a.height } px high` );
	assert.ok( b.y >= a.y + a.height - 1, `when narrow the card (y=${ b.y }) does not go below the map (which ends at ${ a.y + a.height })` );
} );

test( 'without the notice plugin (0.7.0): «Ver mapa» loads THIS map only, no consent cookie', () => {
	const c = get( null, 'loaded' );
	assert.equal( c.values.imageSeen, false, 'once the map is loaded, the static image is still in view' );
	assert.ok( ! c.values.secondSrc, 'without the notice, clicking one map should not load the others' );
	assert.equal( c.cookies.find( ( x ) => 'estrategia_web_consent' === x.name ), undefined, 'without the plugin there is no consent cookie' );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
