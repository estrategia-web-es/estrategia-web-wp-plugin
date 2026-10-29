// NIVEL: 1 — reads the browser scenario «17-ct-hours-panel»: a week entered in the fields panel's hours control — ranges, «same as the day before», the warning on a range that will not be kept — is stored canonical. Opens no browser.
/**
 * Was the first test of hours-editor.test.mjs (level 3) until 29-10-2026;
 * every assertion kept. ISOLATION (28-10-2026): the day names are core's
 * (WP_Locale, translated by core's es_ES pack, which the test WordPress has
 * only if it downloaded it): what OUR code does is asserted in either language.
 * Seen red (22-10-2026): with HoursField's «Same as the day before» copying
 * the CURRENT day, Tuesday stayed closed. Seen red (28-10-2026) with the
 * range number in the label expected otherwise. Seen red (29-10-2026)
 * expecting Sunday's 10:00-10:00 kept; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get, setup } = artifacts( '17-ct-hours-panel' );
const v = ( label ) => get( null, label ).values;

test( 'the setup: the location copy in the sidebar, and its draft', () => {
	assert.equal( setup.vars.side.status, 200, JSON.stringify( setup.vars.side.answer ).slice( 0, 300 ) );
	assert.equal( setup.vars.loc[ 0 ].status, 201, JSON.stringify( setup.vars.loc[ 0 ].json ).slice( 0, 300 ) );
} );

test( 'a week entered in the panel: ranges, «same as the day before», the warning on a range that will not be kept, stored canonical', () => {
	assert.match( v( 'empty' ).legend, /lunes|monday/i, 'the day\'s name, in the editor\'s language, as the group\'s legend' );
	assert.match( v( 'empty' ).monday, /Cerrado/, 'an empty day reads «Closed»' );
	assert.equal( v( 'monday-range' ).inputs, 2 );
	assert.match( v( 'labels' ).id, /mo-0-0$/ );
	assert.match( v( 'labels' ).label, /(lunes|monday).*1/i, 'each input has its label with the day and the range' );
	assert.match( v( 'warning' ).text, /no se guardará|will not be saved/ );
	assert.equal( v( 'warning' ).describedby, v( 'warning' ).id, 'the warning is tied to the range' );
	const six = [ '08:00-16:00' ];
	assert.equal( v( 'stored' ).hours, JSON.stringify( { mo: six, tu: six, we: six, th: six, fr: six, sa: six } ), 'Monday copied down to Saturday; Sunday\'s 10:00-10:00 not kept' );
	const errors = errorsOf( get( null, 'stored' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
