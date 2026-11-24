// NIVEL: 1 — reads the browser scenario «16-ct-editor-panel»: blocks bound to estrategia-web/content-field show the formatted value, the source declares its fields, and the «details» panel stores what is typed with its JSON types; a repeater edited in the panel and in the classic meta box. Opens no browser.
/**
 * Was editor-panel.test.mjs (level 3) until 29-10-2026; every assertion
 * kept, with one editor boot per post as before (the restaurant, whose
 * content is the bindings' Queries, and the wine).
 * The waits are FACTS of the scenario: the editor booted, the panel painted
 * (expanded if the first editor of a fresh WordPress paints it collapsed:
 * the 28-10-2026 root cause), the related post's title in the canvas, the
 * editor's save promise resolved.
 * Seen red (15-10-2026): with the number case of Field() sending the raw
 * string — the real break is the emptied field: "" is refused by REST.
 * Seen red (16-10-2026, bindings): with format() returning the stored meta as
 * it is. Seen red (29-10-2026) with the price label in the canvas and the
 * stored alcohol expected otherwise; restored, green.
 * The repeater (24-11-2026): an esquela (examples/llest_esquela.json) in the
 * sidebar panel and a winery's visits in the classic meta box. Seen red with
 * the panel's Move up leaving the row where it was (the scenario's wait for
 * «Vetlla» first never held); and, for real on its first run, the box's
 * Remove read «Quitar»: its text key collided with the image picker's
 * «remove» in Fields_Panel::enqueue_box() (now «rowRemove»).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get, setup } = artifacts( '16-ct-editor-panel' );
const v = ( label ) => get( null, label ).values;

test( 'the setup made the restaurants and the wine', () => {
	assert.deepEqual( setup.vars.made.map( ( a ) => a.status ), [ 201, 201 ], JSON.stringify( setup.vars.made ).slice( 0, 300 ) );
	assert.equal( setup.vars.wine[ 0 ].status, 201, JSON.stringify( setup.vars.wine ).slice( 0, 300 ) );
} );

test( 'bindings: the editor shows the formatted value — the option\'s label, the related post\'s title, the boolean\'s text, the number', () => {
	const { texts } = v( 'bindings' );
	assert.equal( texts.price, '€€€ — alto' );
	assert.equal( texts.winery, 'Ca n’Andreu' );
	assert.equal( texts.organic, 'Ecológico' );
	assert.match( texts.alcohol, /^13[.,]5$/ );
	assert.equal( texts.released, '[Released]', 'an empty field shows its name in brackets' );
} );

test( 'bindings: the source is registered with its label, is not editable from the block, and lists the fields of the type in context', () => {
	const { source } = v( 'bindings' );
	assert.ok( source, 'registered in the editor' );
	assert.equal( source.label, 'Campo de contenido' );
	assert.ok( source.context.includes( 'postId' ) && source.context.includes( 'postType' ), 'uses the Query context' );
	assert.equal( source.editable, false );
	assert.ok( source.wine.includes( 'gment_winery:string' ) && source.wine.includes( 'gment_organic:string' ), source.wine.join( ', ' ) );
	assert.ok( source.wine.includes( 'gment_label:number' ), 'image (and relation) also as number, for core/image\'s id' );
	assert.deepEqual( source.page, [], 'a page has no fields of ours: the source is not offered' );
} );

test( 'restaurant: the panel shows the JSON\'s fields in the site language, and what is typed there is stored', () => {
	const p = v( 'panel' );
	assert.match( p.title, /Restaurante/ );
	assert.equal( p.address, 'Plaça Major, 3' );
	assert.match( p.addressText, /Dirección \*/, 'required is marked' );
	assert.equal( p.phoneType, 'tel' );
	assert.equal( p.price, '€€', 'the default is selected' );
	assert.deepEqual( p.priceOptions, [ '€ — económico', '€€ — medio', '€€€ — alto' ], 'es labels, no «None» (it has a default)' );
	const { post } = v( 'restaurant' );
	assert.equal( post.status, 'publish' );
	assert.equal( post.meta.gment_phone, '971 12 34 56' );
	assert.equal( post.meta.gment_price_range, '€€€' );
	assert.equal( post.meta.gment_website, 'https://esraco.example' );
} );

test( 'wine: number, boolean, date and relation travel with their types, and an emptied number as null', () => {
	const { post } = v( 'wine' );
	assert.equal( post.status, 'publish', JSON.stringify( post ).slice( 0, 300 ) );
	assert.equal( post.meta.gment_vintage, 2019 );
	assert.equal( post.meta.gment_alcohol, 14.5 );
	assert.equal( post.meta.gment_released, '2021-03-01' );
	assert.equal( post.meta.gment_organic, true );
	assert.equal( post.meta.gment_winery, setup.vars.made[ 0 ].json.id );
	const emptied = v( 'emptied' );
	assert.equal( emptied.dirty, false, 'saved without errors' );
	// Read from REST: the editor keeps its own copy of a deleted meta key.
	assert.equal( emptied.rest, 0, 'back to the default' );
} );

