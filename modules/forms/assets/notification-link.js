/**
 * «Forms › Forms» → a notification's link (2.0.1, David, 28-10-2026: «fix a
 * recipient in two clicks»). The link is the form's edit URL plus
 * ?eweb_form=<formId>&eweb_notification=<n> (Forms_List::notification_url());
 * this script, enqueued only on such a request
 * (Forms_List::enqueue_notification_link()), waits for the editor to have
 * the blocks, selects that notification and opens the block sidebar, so its
 * «To» field is in front of you.
 *
 * Which notification: the n-th estrategia-web-forms/notification block,
 * depth-first, inside the form block whose formId is eweb_form (a page may
 * hold several forms; the first one if none matches) — the same order
 * Definitions::collect() reads them in, so n means the same in the list and
 * here.
 *
 * How to maintain:
 * - Who: whoever changes where notifications live inside a form, or the
 *   block names. Test: modules/forms/tests/admin.test.mjs (level 3).
 * - Edge case, by name: a form post being created (post-new.php) has no
 *   link; a notification number that no longer exists (deleted since the
 *   list was loaded) selects nothing and leaves the editor as it is.
 * - The trap: the editor fills the block list AFTER this script runs, and in
 *   steps, and it CLEARS the selection once more while it boots (selecting
 *   on the first state with the block did nothing: seen 28-10-2026). So it
 *   subscribes to the store and keeps the selection for SETTLE ms after the
 *   block first appears, then unsubscribes. It gives up after 20 s if the
 *   block never appears (a broken post must not leave a listener forever).
 */
( function () {
	const params = new URLSearchParams( window.location.search );
	const index = parseInt( params.get( 'eweb_notification' ) || '', 10 );
	if ( Number.isNaN( index ) || index < 0 || ! window.wp || ! wp.data ) {
		return;
	}
	const formId = params.get( 'eweb_form' ) || '';
	const { select, dispatch, subscribe } = wp.data;

	const collect = ( blocks, name, out ) => {
		for ( const block of blocks ) {
			if ( block.name === name ) {
				out.push( block );
			}
			collect( block.innerBlocks || [], name, out );
		}
		return out;
	};

	const target = () => {
		const forms = collect( select( 'core/block-editor' ).getBlocks(), 'estrategia-web-forms/form', [] );
		const form = forms.find( ( f ) => ( f.attributes.formId || '' ) === formId ) || forms[ 0 ];
		return form ? collect( form.innerBlocks || [], 'estrategia-web-forms/notification', [] )[ index ] || null : null;
	};

	const openSidebar = () => {
		const editPost = dispatch( 'core/edit-post' );
		if ( editPost && editPost.openGeneralSidebar ) {
			editPost.openGeneralSidebar( 'edit-post/block' );
			return;
		}
		const ui = dispatch( 'core/interface' );
		if ( ui && ui.enableComplementaryArea ) {
			ui.enableComplementaryArea( 'core', 'edit-post/block' );
		}
	};

	// Selected once is not enough: while it boots, the editor replaces the
	// blocks (the form post's locked template is synced) and clears the
	// selection, measured 28-10-2026. So for SETTLE ms after the block first
	// shows up, a lost selection is put back; then it lets go, and whatever
	// the user clicks stays clicked.
	const SETTLE = 2500;
	let found = 0;
	let done = false;
	const stop = () => {
		done = true;
		unsubscribe();
	};
	const unsubscribe = subscribe( () => {
		if ( done ) {
			return;
		}
		const block = target();
		if ( ! block ) {
			return;
		}
		if ( ! found ) {
			found = Date.now();
			setTimeout( stop, SETTLE );
		}
		if ( select( 'core/block-editor' ).getSelectedBlockClientId() !== block.clientId ) {
			dispatch( 'core/block-editor' ).selectBlock( block.clientId );
			openSidebar();
		}
	} );
	setTimeout( () => ! found && stop(), 20000 );
} )();
