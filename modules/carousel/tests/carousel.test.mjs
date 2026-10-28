// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: scrolling (scrollLeft, scroll-snap), focus and the width of each slide only exist with the page painted, and the block's registration only exists in the running editor.
/**
 * estrategia-web/carousel, the basics (merged on 28-10-2026 from
 * carousel.test.mjs, carousel-query.test.mjs and carousel-editor.test.mjs,
 * which seeded the same site three times and launched three browsers):
 * - /prueba-carrusel/ (five slides, no loop): moves forward and back with the
 *   arrows, with the keyboard (← → End) and with the dots; the arrows get
 *   disabled at the ends.
 * - /prueba-carrusel-consulta/ (a core/query inside, 0.2.0): each <li> of the
 *   post-template is a slide — two are seen per row, there is one dot per
 *   post, the arrow advances ONE post and "End" reaches the last one.
 * - the editor: the block exists with its Spanish title (the test site's
 *   locale), it inserts without errors and it accepts inner blocks. Its
 *   login and boot start in before(), while the two pages are tested.
 * - the photos (round 2, 28-10-2026: carousel-images.test.mjs became
 *   ./images.mjs, started in before() with the rest; it paid a reset, a
 *   seed and a browser of its own; its checks read no animation): priority, deferring, the downloaded size, the mobile crop
 *   and the desktop preload.
 * - any number of slides (round 2 too: carousel-any-count.test.mjs became
 *   ./any-count.mjs, started in before() with the rest): 7 of 3 and 23 in a row
 *   or in a 12 + 11 grid wrap across the seam without going back.
 * Pages seeded in modules/carousel/tests/wp/estrategia-web-carousel-test.php.
 * The waits are for FACTS (scrollLeft changes), never for time.
 * Careful: Playground's --login does NOT serve a new browser; the editor
 * logs in through wp-login.php.
 * Seen red (28-10-2026) with the expected aria-roledescription at «carousel»,
 * the expected dot count of the query at 5 and the expected block title at
 * «Carrusel de fotos».
 */
// Round 2 (28-10-2026), the merged file seen red at once: the dots' goTo() emptied in view.min.js
// (the basic and query tests), preload_config() never preloading (photos: «expected one image
// preload, there are 0»), the grid expected at 12 + 12 (any count) and the block title expected
// at «Carrusel de fotos» (editor).
import { before, after } from 'node:test';
import { test, assert, BASE_URL, BLOCK_MODULES } from '../../../tests/browser.mjs';
import { playwright, reset, testApi } from '../../../tests/lib.mjs';
import { checkImages } from './images.mjs';
import { checkAnyCount } from './any-count.mjs';

// What blockSite('carousel') does (tests/browser.mjs), written out HERE, in this file's own
// before(): this file starts loading pages INSIDE before() (they load while other pages are
// tested), and with blockSite's separate before() the page loads started before its seed had run
// (28-10-2026, logged: this before() at …399.1 s, the 404s at …402 s, the seed at …408.5 s). On a
// used WordPress the pages of an earlier seed hid it; on a fresh one every page answered 404
// («scrollIntoViewIfNeeded: Timeout»). The trap: never start loading pages in a before() that
// does not itself await the seed.

let browser, editor, basic, query, images, anyCount;

/** A page in a fresh context that records console errors and page errors (as withBrowser's newPage). */
const newPage = async ( options = {} ) => {
	const page = await ( await browser.newContext( options ) ).newPage();
	page.errors = [];
	page.on( 'console', ( m ) => m.type() === 'error' && page.errors.push( `console: ${ m.text() } @ ${ m.location().url }` ) );
	page.on( 'pageerror', ( e ) => page.errors.push( `pageerror: ${ e.message }` ) );
	return page;
};

