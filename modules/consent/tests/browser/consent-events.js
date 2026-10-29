// NIVEL: BANCO DE TRABAJO — not a test. A probe the consent scenarios load with «initScripts» (tests/browser.mjs): it records the notice's events from the very first instant of every page load, before the notice's own module runs.
//
// window.ready: one entry per «estrategia-web:consent-ready» — [ detail.accepted, typeof window.estrategiaWebConsent?.get ]
// window.seen:  one entry per «estrategia-web:consent» — detail.accepted
//
// How to maintain it: if the notice's events change their detail, change it
// here and in the checks that read window.ready / window.seen. The trap: it
// must be an init script, not a «run» step after the load — consent-ready
// fires while the page boots, and a listener added later never sees it.
window.ready = [];
window.seen = [];
document.addEventListener( 'estrategia-web:consent-ready', ( e ) => window.ready.push( [ e.detail.accepted, typeof window.estrategiaWebConsent?.get ] ) );
document.addEventListener( 'estrategia-web:consent', ( e ) => window.seen.push( e.detail.accepted ) );
