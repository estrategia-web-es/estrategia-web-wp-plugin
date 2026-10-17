// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: that each post of the Query is a slide is a measurement of boxes (widths, scrollLeft), which only exists when painted.
/**
 * estrategia-web/carousel with a core/query inside (0.2.0): each <li> of the
 * post-template is a slide — two are seen per row, there is one dot per
 * post, the arrow advances ONE post and "End" reaches the last one.
 * Page seeded in modules/carousel/tests/wp/estrategia-web-carousel-test.php (/prueba-carrusel-consulta/).
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'carousel' );

test( 'carousel-query', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage( { viewport: { width: 1200, height: 900 } } );
		await page.goto( `${ BASE_URL }/prueba-carrusel-consulta/`, { waitUntil: 'networkidle' } );

		const carousel = page.locator( '.estrategia-web-carousel' );
		const track = carousel.locator( '.estrategia-web-carousel__track' );
		const slides = track.locator( '.wp-block-post-template > li' );
		await carousel.scrollIntoViewIfNeeded();

		assert.equal( await slides.count(), 6, 'the query should bring six posts' );
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
	} )
);
