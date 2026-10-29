// NIVEL: 1 — reads the browser scenario «40-site-location-editor»: paragraphs bound to the company and location sources show their values in the editor, the sources declare their fields, and the location's wide fields box saves what is typed with the editor's Save. Opens no browser.
/**
 * Was meta-panel-editor.test.mjs (level 3) until 29-10-2026; every assertion
 * kept, on ONE login and ONE editor boot as before. The server's HTML of
 * bound pages is bindings.test.mjs and location.test.mjs, the box's HTML and
 * its save as a request content-types/tests/location-box.test.mjs (level 2).
 * The old fixed wait («isSavingMetaBoxes turns true a tick after the post's
 * save ends»: 500 ms) is the FACT «the meta box's POST (meta-box-loader=1)
 * finished» in the scenario.
 * Seen red (17-10-2026) with the location template's «details» label removed.
 * (27-10-2026, for real) the box was INVISIBLE: WordPress 7.1 keeps the meta
 * boxes in a drawer that starts closed; Fields_Panel::enqueue() opens it.
 * Seen red (28-10-2026) with the expected Sunday hours after «Copiar el
 * lunes» changed, and the company source's label changed. Seen red
 * (29-10-2026) with the saved phone expected unchanged; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';
import { type } from '../../../tests/lib.mjs';

const { get, setup } = artifacts( '40-site-location-editor' );
const v = ( label ) => get( null, label ).values;

test( 'the location was made (the setup), with the registered type name', () => {
	const flow = fs.readFileSync( new URL( './browser/40-site-location-editor.json', import.meta.url ), 'utf8' );
	assert.ok( flow.includes( `"path": "/wp/v2/${ type( 'location' ) }"` ), `the scenario does not create a ${ type( 'location' ) }` );
	assert.equal( setup.vars.loc[ 0 ].status, 201, JSON.stringify( setup.vars.loc[ 0 ].json ).slice( 0, 300 ) );
} );

test( 'editor: a paragraph bound to estrategia-web-site/company:phone SHOWS the phone, and the source offers the list of fields', () => {
	const { paragraphs, company } = v( 'bindings' );
	assert.equal( paragraphs[ 0 ], '971 12 34 56' );
	assert.match( paragraphs[ 1 ], /\[(NIF\/CIF|Tax ID)\]/, 'an empty field shows its name in brackets' );
	assert.ok( company, 'the source is registered in the editor' );
	assert.equal( company.label, 'Datos de la empresa' );
	assert.ok( company.fields.includes( 'phone' ) && company.fields.includes( 'whatsapp_href' ) && company.fields.includes( 'social.instagram' ) );
	assert.equal( company.editable, false, 'the field is not edited from the paragraph' );
} );

test( 'editor (0.2.0): a paragraph bound to estrategia-web-site/location with slug SHOWS that location\'s field, and the source lists its keys', () => {
	const { paragraphs, location } = v( 'bindings' );
	assert.ok( paragraphs.some( ( p ) => p.includes( '971 00 00 00' ) ), 'the location\'s phone is not shown' );
	assert.match( paragraphs[ 3 ], /\[(Correo electrónico|Email)\]/, 'an empty field shows its name' );
	assert.ok( location, 'the locations source is registered in the editor' );
	assert.equal( location.label, 'Datos de la sede' );
	assert.ok( location.fields.includes( 'title' ) && location.fields.includes( 'estrategia_web_location_address' ) && location.fields.includes( 'estrategia_web_location_phone_href' ) );
	assert.ok( location.context.includes( 'postId' ), 'uses the Query context' );
} );

test( 'location: the «Datos de la sede» box visible in the main area, wide, grouped, with no sidebar panel', () => {
	const box = v( 'box' );
	assert.match( box.title, /Datos de la sede/ );
	assert.equal( box.panels, 0, 'no sidebar panel' );
	assert.equal( box.panelScript, 'undefined' );
	assert.deepEqual( box.groups, [ 'Contacto', 'Dirección', 'Horario', 'Mapa', 'Para Google' ] );
	assert.equal( box.googleOpen, null, '«Para Google» starts closed' );
	assert.ok( box.width > 700, 'wide: the main area, not the 280 px sidebar' );
} );

test( 'location: what is typed in the box (and filled by its JavaScript) is saved with the editor\'s Save', () => {
	assert.equal( v( 'box' ).phone, '971 00 00 00' );
	assert.equal( v( 'filled' ).sunday, '08:00-23:00' );
	assert.equal( v( 'filled' ).mediaButton, 'Elegir imagen' );
	const saved = v( 'saved' ).meta;
	assert.equal( saved.estrategia_web_location_phone, '971 99 88 77' );
	assert.equal( saved.estrategia_web_location_street, 'Centro Comercial Maioris\nCalle Imperial, 2, Local 12' );
	assert.equal( saved.estrategia_web_location_hours, JSON.stringify( Object.fromEntries( [ 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' ].map( ( d ) => [ d, [ '08:00-23:00' ] ] ) ) ) );
	assert.equal( saved.estrategia_web_location_locality, 'Llucmajor', 'what was not touched stays the same' );
} );

test( 'no JavaScript errors in the editor, from the boot to the save', () => {
	const errors = errorsOf( get( null, 'saved' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
