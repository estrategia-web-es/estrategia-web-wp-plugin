// NIVEL: 1 — reads the browser scenario «35-blocks-editor»: the map, WhatsApp and carousel blocks are registered in the editor with their Spanish titles and translated labels, insert valid, and the map's server preview arrives. Opens no browser.
/**
 * Was map-editor.test.mjs (map and WhatsApp) and the editor test of
 * carousel.test.mjs (level 3) until 29-10-2026: two logins and two editor
 * boots became one. Every assertion kept:
 * - estrategia-web/map «Mapa (en dos clics)», estrategia-web/whatsapp «Botón
 *   de WhatsApp» with the autoRegister «phone» label from labels.php
 *   translated, estrategia-web/carousel «Carrusel»;
 * - inserted, the three are valid, the carousel with its two inner blocks;
 * - the map's ServerSideRender preview arrives in the canvas: the scenario
 *   waits for that FACT («Calle Falsa 1» in the canvas) and fails naming it.
 * The trap (carousel, 28-10-2026): our block can register BEFORE core's
 * library, and createBlock() of a core block then dies with «Maximum call
 * stack size exceeded»; the scenario waits for core/paragraph too.
 * Seen red (29-10-2026) with the WhatsApp «phone» label expected in other
 * words («autoRegister label untranslated») and the carousel title expected
 * at «Carrusel de fotos»; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '35-blocks-editor' );
const editor = get( null, 'editor' );

test( 'registered, with their Spanish titles and the translated autoRegister label', () => {
	const { titles, label } = editor.values;
	assert.equal( titles.map, 'Mapa (en dos clics)' );
	assert.equal( titles.whatsapp, 'Botón de WhatsApp' );
	assert.equal( titles.carousel, 'Carrusel' );
	assert.equal( label, 'Teléfono en formato internacional (vacío: la opción del sitio)', 'autoRegister label untranslated' );
} );

test( 'they insert valid (the carousel with its inner blocks), the map preview arrives, no errors', () => {
	assert.deepEqual( editor.values.inserted.slice( -3 ), [
		[ 'estrategia-web/map', true, 0 ],
		[ 'estrategia-web/whatsapp', true, 0 ],
		[ 'estrategia-web/carousel', true, 2 ],
	] );
	const errors = errorsOf( editor, { ignore: /favicon/ } );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
} );
