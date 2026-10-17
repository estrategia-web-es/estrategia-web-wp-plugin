// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: scrolling (scrollLeft, scroll-snap) and focus only exist with the page painted.
/**
 * estrategia-web/carousel: moves forward and back with the arrows, with the
 * keyboard (← → End) and with the dots; the arrows get disabled at the ends.
 * The waits are for FACTS (scrollLeft changes), never for time.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

const PAGE = `${ BASE_URL }/prueba-carrusel/`;

blockSite( 'carousel' );

test( 'carousel', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage( { viewport: { width: 1200, height: 900 } } );
		await page.goto( PAGE, { waitUntil: 'networkidle' } );

		const carousel = page.locator( '.estrategia-web-carousel' );
		const track = carousel.locator( '.estrategia-web-carousel__track' );
		const prev = page.getByRole( 'button', { name: 'Diapositiva anterior' } );
		const next = page.getByRole( 'button', { name: 'Diapositiva siguiente' } );
		const scrollLeft = () => track.evaluate( ( el ) => el.scrollLeft );

		await carousel.scrollIntoViewIfNeeded();
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
		await page.waitForFunction( ( [ el, before ] ) => el.scrollLeft > before + 50, [ await track.elementHandle(), afterArrow ] );

		// End: it reaches the end and "next" gets disabled.
		await page.keyboard.press( 'End' );
		await page.waitForFunction( ( el ) => el.disabled, await next.elementHandle() );

		// The previous arrow goes back.
		const atEnd = await scrollLeft();
		await prev.click();
		await page.waitForFunction( ( [ el, before ] ) => el.scrollLeft < before - 50, [ await track.elementHandle(), atEnd ] );

		// Dots: the first one goes back to the start and stays marked.
		await page.getByRole( 'button', { name: 'Ir a la diapositiva 1' } ).click();
		await page.waitForFunction( ( el ) => el.scrollLeft < 1, await track.elementHandle() );
		await page.waitForFunction( () => document.querySelector( '.estrategia-web-carousel__dot' ).getAttribute( 'aria-current' ) === 'true' );

		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
	} )
);
