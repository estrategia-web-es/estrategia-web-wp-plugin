// NIVEL: 1 — reads the browser scenario «37-matomo-hybrid-notice»: hybrid Matomo with the real notice sets no cookie until «Aceptar todas» (maps alone does not count), sets _pk_id afterwards, and «Rechazar todas» deletes them. Opens no browser.
/**
 * Was the first visitor of test 2 of matomo-browser.test.mjs (level 3) until
 * 29-10-2026. The scenario waits for each FACT (the cookieless hit, the
 * notice hidden after saving, the second hit, mtm_cookie_consent, _pk_id,
 * their deletion) and fails naming it; here, the cookies at each stage.
 * The control step («with consent DOES set _pk_id») must stay green, or the
 * «no cookies» stages prove nothing.
 * Seen red (29-10-2026) with a Matomo cookie expected after «maps» alone; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '37-matomo-hybrid-notice' );
const matomoCookies = ( label ) => get( null, label ).cookies.map( ( c ) => c.name ).filter( ( n ) => /^(_pk_|mtm_)/.test( n ) ).sort();

test( 'before answering, and with only «maps», no Matomo cookie', () => {
	assert.deepEqual( matomoCookies( 'unanswered' ), [], 'before answering, no cookie' );
	assert.deepEqual( matomoCookies( 'maps-only' ), [], 'accepting another category does not give consent to Matomo' );
} );

test( '«Aceptar todas»: mtm_cookie_consent and, on the next hit, _pk_id (the control)', () => {
	const names = matomoCookies( 'accepted' );
	assert.ok( names.includes( 'mtm_cookie_consent' ), `mtm_cookie_consent after accepting (there are: ${ names.join( ', ' ) })` );
	assert.ok( names.some( ( n ) => n.startsWith( '_pk_id' ) ), `_pk_id after accepting (there are: ${ names.join( ', ' ) })` );
} );

test( '«Rechazar todas» deletes them, without errors', () => {
	assert.deepEqual( matomoCookies( 'rejected' ), [], 'them being deleted when consent is withdrawn' );
	const errors = errorsOf( get( null, 'rejected' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
