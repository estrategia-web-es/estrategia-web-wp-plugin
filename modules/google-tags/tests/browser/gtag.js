// NIVEL: BANCO DE TRABAJO — not a test. A probe the google-tags scenarios load with «initScripts» (tests/browser.mjs), next to modules/consent/tests/browser/consent-events.js.
//
// window.formSent:      every «estrategia-web:form-sent» detail, from the first instant (the forms
//                       module prints the «returned» one as an inline script while the page parses).
// window.__layer():     dataLayer with gtag's «arguments» turned into arrays.
// window.__lastUpdate(): the last gtag('consent', 'update', …) settings, or undefined.
// window.__conversions(): the gtag('event', 'conversion', …) entries.
//
// How to maintain it: the dataLayer helpers are what the checks read through
// «values»; if gtag's call shape changes, they change here. The trap: the
// google-tags script reacts INSIDE the notice's event listeners (synchronous),
// so «the consent event fired» (window.seen, consent-events.js) is the fact
// after which dataLayer already says what the choice did.
window.formSent = [];
document.addEventListener( 'estrategia-web:form-sent', ( e ) => window.formSent.push( e.detail ) );
window.__layer = () => ( window.dataLayer || [] ).map( ( a ) => ( 'number' === typeof a.length ? Array.from( a ) : a ) );
window.__lastUpdate = () => window.__layer().filter( ( e ) => 'consent' === e[ 0 ] && 'update' === e[ 1 ] ).pop()?.[ 2 ];
window.__conversions = () => window.__layer().filter( ( e ) => 'event' === e[ 0 ] && 'conversion' === e[ 1 ] );
