/**
 * Settings → Imajiner Editor: "Load models" and "Test" for the primary and fallback models.
 * Both use the saved settings on the server; keys never reach the browser.
 */
( function ( wp, config ) {
	'use strict';
	const { __, sprintf } = wp.i18n;

	document.querySelectorAll( '.imajiner-slot' ).forEach( ( row ) => {
		const slot = row.dataset.slot;
		const select = row.querySelector( '.imajiner-slot-provider' );
		const modelInput = row.querySelector( '.imajiner-slot-model' );
		const list = row.querySelector( 'datalist' );
		const result = row.querySelector( '.imajiner-result' );

		function show( message, ok ) {
			result.textContent = message;
			result.style.color = ok ? '#00a32a' : '#d63638';
		}

		// Suggest the provider's default model, and drop models loaded for another provider.
		select.addEventListener( 'change', () => {
			modelInput.placeholder = config.defaultModels[ select.value ] || __( 'Model id', 'imajiner-editor' );
			list.replaceChildren();
			show( '', true );
		} );

		row.querySelector( '.imajiner-load-models' ).addEventListener( 'click', async ( event ) => {
			const button = event.currentTarget;
			if ( ! select.value ) {
				show( __( 'Choose a provider first.', 'imajiner-editor' ), false );
				return;
			}
			button.disabled = true;
			try {
				const models = await wp.apiFetch( { path: '/imajiner/v1/ai/models?provider=' + encodeURIComponent( select.value ) } );
				list.replaceChildren(
					...models.map( ( id ) => {
						const option = document.createElement( 'option' );
						option.value = id;
						return option;
					} )
				);
				show( sprintf( __( '%d models loaded. Start typing in the model field to pick one.', 'imajiner-editor' ), models.length ), true );
			} catch ( error ) {
				show( error.message || __( 'Could not load models.', 'imajiner-editor' ), false );
			}
			button.disabled = false;
		} );

		row.querySelector( '.imajiner-test' ).addEventListener( 'click', async ( event ) => {
			const button = event.currentTarget;
			button.disabled = true;
			show( __( 'Testing…', 'imajiner-editor' ), true );
			try {
				const response = await wp.apiFetch( { path: '/imajiner/v1/ai/test', method: 'POST', data: { slot } } );
				show( sprintf( __( 'Connected to %1$s. It replied: %2$s', 'imajiner-editor' ), response.model, response.reply || __( '(empty reply)', 'imajiner-editor' ) ), true );
			} catch ( error ) {
				show( error.message || __( 'Connection failed.', 'imajiner-editor' ), false );
			}
			button.disabled = false;
		} );
	} );
} )( window.wp, window.imajinerSettings );
