// NIVEL: 1 — reads the browser scenario «36-matomo-cookieless»: in cookieless mode Matomo measures, leaves no cookie and there is no notice. Opens no browser.
/**
 * Was test 1 of matomo-browser.test.mjs (level 3) until 29-10-2026. No
 * Matomo server is called: the runner serves the REAL tracker
 * (modules/matomo/tests/fixtures/matomo.js) and answers matomo.php with 204,
 * so what is tested is what the tracker does with our commands. The
 * scenario waits for the FACTS «the visit reached matomo.php with idsite=3»
 * and «the event was the second hit»; here, what was left.
 * How to maintain: when updating the fixture, its header states the version.
 * Seen red (29-10-2026) with a _pk_id cookie expected in cookieless mode; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '36-matomo-cookieless' );

test( 'COOKIELESS measures (matomo.php is hit), leaves no Matomo cookie and there is no notice', () => {
	const c = get( null, 'measured' );
	const hits = c.network.filter( ( r ) => /^https:\/\/stats\.ejemplo\.test\/matomo\.php/.test( r.url ) );
	assert.ok( hits.some( ( r ) => /idsite=3/.test( r.url ) ), 'the visit did not reach matomo.php with idsite=3' );
	assert.ok( hits.length >= 2, 'the event is not a second hit' );
	assert.deepEqual( c.cookies.map( ( x ) => x.name ).filter( ( n ) => /^(_pk_|mtm_)/.test( n ) ), [], 'no Matomo cookies' );
	assert.equal( c.values.notices, 0, 'without cookies there is nothing to ask: no notice' );
	const errors = errorsOf( c, { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
