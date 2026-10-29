// NIVEL: 1 — reads the browser scenario «19-forms-editor»: the synced form keeps its preselection on save, the blocks are registered and a new form gets its template, the preselection follows its TEXT, and a notification link opens the form with it selected. Opens no browser.
/**
 * Was admin.test.mjs (level 3) until 29-10-2026; every assertion kept, on
 * ONE login and ONE editor boot as before. The scenario waits for the FACTS
 * (saved and not dirty, the new form's inner blocks, the options changed in
 * the block, the notification selected) and fails naming them.
 * Seen red (28-10-2026) with the select block.json without
 * «pattern/overrides» in usesContext (the saved page no longer preselects),
 * the inner template with a text field instead of the textarea, and
 * followOption() returning the old position. Seen red (29-10-2026) with the
 * inner template expected with a text field, and with the «To» expected as
 * «{{nombre}}»; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '19-forms-editor' );
const v = ( label ) => get( null, label ).values;

test( 'editor: opening and saving a page with the synced form keeps its preselection', () => {
	const { content, html } = v( 'saved' );
	assert.match( content, /"content":\{"servicio":\{"defaultOption":3\}\}/, 'the editor does not drop the content it cannot render: ' + content );
	assert.match( html, /<option value="Barcos" selected>/, 'and the page still preselects' );
} );

test( 'editor: the blocks are registered and a new form comes with its template with two notifications', () => {
	const { names, inserter, form } = v( 'new-form' );
	assert.deepEqual( names, [ 'checkbox', 'email', 'form', 'form-ref', 'notification', 'select', 'submit', 'tel', 'text', 'textarea' ].map( ( n ) => 'estrategia-web-forms/' + n ) );
	assert.deepEqual( inserter, { form: false, ref: true }, '2.0.0: a page gets the reference block; the form tree lives in a form post' );
	assert.match( form.formId, /^form-[a-z0-9]+$/, 'the identifier is generated automatically' );
	assert.deepEqual( form.inner, [ 'text', 'email', 'textarea', 'checkbox', 'submit', 'notification', 'notification' ] );
} );

test( 'editor: the preselected option follows its TEXT when options are reordered or removed', () => {
	assert.equal( v( 'preselect' ).shown, '2', 'the panel shows the saved option' );
	assert.equal( v( 'reordered' ).defaultOption, 3, '«Restaurantes» moved to the third position and the preselection with it' );
	assert.equal( v( 'removed' ).defaultOption, 0, 'if the option disappears, no other one is preselected by accident' );
	const errors = errorsOf( get( null, 'removed' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );

test( 'editor: a notification link opens the form with that notification selected and its «To» in the sidebar', () => {
	const n = v( 'notification' );
	assert.equal( n.selected, 'Acuse al remitente', 'the second notification of «contacto»' );
	assert.equal( n.to, '{{email}}', 'its recipient, in the open sidebar' );
	const errors = errorsOf( get( null, 'notification' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
