( function ( config, wp ) {
	'use strict';
	const { __ } = wp.i18n;
	const form = document.getElementById( 'imj-ai-form' );
	if ( ! form ) return;
	const key = document.getElementById( 'imj-ai-key' );
	const name = document.getElementById( 'imj-ai-name' );
	const prompt = document.getElementById( 'imj-ai-prompt' );
	const status = document.getElementById( 'imj-ai-status' );
	const errors = document.getElementById( 'imj-ai-errors' );
	const review = document.getElementById( 'imj-ai-review' );
	const comparison = document.getElementById( 'imj-ai-comparison' );
	const accept = document.getElementById( 'imj-ai-accept' );
	const cancel = document.getElementById( 'imj-ai-cancel' );
	let proposal = '';
	let busy = false;
	let job = 0;

	function list( target, items ) {
		target.replaceChildren();
		items.forEach( ( item ) => {
			const li = document.createElement( 'li' );
			li.textContent = item;
			target.append( li );
		} );
	}

	function discard() {
		proposal = '';
		review.hidden = true;
		comparison.replaceChildren();
	}

	function setBusy( value ) {
		busy = value;
		form.querySelectorAll( 'input, textarea, select, button' ).forEach( ( field ) => { field.disabled = value; } );
		accept.disabled = value;
		cancel.disabled = value;
		document.getElementById( 'imj-ai-stop' ).hidden = ! value || ! job;
	}

	async function request( action, data, method = 'POST' ) {
		const response = await fetch( config.restUrl + action, {
			method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin',
			body: method === 'GET' ? undefined : JSON.stringify( data ),
		} );
		const result = await response.json();
		if ( ! response.ok ) {
			list( errors, result.data && result.data.warnings || [] );
			throw new Error( result.message || __( 'Request failed.', 'imajiner-editor' ) );
		}
		return result;
	}

	async function poll( id ) {
		while ( job === id ) {
			const result = await request( 'jobs/' + id, null, 'GET' );
			if ( job !== id ) throw new Error( __( 'Job cancelled. Nothing was saved.', 'imajiner-editor' ) );
			if ( result.state === 'complete' ) return result.result;
			if ( result.state === 'failed' || result.state === 'cancelled' ) throw new Error( result.message || __( 'AI job stopped. Nothing was saved.', 'imajiner-editor' ) );
			status.textContent = result.state === 'queued' ? __( 'Queued for background generation…', 'imajiner-editor' ) : __( 'Generating and validating in the background…', 'imajiner-editor' ) + ' ' + result.progress + '%';
			await new Promise( ( resolve ) => setTimeout( resolve, 1500 ) );
		}
		throw new Error( __( 'Job cancelled. Nothing was saved.', 'imajiner-editor' ) );
	}

	document.getElementById( 'imj-ai-stop' ).addEventListener( 'click', async () => {
		const id = job;
		if ( ! id ) return;
		try {
			await request( 'jobs/' + id + '/cancel', {} );
			job = 0;
			status.textContent = __( 'Job cancelled. Nothing was saved.', 'imajiner-editor' );
		} catch ( error ) { status.textContent = error.message; }
	} );

	function escape( value ) {
		return value.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function panel( title, files, markup, scope, warnings ) {
		const column = document.createElement( 'div' );
		column.style.cssText = 'flex:1;min-width:280px';
		const heading = document.createElement( 'h4' );
		heading.textContent = title;
		column.append( heading );
		const frame = document.createElement( 'iframe' );
		frame.title = title;
		frame.setAttribute( 'sandbox', '' );
		frame.style.cssText = 'width:100%;height:300px;border:1px solid #c3c4c7;background:white';
		frame.srcdoc = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\' http: https:; img-src http: https: data:; font-src http: https:;">'
			+ '<link rel="stylesheet" href="' + escape( config.baseCss ) + '"><link rel="stylesheet" href="' + escape( config.childCss ) + '">'
			+ '<style>' + files.css.replace( /<\/style/gi, '<\\/style' ) + '</style></head><body class="' + escape( scope.slice( 1 ) ) + '"><main>' + markup + '</main></body></html>';
		column.append( frame );
		const warningList = document.createElement( 'ul' );
		list( warningList, warnings );
		column.append( warningList );
		[ 'php', 'css' ].forEach( ( type ) => {
			const details = document.createElement( 'details' );
			const summary = document.createElement( 'summary' );
			summary.textContent = title + ' — ' + type.toUpperCase();
			const source = document.createElement( 'textarea' );
			source.readOnly = true;
			source.setAttribute( 'aria-label', summary.textContent );
			source.rows = 12;
			source.style.cssText = 'width:100%;font-family:monospace';
			source.value = files[ type ];
			details.append( summary, source );
			column.append( details );
		} );
		comparison.append( column );
	}

	function updateAction() {
		document.getElementById( 'imj-ai-name-field' ).hidden = !! key.value;
		name.required = ! key.value;
		prompt.required = ! key.value;
	}
	const normalize = new URL( window.location.href ).searchParams.get( 'normalize' );
	if ( normalize && Array.from( key.options ).some( ( option ) => option.value === normalize ) ) key.value = normalize;
	updateAction();
	key.addEventListener( 'change', updateAction );
	form.addEventListener( 'input', discard );
	cancel.addEventListener( 'click', () => {
		discard();
		status.textContent = __( 'Proposal discarded. Nothing was saved.', 'imajiner-editor' );
	} );
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		if ( busy ) return;
		discard();
		list( errors, [] );
		setBusy( true );
		status.textContent = __( 'Generating and validating… This may take a couple of minutes.', 'imajiner-editor' );
		try {
			const queued = await request( 'generate', { key: key.value, name: name.value, prompt: prompt.value } );
			job = queued.id;
			setBusy( true );
			const result = await poll( job );
			proposal = result.proposal;
			if ( key.value ) panel( __( 'Before', 'imajiner-editor' ), result.before, result.beforeMarkup, result.scope, result.beforeWarnings );
			panel( __( 'After', 'imajiner-editor' ), result.after, result.afterMarkup, result.scope, [] );
			list( document.getElementById( 'imj-ai-warnings' ), result.warnings.length ? result.warnings : [ __( 'No contract warnings.', 'imajiner-editor' ) ] );
			review.hidden = false;
			status.textContent = __( 'Proposal ready. Nothing has been saved yet.', 'imajiner-editor' );
		} catch ( error ) {
			status.textContent = error.message;
		} finally {
			job = 0;
			setBusy( false );
		}
	} );
	accept.addEventListener( 'click', async () => {
		if ( busy || ! proposal ) return;
		list( errors, [] );
		setBusy( true );
		status.textContent = __( 'Saving…', 'imajiner-editor' );
		try {
			const result = await request( 'accept', { proposal } );
			window.location.assign( result.url );
		} catch ( error ) {
			status.textContent = error.message;
			setBusy( false );
		}
	} );
} )( window.imajinerBuilderAI, window.wp );
