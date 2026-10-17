// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested is the HEIGHT the layout engine computes for the map inside a flex container, and that only exists with the page painted.
/**
 * estrategia-web/map inside a vertical Group (column flex) does NOT SHRINK.
 *
 * The bug, 03-10-2026: Lavandería's child rounds the map with overflow:hidden
 * and puts it inside a vertical Group; a flex child with overflow other than
 * visible has a minimum size of 0, and the map ended up 2 px tall with
 * everything inside cut off (the page came out 14 % shorter). The fix is
 * `flex: none` on .estrategia-web-map (blocks/map/style.css).
 *
 * The test reproduces exactly that case on /prueba-mapa/: it wraps the map
 * in the same thing a WordPress vertical Group renders —column flex,
 * align-items:flex-start, AUTOMATIC HEIGHT— and sets overflow:hidden on it,
 * and checks that the figure is still as tall as its frame.
 *
 * The TRAP, which cost a version: the first test (0.3.1) gave the container a
 * fixed height of 200 px. That way the bug was a different one (shrinking) and
 * the test passed with a fix that fixed nothing on the real site. A test must
 * reproduce the real case, not a similar one.
 */
import { test, withBrowser, assert, BASE_URL, blockSite } from '../../../tests/browser.mjs';

const PAGE = `${ BASE_URL }/prueba-mapa/`;

blockSite( 'map' );

test( 'map-in-flex', () =>
	withBrowser( async ( { newPage } ) => {
		const page = await newPage();
		await page.goto( PAGE, { waitUntil: 'networkidle' } );
		const measure = await page.evaluate( () => {
			const map = document.querySelector( '.estrategia-web-map' );
			const wrap = document.createElement( 'div' );
			// What a left-aligned WordPress vertical Group renders.
			wrap.style.cssText = 'display:flex;flex-direction:column;align-items:flex-start';
			map.before( wrap );
			wrap.append( map );
			map.style.overflow = 'hidden';
			// What the child's "map-card" style does: the caption (address and
			// buttons) goes ON TOP of the map, out of the flow. Without this the
			// figure has in-flow content with its own width and the bug does not show.
			const caption = map.querySelector( '.estrategia-web-map__caption' );
			if ( caption ) caption.style.position = 'absolute';
			const frame = map.querySelector( '.estrategia-web-map__frame' );
			return { figure: map.getBoundingClientRect().height, frame: frame.getBoundingClientRect().height };
		} );
		// With the bug, the frame is measured with width 0 and its height (aspect-ratio) comes out 0:
		// a map less than 100 px tall is the bug, not a test without data.
		assert.ok( measure.frame > 100 && measure.figure > 100, `the map gets squashed inside a vertical Group: figure ${ measure.figure } px, frame ${ measure.frame } px` );
		assert.ok(
			measure.figure >= measure.frame,
			`the map shrinks inside a flex: the figure is ${ measure.figure } px and its frame ${ measure.frame } px`
		);
		assert.deepEqual( page.errors, [], page.errors.join( '\n' ) );
	} )
);
