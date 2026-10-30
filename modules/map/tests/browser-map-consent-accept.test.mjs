// NIVEL: 1 — reads the browser scenario «33-map-consent-accept»: with the notice unanswered nothing goes to third parties and the static map is the site's own; after «Accept all» the Google Maps iframe is there and seen. Opens no browser.
/**
 * Was the first visitor of map-consent.test.mjs (level 3) until 29-10-2026;
 * every assertion kept. Edge case: a request to another host AFTER accepting
 * is legitimate (that is what accepting is for); only what leaves before is
 * watched (the «static» capture's network, from the load to before the
 * click). The trap: the iframe has loading="lazy" and is not requested until
 * seen, so after accepting its src and that it is seen are checked, not the request.
 * Seen red (29-10-2026) expecting the static map wider than 2000 px and
 * the embed at …/maps/embed2; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get, base } = artifacts( '33-map-consent-accept' );

test( 'unanswered: the notice is seen, no iframe with a src, the static map seen and served by the site, nothing to third parties', () => {
	const u = get( null, 'unanswered' ).values;
	assert.ok( u.noticeSeen, 'the cookie notice is not seen' );
	assert.deepEqual( u.framed, [], 'iframes with a src before accepting' );
	assert.ok( u.statics, 'no static map' );
	const c = get( null, 'static' );
	const info = c.values.info;
	assert.ok( info.natural > 0 && info.width > 200, `the static map is not seen (${ JSON.stringify( info ) })` );
	assert.equal( new URL( info.src ).host, base.host, `the static map is not served by the site itself (${ info.src })` );
	const outside = c.network.filter( ( r ) => r.url.startsWith( 'http' ) && new URL( r.url ).host !== base.host ).map( ( r ) => r.url );
	assert.deepEqual( outside, [], `requests to third parties without consent:\n${ outside.join( '\n' ) }` );
	const errors = errorsOf( c );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );

test( '«Accept all» → the Google Maps iframe, seen', () => {
	const a = get( null, 'accepted' ).values;
	assert.match( a.src, /^https:\/\/www\.google\.com\/maps\/embed/, 'after accepting, the iframe is not Google Maps\'' );
	assert.ok( a.seen, 'after accepting, the iframe is not seen' );
} );
