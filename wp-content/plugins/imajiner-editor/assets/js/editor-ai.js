( function ( wp ) {
	'use strict';
	const { __ } = wp.i18n;
	const api = window.imajinerEditor;
	const actions = document.querySelector( '.imj-topbar__actions' );
	if ( ! api || ! actions ) return;
	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'imj-button imj-button--ghost';
	button.textContent = __( 'Edit selection with AI', 'imajiner-editor' );
	actions.prepend( button );
	const dialog = document.createElement( 'dialog' );
	dialog.style.cssText = 'width:min(960px,90vw);max-height:90vh;overflow:auto;padding:24px';
	const title = document.createElement( 'h2' );
	title.textContent = __( 'Edit selection with AI', 'imajiner-editor' );
	const instructions = document.createElement( 'textarea' );
	instructions.rows = 4;
	instructions.maxLength = 20000;
	instructions.style.width = '100%';
	instructions.setAttribute( 'aria-label', __( 'Describe the selected-node edit', 'imajiner-editor' ) );
	const status = document.createElement( 'p' );
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );
	const review = document.createElement( 'div' );
	const generate = control( __( 'Generate proposal', 'imajiner-editor' ) );
	const accept = control( __( 'Confirm and save selected edit', 'imajiner-editor' ) );
	const close = control( __( 'Cancel / discard', 'imajiner-editor' ) );
	accept.hidden = true;
	dialog.append( title, instructions, generate, status, review, accept, close );
	document.body.append( dialog );
	let job = 0;
	let proposal = '';
	let initial = null;
	let busy = false;
	let session = 0;

	function control( text ) {
		const item = document.createElement( 'button' );
		item.type = 'button';
		item.className = 'imj-button';
		item.textContent = text;
		return item;
	}

	function key( state ) { return typeof state.template === 'string' ? state.template : state.template.key; }
	function eligible( state ) { return ! state.dirty && /^[es][0-9]+$/.test( state.selectedId || '' ); }
	function sync() { button.disabled = ! eligible( api.getState() ); }
	document.addEventListener( 'imajiner:selection', sync );
	sync();
	button.addEventListener( 'click', () => {
		initial = api.getState();
		if ( ! eligible( initial ) ) return;
		proposal = '';
		review.replaceChildren();
		instructions.value = '';
		status.textContent = __( 'AI replaces only this node. Review PHP and scoped CSS before saving.', 'imajiner-editor' );
		accept.hidden = true;
		dialog.showModal();
		instructions.focus();
	} );

	async function discard() {
		session++;
		const id = job;
		job = 0;
		proposal = '';
		if ( id ) {
			try { await wp.apiFetch( { path: '/imajiner/v1/ai/jobs/' + id, method: 'DELETE' } ); } catch ( error ) { status.textContent = error.message; }
		}
		busy = false;
		generate.disabled = false;
		instructions.disabled = false;
		accept.disabled = false;
		dialog.close();
	}
	close.addEventListener( 'click', discard );
	dialog.addEventListener( 'cancel', ( event ) => {
		event.preventDefault();
		if ( ! close.disabled ) discard();
	} );

	function render( label, files, markup, scope ) {
		const heading = document.createElement( 'h3' );
		heading.textContent = label;
		review.append( heading );
		const frame = document.createElement( 'iframe' );
		frame.title = label;
		frame.setAttribute( 'sandbox', '' );
		frame.style.cssText = 'width:100%;height:240px;background:white;border:1px solid #ccc';
		frame.srcdoc = '<!doctype html><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src https:;"><style>' + files.css.replace( /<\/style/gi, '<\\/style' ) + '</style><body class="' + scope.slice( 1 ).replace( /[^a-zA-Z0-9_-]/g, '' ) + '">' + markup;
		review.append( frame );
		[ 'php', 'css' ].forEach( ( type ) => {
			const source = document.createElement( 'textarea' );
			source.readOnly = true;
			source.rows = 8;
			source.style.cssText = 'width:100%;font-family:monospace';
			source.setAttribute( 'aria-label', label + ' ' + type.toUpperCase() );
			source.value = files[ type ];
			review.append( source );
		} );
	}

	generate.addEventListener( 'click', async () => {
		if ( busy || ! instructions.value.trim() ) return;
		if ( api.getState().dirty || api.getState().hash !== initial.hash || key( api.getState() ) !== key( initial ) ) {
			status.textContent = __( 'Save or discard pending changes, then reopen AI editing.', 'imajiner-editor' );
			return;
		}
		const current = ++session;
		busy = true;
		generate.disabled = true;
		instructions.disabled = true;
		proposal = '';
		accept.hidden = true;
		review.replaceChildren();
		try {
			const queued = await wp.apiFetch( { path: '/imajiner/v1/ai/section', method: 'POST', data: { key: key( initial ), hash: initial.hash, id: initial.selectedId, prompt: instructions.value } } );
			if ( session !== current ) { await wp.apiFetch( { path: '/imajiner/v1/ai/jobs/' + queued.job, method: 'DELETE' } ); return; }
			job = queued.job;
			while ( session === current && job ) {
				const result = await wp.apiFetch( { path: '/imajiner/v1/ai/jobs/' + job } );
				if ( session !== current ) return;
				if ( result.state === 'failed' || result.state === 'cancelled' ) throw new Error( result.error ? result.error.message : __( 'AI task cancelled.', 'imajiner-editor' ) );
				if ( result.state === 'complete' ) {
					const data = result.result;
					proposal = data.proposal;
					render( __( 'Before', 'imajiner-editor' ), data.before, data.beforeMarkup, data.scope );
					render( __( 'After', 'imajiner-editor' ), data.after, data.afterMarkup, data.scope );
					status.textContent = __( 'Static preview only; PHP and scripts do not run. Review the source before confirming. Nothing has been saved.', 'imajiner-editor' );
					accept.hidden = false;
					job = 0;
					break;
				}
				status.textContent = result.state === 'queued' ? __( 'Queued. Waiting for the background worker…', 'imajiner-editor' ) : __( 'Generating and validating the replacement…', 'imajiner-editor' );
				await new Promise( ( resolve ) => setTimeout( resolve, 1500 ) );
			}
		} catch ( error ) { if ( session === current ) status.textContent = error.message; }
		finally {
			if ( session === current ) { busy = false; generate.disabled = false; instructions.disabled = false; job = 0; }
		}
	} );
	accept.addEventListener( 'click', async () => {
		if ( busy || ! proposal ) return;
		const state = api.getState();
		if ( state.dirty || state.hash !== initial.hash || key( state ) !== key( initial ) ) {
			status.textContent = __( 'The editor changed. Discard this proposal and generate it again.', 'imajiner-editor' );
			return;
		}
		busy = true;
		accept.disabled = true;
		close.disabled = true;
		try {
			await wp.apiFetch( { path: '/imajiner/v1/ai/section/accept', method: 'POST', data: { proposal } } );
			proposal = '';
			await api.reload();
			dialog.close();
		} catch ( error ) { status.textContent = error.message; }
		finally { busy = false; accept.disabled = false; close.disabled = false; sync(); }
	} );
} )( window.wp );
