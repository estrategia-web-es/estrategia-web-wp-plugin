// NIVEL: 1 — reads the browser scenario «18-ct-template-fields»: the constructor offers a template's new fields to a copy made before them, and adds them only when asked. Opens no browser.
/**
 * Was the second test of hours-editor.test.mjs (level 3) until 29-10-2026;
 * every assertion kept. The copy «as it was on 17-10-2026» (the template's
 * first 8 fields) is made by the setup (/estrategia-web-ct-test/v1/template-copy),
 * which also returns the template it came from: the count and order checked
 * here are the template's.
 * Seen red (22-10-2026) with offerSection() looking at the stored definition
 * instead of the draft (the notice did not go away). Seen red (29-10-2026)
 * with 9 fields expected stored before saving; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get, setup } = artifacts( '18-ct-template-fields' );
const v = ( label ) => get( null, label ).values;

test( 'the constructor offers a template\'s new fields to a copy made before them, and adds them only when asked', () => {
	const { status, answer, template } = setup.vars.old;
	assert.equal( status, 200, JSON.stringify( answer ).slice( 0, 300 ) );
	const missing = template.fields.length - 8;
	assert.equal( answer.missingFields.length, missing );
	assert.match( v( 'offered' ).notice, new RegExp( `\\(${ missing }\\)` ) );
	assert.equal( v( 'added' ).notice, '', 'the notice goes away once they are in the draft' );
	assert.equal( v( 'added' ).stored, 8, 'nothing stored until the person saves' );
	const after = v( 'saved' ).after;
	assert.deepEqual( after.fields, template.fields.map( ( f ) => f.name ), 'the same fields, in the template\'s order' );
	assert.deepEqual( after.missing, [] );
	const errors = errorsOf( get( null, 'saved' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
