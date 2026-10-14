/**
 * estrategia-web/map — carga el iframe de Google Maps solo cuando toca.
 *
 * Toca cuando: el visitante pulsa «Ver mapa» (que además concede «maps» en
 * el consentimiento si la web la declara, 0.5.0), o el consentimiento
 * (el aviso del plugin estrategia-web-site desde 0.7.0) tiene aceptada la categoría
 * «maps» — ya al arrancar, o cuando se acepta con la página abierta.
 * Sin el plugin no hay window.estrategiaWebConsent: por eso SIEMPRE «?.»; quitar
 * uno rompe «Ver mapa» en una web sin aviso (tests/map.test.mjs).
 * Documentación del módulo en la cabecera de render.php.
 *
 * La trampa: este módulo puede ejecutarse antes o después que el del
 * consentimiento. Por eso mira window.estrategiaWebConsent si ya existe Y escucha
 * el evento «estrategia-web:consent». Con una sola de las dos, a veces no carga.
 *
 * Y otra, medida el 01-10-2026: el servidor pinta el iframe SIN src, pero al
 * hidratar, data-wp-bind--src con undefined deja src="" (Interactivity asigna
 * la propiedad). Es about:blank y no pide nada a nadie; la prueba
 * tests/map.test.mjs mira las peticiones, que es lo que importa.
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
			// Pulsar es aceptar los mapas (0.5.0): si la web declara «maps», queda
			// concedida y los demás mapas cargan solos (también los de otras páginas).
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
