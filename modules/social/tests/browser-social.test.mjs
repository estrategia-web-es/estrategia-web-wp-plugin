// NIVEL: 1 — reads the browser scenario «41-social»: the social block's preview in the editor, and the «Redes sociales» screen saving in the order on screen and saving an empty list. Opens no browser.
/**
 * Was social-admin.test.mjs (level 3) until 29-10-2026; every assertion
 * kept. The reloaded screen is drawn from the SAVED option, so what it shows
 * is what was stored. «Company details has no fixed network fields and the
 * menu has Social networks» is plain HTML: social.test.mjs (level 2).
 * The scenario waits for the editor's preview FACT (Instagram in the canvas)
 * and for each save's navigation.
 * Seen red (28-10-2026) with the screen's renumbering of name="…[n]…" removed
 * (the saved order came out as typed), with migrate() returning early, and
 * with the block title expected at «Redes». Seen red (29-10-2026) with the
 * saved order expected as typed; restored, green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { artifacts, errorsOf } from '../../../tests/browser.mjs';

const { get } = artifacts( '41-social' );
const v = ( label ) => get( null, label ).values;

test( 'editor: the “Social networks” block shows in the editor (server preview, with the migrated network), without errors', () => {
	assert.equal( v( 'editor' ).title, 'Redes sociales' );
	const errors = errorsOf( get( null, 'editor' ), { pageOnly: true } );
	assert.deepEqual( errors, [], 'no JavaScript errors in the editor:\n' + errors.join( '\n' ) );
} );

test( '“Social networks” screen: from the migrated one, adds, moves up, removes and saves in the order on screen; an emptied list is saved EMPTY', () => {
	const screen = v( 'screen' );
	assert.equal( screen.h1, 'Redes sociales' );
	assert.equal( screen.rows, 2, 'the company one and the empty one' );
	assert.equal( screen.firstUrl, 'https://instagram.com/prueba' );
	assert.match( screen.firstPreview, /<svg/ );
	assert.match( v( 'typed' ).preview, /<svg/, 'the icon preview is guessed while typing' );
	assert.equal( v( 'added' ).rows, 3 );
	assert.equal( v( 'arranged' ).rows, 2 );
	// The form posts to options.php, which redirects back with settings-updated (wp-admin then removes it from the address bar: the network says it).
	const network = get( null, 'saved' ).network;
	assert.ok( network.some( ( r ) => 'POST' === r.method && /\/options\.php$/.test( r.url ) && 302 === r.status ), 'the screen was not saved through options.php' );
	assert.ok( network.some( ( r ) => 'document' === r.type && /settings-updated=true/.test( r.url ) ), 'options.php did not come back with settings-updated' );
	assert.deepEqual( v( 'saved' ).shown, [ [ 'Correo', 'mailto:hola@ejemplo.test' ], [ '', 'https://instagram.com/prueba' ], [ '', '' ] ], 'saved in the order on screen (and the new empty row)' );
	assert.equal( v( 'emptied' ).rows, 1 );
	assert.deepEqual( v( 'saved-empty' ).shown, [ [ '', '' ] ], 'an EMPTY list is saved: it does not go back to the company ones (they would be migrated and shown)' );
	const errors = errorsOf( get( null, 'saved-empty' ), { pageOnly: true } );
	assert.deepEqual( errors, [], 'no JavaScript errors:\n' + errors.join( '\n' ) );
} );
