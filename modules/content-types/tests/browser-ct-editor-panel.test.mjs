// NIVEL: 1 — reads the browser scenario «16-ct-editor-panel»: blocks bound to estrategia-web/content-field show the formatted value, the source declares its fields, and the «details» panel stores what is typed with its JSON types. Opens no browser.
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
