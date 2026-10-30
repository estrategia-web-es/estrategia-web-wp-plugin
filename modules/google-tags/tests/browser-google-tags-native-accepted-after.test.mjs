// NIVEL: 1 — reads the browser scenario «26-google-tags-native-accepted-after»: a form sent without JavaScript before choosing is counted, once, when the visitor accepts on the page that comes back. Opens no browser.
/**
 * Was the «native 3» visitor of test 2 of google-tags-browser.test.mjs
 * (level 3) until 29-10-2026; every assertion kept. The scenario waits for
 * the FACT that a conversion was pushed after accepting.
 * Seen red (29-10-2026) expecting two conversions; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '26-google-tags-native-accepted-after' );

test( 'no choice yet, and the visitor accepts on the page that comes back: it is sent then, once', () => {
	const c = get( null, 'accepted' );
	assert.equal( c.values.conversions.length, 1 );
	const errors = errorsOf( c, { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
