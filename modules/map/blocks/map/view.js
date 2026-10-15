/**
 * estrategia-web/map — loads the Google Maps iframe only when it should.
 *
 * It should when: the visitor clicks "Show map" (which also grants "maps" in
 * the consent if the site declares it, 0.5.0), or the consent (the notice of
 * the estrategia-web-site plugin since 0.7.0) has the "maps" category
 * accepted — already at startup, or when accepted with the page open.
 * Without the plugin there is no window.estrategiaWebConsent: hence ALWAYS "?."; removing
 * one breaks "Show map" on a site without the notice (tests/map.test.mjs).
 * Module documentation in the header of render.php.
 *
 * The trap: this module may run before or after the consent one. That is
 * why it checks window.estrategiaWebConsent if it already exists AND listens
 * for the "estrategia-web:consent" event. With only one of the two, it sometimes does not load.
 *
 * And another, measured on 01-10-2026: the server renders the iframe WITHOUT
 * src, but on hydration, data-wp-bind--src with undefined leaves src=""
 * (Interactivity sets the property). That is about:blank and requests
 * nothing from anyone; tests/map.test.mjs checks the requests, which is what
 * matters.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

store( 'estrategia-web/map', {
	state: {
		get src() {
			const context = getContext();
			return context.loaded && context.embedUrl ? context.embedUrl : undefined;
		},
	},
	actions: {
		load() {
			const context = getContext();
			const { ref } = getElement();
			context.loaded = true;
			// Clicking means accepting maps (0.5.0): if the site declares "maps", it
			// is granted and the other maps load by themselves (also on other pages).
			window.estrategiaWebConsent?.grant?.( 'maps' );
			// The button disappears: send focus to the map so it is not lost.
			const iframe = ref.closest( '.estrategia-web-map' ).querySelector( '.estrategia-web-map__iframe' );
			window.requestAnimationFrame( () => iframe?.focus() );
		},
	},
	callbacks: {
		init() {
			const context = getContext();
			if ( ! context.embedUrl ) {
				return undefined;
			}
			if ( window.estrategiaWebConsent?.has( 'maps' ) ) {
				context.loaded = true;
				return undefined;
			}
			const onConsent = ( event ) => {
				if ( event.detail?.accepted?.includes( 'maps' ) ) {
					context.loaded = true;
				}
			};
			document.addEventListener( 'estrategia-web:consent', onConsent );
			return () => document.removeEventListener( 'estrategia-web:consent', onConsent );
		},
	},
} );
