// NIVEL: 1 — reads the browser scenario «21-forms-no-js»: without JavaScript the form posts normally, comes back with the result and the message, and is stored. Opens no browser.
/**
 * Was the no-JS case of browser.test.mjs (level 3) until 29-10-2026; every
 * assertion kept, on its own seed now (so one stored row, from /contacto/).
 * The scenario's form is a copy of FORM in ./lib.mjs: compared here too.
 * Seen red (28-10-2026) with the form rendered with method «get» (the no-JS
 * submit never came back). Seen red (29-10-2026) expecting the URL without
 * the #af-contacto-status anchor; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { artifacts } from '../../../tests/browser.mjs';
import { FORM } from './lib.mjs';

const { get } = artifacts( '21-forms-no-js' );

test( 'the scenario\'s form is lib.mjs\'s FORM', () => {
	const flow = JSON.parse( fs.readFileSync( new URL( './browser/21-forms-no-js.json', import.meta.url ), 'utf8' ) );
	assert.equal( flow.steps[ 0 ].parameters.requests[ 0 ].body.pages[ 0 ].content, FORM, 'modules/forms/tests/browser/21-forms-no-js.json no longer has lib.mjs\'s FORM' );
} );

test( 'without JS: normal POST, redirect and message on the page, stored', () => {
	const c = get( null, 'returned' );
	assert.match( c.page.url, /\/contacto\/\?af_result=[a-z0-9]+#af-contacto-status$/ );
	assert.match( c.values.status, /Thank you|Gracias/ );
	assert.equal( c.values.sentClass, true, 'the server renders the form as sent (2.3.0)' );
	assert.equal( c.values.successClass, true, 'and the message dressed as a success' );
	assert.equal( c.values.state.submissions.filter( ( row ) => row.page_url.includes( '/contacto/' ) ).length, 1, 'the no-JS submission is stored' );
} );
