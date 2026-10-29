// NIVEL: BANCO DE TRABAJO — not a test. A probe the cookie-detector scenarios load with «initScripts» (tests/browser.mjs): it counts, per delay, the timers the page SCHEDULES and the ones that have FIRED.
//
// window.__timers = { scheduled: { 300: 1 }, fired: { 300: 1 } }
//
// Why (29-10-2026): the detector (blocks/consent/view.js, scheduleScan) reads
// the cookie names state.detector.delay ms after a choice, and then decides
// whether to send. «It did not send» used to be checked after waiting the delay
// plus a second: a fixed wait. With this probe it is two FACTS: the scan's
// timer FIRED (then it already decided: the send, if any, happens inside it),
// or no scan timer was ever SCHEDULED (then nothing will ever be sent from
// this page). The scenarios serve the page with the delay at 300 ms
// (network.rewrite) so that the timer is recognised by its delay.
//
// How to maintain it: if the detector stops using window.setTimeout (a
// requestIdleCallback, a worker), this probe has to follow it, or the
// scenarios wait for a timer that never comes and fail saying so. The trap:
// fired is counted AFTER the callback, so a fetch made inside it has already
// been issued when fired goes up.
window.__timers = { scheduled: {}, fired: {} };
const nativeSetTimeout = window.setTimeout;
window.setTimeout = function ( fn, delay, ...args ) {
	const d = Number( delay ) || 0;
	window.__timers.scheduled[ d ] = ( window.__timers.scheduled[ d ] || 0 ) + 1;
	if ( 'function' !== typeof fn ) {
		return nativeSetTimeout.call( this, fn, delay, ...args );
	}
	return nativeSetTimeout.call(
		this,
		function ( ...a ) {
			try {
				return fn.apply( this, a );
			} finally {
				window.__timers.fired[ d ] = ( window.__timers.fired[ d ] || 0 ) + 1;
			}
		},
		delay,
		...args
	);
};
