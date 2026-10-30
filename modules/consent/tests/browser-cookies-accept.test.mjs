// NIVEL: 1 — reads the browser scenario «13-cookies-accept»: on accepting, the detector sends only cookie NAMES and does not repeat on the next page; the Cookies screen confirms the detected cookie and the policy table shows it. Opens no browser.
/**
 * Was the «accept» visitor and the «Cookies screen» test of
 * cookies-browser.test.mjs (level 3) until 29-10-2026; every assertion kept.
 * What the registry is: includes/class-cookie-registry.php and
 * class-cookie-rest.php.
 * - «not a single value»: the request BODY as it left the browser (the
 *   network entry), not what was stored.
 * - «it does not send again»: the scenario waits for the FACT that the
 *   detector's scan RAN on the next page (timers.js); then no report among
 *   that page's requests, and the scan's record in localStorage is the same
 *   one (a new send would have rewritten it first).
 * - the screen and the policy: the same browser logs in as the
 *   administrator after the visitor (the row it made is what the screen
 *   shows); the policy table is read from the page.
 * Seen failing on 14-10-2026 with cookieNames() returning the whole pairs (the
 * value leaks). Seen red (29-10-2026) with the suggestion expected as
 * «Propuesta: Google»; restored, green.
 * How to maintain: if what the detector sends changes, «not a single value»
 * keeps looking at the request body.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '13-cookies-accept' );
const reports = ( c ) => c.network.filter( ( r ) => /\/estrategia-web\/v1\/cookies\/seen/.test( r.url ) );

test( 'the page was served with the detector\'s delay shortened (or the waits prove nothing)', () => {
	const docs = get( null, 'accepted' ).network.filter( ( r ) => r.rewrite );
	assert.ok( docs.length && docs.every( ( r ) => r.rewrite.found ), `the page state has no "delay":4000 to shorten (did the detector delay change? includes/class-consent.php): ${ JSON.stringify( docs.map( ( r ) => r.rewrite ) ) }` );
} );

test( 'accepting sends ONLY names, once; the registry keeps them pending', () => {
	const c = get( null, 'accepted' );
	const sent = reports( c );
	assert.equal( sent.length, 1 );
	const body = sent[ 0 ].body;
	assert.ok( ! body.includes( 'SECRET123' ), `a cookie value left the browser: ${ body }` );
	const names = JSON.parse( body ).names;
	assert.ok( names.includes( 'prueba_valor' ) && names.includes( 'estrategia_web_consent' ), body );
	assert.ok( names.every( ( n ) => ! n.includes( '=' ) ), body );
	const raw = c.values.raw.raw;
	assert.equal( raw.estrategia_web_consent.category, 'necessary' );
	assert.equal( raw.estrategia_web_consent.status, 'pending' );
	assert.ok( raw.prueba_valor, 'an unknown cookie gets in anyway, without a suggestion' );
} );

test( 'the next page: same list and same names, it does not send again', () => {
	const accepted = get( null, 'accepted' );
	const returned = get( null, 'returned' );
	assert.deepEqual( reports( returned ), [], 'same list and same names: it does not send again' );
	assert.ok( accepted.values.scan, 'the first scan left no record in localStorage' );
	assert.equal( returned.values.scan, accepted.values.scan, 'the scan record changed: it sent (or tried) again' );
	const errors = errorsOf( returned, { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );

test( 'Cookies screen: pending with its suggestion; confirmed, it shows in the policy', () => {
	const { row } = get( null, 'screen' ).values;
	assert.ok( row, 'no row for estrategia_web_consent on the Cookies screen' );
	assert.equal( row.status, 'pending' );
	assert.equal( row.category, 'necessary' );
	assert.match( row.text, /Propuesta: Estrategia Web/ );
	assert.match( get( null, 'saved' ).values.notice, /Cambios guardados/ );
	const policy = get( null, 'policy' ).html();
	const table = policy.match( /<div [^>]*wp-block-estrategia-web-cookie-table[\s\S]*?<\/figure><\/div>/ )?.[ 0 ] || '';
	assert.match( table, /Necesarias[\s\S]*estrategia_web_consent[\s\S]*Este sitio web[\s\S]*1 año/, 'the confirmed row is not in the policy' );
	assert.ok( ! table.includes( 'prueba_valor' ), 'pending items do not show' );
} );