test( 'no JavaScript errors in the editor', () => {
	const errors = errorsOf( get( null, 'emptied' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );

// The repeater (24-11-2026): llest_esquela's acts (examples/) in the sidebar panel, and gment_winery's
// visits (tests/fixtures/valid/) in the classic screen's meta box. The site speaks es_ES.
test( 'repeater in the sidebar panel: a row per act with its labelled subfields, Add / Move up / Move down / Remove with the row in their names, focus following the row', () => {
	const empty = v( 'repeater-empty' );
	assert.equal( empty.legend, 'Actos' );
	assert.equal( empty.add, 'Añadir Acto', 'the button says what a row is called' );
	assert.equal( empty.rows, 0 );
	const added = v( 'repeater-added' );
	assert.equal( added.legend, 'Acto 1' );
	assert.deepEqual( added.labels, [ 'Tipo *→SELECT', 'Día *→INPUT', 'Hora→INPUT', 'Hasta→INPUT', 'Lugar→INPUT' ], 'each subfield with its label tied to its control; required marked' );
	assert.deepEqual( added.options.slice( 1 ), [ 'Velatorio', 'Funeral', 'Rosario', 'Exequias', 'Duelo', 'Misa' ], 'the options\' labels in the site language' );
	assert.deepEqual( added.types, [ 'date', 'time', 'time', 'text' ] );
	assert.deepEqual( added.buttons, [ 'Subir|Subir Acto 1|true', 'Bajar|Bajar Acto 1|true', 'Eliminar|Eliminar Acto 1|false' ], 'real buttons, their visible text at the start of their name; a lone row neither moves nor stops a removal' );
	assert.equal( added.focus, 'SELECT', 'a new row takes the focus' );
	const moved = v( 'repeater-moved' );
	assert.deepEqual( moved.order, [ 'Vetlla', 'Funeral' ] );
	assert.deepEqual( moved.legends, [ 'Acto 1', 'Acto 2' ], 'the names follow the position' );
	assert.equal( moved.focus, '0:SELECT', 'the focus went with the row (to its first control: at the top it cannot move up again)' );
	const { post } = v( 'esquela' );
	assert.equal( post.status, 'publish', JSON.stringify( post ).slice( 0, 300 ) );
	assert.deepEqual( post.actes, [
		{ tipus: 'Vetlla', dia: '2026-11-24' },
		{ tipus: 'Funeral', dia: '2026-11-25', hora: '17:00', lloc: 'Parròquia de Sant Miquel' },
	], 'stored in the order shown; the removed empty row is gone; REST gives the list back' );
} );

test( 'repeater in the meta box (fields-box.js): the spare row becomes the template, Add up to max, rows renamed by position, input names kept unique, focus and an announcement', () => {
	const start = v( 'box-start' );
	assert.equal( start.rows, 0, 'the spare empty row PHP adds for the form without JavaScript is gone' );
	assert.equal( start.add, 'Añadir Visita' );
	const moved = v( 'box-moved' );
	assert.deepEqual( moved.rows.map( ( r ) => r.legend + '=' + r.day ), [ 'Visita 1=2026-12-12', 'Visita 2=2026-12-05' ] );
	assert.deepEqual( moved.rows.map( ( r ) => r.name ), [ 'estrategia_web_ct[gment_winery_visits][2][dia]', 'estrategia_web_ct[gment_winery_visits][1][dia]' ], 'a moved row keeps its number: the order saved is the form\'s' );
	assert.ok( moved.rows.every( ( r ) => r.labelFor ), 'each new label points at its own input' );
	assert.deepEqual( moved.buttons, [ 'Subir|Subir Visita 1|true', 'Bajar|Bajar Visita 1|false', 'Eliminar|Eliminar Visita 1|false' ] );
	assert.equal( moved.focus, 'move-down:Visita 1', 'at the top its Move up is disabled: the focus goes to Move down of the same row' );
	const full = v( 'box-full' );
	assert.equal( full.rows, 3 );
	assert.equal( full.add, true, 'max 3: Add is disabled' );
	assert.equal( new Set( full.names ).size, 3, 'three different numbers: ' + full.names.join( ', ' ) );
	const removed = v( 'box-removed' );
	assert.deepEqual( removed.rows, [ 'Visita 1=2026-12-05', 'Visita 2=' ] );
	assert.equal( removed.add, false );
	assert.equal( removed.status, 'Se ha eliminado la fila.' );
	assert.equal( removed.focus, 'date:Visita 1', 'the focus goes to the row now in its place' );
	const errors = errorsOf( get( null, 'box-removed' ), { pageOnly: true } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
