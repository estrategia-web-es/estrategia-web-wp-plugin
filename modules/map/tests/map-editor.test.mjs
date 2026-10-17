// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: block registration in the editor and the server preview (ServerSideRender) only exist when running the WordPress editor.
/**
 * estrategia-web/map in the editor: it exists with its Spanish title (the test
 * site's locale), it inserts without errors and its server preview arrives.
 * Came from the parent theme's tests/editor.test.mjs (17-10-2026), which
 * checked the three blocks at once; each module now checks its own.
 * Careful: Playground's --login does NOT serve a new browser; login() goes
 * through wp-login.php.
 */
import { test, withBrowser, assert, BASE_URL, blockSite, login } from '../../../tests/browser.mjs';

blockSite( 'map' );

test( 'map-editor', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage( { viewport: { width: 1400, height: 1000 } } );
		await login( page );
		await page.goto( `${ BASE_URL }/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.wp?.blocks?.getBlockType?.( 'estrategia-web/map' ), null, { timeout: 60000 } );

		assert.equal( await page.evaluate( () => window.wp.blocks.getBlockType( 'estrategia-web/map' ).title ), 'Mapa (en dos clics)' );

		const inserted = await page.evaluate( () => {
			const { createBlock } = window.wp.blocks;
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( [ createBlock( 'estrategia-web/map', { address: 'Calle Falsa 1' } ) ] );
			return window.wp.data.select( 'core/block-editor' ).getBlocks().map( ( b ) => [ b.name, b.isValid, b.innerBlocks.length ] );
		} );
		assert.deepEqual( inserted.slice( -1 ), [ [ 'estrategia-web/map', true, 0 ] ] );

		// The map's server preview (ServerSideRender) arrives.
		const editorFrame = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await editorFrame.getByText( 'Calle Falsa 1' ).first().waitFor( { timeout: 30000 } );

		const errors = page.errors.filter( ( e ) => ! /favicon/.test( e ) );
		assert.deepEqual( errors, [], errors.join( '\n' ) );
	} )
);
