// NIVEL: 1 — reads the browser scenario «15-ct-constructor»: a person makes a content type on the constructor screens — the error in the alert region, three fields with tabs, rows, keyboard and drag reordering and a group, the confirmation of a change that moves published things, the registered type, and «Add from template». Opens no browser.
/**
 * Was constructor-editor.test.mjs (level 3) until 29-10-2026; every
 * assertion kept, in the same single pass. Two changes in HOW:
 * - «close every open row» clicked the open toggles one by one in a loop; the
 *   scenario clicks every open toggle in one «run» (the same click handler),
 *   and then a real click opens one row and closes it again (what is tested);
 * - the published entry made «while the person goes on» is made by the same
 *   administrator's browser (wp.apiFetch) instead of a second REST client.
 * What was stored is the REST answer to the screen's own saves (the
 * scenario keeps those response bodies).
 * Seen red (17-10-2026): with the submit handler not calling save(), with
 * alarm() not focusing the region. 2.0.0 (27-10-2026): with the Alt + arrow
 * handler removed, with the drop handler not calling moveField(). Seen red
 * (29-10-2026) expecting another group for «Terraza» and the focus NOT on the
 * alert; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '15-ct-constructor' );
const v = ( label ) => get( null, label ).values;
const answer = ( label, re ) => get( null, label ).network.filter( ( r ) => 'POST' === r.method && re.test( r.url ) ).pop();

test( 'from the list\'s «Add new», an error goes to the alert region, which takes the focus', () => {
	const e = v( 'error' );
	assert.match( e.url, /new=1/ );
	assert.match( e.alert, /prefix|prefijo/i );
	assert.equal( e.focused, true, 'focus on the alert' );
} );

test( 'then «Restaurantes» with three fields: tabs, rows that open, keyboard and drag reordering, a field group, saved', () => {
	assert.equal( v( 'error' ).general, 'true' );
	assert.equal( v( 'tabs' ).urls, 'true', 'ArrowRight selects the next tab' );
	assert.equal( v( 'tabs' ).generalHidden, true );
	assert.equal( v( 'named' ).name, 'gment_restaurante', 'the internal name is suggested' );
	assert.equal( v( 'new-field' ).expanded, 'true', 'a new field opens' );
	assert.equal( v( 'new-field' ).focusInside, true, 'focus inside it' );
	assert.match( v( 'three-fields' ).head, /Terraza.*gment_terraza/, 'the row\'s head follows its settings' );
	assert.deepEqual( v( 'three-fields' ).unlabelled, [] );
	assert.equal( v( 'collapsed' ).openBodies, 0, 'all collapsed' );
	assert.equal( v( 'one-open' ).open, true );
	assert.deepEqual( v( 'keyboard' ).order, [ 'gment_telefono', 'gment_terraza', 'gment_precio' ] );
	assert.equal( v( 'keyboard' ).focusField, '1', 'the focus follows the field' );
	assert.deepEqual( v( 'dragged' ).order, [ 'gment_telefono', 'gment_precio', 'gment_terraza' ], 'dragged back to the end' );
	assert.equal( v( 'group' ).key, 'mas_datos', 'the key follows the title' );
	const saved = answer( 'saved', /\/estrategia-web\/v1\/content-types(\?|$)/ );
	assert.equal( saved?.status, 201 );
	assert.match( v( 'saved' ).url, /&type=gment_restaurante$/, 'the screen stays on the type, at its own URL' );
	assert.equal( v( 'saved' ).focusNotice, true, 'focus on the notice' );
	const def = saved.response.definition;
	assert.deepEqual( def.fields.map( ( f ) => [ f.name, f.type ] ), [ [ 'gment_telefono', 'tel' ], [ 'gment_precio', 'select' ], [ 'gment_terraza', 'boolean' ] ] );
	assert.deepEqual( def.fields[ 1 ].options, [ { value: 'barato', label: { es: 'Barato' } }, { value: 'caro', label: { es: 'Caro' } } ] );
	assert.deepEqual( def.groups, [ { key: 'mas_datos', label: { es: 'Más datos' }, collapsed: true } ] );
	assert.equal( def.fields[ 2 ].group, 'mas_datos' );
} );

test( 'then a change that moves published things asks first; «Save anyway» saves it; the type, its menu and its fields appear', () => {
	assert.equal( v( 'entry' ).entry?.type, 'gment_restaurante', `the published entry was not made: ${ JSON.stringify( v( 'entry' ).entry ) }` );
	assert.equal( v( 'removed' ).focusField, '1', 'focus to the row that is now last' );
	assert.match( v( 'asks' ).alert, /gment_terraza/ );
	const def = answer( 'confirmed', /content-types\/gment_restaurante(\?|$)/ ).response.definition;
	assert.equal( def.fields.length, 2 );
	assert.equal( def.fields_box, 'main' );
	assert.equal( v( 'confirmed' ).advanced, 'true', 'the tab stays where it was' );
	const entry = v( 'new-entry' );
	assert.ok( entry.menu > 0, 'in the menu' );
	assert.equal( entry.boxes, 1, 'the fields in a meta box in the main area' );
	assert.deepEqual( entry.keys, [ 'gment_telefono', 'gment_precio' ], 'the two fields left, in their order' );
} );

test( '«Add from template»: a menu that Escape closes; a template is added and opens its edit screen; no errors', () => {
	assert.equal( v( 'menu-open' ).expanded, 'true' );
	assert.equal( v( 'menu-open' ).focusTemplate, true, 'focus on the first template' );
	assert.equal( v( 'menu-closed' ).hidden, true );
	assert.equal( v( 'menu-closed' ).focus, 'templates', 'focus back on the button' );
	assert.match( v( 'template' ).url, /type=eweb_testimonial/ );
	const errors = errorsOf( get( null, 'template' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
