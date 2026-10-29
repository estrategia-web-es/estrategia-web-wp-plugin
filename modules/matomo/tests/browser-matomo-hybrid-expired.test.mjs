// NIVEL: 1 — reads the browser scenario «39-matomo-hybrid-expired»: with the notice's choice expired, hybrid Matomo forgets an earlier consent. Opens no browser.
/**
 * Was the «expired» visitor of test 2 of matomo-browser.test.mjs (level 3)
 * until 29-10-2026. The scenario waits for the notice to show and for the
 * FACT that mtm_cookie_consent is gone (and fails naming it if not); here,
 * the cookies that were left.
 * Seen red (29-10-2026) expecting mtm_cookie_consent kept; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts } from '../../../tests/browser.mjs';

const { get } = artifacts( '39-matomo-hybrid-expired' );

test( 'an expired choice forgets the earlier Matomo consent', () => {
	const names = get( null, 'forgotten' ).cookies.map( ( c ) => c.name );
	assert.ok( ! names.includes( 'mtm_cookie_consent' ), `mtm_cookie_consent being deleted (there are: ${ names.join( ', ' ) })` );
	assert.ok( names.includes( 'estrategia_web_consent' ), 'the expired notice cookie was there to begin with' );
} );
