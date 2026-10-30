// NIVEL: 1 — reads the browser scenario «25-google-tags-native-rejected»: a form sent without JavaScript before choosing waits, and rejecting sends nothing. Opens no browser.
/**
 * Was the «native 2» visitor of test 2 of google-tags-browser.test.mjs
 * (level 3) until 29-10-2026; every assertion kept. The old 300 ms wait after
 * rejecting is the FACT «the consent event fired» (window.seen) in the scenario.
 * Seen red (28-10-2026) with a conversion expected after rejecting; seen red
 * again (29-10-2026) the same way; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '25-google-tags-native-rejected' );

test( 'no choice yet: it waits; rejecting sends nothing', () => {
	const returned = get( null, 'returned' ).values;
	assert.ok( returned.formSent.some( ( d ) => d.returned ), 'the page that comes back did not announce the submission' );
	assert.equal( returned.conversions.length, 0, 'nothing before choosing' );
	const rejected = get( null, 'rejected' );
	assert.equal( rejected.values.conversions.length, 0, 'nothing after rejecting' );
	const errors = errorsOf( rejected, { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
