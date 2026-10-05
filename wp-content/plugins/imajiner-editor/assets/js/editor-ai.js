/** Separate selected-node AI review UI; PHP remains authoritative. */
( function ( config, wp ) {
	'use strict';
	if ( ! config || ! wp || ! wp.i18n || ! window.imajinerEditor ) return;
	const { __, sprintf } = wp.i18n;
	const editor = window.imajinerEditor;
	let busy = false;
	let job = 0;
	let proposal = '';
	let snapshot = null;
	const open = document.createElement( 'button' );
	open.type = 'button';
	open.className = 'imj-button imj-button--ghost';
	open.textContent = __( 'Edit selection with AI', 'imajiner-editor' );
	const toolbar = document.querySelector( '.imj-topbar__actions' );
	if ( ! toolbar ) return;
	toolbar.append( open );
	const dialog = document.createElement( 'dialog' );
	dialog.style.cssText = 'max-width:1000px;width:85%;max-height:90vh;overflow:auto;padding:24px';
	const title = document.createElement( 'h2' );
	title.textContent = __( 'Edit selected section or element with AI', 'imajiner-editor' );
	const note = document.createElement( 'p' );
	note.textContent = __( 'Only static HTML is editable. Review the replacement and scoped CSS. Nothing is saved until you confirm.', 'imajiner-editor' );
	const prompt = document.createElement( 'textarea' );
	prompt.rows = 4;
	prompt.maxLength = 20000;
	prompt.style.width = '100%';
	prompt.setAttribute( 'aria-label', __( 'Describe the selected edit', 'imajiner-editor' ) );
	const status = document.createElement( 'p' );
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );
	const review = document.createElement( 'div' );
	review.style.cssText = 'display:flex;gap:16px;flex-wrap:wrap';
	function button( label ) {
		const node = document.createElement( 'button' );
		node.type = 'button';
		node.className = 'imj-button';
		node.textContent = label;
		return node;
	}
	const generate = button( __( 'Generate replacement', 'imajiner-editor' ) );
	const accept = button( __( 'Confirm and save selected edit', 'imajiner-editor' ) );
	const cancel = button( __( 'Cancel job', 'imajiner-editor' ) );
	const close = button( __( 'Discard / close', 'imajiner-editor' ) );
	accept.hidden = true;
	cancel.hidden = true;
	dialog.append( title, note, prompt, generate, cancel, close, status, review, accept );
	document.body.append( dialog );

	function eligible( state ) {
		return state && ! state.dirty && state.selectedId && /^[es]\d+$/.test( state.selectedId );
	}
	function update() {
		open.disabled = busy || ! eligible( editor.getState() );
	}
	document.addEventListener( 'imajiner:selection', update );
	window.addEventListener( 'imajiner:selection', update );
	open.addEventListener( 'click', () => {
		if ( ! eligible( editor.getState() ) ) return;
		proposal = '';
		accept.hidden = true;
		review.replaceChildren();
		status.textContent = __( 'Describe your change, then generate a proposal.', 'imajiner-editor' );
		dialog.showModal();
		prompt.focus();
	} );
	function setBusy( value ) {
		busy = value;
		generate.disabled = value;
		prompt.disabled = value;
		accept.disabled = value;
		close.disabled = value;
		cancel.hidden = ! value || ! job;
		update();
	}
	async function request( path, data, method = 'POST' ) {
		const response = await fetch( config.restUrl + path, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body: method === 'GET' ? undefined : JSON.stringify( data ) } );
		const result = await response.json();
		if ( ! response.ok ) throw new Error( result.message || __( 'AI request failed.', 'imajiner-editor' ) );
		return result;
	}
	function escape( value ) {
		return value.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}
	function panel( label, files, scope ) {
		const column = document.createElement( 'div' );
		column.style.cssText = 'flex:1;min-width:280px';
		const heading = document.createElement( 'h3' );
		heading.textContent = label;
		const frame = document.createElement( 'iframe' );
		frame.title = label;
		frame.setAttribute( 'sandbox', '' );
		frame.style.cssText = 'width:100%;height:220px;background:white';
		frame.srcdoc = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="default-src \'none\';style-src \'unsafe-inline\' https: http:;img-src https: http:;font-src https: http:">' +
			config.stylesheets.map( ( url ) => '<link rel="stylesheet" href="' + escape( url ) + '">' ).join( '' ) + '<style>' + files.css.replace( /<\/style/gi, '<\\/style' ) + '</style></head><body class="' + escape( scope.slice( 1 ) ) + '">' + files.html + '</body></html>';
		const source = document.createElement( 'textarea' );
		source.readOnly = true;
		source.rows = 12;
		source.style.width = '100%';
		source.setAttribute( 'aria-label', sprintf( __( '%s HTML / CSS', 'imajiner-editor' ), label ) );
		source.value = files.html + '\n\n' + files.css;
		column.append( heading, frame, source );
		review.append( column );
	}
	generate.addEventListener( 'click', async () => {
		const state = editor.getState();
		if ( busy || ! eligible( state ) || ! prompt.value.trim() ) {
			status.textContent = __( 'Save or discard pending edits, select a static node, and enter instructions.', 'imajiner-editor' );
			return;
		}
		snapshot = { template: state.template, hash: state.hash, id: state.selectedId };
		proposal = '';
		accept.hidden = true;
		review.replaceChildren();
		setBusy( true );
		try {
			const queued = await request( 'section', { key: typeof snapshot.template === 'string' ? snapshot.template : snapshot.template.key, hash: snapshot.hash, id: snapshot.id, prompt: prompt.value } );
			job = queued.id;
			setBusy( true );
			while ( job ) {
				const current = await request( 'jobs/' + queued.id, null, 'GET' );
				if ( ! job ) break;
				if ( current.state === 'complete' ) {
					proposal = current.result.proposal;
					panel( __( 'Before', 'imajiner-editor' ), current.result.before, current.result.scope );
					panel( __( 'Proposed replacement', 'imajiner-editor' ), current.result.after, current.result.scope );
					accept.hidden = false;
					status.textContent = __( 'Validated proposal ready. Review before confirming. PHP is never executed in this preview.', 'imajiner-editor' );
					break;
				}
				if ( current.state === 'failed' || current.state === 'cancelled' ) throw new Error( current.message || __( 'AI job stopped.', 'imajiner-editor' ) );
				status.textContent = __( 'Working in the background…', 'imajiner-editor' ) + ' ' + current.progress + '%';
				await new Promise( ( resolve ) => setTimeout( resolve, 1500 ) );
			}
		} catch ( error ) { status.textContent = error.message; }
		finally { job = 0; setBusy( false ); }
	} );
	accept.addEventListener( 'click', async () => {
		const state = editor.getState();
		if ( busy || ! proposal || state.dirty || state.hash !== snapshot.hash || JSON.stringify( state.template ) !== JSON.stringify( snapshot.template ) ) {
			status.textContent = __( 'The editor changed. Discard this proposal and generate again.', 'imajiner-editor' );
			return;
		}
		setBusy( true );
		try {
			await request( 'section/accept', { proposal } );
			proposal = '';
			dialog.close();
			await editor.reload();
		} catch ( error ) { status.textContent = error.message; }
		finally { setBusy( false ); }
	} );
	cancel.addEventListener( 'click', async () => {
		if ( ! job ) return;
		try { await request( 'jobs/' + job + '/cancel', {} ); job = 0; status.textContent = __( 'Job cancelled. Nothing was saved.', 'imajiner-editor' ); }
		catch ( error ) { status.textContent = error.message; }
	} );
	close.addEventListener( 'click', () => { proposal = ''; dialog.close(); } );
	dialog.addEventListener( 'cancel', ( event ) => { if ( busy ) event.preventDefault(); else proposal = ''; } );
	update();
} )( window.imajinerEditorAI, window.wp );