before( async () => {
	await reset();
	await testApi( '/modules', { method: 'POST', body: { active: BLOCK_MODULES } } );
	// The seed in its own request, after the list is saved (the modules load when the plugin does).
	const [ seed, launched ] = await Promise.all( [ fetch( `${ BASE_URL }/wp-json/estrategia-web-carousel-test/v1/seed`, { method: 'POST' } ), playwright().chromium.launch() ] );
	browser = launched;
	if ( ! seed.ok ) {
		throw new Error( `seed carousel -> ${ seed.status } ${ await seed.text() }` );
	}
	// The editor's login and boot, in the background (awaited by its test).
	editor = ( async () => {
		const page = await newPage( { viewport: { width: 1400, height: 1000 } } );
		await page.goto( `${ BASE_URL }/wp-login.php?redirect_to=${ encodeURIComponent( `${ BASE_URL }/wp-admin/post-new.php?post_type=page` ) }` );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await Promise.all( [ page.waitForURL( /post-new\.php/ ), page.click( '#wp-submit' ) ] );
		// Also core's paragraph: our block can register BEFORE core's library, and createBlock() of a
		// core block then dies with «RangeError: Maximum call stack size exceeded» (28-10-2026: the
		// editor had 2 block types when ours appeared; 3 seconds later, 136).
		await page.waitForFunction( () => window.wp?.blocks?.getBlockType?.( 'estrategia-web/carousel' ) && window.wp.blocks.getBlockType( 'core/paragraph' ), null, { timeout: 60000 } );
		return page;
	} )();
	editor.catch( () => {} ); // Its error is reported by its test.
	// The two pages load meanwhile too (the waiting is WordPress's); each test uses its own.
	const open = async ( path ) => {
		const page = await newPage( { viewport: { width: 1200, height: 900 } } );
		await page.goto( `${ BASE_URL }${ path }`, { waitUntil: 'load' } );
		return page;
	};
	[ basic, query ] = [ open( '/prueba-carrusel/' ), open( '/prueba-carrusel-consulta/' ) ];
	basic.catch( () => {} );
	query.catch( () => {} );
	// And the photos (./images.mjs): four loads with their own checks, none of them animated.
	images = checkImages( newPage );
	images.catch( () => {} );
	// And «any number of slides» (./any-count.mjs): its checks are positions (no step back, which
	// column is at the edge), not frame counts, so sharing the machine slows it but cannot fake it.
	anyCount = checkAnyCount( newPage );
	anyCount.catch( () => {} );

} );
after( async () => {
	await browser?.close();
	await testApi( '/modules', { method: 'POST', body: { active: null } } ); // As blockSite's after().
} );

test( 'carousel: arrows, keyboard (← → End) and dots; the arrows get disabled at the ends', async () => {
	const page = await basic;

	const carousel = page.locator( '.estrategia-web-carousel' );
	const track = carousel.locator( '.estrategia-web-carousel__track' );
	const prev = page.getByRole( 'button', { name: 'Diapositiva anterior' } );
	const next = page.getByRole( 'button', { name: 'Diapositiva siguiente' } );
	const scrollLeft = () => track.evaluate( ( el ) => el.scrollLeft );

	await carousel.scrollIntoViewIfNeeded();
	// waitUntil 'load', not 'networkidle' (half a second idle per page): the hydration is waited for as a fact.
	await page.waitForFunction( ( el ) => el.classList.contains( 'is-ready' ), await carousel.elementHandle(), { timeout: 5000 } ).catch( () => {} );
	assert.ok( await carousel.evaluate( ( el ) => el.classList.contains( 'is-ready' ) ), 'it did not hydrate (no is-ready)' );
	assert.equal( await carousel.getAttribute( 'aria-roledescription' ), 'carrusel' );
	assert.ok( await prev.isDisabled(), 'at the start, "previous" should be disabled' );

	// Next arrow.
	await next.click();
	await page.waitForFunction( ( el ) => el.scrollLeft > 50, await track.elementHandle() );
	await page.waitForFunction( ( el ) => ! el.disabled, await prev.elementHandle() );
	const afterArrow = await scrollLeft();

	// Keyboard: focus on the track and right arrow.
	await track.focus();
	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction( ( [ el, start ] ) => el.scrollLeft > start + 50, [ await track.elementHandle(), afterArrow ] );

	// End: it reaches the end and "next" gets disabled.
	await page.keyboard.press( 'End' );
	await page.waitForFunction( ( el ) => el.disabled, await next.elementHandle() );

	// The previous arrow goes back.
	const atEnd = await scrollLeft();
	await prev.click();
	await page.waitForFunction( ( [ el, start ] ) => el.scrollLeft < start - 50, [ await track.elementHandle(), atEnd ] );

	// Dots: the first one goes back to the start and stays marked.
	await page.getByRole( 'button', { name: 'Ir a la diapositiva 1' } ).click();
	await page.waitForFunction( ( el ) => el.scrollLeft < 1, await track.elementHandle() );
	await page.waitForFunction( () => document.querySelector( '.estrategia-web-carousel__dot' ).getAttribute( 'aria-current' ) === 'true' );

	assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
} );

