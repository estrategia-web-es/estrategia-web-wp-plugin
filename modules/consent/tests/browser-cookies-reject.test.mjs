// NIVEL: 1 — reads the browser scenario «14-cookies-reject»: rejecting sends nothing, and a link or an element with the class reopens the panel. Opens no browser.
/**
 * Was the «reject» visitor of cookies-browser.test.mjs (level 3) until
 * 29-10-2026; every assertion kept. «Rejecting sends nothing» used to be
 * checked after waiting past the detector's delay; now it is a FACT: no
 * timer of the detector's delay (300 ms, the page is served so) was ever
 * scheduled on the page (timers.js), so no scan will ever run there, and no
 * report is among its requests. Reopening (a link to #estrategia-web-consent
 * that does not navigate, the focus on the title, Escape, an element with
 * the class) are the scenario's facts.
 * Seen failing on 14-10-2026 with scheduleScan not checking whether anything
 * was accepted. Seen red (29-10-2026) with one report expected from this
 * visitor; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '14-cookies-reject' );

test( 'rejecting sends nothing; the link does not navigate', () => {
	assert.ok( ! get( null, 'by-link' ).values.url.endsWith( '#estrategia-web-consent' ), 'the link does not navigate' );
	const c = get( null, 'by-class' );
	const network = [ ...get( null, 'by-link' ).network, ...c.network ];
	const docs = network.filter( ( r ) => r.rewrite );
	assert.ok( docs.length && docs.every( ( r ) => r.rewrite.found ), 'the page state has no "delay":4000 to shorten (includes/class-consent.php)' );
	assert.equal( c.values.timers.scheduled[ 300 ] || 0, 0, 'a detector scan was scheduled after rejecting' );
	const sent = network.filter( ( r ) => /\/estrategia-web\/v1\/cookies\/seen/.test( r.url ) );
	assert.deepEqual( sent.map( ( r ) => r.body ), [], 'rejecting sends nothing' );
	const errors = errorsOf( c, { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
