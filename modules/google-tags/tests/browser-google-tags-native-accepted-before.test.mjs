// NIVEL: 1 — reads the browser scenario «24-google-tags-native-accepted-before»: a form sent without JavaScript with Advertising accepted counts once, and reloading the page that comes back does not count again. Opens no browser.
/**
 * Was the «native 1» visitor of test 2 of google-tags-browser.test.mjs
 * (level 3) until 29-10-2026; every assertion kept. The old 300 ms waits
 * after coming back and after reloading are the FACT «consent-ready fired»
 * in the scenario: the form's «returned» signal is an inline script that ran
 * while the page parsed, before the notice's module, and the google-tags
 * script decides inside the notice's listener, so once it fired nothing is
 * left to come.
 * Seen red (29-10-2026) expecting a conversion after the reload; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '24-google-tags-native-accepted-before' );

test( 'Advertising accepted beforehand: one conversion; reloading does not add another', () => {
	const returned = get( null, 'returned' );
	assert.match( returned.page.url, /af_result=/ );
	assert.deepEqual( returned.values.conversions.map( ( e ) => e[ 2 ] ), [ { send_to: 'AW-123456789/AbCd_ef-12' } ] );
	const reloaded = get( null, 'reloaded' );
	assert.equal( reloaded.values.conversions.length, 0, 'the reload is not a new submission' );
	assert.deepEqual( reloaded.values.formSent, [], 'the reloaded page announced the submission again' );
	const errors = errorsOf( reloaded, { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
