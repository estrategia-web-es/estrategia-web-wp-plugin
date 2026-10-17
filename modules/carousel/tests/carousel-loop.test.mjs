// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: wrapping around means scrolling the track (scrollLeft) and reading whether a button is disabled after hydration, which only exists with the page painted.
/**
 * Looping estrategia-web/carousel (0.5.0, loop attribute, true by default):
 * 1. on load, BOTH arrows are enabled (before, "previous" came out grey);
 * 2. "previous" on the first one shows the last one;
 * 3. "next" on the last one goes back to the first one;
 * 4. the same with the keyboard ← →, and the same with a core/query inside,
 *    where the slides are the posts.
 *
 * On /prueba-carrusel-bucle/ (modules/carousel/tests/wp/estrategia-web-carousel-test.php): two carousels
 * WITHOUT the loop attribute, so it also tests the default value.
 * Seen failing on 03-10-2026 with 0.4.0: "previous" disabled on load.
 * The waits are for FACTS (scrollLeft), never for time.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

blockSite( 'carousel' );

/*
 * What IS SEEN is checked, not scrollLeft (changed in 0.6.0): since the
 * infinite mode the track rotates its slides and "the last one" is no longer
 * the physical end of the track. That the loop also does not rewind is
 * guarded by carousel-infinite.test.mjs.
 * Both functions run INSIDE the page (waitForFunction serialises them): they
 * cannot call anything from this file.
 */
/** The last slide is entirely in view. */
const lastInView = ( el ) => {
	const all = [ ...el.children ].flatMap( ( c ) => ( c.classList.contains( 'wp-block-query' ) ? [ ...c.querySelectorAll( ':scope > .wp-block-post-template > li' ) ] : [ c ] ) );
	const r = all[ all.length - 1 ].getBoundingClientRect();
	const t = el.getBoundingClientRect();
	return r.left >= t.left - 1 && r.right <= t.right + 1;
};
/** The first slide sits at the left edge. */
const firstAtStart = ( el ) => {
	const first = el.children[ 0 ].classList.contains( 'wp-block-query' ) ? el.querySelector( 'li' ) : el.children[ 0 ];
	return Math.abs( first.getBoundingClientRect().left - el.getBoundingClientRect().left ) < 1;
};

test( 'carousel-loop', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage( { viewport: { width: 1200, height: 900 } } );
		await page.goto( `${ BASE_URL }/prueba-carrusel-bucle/`, { waitUntil: 'networkidle' } );

		for ( const name of [ 'Carrusel en bucle', 'Carrusel en bucle con consulta' ] ) {
			const carousel = page.getByRole( 'region', { name, exact: true } );
			const track = carousel.locator( '.estrategia-web-carousel__track' );
			const prev = carousel.getByRole( 'button', { name: 'Diapositiva anterior' } );
			const next = carousel.getByRole( 'button', { name: 'Diapositiva siguiente' } );
			const handle = await track.elementHandle();
			await carousel.scrollIntoViewIfNeeded();
			assert.ok( await carousel.evaluate( ( el ) => el.classList.contains( 'is-ready' ) ), `${ name }: it did not hydrate` );

			// 1. Both arrows enabled on load.
			assert.equal( await prev.isDisabled(), false, `${ name }: "previous" comes out disabled on load` );
			assert.equal( await next.isDisabled(), false, `${ name }: "next" comes out disabled on load` );

			// 2. "Previous" from the first one goes to the end.
			await prev.click();
			await page.waitForFunction( lastInView, handle );

			// 3. "Next" from the last one goes back to the start, and the arrow stays enabled.
			assert.equal( await next.isDisabled(), false, `${ name }: "next" gets disabled on the last one` );
			await next.click();
			await page.waitForFunction( firstAtStart, handle );

			// 4. Keyboard: ← on the first one goes to the end; → on the last one goes back.
			await track.focus();
			await page.keyboard.press( 'ArrowLeft' );
			await page.waitForFunction( lastInView, handle );
			await page.keyboard.press( 'ArrowRight' );
			await page.waitForFunction( firstAtStart, handle );
		}

		// With a query: each post is still a slide (six dots).
		const dots = page.getByRole( 'region', { name: 'Carrusel en bucle con consulta', exact: true } ).locator( '.estrategia-web-carousel__dot' );
		assert.equal( await dots.count(), 6, 'with a query there should be one dot per post' );
		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
	} )
);
