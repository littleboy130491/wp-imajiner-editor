/**
 * Settings → Imajiner Editor: "Load models" and "Test" for the primary and fallback models.
 * Both use the saved settings on the server; keys never reach the browser.
 */
( function ( wp, config ) {
	'use strict';

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
			modelInput.placeholder = config.defaultModels[ select.value ] || 'Model id';
			list.replaceChildren();
			show( '', true );
		} );

		row.querySelector( '.imajiner-load-models' ).addEventListener( 'click', async ( event ) => {
			const button = event.currentTarget;
			if ( ! select.value ) {
				show( 'Choose a provider first.', false );
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
				show( models.length + ' models loaded. Start typing in the model field to pick one.', true );
			} catch ( error ) {
				show( error.message || 'Could not load models.', false );
			}
			button.disabled = false;
		} );

		row.querySelector( '.imajiner-test' ).addEventListener( 'click', async ( event ) => {
			const button = event.currentTarget;
			button.disabled = true;
			show( 'Testing…', true );
			try {
				const response = await wp.apiFetch( { path: '/imajiner/v1/ai/test', method: 'POST', data: { slot } } );
				show( 'Connected to ' + response.model + '. It replied: ' + ( response.reply || '(empty reply)' ), true );
			} catch ( error ) {
				show( error.message || 'Connection failed.', false );
			}
			button.disabled = false;
		} );
	} );
} )( window.wp, window.imajinerSettings );