test( 'carousel with a query: each post is a slide (two per row, one dot per post), the arrow advances one post, End reaches the last', async () => {
	const page = await query;

	const carousel = page.locator( '.estrategia-web-carousel' );
	const track = carousel.locator( '.estrategia-web-carousel__track' );
	const slides = track.locator( '.wp-block-post-template > li' );
	await carousel.scrollIntoViewIfNeeded();

	assert.equal( await slides.count(), 6, 'the query should bring six posts' );
	// waitUntil 'load', not 'networkidle' (half a second idle per page): the hydration is waited for as a fact.
	await page.waitForFunction( ( el ) => el.classList.contains( 'is-ready' ), await carousel.elementHandle(), { timeout: 5000 } ).catch( () => {} );
	assert.ok( await carousel.evaluate( ( el ) => el.classList.contains( 'is-ready' ) ), 'it did not hydrate (no is-ready)' );

	// Two per row: each post is less than half the track wide and they are IN A ROW.
	const trackWidth = await track.evaluate( ( el ) => el.clientWidth );
	const boxes = await slides.evaluateAll( ( els ) => els.map( ( el ) => el.getBoundingClientRect() ) );
	assert.ok( boxes[ 0 ].width <= trackWidth / 2 + 1, `the post is ${ boxes[ 0 ].width }px wide in a ${ trackWidth }px track` );
	assert.ok( Math.abs( boxes[ 1 ].top - boxes[ 0 ].top ) < 2, 'the posts are not in a row (they stack)' );
	assert.ok( boxes[ 1 ].left > boxes[ 0 ].left, 'the second post is not to the right of the first' );

	// One dot per post, not one per inner block (which would be one: the query).
	assert.equal( await page.locator( '.estrategia-web-carousel__dot' ).count(), 6, 'there should be one dot per post' );

	// The arrow advances exactly one post.
	const offsets = await slides.evaluateAll( ( els ) => els.map( ( el ) => el.offsetLeft - els[ 0 ].offsetLeft ) );
	await page.getByRole( 'button', { name: 'Diapositiva siguiente' } ).click();
	await page.waitForFunction( ( [ el, target ] ) => Math.abs( el.scrollLeft - target ) < 2, [ await track.elementHandle(), offsets[ 1 ] ] );

	// End: it reaches the end and "next" gets disabled; the last dot stays marked.
	await track.focus();
	await page.keyboard.press( 'End' );
	const next = page.getByRole( 'button', { name: 'Diapositiva siguiente' } );
	await page.waitForFunction( ( el ) => el.disabled, await next.elementHandle() );
	await page.waitForFunction( () => document.querySelectorAll( '.estrategia-web-carousel__dot' )[ 5 ].getAttribute( 'aria-current' ) === 'true' );

	// A specific dot leads to its post.
	await page.getByRole( 'button', { name: 'Ir a la diapositiva 2' } ).click();
	await page.waitForFunction( ( [ el, target ] ) => Math.abs( el.scrollLeft - target ) < 2, [ await track.elementHandle(), offsets[ 1 ] ] );

	assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
} );

test( 'carousel in the editor: Spanish title, inserts without errors and accepts inner blocks', async () => {
	const page = await editor;
	assert.equal( await page.evaluate( () => window.wp.blocks.getBlockType( 'estrategia-web/carousel' ).title ), 'Carrusel' );

	const inserted = await page.evaluate( () => {
		const { createBlock } = window.wp.blocks;
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( [
			createBlock( 'estrategia-web/carousel', {}, [ createBlock( 'core/paragraph', { content: 'Uno' } ), createBlock( 'core/paragraph', { content: 'Dos' } ) ] ),
		] );
		return window.wp.data.select( 'core/block-editor' ).getBlocks().map( ( b ) => [ b.name, b.isValid, b.innerBlocks.length ] );
	} );
	assert.deepEqual( inserted.slice( -1 ), [ [ 'estrategia-web/carousel', true, 2 ] ] );

	const errors = page.errors.filter( ( e ) => ! /favicon/.test( e ) );
	assert.deepEqual( errors, [], errors.join( '\n' ) );
	await page.context().close(); // The editor keeps polling WordPress (heartbeat, autosave): closed before the photos.
} );

test( 'carousel with any number of slides: across the seam in one row and in a 12 + 11 grid, on a phone and with «reduce motion» (./any-count.mjs)', () => anyCount );

test( 'carousel with photos: priority, deferring, the downloaded size, the mobile crop and the desktop preload (./images.mjs)', () => images );
