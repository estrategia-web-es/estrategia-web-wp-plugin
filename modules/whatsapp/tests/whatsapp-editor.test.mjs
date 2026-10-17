// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: block registration in the editor (autoRegister) only exists when running the WordPress editor.
/**
 * estrategia-web/whatsapp in the editor: it exists with its Spanish title (the
 * test site's locale), it inserts without errors, and the automatic
 * autoRegister controls carry the translated labels from labels.php.
 * Came from the parent theme's tests/editor.test.mjs (17-10-2026), which
 * checked the three blocks at once; each module now checks its own.
 * Careful: Playground's --login does NOT serve a new browser; login() goes
 * through wp-login.php.
 */
import { test, withBrowser, assert, BASE_URL, blockSite, login } from '../../../tests/browser.mjs';

blockSite( 'whatsapp' );

test( 'whatsapp-editor', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage( { viewport: { width: 1400, height: 1000 } } );
		await login( page );
		await page.goto( `${ BASE_URL }/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.wp?.blocks?.getBlockType?.( 'estrategia-web/whatsapp' ), null, { timeout: 60000 } );

		const type = await page.evaluate( () => {
			const t = window.wp.blocks.getBlockType( 'estrategia-web/whatsapp' );
			return { title: t.title, label: t.attributes.phone?.label };
		} );
		assert.equal( type.title, 'Botón de WhatsApp' );
		assert.equal( type.label, 'Teléfono en formato internacional (vacío: la opción del sitio)', 'autoRegister label untranslated' );

		const inserted = await page.evaluate( () => {
			const { createBlock } = window.wp.blocks;
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( [ createBlock( 'estrategia-web/whatsapp', { phone: '34600111222' } ) ] );
			return window.wp.data.select( 'core/block-editor' ).getBlocks().map( ( b ) => [ b.name, b.isValid, b.innerBlocks.length ] );
		} );
		assert.deepEqual( inserted.slice( -1 ), [ [ 'estrategia-web/whatsapp', true, 0 ] ] );

		const errors = page.errors.filter( ( e ) => ! /favicon/.test( e ) );
		assert.deepEqual( errors, [], errors.join( '\n' ) );
	} )
);
