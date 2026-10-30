// NIVEL: 1 — reads the browser scenario «20-forms-js»: with JavaScript the browser stops an empty required field, the server marks a bypassed one and announces it with the focus on it, and the filled form is sent without reloading. Opens no browser.
/**
 * Was the three JS cases of browser.test.mjs (level 3) until 29-10-2026;
 * every assertion kept. What changed: the JS visitor and the no-JS one are
 * two scenarios now (21-forms-no-js), each with its own seed, so each reads
 * ITS submission: here one stored row and three emails (it was «2 and 6»,
 * the two visitors together). And the minimum fill time is 0 in the seed
 * instead of waiting it out (a fixed wait): it is the server's rule,
 * server.test.mjs (level 2).
 * The scenario's form is a COPY of FORM in ./lib.mjs (a JSON cannot import
 * it): the first test fails if they drift apart.
 * Seen red (28-10-2026) with the form rendered with «novalidate» (1), view.js
 * without the focus on the first invalid field (2) and without clearing the
 * form (3). Seen red (29-10-2026) expecting the focus on «nombre» after the
 * refusal, and two stored rows; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';
import { FORM } from './lib.mjs';

const { get } = artifacts( '20-forms-js' );
const v = ( label ) => get( null, label ).values;
const submits = ( c ) => c.network.filter( ( r ) => 'POST' === r.method && r.url.includes( '/estrategia-web-forms/v1/submit' ) );

test( 'the scenario\'s form is lib.mjs\'s FORM', () => {
	const flow = JSON.parse( fs.readFileSync( new URL( './browser/20-forms-js.json', import.meta.url ), 'utf8' ) );
	assert.equal( flow.steps[ 0 ].parameters.requests[ 0 ].body.pages[ 0 ].content, FORM, 'modules/forms/tests/browser/20-forms-js.json no longer has lib.mjs\'s FORM' );
} );

test( 'with JS: the browser does not allow submitting an empty required field', () => {
	const c = get( null, 'blocked' );
	assert.equal( c.values.valueMissing, true );
	assert.deepEqual( submits( c ), [], 'no request goes out' );
} );

test( 'with JS and bypassing the browser: the server marks the field and announces it', () => {
	const r = v( 'refused' );
	assert.notEqual( r.fieldError, '', 'the field error is written' );
	assert.notEqual( r.status, '', 'and the general message in the aria-live region' );
	assert.equal( r.focus, 'af-contacto-mensaje', 'focus goes to the first field with an error' );
} );

test( 'with JS: submits without reloading, announces via aria-live and is stored in the log', () => {
	const c = get( null, 'sent' );
	assert.equal( c.values.live, 'polite' );
	assert.equal( c.values.sameDocument, true, 'there was no reload' );
	assert.equal( c.values.nombre, '', 'the form is cleared after submitting' );
	// 2.3.0: a success that cannot be missed (David, 29-10-2026).
	assert.equal( c.values.sentClass, true, 'the form is marked is-sent' );
	assert.equal( c.values.successClass, true, 'the message is dressed as a success' );
	assert.equal( c.values.fieldsShown, false, 'the fields are hidden once sent' );
	assert.equal( c.values.focus, 'af-contacto-status', 'the focus goes to the message' );
	assert.equal( c.values.state.submissions.length, 1, 'this one: the rejected one was not stored' );
	assert.equal( c.values.state.mails.length, 3, 'three notifications' );
	// The 422 of the case before (the server's answer to the rejected one) is the only expected console line.
	const errors = errorsOf( c, { ignore: /status of 422/ } );
	assert.deepEqual( errors, [], 'no console errors:\n' + errors.join( '\n' ) );
} );
