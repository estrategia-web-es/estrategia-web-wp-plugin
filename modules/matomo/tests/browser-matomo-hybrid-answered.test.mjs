// NIVEL: 1 — reads the browser scenario «38-matomo-hybrid-answered»: with the notice already answered, hybrid Matomo remembers the consent on load and the notice does not show. Opens no browser.
/**
 * Was the «answered» visitor of test 2 of matomo-browser.test.mjs (level 3)
 * until 29-10-2026. The scenario stores the notice's cookie with the
 * revision md5("statistics,maps|1").slice(0, 8) written in it (8d6a64a0);
 * this check recomputes it, so a scenario that drifted from the formula is
 * caught here and not as a mysterious «never arrived». The trap: the hybrid
 * revision depends on what the notice declares (statistics from Matomo plus
 * maps from «the theme»).
 * Seen red (29-10-2026) with the revision recomputed from «statistics» alone; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { artifacts } from '../../../tests/browser.mjs';

const { get } = artifacts( '38-matomo-hybrid-answered' );
const REVISION = crypto.createHash( 'md5' ).update( 'statistics,maps|1' ).digest( 'hex' ).slice( 0, 8 );

test( 'the scenario\'s cookie carries the current revision', () => {
	const scenario = fs.readFileSync( new URL( './browser/38-matomo-hybrid-answered.json', import.meta.url ), 'utf8' );
	assert.ok( scenario.includes( `"${ REVISION }.statistics.{{@now}}"` ), `the scenario's consent cookie is not built with the revision ${ REVISION }` );
} );

test( 'remembered on load: mtm_cookie_consent, and the notice does not show', () => {
	const c = get( null, 'remembered' );
	assert.ok( c.cookies.some( ( x ) => 'mtm_cookie_consent' === x.name ), 'mtm_cookie_consent on load' );
	assert.ok( c.values.noticeHidden, 'the notice does not show with the choice saved' );
} );
