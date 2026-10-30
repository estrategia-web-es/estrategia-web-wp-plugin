// NIVEL: 1 — reads the browser scenario «34-map-consent-show-map»: «Ver mapa» with the notice unanswered shows that map and grants «maps». Opens no browser.
/**
 * Was the second visitor of map-consent.test.mjs (level 3) until
 * 29-10-2026; every assertion kept. The scenario waits for the FACT that the
 * iframe has a src after the click; here, that it is seen and that the
 * notice's API now has «maps».
 * Seen red (29-10-2026) expecting «maps» NOT granted; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '34-map-consent-show-map' );

test( '«Ver mapa» with the notice unanswered → that map, and «maps» granted', () => {
	const c = get( null, 'shown' );
	assert.ok( c.values.seen, '«Ver mapa» does not show the iframe' );
	assert.ok( c.values.maps, '«Ver mapa» does not grant «maps»' );
	const errors = errorsOf( c, { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
