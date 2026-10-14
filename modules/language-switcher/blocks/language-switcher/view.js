/**
 * estrategia-web/language-switcher — abrir, cerrar con Escape (el foco vuelve al
 * botón) y cerrar al pulsar fuera. Documentación en render.php.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

store( 'estrategia-web/language-switcher', {
	actions: {
		toggle() {
			const context = getContext();
			context.open = ! context.open;
		},
	},
	callbacks: {
		closeOnEscape( event ) {
			const context = getContext();
			if ( 'Escape' === event.key && context.open ) {
				context.open = false;
				getElement().ref.querySelector( '.estrategia-web-language__toggle' )?.focus();
			}
		},
		closeOutside( event ) {
			const context = getContext();
			if ( context.open && ! getElement().ref.contains( event.target ) ) {
				context.open = false;
			}
		},
	},
} );
