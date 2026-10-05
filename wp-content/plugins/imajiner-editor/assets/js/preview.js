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
	let inlineEditor = null;
	let inlineAllowed = false;

	function textRanges( id ) {
		const result = [];
		const walker = document.createTreeWalker( document.body, window.NodeFilter.SHOW_COMMENT );
		let comment;
		while ( ( comment = walker.nextNode() ) ) {
			if ( comment.data === 'imj-text:' + id ) {
				const end = comment.nextSibling;
				if ( end && end.nodeType === 3 && end.nextSibling && end.nextSibling.nodeType === 8 && end.nextSibling.data === '/imj-text:' + id ) {
					result.push( end );
				}
			}
		}
		return result;
	}

	function applyText( id, text ) {
		textRanges( id ).forEach( ( node ) => {
			const leading = ( node.textContent.match( /^\s*/ ) || [ '' ] )[ 0 ];
			const trailing = ( node.textContent.match( /\s*$/ ) || [ '' ] )[ 0 ];
			node.textContent = leading + text + trailing;
		} );
	}

	document.addEventListener( 'dblclick', ( event ) => {
		if ( ! inlineAllowed || inlineEditor ) { return; }
		let range;
		if ( document.caretRangeFromPoint ) { range = document.caretRangeFromPoint( event.clientX, event.clientY ); }
		const position = ! range && document.caretPositionFromPoint ? document.caretPositionFromPoint( event.clientX, event.clientY ) : null;
		const text = range ? range.startContainer : position && position.offsetNode;
		if ( ! text || text.nodeType !== 3 || ! text.previousSibling || text.previousSibling.nodeType !== 8 || ! /^imj-text:t\d+$/.test( text.previousSibling.data ) ) { return; }
		const id = text.previousSibling.data.slice( 'imj-text:'.length );
		const original = text.textContent;
		const span = document.createElement( 'span' );
		span.className = 'imj-inline-editor'; span.contentEditable = 'true'; span.setAttribute( 'role', 'textbox' );
		span.setAttribute( 'aria-label', window.wp.i18n.__( 'Edit text', 'imajiner-editor' ) );
		span.textContent = original.trim(); text.replaceWith( span ); inlineEditor = span;
		let cancelled = false;
		span.addEventListener( 'keydown', ( keyEvent ) => {
			if ( keyEvent.key === 'Escape' ) { cancelled = true; span.blur(); }
			if ( keyEvent.key === 'Enter' ) { keyEvent.preventDefault(); span.blur(); }
		} );
		span.addEventListener( 'paste', ( pasteEvent ) => {
			pasteEvent.preventDefault();
			const selection = window.getSelection();
			if ( ! selection.rangeCount ) { return; }
			const selected = selection.getRangeAt( 0 ); selected.deleteContents();
			const plain = document.createTextNode( pasteEvent.clipboardData.getData( 'text/plain' ) ); selected.insertNode( plain ); selected.setStartAfter( plain ); selected.collapse( true );
		} );
		span.addEventListener( 'blur', () => {
			const value = span.textContent.trim();
			const replacement = document.createTextNode( original ); span.replaceWith( replacement ); inlineEditor = null;
			if ( ! cancelled && value ) { applyText( id, value ); send( { type: 'imj:inline', id, text: value } ); }
		}, { once: true } );
		span.focus();
	} );

	document.addEventListener( 'dragstart', ( event ) => {
		const element = event.target.closest( '[data-imj-id]' );
		const node = element && dragNodes.get( element.dataset.imjId );
		if ( ! node || ! node.draggable ) {
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
			if ( inlineEditor && inlineEditor.contains( event.target ) ) { return; }
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
		return document.querySelectorAll( '[data-imj-id="' + window.CSS.escape( id ) + '"]' );
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
		if ( event.origin !== editorOrigin || event.source !== window.parent || ! event.data ) {
			return;
		}

		switch ( event.data.type ) {
			case 'imj:inline-config':
				inlineAllowed = !! event.data.enabled;
				break;
			case 'imj:text':
				if ( /^t\d+$/.test( event.data.id ) && typeof event.data.text === 'string' ) { applyText( event.data.id, event.data.text ); }
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
