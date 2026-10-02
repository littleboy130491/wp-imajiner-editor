/**
 * Runs inside the editor preview iframe: highlights elements on hover and
 * reports clicks to the editor. Every editable element carries data-imj-id.
 */
( function ( config ) {
	'use strict';

	if ( window.parent === window ) {
		return;
	}

	const editorOrigin = config.editorOrigin;
	let hovered = null;
	let dragNodes = new Map();
	let draggedId = null;
	const __ = window.wp.i18n.__;
	const comments = document.createTreeWalker( document.body, NodeFilter.SHOW_COMMENT );
	const markers = [];
	while ( comments.nextNode() ) {
		if ( /^imj-text:t\d+$/.test( comments.currentNode.data ) ) {
			markers.push( comments.currentNode );
		}
	}
	markers.forEach( ( marker ) => {
		const text = marker.nextSibling;
		const end = text && text.nextSibling;
		if ( ! text || text.nodeType !== Node.TEXT_NODE || ! end || end.nodeType !== Node.COMMENT_NODE || end.data !== '/imj-text' ) {
			return;
		}
		const span = document.createElement( 'span' );
		span.dataset.imjText = marker.data.slice( 9 );
		span.className = 'imj-inline-text';
		span.title = __( 'Double-click to edit text', 'imajiner-editor' );
		text.before( span );
		span.append( text );
		marker.remove();
		end.remove();
	} );

	document.addEventListener( 'dblclick', ( event ) => {
		const span = event.target.closest( '[data-imj-text]' );
		if ( ! span || ! inlineEnabled ) {
			return;
		}
		event.preventDefault();
		span.dataset.original = span.textContent;
		span.contentEditable = 'plaintext-only';
		span.setAttribute( 'role', 'textbox' );
		span.setAttribute( 'aria-label', __( 'Edit text', 'imajiner-editor' ) );
		span.focus();
	} );
	let inlineEnabled = false;
	document.addEventListener( 'focusout', ( event ) => {
		const span = event.target.closest( '[data-imj-text][contenteditable]' );
		if ( ! span ) {
			return;
		}
		const value = span.textContent.trim();
		if ( value ) {
			span.textContent = span.dataset.original.match( /^\s*/ )[0] + value + span.dataset.original.match( /\s*$/ )[0];
			send( { type: 'imj:text-edited', id: span.dataset.imjText, value } );
		} else {
			span.textContent = span.dataset.original;
		}
		span.removeAttribute( 'contenteditable' );
		span.removeAttribute( 'role' );
		span.removeAttribute( 'aria-label' );
	} );
	document.addEventListener( 'keydown', ( event ) => {
		const span = event.target.closest( '[data-imj-text][contenteditable]' );
		if ( span && ( event.key === 'Escape' || event.key === 'Enter' ) ) {
			event.preventDefault();
			if ( event.key === 'Escape' ) {
				span.textContent = span.dataset.original;
			}
			span.blur();
		}
	} );

	document.addEventListener( 'dragstart', ( event ) => {
		const element = event.target.closest( '[data-imj-id]' );
		const node = element && dragNodes.get( element.dataset.imjId );
		if ( event.target.closest( '[contenteditable]' ) || ! node || ! node.draggable ) {
			event.preventDefault();
			return;
		}
		draggedId = node.id;
		event.dataTransfer.setData( 'text/plain', node.id );
		event.dataTransfer.effectAllowed = 'move';
	} );
	document.addEventListener( 'dragover', ( event ) => {
		if ( draggedId && event.target.closest( '[data-imj-id]' ) ) {
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
		}
	} );
	document.addEventListener( 'drop', ( event ) => {
		event.preventDefault();
		const element = event.target.closest( '[data-imj-id]' );
		const target = element && dragNodes.get( element.dataset.imjId );
		if ( draggedId && target ) {
			const bounds = element.getBoundingClientRect();
			send( { type: 'imj:move', id: draggedId, target: target.id, position: event.clientY < bounds.top + bounds.height / 2 ? 'before' : 'after' } );
		}
		draggedId = null;
	} );
	document.addEventListener( 'dragend', () => { draggedId = null; } );

	function send( message ) {
		window.parent.postMessage( message, editorOrigin );
	}

	function setHovered( element ) {
		if ( hovered === element ) {
			return;
		}
		if ( hovered ) {
			hovered.classList.remove( 'imj-hover' );
		}
		hovered = element;
		if ( hovered ) {
			hovered.classList.add( 'imj-hover' );
		}
	}

	document.addEventListener( 'mouseover', ( event ) => {
		setHovered( event.target.closest( '[data-imj-id]' ) );
	} );

	document.documentElement.addEventListener( 'mouseleave', () => setHovered( null ) );

	// The preview is for selecting, so links and forms must not navigate away.
	document.addEventListener(
		'click',
		( event ) => {
			if ( event.target.closest( '[contenteditable]' ) ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			const target = event.target.closest( '[data-imj-id]' );
			if ( target ) {
				send( { type: 'imj:select', id: target.dataset.imjId } );
			}
		},
		true
	);

	document.addEventListener( 'submit', ( event ) => event.preventDefault(), true );

	function elements( id ) {
		return document.querySelectorAll( '[data-imj-id="' + CSS.escape( id ) + '"]' );
	}

	function highlight( ids, scroll ) {
		document.querySelectorAll( '.imj-selected' ).forEach( ( node ) => node.classList.remove( 'imj-selected' ) );

		let first = null;
		ids.forEach( ( id ) => {
			elements( id ).forEach( ( node ) => {
				node.classList.add( 'imj-selected' );
				first = first || node;
			} );
		} );

		if ( first && scroll ) {
			first.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
		}
	}

	// Shows an unsaved change. Loop items share an id, so every copy updates.
	function apply( change ) {
		elements( change.id ).forEach( ( node ) => {
			if ( typeof change.text === 'string' ) {
				node.textContent = change.text;
				return;
			}

			// Keep the editor's own outline classes when the class attribute is replaced.
			const editorClasses = [ 'imj-hover', 'imj-selected' ].filter( ( name ) => node.classList.contains( name ) );
			if ( change.value === null ) {
				node.removeAttribute( change.name );
			} else {
				node.setAttribute( change.name, change.value );
			}
			if ( change.name === 'class' ) {
				editorClasses.forEach( ( name ) => node.classList.add( name ) );
			}
		} );
	}

	// Unsaved style changes, in a style element after the template stylesheet so they win.
	function applyCss( css ) {
		let style = document.getElementById( 'imj-live-css' );
		if ( ! style ) {
			style = document.createElement( 'style' );
			style.id = 'imj-live-css';
			document.body.append( style );
		}
		style.textContent = css;
	}

	function inspect( id, properties ) {
		const node = elements( id )[ 0 ];
		if ( ! node ) {
			return;
		}
		const computed = window.getComputedStyle( node );
		const values = {};
		properties.forEach( ( property ) => {
			values[ property ] = computed.getPropertyValue( property );
		} );
		send( { type: 'imj:computed', id, values } );
	}

	// Reloads after a save or discard without losing the scroll position.
	const scrollKey = 'imajinerPreviewScroll:' + window.location.pathname;

	function reload() {
		try {
			window.sessionStorage.setItem( scrollKey, String( window.scrollY ) );
		} catch ( error ) {
			// Storage unavailable: reload at the top.
		}
		window.location.reload();
	}

	try {
		const saved = window.sessionStorage.getItem( scrollKey );
		if ( saved !== null ) {
			window.sessionStorage.removeItem( scrollKey );
			window.addEventListener( 'load', () => window.scrollTo( 0, Number( saved ) ) );
		}
	} catch ( error ) {
		// Storage unavailable: nothing to restore.
	}

	window.addEventListener( 'message', ( event ) => {
		if ( event.source !== window.parent || event.origin !== editorOrigin || ! event.data ) {
			return;
		}

		switch ( event.data.type ) {
			case 'imj:inline-config':
				inlineEnabled = event.data.enabled;
				break;
			case 'imj:text':
				document.querySelectorAll( '[data-imj-text="' + window.CSS.escape( event.data.id ) + '"]' ).forEach( ( span ) => {
					const current = span.textContent;
					span.textContent = ( current.match( /^\s*/ )[0] ) + event.data.text + ( current.match( /\s*$/ )[0] );
				} );
				break;
			case 'imj:drag-config':
				dragNodes = new Map( event.data.nodes.map( ( node ) => [ node.element, node ] ) );
				event.data.nodes.forEach( ( node ) => elements( node.element ).forEach( ( element ) => { element.draggable = node.draggable; } ) );
				break;
			case 'imj:highlight':
				highlight( event.data.ids, event.data.scroll );
				break;
			case 'imj:apply':
				apply( event.data );
				break;
			case 'imj:css':
				applyCss( event.data.css );
				break;
			case 'imj:inspect':
				inspect( event.data.id, event.data.properties );
				break;
			case 'imj:reload':
				reload();
				break;
		}
	} );

	send( { type: 'imj:ready' } );
} )( window.imajinerPreview );
