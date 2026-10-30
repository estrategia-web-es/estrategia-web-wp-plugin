// NIVEL: BANCO DE TRABAJO — not a test. Small helpers that a browser scenario loads with «initScripts» (tests/browser.mjs) so that its «values» and «waitForExpression» stay short.
//
//   "initScripts": ["tests/browser-probes.js"]
//   "values": { "shown": "__seen(__q('.estrategia-web-map__iframe'))" }
//
// - __q(selector, i = 0): the i-th element that matches (document order), or null.
// - __all(selector): every element that matches, as an array.
// - __seen(el): what Playwright calls visible — a non-empty box and not visibility:hidden
//   (opacity 0 still counts as visible, as in Playwright's isVisible()).
// - __box(el): { x, y, width, height } of its bounding box, or null.
// - __text(el): its innerText (line breaks kept), or null.
//
// How to maintain it: a helper goes here only when two scenarios need it; one
// scenario's own probe goes in that module (e.g. modules/carousel/tests/browser/probes.js).
// The trap: it runs in EVERY frame of the page before its scripts, so it must
// not touch the DOM (there is none yet) and must not depend on the page.
window.__q = ( selector, i = 0 ) => document.querySelectorAll( selector )[ i ] || null;
window.__all = ( selector ) => [ ...document.querySelectorAll( selector ) ];
window.__seen = ( el ) => {
	if ( ! el ) {
		return false;
	}
	const r = el.getBoundingClientRect();
	return r.width > 0 && r.height > 0 && el.checkVisibility( { visibilityProperty: true } );
};
window.__box = ( el ) => {
	if ( ! el ) {
		return null;
	}
	const r = el.getBoundingClientRect();
	return { x: r.x, y: r.y, width: r.width, height: r.height };
};
window.__text = ( el ) => ( el ? el.innerText : null );
