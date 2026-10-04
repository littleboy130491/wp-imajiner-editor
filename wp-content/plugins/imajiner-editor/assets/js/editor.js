/**
 * Imajiner Editor screen: layers tree, preview selection, properties panel,
 * pending changes, save and version history.
 *
 * The structure comes from Imajiner_Template_Scanner. Element ids match the
 * data-imj-id attributes in the preview. Changes are kept locally, shown live
 * in the preview where possible, and written to the template on save.
 */
( function ( data ) {
	'use strict';
	const __ = ( text ) => window.wp.i18n.__( text, 'imajiner-editor' );

	let structure = data.structure;
	let php = structure.php;
	let hash = data.hash;
	// Breakpoint => class name => property => value, from the template stylesheet.
	let styles = data.styles;
	let styleRules = data.styleRules || [];
	let styleState = '';
	let styleContext = null;
	let lockBlocked = true;
	let undoStack = [];
	let redoStack = [];
	let stageTimer;
	let currentStage = null;
	// Breakpoint the preview shows and the Style tab edits. The first is the base styles.
	let device = data.breakpoints[ 0 ].name;

	const treeEl = document.getElementById( 'imj-tree' );
	const propsEl = document.getElementById( 'imj-props' );
	const warningsEl = document.getElementById( 'imj-warnings' );
	const frame = document.getElementById( 'imj-preview' );
	const frameWrap = document.getElementById( 'imj-frame' );
	const saveButton = document.getElementById( 'imj-save' );
	const discardButton = document.getElementById( 'imj-discard' );
	const statusEl = document.getElementById( 'imj-status' );
	const historyToggle = document.getElementById( 'imj-history-toggle' );
	const historyPanel = document.getElementById( 'imj-history-panel' );
	const devicesEl = document.getElementById( 'imj-devices' );

	// Node id => { node, parent, row, item }.
	const index = new Map();
	// Change key => change sent to the save endpoint.
	const changes = new Map();
	let operations = [];
	let saved = { hash: data.hash, structure: data.structure, styles: data.styles, styleRules };
	let draggedId = null;
	let selectedId = null;
	let busy = false;
	let activeTab = 'content';
	// Element id => class chosen as the style target, for elements with several classes.
	const styleTargets = new Map();

	const LEVELS = {
		static: { label: 'Static', note: 'Static HTML. Edit it below.' },
		dynamic: { label: 'Dynamic', note: 'The value comes from PHP, so it can’t be edited as text.' },
		structure: { label: 'Structure', note: 'A PHP loop or condition. Edit the elements inside it.' },
		locked: { label: 'Locked', note: 'PHP the visual editor leaves alone.' },
	};

	// Attributes that point at URLs or image sources get their own field types.
	const URL_ATTRIBUTES = [ 'href', 'src', 'action', 'poster' ];

	// Style controls shown for every element. "list" picks the suggestions offered.
	const STYLE_GROUPS = [
		{
			title: 'Typography',
			controls: [
				{ property: 'color', label: 'Color', list: 'color' },
				{ property: 'font-size', label: 'Size', list: 'text' },
				{ property: 'font-weight', label: 'Weight', list: 'weight' },
				{ property: 'line-height', label: 'Line height' },
				{ property: 'text-align', label: 'Align', list: 'align' },
			],
		},
		{ title: 'Background', controls: [ { property: 'background-color', label: 'Color', list: 'color' } ] },
		{
			title: 'Spacing',
			controls: [
				{ property: 'padding', label: 'Padding', list: 'space' },
				{ property: 'margin', label: 'Margin', list: 'space' },
				{ property: 'gap', label: 'Gap', list: 'space' },
			],
		},
		{
			title: 'Size',
			controls: [
				{ property: 'width', label: 'Width' },
				{ property: 'max-width', label: 'Max width' },
			],
		},
		{
			title: 'Border',
			controls: [
				{ property: 'border-radius', label: 'Radius', list: 'radius' },
				{ property: 'border', label: 'Border' },
			],
		},
	];
	const CONTROLLED_PROPERTIES = STYLE_GROUPS.flatMap( ( group ) => group.controls.map( ( control ) => control.property ) );

	// Same rules as Imajiner_Css_Editor on the server.
	const CLASS_PATTERN = /^-?[_a-zA-Z][_a-zA-Z0-9-]*$/;
	const PROPERTY_PATTERN = /^(--[a-zA-Z0-9-]+|-?[a-zA-Z][a-zA-Z0-9-]*)$/;
	const FORBIDDEN_IN_VALUE = /[{};<>\\]|\/\*|\*\//;

	/* Helpers */

	function h( tag, props, ...children ) {
		const node = document.createElement( tag );
		Object.entries( props || {} ).forEach( ( [ key, value ] ) => {
			if ( value === null || value === undefined || value === false ) {
				return;
			}
			if ( key === 'className' ) {
				node.className = value;
			} else if ( key === 'value' ) {
				node.value = value;
			} else if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else {
				node.setAttribute( key, value === true ? '' : value );
			}
		} );
		children.flat( Infinity ).forEach( ( child ) => {
			if ( child !== null && child !== undefined && child !== false ) {
				node.append( child instanceof Node ? child : String( child ) );
			}
		} );
		return node;
	}

	function truncate( text, length ) {
		const clean = text.replace( /\s+/g, ' ' ).trim();
		return clean.length > length ? clean.slice( 0, length ) + '…' : clean;
	}

	function isDynamicValue( value ) {
		return typeof value === 'string' && value.indexOf( '{{imj-php:' ) !== -1;
	}

	// An element whose only child is text shows and edits that text directly.
	function soleText( node ) {
		return node.type === 'element' && node.children.length === 1 && node.children[ 0 ].type === 'text' ? node.children[ 0 ] : null;
	}

	function visibleChildren( node ) {
		return ! node.children || soleText( node ) ? [] : node.children;
	}

	function level( node ) {
		switch ( node.type ) {
			case 'element':
			case 'text':
				return 'static';
			case 'block':
				return 'structure';
			case 'php':
				return php[ node.php ].kind === 'dynamic' ? 'dynamic' : 'locked';
		}
		return null;
	}

	function ancestors( id ) {
		const list = [];
		for ( let parent = index.get( id ).parent; parent; parent = index.get( parent.id ).parent ) {
			list.push( parent );
		}
		return list;
	}

	/* Pending changes */

	function textKey( id ) {
		return id + '|text';
	}

	function attrKey( id, name ) {
		return id + '|attr|' + name;
	}

	function currentText( textNode ) {
		const change = changes.get( textKey( textNode.id ) );
		return change ? change.value : textNode.text.trim();
	}

	// Attributes with pending changes applied. Removed ones are left out.
	function currentAttrs( node ) {
		const attrs = Object.assign( {}, node.attrs );
		changes.forEach( ( change ) => {
			if ( change.type === 'attr' && change.id === node.id ) {
				if ( change.value === null ) {
					delete attrs[ change.name ];
				} else {
					attrs[ change.name ] = change.value;
				}
			}
		} );
		return attrs;
	}

	function setText( textNode, element, value ) {
		if ( busy || lockBlocked ) { return; }
		checkpoint();
		const key = textKey( textNode.id );
		if ( value === textNode.text.trim() ) {
			changes.delete( key );
		} else {
			changes.set( key, { type: 'text', id: textNode.id, value } );
		}
		// Text markers preserve surrounding mixed markup and PHP output.
		sendToPreview( { type: 'imj:text', id: textNode.id, text: value } );
		refreshTreeLabel( element || textNode );
		updateToolbar();
	}

	function setAttr( node, name, value ) {
		if ( busy || lockBlocked ) { return; }
		checkpoint();
		const key = attrKey( node.id, name );
		const original = Object.prototype.hasOwnProperty.call( node.attrs, name ) ? node.attrs[ name ] : null;
		if ( value === original ) {
			changes.delete( key );
		} else {
			changes.set( key, { type: 'attr', id: node.id, name, value } );
		}
		sendToPreview( { type: 'imj:apply', id: node.id, name, value } );
		refreshTreeLabel( node );
		updateToolbar();
	}

	/* Style values, for the active device */

	function styleKey( className, property ) {
		return 'style|' + ( styleContext ? styleContext.media + '|' + styleContext.selector : device + '|' + className + styleState ) + '|' + property;
	}

	function classStyles( className ) {
		return styleContext ? styleContext.values : ( styles[ device ] && styles[ device ][ className + styleState ] ) || {};
	}

	function savedStyle( className, property ) {
		return classStyles( className )[ property ] || '';
	}

	function currentStyle( className, property ) {
		const change = changes.get( styleKey( className, property ) );
		if ( change ) {
			return change.value === null ? '' : change.value;
		}
		return savedStyle( className, property );
	}

	// An empty value removes the declaration.
	function setStyle( className, property, value ) {
		if ( busy || lockBlocked ) { return; }
		checkpoint();
		const clean = value.trim();
		const key = styleKey( className, property );
		if ( clean === savedStyle( className, property ) ) {
			changes.delete( key );
		} else {
			changes.set( key, Object.assign( { type: 'style', device, state: styleState, class: className, property, value: clean === '' ? null : clean }, styleContext ? { selector: styleContext.selector, media: styleContext.media } : {} ) );
		}
		sendLiveCss();
		clearTimeout( stageTimer );
		stageTimer = setTimeout( () => refreshStage().catch( ( error ) => setStatus( error.message, 'error' ) ), 350 );
		updateToolbar();
	}

	// Unsaved style changes as CSS for the preview, in breakpoint order so the
	// cascade matches the stylesheet. It comes after the template stylesheet with
	// the same specificity, so it wins. Removals show after saving.
	function sendLiveCss() {
		const styleChanges = [ ...changes.values() ].filter( ( change ) => change.type === 'style' && change.value !== null );
		const css = data.breakpoints.flatMap( ( breakpoint ) => styleChanges.filter( ( change ) => change.device === breakpoint.name ).map( ( change ) => {
			const rule = ( change.selector || data.cssScope + ' .' + change.class + ( change.state || '' ) ) + ' { ' + change.property + ': ' + change.value + '; }';
			const media = change.selector ? change.media : breakpoint.media;
			return media ? '@media ' + media + ' { ' + rule + ' }' : rule;
		} ) ).join( '\n' );
		sendToPreview( { type: 'imj:css', css } );
	}

	/* Devices */

	function activeBreakpoint() {
		return data.breakpoints.find( ( breakpoint ) => breakpoint.name === device );
	}

	function renderDevices() {
		devicesEl.replaceChildren(
			...data.breakpoints.map( ( breakpoint ) =>
				h(
					'button',
					{
						type: 'button',
						className: 'imj-device' + ( breakpoint.name === device ? ' is-active' : '' ),
						'aria-pressed': breakpoint.name === device ? 'true' : 'false',
						title: breakpoint.label + ( breakpoint.media ? ' ' + breakpoint.media : '' ),
						onClick: () => setDevice( breakpoint.name ),
					},
					breakpoint.label
				)
			)
		);
	}

	// Renders the preview at the device's real width, so @media rules match as on a
	// real screen, and scales it down when the canvas is narrower than that.
	function fitFrame() {
		const canvas = frameWrap.parentElement;
		const style = window.getComputedStyle( canvas );
		const availableWidth = canvas.clientWidth - parseFloat( style.paddingLeft ) - parseFloat( style.paddingRight );
		const availableHeight = canvas.clientHeight - parseFloat( style.paddingTop ) - parseFloat( style.paddingBottom );
		const breakpoint = activeBreakpoint();

		const width = breakpoint.media ? breakpoint.width : Math.max( availableWidth, breakpoint.width );
		const scale = Math.min( 1, availableWidth / width );

		frame.style.width = width + 'px';
		frame.style.height = availableHeight / scale + 'px';
		frame.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
		frameWrap.style.width = width * scale + 'px';
		frameWrap.style.height = availableHeight + 'px';
		canvas.classList.toggle( 'is-device', !! breakpoint.media );

		let label = frameWrap.querySelector( '.imj-frame__scale' );
		if ( ! label ) {
			label = h( 'span', { className: 'imj-frame__scale' } );
			frameWrap.append( label );
		}
		label.textContent = Math.round( width ) + 'px' + ( scale < 1 ? ' · ' + Math.round( scale * 100 ) + '%' : '' );
	}

	function setDevice( name ) {
		device = name;
		fitFrame();
		renderDevices();
		if ( selectedId && activeTab === 'style' ) {
			renderProps( selectedId );
			// Computed values change with the preview width; ask again once it has re-laid out.
			window.requestAnimationFrame( () => sendToPreview( { type: 'imj:inspect', id: selectedId, properties: CONTROLLED_PROPERTIES } ) );
		}
	}

	function updateToolbar() {
		const count = changes.size + operations.length;
		saveButton.disabled = busy || lockBlocked || ! count;
		discardButton.disabled = busy || ! count;
		saveButton.textContent = count ? 'Save (' + count + ')' : 'Save';
		propsEl.inert = busy || lockBlocked;
		document.getElementById( 'imj-undo' ).disabled = busy || lockBlocked || ! undoStack.length;
		document.getElementById( 'imj-redo' ).disabled = busy || lockBlocked || ! redoStack.length;
		document.getElementById( 'imj-library' ).disabled = busy || lockBlocked;
		treeEl.inert = busy;
		sendToPreview( { type: 'imj:inline-config', enabled: ! busy && ! lockBlocked } );
		if ( document.getElementById( 'imj-add-section' ) ) {
			document.getElementById( 'imj-add-section' ).disabled = busy || lockBlocked;
		}
	}

	function setStatus( message, type ) {
		statusEl.textContent = message || '';
		statusEl.className = 'imj-status' + ( type ? ' imj-status--' + type : '' );
	}

	/* Layers tree */

	function describe( node ) {
		switch ( node.type ) {
			case 'section':
				return { kind: 'Section', text: node.name };
			case 'element': {
				const attrs = currentAttrs( node );
				const className = typeof attrs.class === 'string' && ! isDynamicValue( attrs.class ) ? attrs.class.trim().split( /\s+/ )[ 0 ] : '';
				const text = soleText( node );
				return { kind: node.tag + ( className ? '.' + className : '' ), text: text ? truncate( currentText( text ), 32 ) : '' };
			}
			case 'text':
				return { kind: 'Text', text: truncate( currentText( node ), 32 ) };
			default: {
				const info = php[ node.php ];
				return { kind: info.label, text: info.detail ? truncate( info.detail, 32 ) : '' };
			}
		}
	}

	function renderNodes( nodes, parent ) {
		return h(
			'ul',
			{ className: 'imj-tree__list', role: parent ? 'group' : 'tree', id: parent ? 'imj-group-' + parent.id : null, 'aria-label': parent ? null : __( 'Layers' ) },
			nodes.map( ( node ) => renderNode( node, parent ) )
		);
	}

	function renderRowContent( node ) {
		const { kind, text } = describe( node );
		const nodeLevel = level( node );
		return [
			h( 'span', { className: 'imj-tree__kind' }, kind ),
			text ? h( 'span', { className: 'imj-tree__text' }, text ) : null,
			nodeLevel && nodeLevel !== 'static' ? h( 'span', { className: 'imj-badge imj-badge--' + nodeLevel }, LEVELS[ nodeLevel ].label ) : null,
		];
	}

	function renderNode( node, parent ) {
		const children = visibleChildren( node );

		const toggle = children.length
			? h( 'button', {
					className: 'imj-tree__toggle',
					type: 'button',
					'aria-label': 'Expand or collapse',
					onClick: ( event ) => {
						event.stopPropagation();
						item.classList.toggle( 'is-collapsed' );
					},
			  } )
			: h( 'span', { className: 'imj-tree__toggle imj-tree__toggle--empty' } );

		const label = h( 'span', { className: 'imj-tree__label' }, renderRowContent( node ) );
		const row = h( 'div', { className: 'imj-tree__row imj-tree__row--' + node.type, onClick: () => select( node.id, 'tree' ) }, toggle, label );
		row.draggable = !! node.mutable;
		row.addEventListener( 'dragstart', ( event ) => {
			if ( busy || ! node.mutable ) {
				event.preventDefault();
				return;
			}
			draggedId = node.id;
			event.dataTransfer.setData( 'text/plain', node.id );
			event.dataTransfer.effectAllowed = 'move';
		} );
		row.addEventListener( 'dragover', ( event ) => {
			if ( canMove( draggedId, node.id ) ) {
				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';
			}
		} );
		row.addEventListener( 'drop', ( event ) => {
			event.preventDefault();
			const position = event.clientY < row.getBoundingClientRect().top + row.offsetHeight / 2 ? 'before' : 'after';
			moveNode( draggedId, node.id, position );
			draggedId = null;
		} );
		row.addEventListener( 'dragend', () => { draggedId = null; } );
		const item = h( 'li', { className: 'imj-tree__item', role: 'treeitem' }, row, children.length ? renderNodes( children, node ) : null );

		index.set( node.id, { node, parent, row, item, label } );
		row.setAttribute( 'role', 'treeitem' );
		row.dataset.nodeId = node.id;
		row.tabIndex = -1;
		item.setAttribute( 'role', 'none' );
		if ( children.length ) { row.setAttribute( 'aria-expanded', 'true' ); row.setAttribute( 'aria-owns', 'imj-group-' + node.id ); }
		row.addEventListener( 'focus', () => select( node.id, 'keyboard' ) );
		row.addEventListener( 'keydown', ( event ) => navigateLayers( event, node.id ) );
		if ( children.length ) {
			toggle.tabIndex = -1;
			toggle.addEventListener( 'click', () => row.setAttribute( 'aria-expanded', String( ! item.classList.contains( 'is-collapsed' ) ) ) );
		}
		return item;
	}

	function navigateLayers( event, id ) {
		const entry = index.get( id );
		const visible = [ ...treeEl.querySelectorAll( '.imj-tree__row' ) ].filter( ( row ) => row.getClientRects().length ).map( ( row ) => index.get( row.dataset.nodeId ) );
		const at = visible.indexOf( entry );
		let next;
		if ( event.key === 'ArrowDown' ) { next = visible[ at + 1 ]; }
		else if ( event.key === 'ArrowUp' ) { next = visible[ at - 1 ]; }
		else if ( event.key === 'Home' ) { next = visible[ 0 ]; }
		else if ( event.key === 'End' ) { next = visible[ visible.length - 1 ]; }
		else if ( event.key === 'ArrowRight' && visibleChildren( entry.node ).length ) {
			const collapsed = entry.item.classList.contains( 'is-collapsed' );
			entry.item.classList.remove( 'is-collapsed' ); entry.row.setAttribute( 'aria-expanded', 'true' );
			if ( ! collapsed ) { next = index.get( visibleChildren( entry.node )[ 0 ].id ); }
		} else if ( event.key === 'ArrowLeft' ) {
			if ( visibleChildren( entry.node ).length && ! entry.item.classList.contains( 'is-collapsed' ) ) {
				entry.item.classList.add( 'is-collapsed' ); entry.row.setAttribute( 'aria-expanded', 'false' );
			} else if ( entry.parent ) { next = index.get( entry.parent.id ); }
		} else { return; }
		event.preventDefault();
		if ( next ) { next.row.focus(); }
	}

	function refreshTreeLabel( node ) {
		const entry = index.get( node.id );
		if ( entry ) {
			entry.label.replaceChildren( ...renderRowContent( node ).filter( Boolean ) );
			entry.row.classList.toggle( 'is-changed', hasChanges( node ) );
		}
	}

	function hasChanges( node ) {
		const text = soleText( node );
		for ( const change of changes.values() ) {
			if ( change.id === node.id || ( text && change.id === text.id ) ) {
				return true;
			}
		}
		return false;
	}

	function renderTree() {
		index.clear();
		treeEl.replaceChildren( renderNodes( structure.tree, null ) );
		if ( index.size ) { treeEl.querySelector( '.imj-tree__row' ).tabIndex = 0; }

		warningsEl.replaceChildren();
		const warnings = structure.warnings;
		if ( warnings.length ) {
			warningsEl.append(
				h(
					'details',
					{ className: 'imj-warnings' },
					h( 'summary', {}, warnings.length + ( warnings.length === 1 ? ' template contract warning' : ' template contract warnings' ) ),
					h( 'ul', {}, warnings.map( ( warning ) => h( 'li', {}, warning ) ) ),
					data.normalizeUrl ? h( 'a', { href: data.normalizeUrl }, 'Normalize with AI' ) : null
				)
			);
		}
	}

	/* Structural edits are staged on the server so ids and PHP remain accurate. */

	function canMove( id, target ) {
		const source = index.get( id );
		const destination = index.get( target );
		return ! busy && source && destination && id !== target && source.node.mutable && source.node.group === destination.node.group && source.parent === destination.parent && [ 'element', 'section' ].includes( destination.node.type );
	}

	function moveNode( id, target, position ) {
		if ( canMove( id, target ) ) {
			stageStructure( { type: 'move', id, target, position } );
		}
	}

	function structuralControls( node ) {
		if ( ! [ 'element', 'section' ].includes( node.type ) ) {
			return null;
		}
		const controls = [];
		if ( node.container ) {
			const starter = h( 'select', { 'aria-label': 'Element to add' },
				[ 'heading', 'paragraph', 'link', 'image', 'div' ].map( ( name ) => h( 'option', { value: name }, name ) ) );
			let target = node;
			if ( node.type === 'section' ) {
				target = node.children.find( ( child ) => child.type === 'element' && child.container ) || node;
			}
			controls.push( starter, h( 'button', { type: 'button', className: 'imj-button', onClick: () => stageStructure( { type: 'insert', target: target.id, position: 'inside', starter: starter.value } ) }, 'Add element' ) );
		}
		if ( node.mutable ) {
			controls.push(
				h( 'button', { type: 'button', className: 'imj-button', onClick: () => stageStructure( { type: 'duplicate', id: node.id } ) }, 'Duplicate' ),
				h( 'button', { type: 'button', className: 'imj-button', onClick: ( event ) => {
					if ( event.currentTarget.dataset.confirm !== 'yes' ) {
						event.currentTarget.dataset.confirm = 'yes';
						event.currentTarget.textContent = 'Confirm delete';
						return;
					}
					stageStructure( { type: 'delete', id: node.id } );
				} }, 'Delete' )
			);
			const entry = index.get( node.id );
			const siblings = entry.parent ? entry.parent.children : structure.tree;
			const at = siblings.indexOf( node );
			[ [ -1, 'Move up', 'before' ], [ 1, 'Move down', 'after' ] ].forEach( ( [ step, label, position ] ) => {
				const target = siblings[ at + step ];
				controls.push( h( 'button', { type: 'button', className: 'imj-button', disabled: ! target || ! canMove( node.id, target.id ), onClick: () => moveNode( node.id, target.id, position ) }, label ) );
			} );
		} else {
			controls.push( h( 'p', { className: 'imj-muted' }, 'This container includes PHP. Edit its static children; the container cannot be deleted, duplicated or moved.' ) );
		}
		return h( 'div', { className: 'imj-structure-controls' }, controls );
	}

	async function stageStructure( operation ) {
		return stageOperation( { type: 'structure', operation } ).catch( () => {} );
	}

	function orderedChanges() {
		return [ ...operations, ...( changes.size ? [ { type: 'batch', changes: [ ...changes.values() ] } ] : [] ) ];
	}

	function snapshot() {
		return JSON.parse( JSON.stringify( { operations, changes: [ ...changes.entries() ], selectedId } ) );
	}

	function checkpoint() {
		undoStack.push( snapshot() );
		if ( undoStack.length > 100 ) { undoStack.shift(); }
		redoStack = [];
	}

	function stagedResult( result ) {
		currentStage = result.stage;
		structure = result.structure; php = structure.php; styles = result.styles;
		styleRules = result.styleRules || [];
		styleContext = null;
		renderTree();
		if ( selectedId && index.has( selectedId ) ) { select( selectedId, 'tree' ); }
		else { selectedId = null; renderEmptyProps(); window.dispatchEvent( new window.CustomEvent( 'imajiner:selection', { detail: getState() } ) ); }
		const url = new URL( data.previewUrl );
		url.searchParams.set( 'imajiner_stage', result.stage );
		frame.src = url.toString();
	}

	async function refreshStage() {
		if ( busy || lockBlocked ) { return; }
		busy = true; updateToolbar();
		try {
			const proposed = orderedChanges();
			const result = await api( 'POST', '/stage', { hash, changes: proposed } );
			operations = proposed; changes.clear(); stagedResult( result );
			return result;
		} finally { busy = false; updateToolbar(); }
	}

	async function stageOperation( operation ) {
		if ( busy || lockBlocked ) { return; }
		clearTimeout( stageTimer );
		const proposed = [ ...orderedChanges(), operation ];
		busy = true; updateToolbar();
		try {
			const result = await api( 'POST', '/stage', { hash, changes: proposed } );
			checkpoint(); operations = proposed; changes.clear(); selectedId = null;
			styleTargets.clear(); stagedResult( result );
			setStatus( __( 'Unsaved changes staged' ) );
			return result;
		} catch ( error ) { setStatus( error.message, 'error' ); throw error; }
		finally { busy = false; updateToolbar(); }
	}

	async function travel( redo ) {
		if ( busy || lockBlocked ) { return; }
		clearTimeout( stageTimer );
		const from = redo ? redoStack : undoStack;
		const to = redo ? undoStack : redoStack;
		if ( ! from.length ) { return; }
		const target = from[ from.length - 1 ];
		const proposed = [ ...target.operations, ...( target.changes.length ? [ { type: 'batch', changes: target.changes.map( ( item ) => item[ 1 ] ) } ] : [] ) ];
		busy = true; updateToolbar();
		try {
			const result = await api( 'POST', '/stage', { hash, changes: proposed } );
			to.push( snapshot() ); from.pop(); operations = proposed; changes.clear(); selectedId = target.selectedId;
			stagedResult( result );
		} catch ( error ) { setStatus( error.message, 'error' ); }
		finally { busy = false; updateToolbar(); }
	}

	/* Properties panel */

	function field( label, ...value ) {
		return h( 'div', { className: 'imj-field' }, h( 'div', { className: 'imj-field__label' }, label ), h( 'div', { className: 'imj-field__value' }, value ) );
	}

	function phpChip( number ) {
		return h( 'span', { className: 'imj-chip', title: php[ number ].code }, php[ number ].label );
	}

	// Attribute values hold {{imj-php:N}} where PHP prints into them.
	function dynamicValue( value ) {
		return value.split( /\{\{imj-php:(\d+)\}\}/ ).map( ( part, i ) => ( i % 2 ? phpChip( Number( part ) ) : part ) );
	}

	function textEditor( textNode, element ) {
		return h( 'textarea', {
			className: 'imj-input imj-input--text',
			rows: 3,
			value: currentText( textNode ),
			onInput: ( event ) => {
				const value = event.target.value;
				event.target.classList.toggle( 'is-invalid', ! value.trim() );
				if ( value.trim() ) {
					setText( textNode, element, value );
				}
			},
		} );
	}

	function attributeRow( node, name, value ) {
		const original = node.attrs[ name ];
		if ( isDynamicValue( original ) ) {
			return [ h( 'dt', {}, name ), h( 'dd', { className: 'imj-attrs__dynamic' }, dynamicValue( original ) ) ];
		}

		const input =
			value === true
				? h( 'span', { className: 'imj-attrs__boolean' }, 'on' )
				: h( 'input', {
						className: 'imj-input',
						type: 'text',
						value,
						spellcheck: URL_ATTRIBUTES.includes( name ) || name === 'class' ? 'false' : null,
						onInput: ( event ) => setAttr( node, name, event.target.value ),
				  } );

		const remove = h( 'button', {
			type: 'button',
			className: 'imj-icon-button',
			title: 'Remove ' + name,
			'aria-label': 'Remove ' + name,
			onClick: () => {
				setAttr( node, name, null );
				renderProps( node.id );
			},
		}, '×' );

		return [ h( 'dt', {}, name ), h( 'dd', {}, input, remove ) ];
	}

	function addAttributeForm( node ) {
		const nameInput = h( 'input', { className: 'imj-input', type: 'text', placeholder: 'name', spellcheck: 'false' } );
		const valueInput = h( 'input', { className: 'imj-input', type: 'text', placeholder: 'value' } );
		const add = () => {
			const name = nameInput.value.trim();
			if ( ! /^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/.test( name ) || /^data-imj-/i.test( name ) ) {
				nameInput.classList.add( 'is-invalid' );
				return;
			}
			setAttr( node, name, valueInput.value );
			renderProps( node.id );
		};
		return h(
			'form',
			{
				className: 'imj-add-attr',
				onSubmit: ( event ) => {
					event.preventDefault();
					add();
				},
			},
			nameInput,
			valueInput,
			h( 'button', { type: 'submit', className: 'imj-button imj-button--small' }, 'Add' )
		);
	}

	function imageField( node ) {
		const attrs = currentAttrs( node );
		const src = typeof attrs.src === 'string' && ! isDynamicValue( attrs.src ) ? attrs.src : '';
		if ( isDynamicValue( node.attrs.src ) || ! window.wp || ! window.wp.media ) {
			return null;
		}
		return field(
			'Image',
			src ? h( 'img', { className: 'imj-image-preview', src, alt: '' } ) : null,
			h( 'button', { type: 'button', className: 'imj-button imj-button--small', onClick: () => chooseImage( node ) }, src ? 'Replace image' : 'Choose image' )
		);
	}

	function chooseImage( node ) {
		const picker = window.wp.media( {
			title: 'Choose image',
			library: { type: 'image' },
			multiple: false,
			button: { text: 'Use image' },
		} );
		picker.on( 'select', () => {
			const image = picker.state().get( 'selection' ).first().toJSON();
			setAttr( node, 'src', image.url );
			if ( image.alt ) {
				setAttr( node, 'alt', image.alt );
			}
			// Responsive candidates and sizes belong to the old image.
			[ 'srcset', 'sizes' ].forEach( ( name ) => {
				if ( Object.prototype.hasOwnProperty.call( currentAttrs( node ), name ) && ! isDynamicValue( node.attrs[ name ] ) ) {
					setAttr( node, name, null );
				}
			} );
			[ 'width', 'height' ].forEach( ( name ) => {
				if ( Object.prototype.hasOwnProperty.call( currentAttrs( node ), name ) && ! isDynamicValue( node.attrs[ name ] ) ) {
					setAttr( node, name, String( image[ name ] ) );
				}
			} );
			renderProps( node.id );
		} );
		picker.open();
	}

	/* Style tab */

	// Follows var(--imj-...) through the design tokens to a hex color for the color picker.
	function resolveColor( value ) {
		let resolved = value.trim();
		for ( let i = 0; i < 5; i++ ) {
			const match = resolved.match( /^var\(\s*(--[a-zA-Z0-9-]+)\s*(?:,[^)]*)?\)$/ );
			if ( ! match || ! data.tokens[ match[ 1 ] ] ) {
				break;
			}
			resolved = data.tokens[ match[ 1 ] ].trim();
		}
		if ( /^#[0-9a-f]{6}$/i.test( resolved ) ) {
			return resolved.toLowerCase();
		}
		if ( /^#[0-9a-f]{3}$/i.test( resolved ) ) {
			return ( '#' + resolved.slice( 1 ).replace( /./g, '$&$&' ) ).toLowerCase();
		}
		return null;
	}

	// Suggestions for style inputs: design tokens first, so templates stay on-system.
	function buildDatalists() {
		const tokens = Object.entries( data.tokens );
		const tokenOptions = ( prefix ) => tokens.filter( ( [ name ] ) => name.startsWith( prefix ) ).map( ( [ name, value ] ) => [ 'var(' + name + ')', value ] );
		const lists = {
			color: tokenOptions( '--imj-color-' ),
			text: tokenOptions( '--imj-text-' ),
			space: tokenOptions( '--imj-space-' ),
			radius: tokenOptions( '--imj-radius' ),
			weight: [ '300', '400', '500', '600', '700', '800' ].map( ( value ) => [ value, '' ] ),
			align: [ 'left', 'center', 'right', 'justify' ].map( ( value ) => [ value, '' ] ),
		};
		document.body.append(
			...Object.entries( lists ).map( ( [ name, options ] ) =>
				h( 'datalist', { id: 'imj-list-' + name }, options.map( ( [ value, label ] ) => h( 'option', { value, label: label || null } ) ) )
			)
		);
	}

	// Classes the editor can target: from a static class attribute only.
	function styleableClasses( node ) {
		const value = currentAttrs( node ).class;
		if ( typeof value !== 'string' || isDynamicValue( value ) ) {
			return [];
		}
		return value.trim().split( /\s+/ ).filter( ( name ) => CLASS_PATTERN.test( name ) );
	}

	function countClassUsers( className, nodes ) {
		return ( nodes || structure.tree ).reduce( ( count, node ) => {
			const own = node.type === 'element' && styleableClasses( node ).includes( className ) ? 1 : 0;
			return count + own + ( node.children ? countClassUsers( className, node.children ) : 0 );
		}, 0 );
	}

	function onStyleInput( className, property, input ) {
		const valid = ! FORBIDDEN_IN_VALUE.test( input.value );
		input.classList.toggle( 'is-invalid', ! valid );
		if ( valid ) {
			setStyle( className, property, input.value );
		}
	}

	function styleControl( className, control ) {
		const value = currentStyle( className, control.property );
		const input = h( 'input', {
			className: 'imj-input',
			type: 'text',
			value,
			list: control.list ? 'imj-list-' + control.list : null,
			spellcheck: 'false',
			'data-property': control.property,
			onInput: ( event ) => onStyleInput( className, control.property, event.target ),
		} );
		const row = h( 'div', { className: 'imj-style-row' }, h( 'label', { className: 'imj-style-row__label' }, control.label ), input );

		if ( control.list === 'color' ) {
			const swatch = h( 'input', {
				type: 'color',
				className: 'imj-swatch',
				value: resolveColor( value ) || '#000000',
				title: 'Pick a color',
				'aria-label': control.label + ' picker',
				onInput: ( event ) => {
					input.value = event.target.value;
					onStyleInput( className, control.property, input );
				},
			} );
			input.addEventListener( 'input', () => {
				const color = resolveColor( input.value );
				if ( color ) {
					swatch.value = color;
				}
			} );
			row.append( swatch );
		}

		return row;
	}

	// Declarations in the class's rule that have no dedicated control, plus a way to add more.
	function otherDeclarations( node, className ) {
		const properties = new Set( Object.keys( classStyles( className ) ) );
		changes.forEach( ( change ) => {
			if ( change.type === 'style' && change.device === device && change.class === className ) {
				properties.add( change.property );
			}
		} );

		const rows = [ ...properties ]
			.filter( ( property ) => ! CONTROLLED_PROPERTIES.includes( property ) && currentStyle( className, property ) !== '' )
			.map( ( property ) => [
				h( 'dt', {}, property ),
				h(
					'dd',
					{},
					h( 'input', {
						className: 'imj-input',
						type: 'text',
						value: currentStyle( className, property ),
						spellcheck: 'false',
						onInput: ( event ) => onStyleInput( className, property, event.target ),
					} ),
					h(
						'button',
						{
							type: 'button',
							className: 'imj-icon-button',
							title: 'Remove ' + property,
							'aria-label': 'Remove ' + property,
							onClick: () => {
								setStyle( className, property, '' );
								renderProps( node.id );
							},
						},
						'×'
					)
				),
			] );

		const nameInput = h( 'input', { className: 'imj-input', type: 'text', placeholder: 'property', spellcheck: 'false' } );
		const valueInput = h( 'input', { className: 'imj-input', type: 'text', placeholder: 'value', spellcheck: 'false' } );
		const form = h(
			'form',
			{
				className: 'imj-add-attr',
				onSubmit: ( event ) => {
					event.preventDefault();
					const property = nameInput.value.trim().toLowerCase();
					const value = valueInput.value.trim();
					nameInput.classList.toggle( 'is-invalid', ! PROPERTY_PATTERN.test( property ) );
					valueInput.classList.toggle( 'is-invalid', ! value || FORBIDDEN_IN_VALUE.test( value ) );
					if ( PROPERTY_PATTERN.test( property ) && value && ! FORBIDDEN_IN_VALUE.test( value ) ) {
						setStyle( className, property, value );
						renderProps( node.id );
					}
				},
			},
			nameInput,
			valueInput,
			h( 'button', { type: 'submit', className: 'imj-button imj-button--small' }, 'Add' )
		);

		return h(
			'fieldset',
			{ className: 'imj-style-group' },
			h( 'legend', {}, 'Other properties' ),
			rows.length ? h( 'dl', { className: 'imj-attrs' }, rows ) : null,
			form
		);
	}

	function suggestClass( node ) {
		const section = ancestors( node.id ).find( ( parent ) => parent.type === 'section' );
		return ( section ? section.name : 'block' ) + '__' + node.tag;
	}

	function addClassForm( node ) {
		const input = h( 'input', { className: 'imj-input', type: 'text', value: suggestClass( node ), spellcheck: 'false' } );
		return h(
			'form',
			{
				className: 'imj-add-class',
				onSubmit: ( event ) => {
					event.preventDefault();
					const name = input.value.trim();
					if ( ! CLASS_PATTERN.test( name ) ) {
						input.classList.add( 'is-invalid' );
						return;
					}
					setAttr( node, 'class', name );
					renderProps( node.id );
				},
			},
			h( 'p', { className: 'imj-muted' }, 'Styles are written for a class. Give this element one to style it.' ),
			h( 'div', { className: 'imj-add-class__row' }, input, h( 'button', { type: 'submit', className: 'imj-button imj-button--small' }, 'Add class' ) )
		);
	}

	function renderStyleTab( node ) {
		if ( isDynamicValue( node.attrs.class ) ) {
			return [ h( 'p', { className: 'imj-muted' }, 'This element’s class is set by PHP, so it can’t be styled here.' ) ];
		}

		const classes = styleableClasses( node );
		if ( ! classes.length ) {
			return [ addClassForm( node ) ];
		}

		const target = classes.includes( styleTargets.get( node.id ) ) ? styleTargets.get( node.id ) : classes[ 0 ];
		const users = countClassUsers( target );
		const picker =
			classes.length > 1
				? h(
						'select',
						{
							className: 'imj-input',
							onChange: ( event ) => {
								styleTargets.set( node.id, event.target.value );
								renderProps( node.id );
							},
						},
						classes.map( ( name ) => h( 'option', { value: name, selected: name === target }, '.' + name ) )
				  )
				: h( 'code', { className: 'imj-style-target' }, '.' + target );

		// Ask the preview for computed values to show as placeholders.
		sendToPreview( { type: 'imj:inspect', id: node.id, properties: CONTROLLED_PROPERTIES } );

		const breakpoint = activeBreakpoint();
		const deviceNote = breakpoint.media
			? h(
					'p',
					{ className: 'imj-notice' },
					'Editing ',
					h( 'strong', {}, breakpoint.label ),
					' styles ',
					h( 'code', {}, breakpoint.media ),
					'. Wider breakpoints apply unless you change a value here.'
			  )
			: null;

		return [
			field( __( 'State' ), h( 'select', { className: 'imj-input', onChange: ( event ) => { styleState = event.target.value; styleContext = null; renderProps( node.id ); } },
				[ [ '', __( 'Normal' ) ], [ ':hover', __( 'Hover' ) ], [ ':focus-visible', __( 'Keyboard focus' ) ] ].map( ( [ value, label ] ) => h( 'option', { value, selected: value === styleState }, label ) ) ) ),
			field( __( 'Rule context' ), h( 'select', { className: 'imj-input', onChange: ( event ) => { styleContext = event.target.value === '' ? null : styleRules[ Number( event.target.value ) ]; renderProps( node.id ); } },
				h( 'option', { value: '', selected: ! styleContext }, __( 'Class and active breakpoint' ) ),
				styleRules.map( ( rule, i ) => h( 'option', { value: i, selected: styleContext === rule }, rule.selector + ( rule.media ? ' @media ' + rule.media : '' ) ) ) ) ),
			styleContext ? h( 'p', { className: 'imj-notice' }, __( 'Editing this entire rule affects every selector in its list.' ) ) : null,
			deviceNote,
			field(
				'Styles apply to',
				picker,
				h( 'p', { className: 'imj-muted' }, users > 1 ? users + ' elements in this template use this class.' : 'Only this element uses this class.' )
			),
			...STYLE_GROUPS.map( ( group ) =>
				h( 'fieldset', { className: 'imj-style-group' }, h( 'legend', {}, group.title ), group.controls.map( ( control ) => styleControl( target, control ) ) )
			),
			otherDeclarations( node, target ),
		];
	}

	function showComputed( id, values ) {
		if ( id !== selectedId ) {
			return;
		}
		propsEl.querySelectorAll( 'input[data-property]' ).forEach( ( input ) => {
			if ( values[ input.dataset.property ] !== undefined ) {
				input.placeholder = values[ input.dataset.property ];
			}
		} );
	}

	function renderTabs( node ) {
		return h(
			'div',
			{ className: 'imj-tabs', role: 'tablist' },
			[
				[ 'content', 'Content' ],
				[ 'style', 'Style' ],
			].map( ( [ tab, label ] ) =>
				h(
					'button',
					{
						type: 'button',
						role: 'tab',
						className: 'imj-tab' + ( activeTab === tab ? ' is-active' : '' ),
						'aria-selected': activeTab === tab ? 'true' : 'false',
						onClick: () => {
							activeTab = tab;
							renderProps( node.id );
						},
					},
					label
				)
			)
		);
	}

	function renderProps( id ) {
		const node = index.get( id ).node;
		const nodeLevel = level( node );
		const inLoop = ancestors( id ).some( ( parent ) => parent.type === 'block' && php[ parent.php ].kind === 'loop' );
		const content = [ h( 'h3', { className: 'imj-props__title' }, describe( node ).kind ) ];

		if ( nodeLevel ) {
			content.push(
				h( 'p', { className: 'imj-props__level' }, h( 'span', { className: 'imj-badge imj-badge--' + nodeLevel }, LEVELS[ nodeLevel ].label ), ' ', LEVELS[ nodeLevel ].note )
			);
		}
		if ( inLoop ) {
			content.push( h( 'p', { className: 'imj-notice' }, 'Repeated by a loop: a change here applies to every item.' ) );
		}

		if ( node.type === 'section' ) {
			content.push( field( 'Name', node.name ) );
		} else if ( node.type === 'element' && activeTab === 'style' ) {
			content.push( renderTabs( node ), ...renderStyleTab( node ) );
		} else if ( node.type === 'element' ) {
			content.push( renderTabs( node ) );
			const text = soleText( node );
			if ( text ) {
				content.push( field( 'Text', textEditor( text, node ) ) );
			}
			if ( node.tag === 'img' ) {
				content.push( imageField( node ) );
			}

			// Class first, then the rest in source order.
			const attrs = Object.entries( currentAttrs( node ) ).sort( ( a, b ) => ( a[ 0 ] === 'class' ? -1 : b[ 0 ] === 'class' ? 1 : 0 ) );
			content.push(
				field(
					'Attributes',
					attrs.length ? h( 'dl', { className: 'imj-attrs' }, attrs.map( ( [ name, value ] ) => attributeRow( node, name, value ) ) ) : h( 'p', { className: 'imj-muted' }, 'None' ),
					addAttributeForm( node )
				)
			);
			if ( node.text ) {
				content.push( field( 'Content', h( 'pre', { className: 'imj-code' }, node.text.trim() ) ) );
			}
		} else if ( node.type === 'text' ) {
			content.push( field( 'Text', textEditor( node, null ) ), h( 'p', { className: 'imj-muted' }, __( 'Double-click text in the preview to edit this segment without changing its markup.' ) ) );
		} else {
			const info = php[ node.php ];
			if ( info.detail ) {
				content.push( field( node.type === 'block' ? 'Condition' : 'Detail', h( 'code', {}, info.detail ) ) );
			}
			content.push( field( 'PHP', h( 'pre', { className: 'imj-code' }, info.code ) ) );
			if ( node.type === 'php' && info.kind === 'dynamic' ) {
				const source = h( 'select', { className: 'imj-input' }, [ [ 'title', __( 'Post title' ) ], [ 'custom-field', __( 'Custom field' ) ], [ 'acf', __( 'ACF field (requires ACF)' ) ] ].map( ( [ value, label ] ) => h( 'option', { value }, label ) ) );
				const key = h( 'input', { className: 'imj-input', placeholder: __( 'Field key' ), 'aria-label': __( 'Field key' ) } );
				content.push( field( __( 'Dynamic source' ), source, key, h( 'button', { className: 'imj-button', onClick: () => stageOperation( { type: 'source', id: node.id, source: source.value, field: key.value } ).catch( () => {} ) }, __( 'Stage source change' ) ), h( 'p', { className: 'imj-muted' }, __( 'Only isolated title or escaped field calls can change. Other PHP stays read-only.' ) ) ) );
			}
		}

		content.push( structuralControls( node ) );
		propsEl.replaceChildren( ...content.filter( Boolean ) );
	}

	function renderEmptyProps() {
		propsEl.replaceChildren( h( 'p', { className: 'imj-muted' }, 'Select an element in the preview or in the layers panel.' ) );
	}

	/* Preview */

	function sendToPreview( message ) {
		if ( frame.contentWindow ) {
			frame.contentWindow.postMessage( message, data.previewOrigin );
		}
	}

	function firstElements( nodes ) {
		return nodes.flatMap( ( node ) => {
			if ( node.type === 'element' ) {
				return [ node.id ];
			}
			return node.children ? firstElements( node.children ) : [];
		} );
	}

	// Preview elements to outline for a node. Text and PHP values outline the element they sit in.
	function previewIds( id ) {
		const node = index.get( id ).node;
		if ( node.type === 'element' ) {
			return [ node.id ];
		}
		if ( node.children ) {
			return firstElements( node.children );
		}
		const element = ancestors( id ).find( ( parent ) => parent.type === 'element' );
		return element ? [ element.id ] : [];
	}

	function highlight( scroll ) {
		if ( selectedId && index.has( selectedId ) ) {
			sendToPreview( { type: 'imj:highlight', ids: previewIds( selectedId ), scroll } );
		}
	}

	// Re-sends unsaved changes after the preview (re)loads, so it keeps showing them.
	function replayChanges() {
		sendToPreview( { type: 'imj:inline-config', enabled: ! busy && ! lockBlocked } );
		const dragNodes = new Map();
		index.forEach( ( { node } ) => {
			if ( node.type === 'element' ) {
				dragNodes.set( node.id, { element: node.id, id: node.id, draggable: !! node.mutable } );
			}
		} );
		index.forEach( ( { node } ) => {
			if ( node.type === 'section' ) {
				firstElements( node.children ).forEach( ( element ) => dragNodes.set( element, { element, id: node.id, draggable: !! node.mutable } ) );
			}
		} );
		sendToPreview( { type: 'imj:drag-config', nodes: [ ...dragNodes.values() ] } );
		changes.forEach( ( change ) => {
			if ( change.type === 'attr' ) {
				sendToPreview( { type: 'imj:apply', id: change.id, name: change.name, value: change.value } );
			}
		} );
		changes.forEach( ( change ) => { if ( change.type === 'text' ) { sendToPreview( { type: 'imj:text', id: change.id, text: change.value } ); } } );
		sendLiveCss();
	}

	/* Selection */

	function select( id, source ) {
		const entry = index.get( id );
		if ( ! entry ) {
			return;
		}

		if ( selectedId && index.has( selectedId ) ) {
			index.get( selectedId ).row.classList.remove( 'is-selected' );
		}
		selectedId = id;
		entry.row.classList.add( 'is-selected' );
		index.forEach( ( item ) => { item.row.tabIndex = item === entry ? 0 : -1; item.row.setAttribute( 'aria-selected', String( item === entry ) ); } );

		ancestors( id ).forEach( ( parent ) => { index.get( parent.id ).item.classList.remove( 'is-collapsed' ); index.get( parent.id ).row.setAttribute( 'aria-expanded', 'true' ); } );
		if ( source === 'preview' ) {
			entry.row.scrollIntoView( { block: 'nearest' } );
		}

		renderProps( id );
		highlight( source !== 'preview' );
		window.dispatchEvent( new window.CustomEvent( 'imajiner:selection', { detail: getState() } ) );
	}

	/* Saving and history */

	async function api( method, path, body ) {
		const response = await fetch( data.restUrl + path, {
			method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.restNonce },
			body: body ? JSON.stringify( body ) : undefined,
		} );
		const json = await response.json().catch( () => ( {} ) );
		if ( ! response.ok ) {
			throw new Error( json.message || 'Request failed (' + response.status + ').' );
		}
		return json;
	}

	// Takes a fresh structure from the server after the template file changed.
	function loadTemplate( result ) {
		currentStage = null;
		saved = result;
		operations = [];
		hash = result.hash;
		structure = result.structure;
		php = structure.php;
		styles = result.styles;
		styleRules = result.styleRules || [];
		styleContext = null;
		undoStack = []; redoStack = [];
		changes.clear();
		renderTree();
		if ( selectedId && index.has( selectedId ) ) {
			select( selectedId, 'tree' );
		} else {
			selectedId = null;
			renderEmptyProps();
			window.dispatchEvent( new window.CustomEvent( 'imajiner:selection', { detail: getState() } ) );
		}
		frame.src = data.previewUrl;
		updateToolbar();
	}

	async function save() {
		if ( busy || lockBlocked || ( ! changes.size && ! operations.length ) ) {
			return;
		}
		busy = true;
		clearTimeout( stageTimer );
		updateToolbar();
		setStatus( 'Saving…' );
		try {
			loadTemplate( await api( 'POST', '/save', { hash, changes: [ ...operations, ...( changes.size ? [ { type: 'batch', changes: [ ...changes.values() ] } ] : [] ) ] } ) );
			setStatus( 'Saved', 'success' );
		} catch ( error ) {
			setStatus( error.message, 'error' );
		}
		busy = false;
		updateToolbar();
	}

	function discard() {
		if ( busy || ( ! changes.size && ! operations.length ) ) {
			return;
		}
		selectedId = null;
		clearTimeout( stageTimer );
		styleTargets.clear();
		loadTemplate( saved );
		setStatus( 'Changes discarded' );
	}

	function formatDate( iso ) {
		return new Date( iso ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
	}

	async function openHistory() {
		historyPanel.hidden = false;
		historyToggle.setAttribute( 'aria-expanded', 'true' );
		historyPanel.replaceChildren( h( 'p', { className: 'imj-muted' }, 'Loading…' ) );

		let revisions;
		try {
			revisions = await api( 'GET', '/revisions' );
		} catch ( error ) {
			historyPanel.replaceChildren( h( 'p', { className: 'imj-status--error' }, error.message ) );
			return;
		}

		if ( ! revisions.length ) {
			historyPanel.replaceChildren( h( 'p', { className: 'imj-muted' }, 'No earlier versions yet. One is kept every time you save.' ) );
			return;
		}

		historyPanel.replaceChildren(
			h( 'p', { className: 'imj-history__intro' }, 'Earlier versions of this template' ),
			h(
				'ul',
				{ className: 'imj-history__list' },
				revisions.map( ( revision ) => {
					const button = h( 'button', { type: 'button', className: 'imj-button imj-button--small' }, 'Restore' );
					button.addEventListener( 'click', () => restore( revision, button ) );
					return h(
						'li',
						{},
						h( 'div', {}, h( 'strong', {}, formatDate( revision.date ) ), h( 'div', { className: 'imj-muted' }, revision.note + ( revision.author ? ' · ' + revision.author : '' ) ) ),
						button
					);
				} )
			)
		);
	}

	function closeHistory() {
		historyPanel.hidden = true;
		historyToggle.setAttribute( 'aria-expanded', 'false' );
	}

	async function restore( revision, button ) {
		if ( changes.size || operations.length ) {
			setStatus( 'Save or discard your changes before restoring.', 'error' );
			return;
		}
		// Ask for a second click instead of a browser dialog.
		if ( ! button.classList.contains( 'is-confirming' ) ) {
			button.disabled = true;
			try {
				const diff = await api( 'GET', '/revisions/' + revision.id + '/diff' );
				if ( diff.hash !== hash ) { throw new Error( __( 'Template changed. Reload before restoring.' ) ); }
				const panel = h( 'div', { className: 'imj-diff' } );
				const phpDiff = h( 'div' ); phpDiff.innerHTML = diff.php || '';
				const cssDiff = h( 'div' ); cssDiff.innerHTML = diff.css || '';
				panel.append( h( 'h4', {}, __( 'PHP' ) ), phpDiff, h( 'h4', {}, __( 'CSS' ) ), cssDiff );
				if ( ! diff.php && ! diff.css ) { panel.append( __( 'No differences.' ) ); }
				button.parentElement.append( panel );
				button.classList.add( 'is-confirming' ); button.textContent = __( 'Confirm restore' );
			} catch ( error ) { setStatus( error.message, 'error' ); }
			button.disabled = false;
			return;
		}

		busy = true;
		updateToolbar();
		setStatus( 'Restoring…' );
		try {
			loadTemplate( await api( 'POST', '/revisions/' + revision.id + '/restore', { hash } ) );
			setStatus( 'Restored version from ' + formatDate( revision.date ), 'success' );
			closeHistory();
		} catch ( error ) {
			setStatus( error.message, 'error' );
		}
		busy = false;
		updateToolbar();
	}

	/* Events */

	function getState() {
		const current = JSON.parse( JSON.stringify( structure ) );
		const currentStyles = JSON.parse( JSON.stringify( styles ) );
		const currentRules = JSON.parse( JSON.stringify( styleRules ) );
		function overlay( nodes ) {
			nodes.forEach( ( node ) => {
				if ( node.type === 'text' ) { node.text = currentText( node ); }
				if ( node.type === 'element' ) { node.attrs = currentAttrs( node ); }
				if ( node.children ) { overlay( node.children ); }
			} );
		}
		overlay( current.tree );
		changes.forEach( ( change ) => {
			if ( change.type !== 'style' ) { return; }
			if ( change.selector ) {
				const rule = currentRules.find( ( item ) => item.selector === change.selector && item.media === change.media );
				if ( rule ) { if ( change.value === null ) { delete rule.values[ change.property ]; } else { rule.values[ change.property ] = change.value; } }
				return;
			}
			const classes = currentStyles[ change.device ] || ( currentStyles[ change.device ] = {} );
			const values = classes[ change.class + change.state ] || ( classes[ change.class + change.state ] = {} );
			if ( change.value === null ) { delete values[ change.property ]; } else { values[ change.property ] = change.value; }
		} );
		return { template: data.template, hash, structure: current, styles: currentStyles, styleRules: currentRules, selectedId, dirty: !! ( changes.size || operations.length ), stage: currentStage };
	}

	async function renewLock() {
		try {
			await api( 'POST', '/lock', { action: 'acquire' } );
			const previouslyBlocked = lockBlocked; lockBlocked = false;
			if ( previouslyBlocked ) { setStatus( __( 'Editing lock acquired' ) ); }
		} catch ( error ) { lockBlocked = true; setStatus( error.message, 'error' ); }
		updateToolbar(); sendToPreview( { type: 'imj:inline-config', enabled: ! busy && ! lockBlocked } );
	}

	function breakpointSettings() {
		if ( changes.size || operations.length ) { setStatus( __( 'Save or discard before changing breakpoints.' ), 'error' ); return; }
		const dialog = h( 'dialog', { className: 'imj-breakpoint-dialog' } );
		const points = Object.fromEntries( data.breakpoints.map( ( point ) => [ point.name, { label: point.label, media: point.media, width: point.width } ] ) );
		const input = h( 'textarea', { className: 'imj-input', rows: 18, value: JSON.stringify( points, null, 2 ), 'aria-label': __( 'Breakpoint settings JSON' ) } );
		dialog.append( h( 'h2', {}, __( 'Site breakpoints' ) ), h( 'p', {}, __( 'Edit labels, preview widths and media conditions. Keep the base breakpoint first, then wider to narrower. Filters still apply.' ) ), input,
			h( 'button', { className: 'imj-button', onClick: async () => {
				try {
					const response = await fetch( data.restUrl.replace( /\/templates\/.*$/, '/editor/breakpoints' ), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.restNonce }, body: JSON.stringify( { breakpoints: JSON.parse( input.value ) } ) } );
					const result = await response.json(); if ( ! response.ok ) { throw new Error( result.message ); }
					window.location.reload();
				} catch ( error ) { setStatus( error.message, 'error' ); }
			} }, __( 'Save breakpoints' ) ), h( 'button', { className: 'imj-button', onClick: () => { dialog.close(); dialog.remove(); } }, __( 'Cancel' ) ) );
		document.body.append( dialog ); dialog.showModal();
	}

	window.imajinerEditor = {
		getState,
		reload: async () => {
			if ( busy ) { throw new Error( __( 'Wait for the current edit to finish.' ) ); }
			if ( changes.size || operations.length ) { return refreshStage(); }
			loadTemplate( await api( 'GET', '/state' ) );
		},
		applyProposal: ( proposal ) => {
			if ( busy || lockBlocked ) { return Promise.reject( new Error( __( 'Wait for the editor lock and current edit.' ) ) ); }
			if ( ! selectedId || ! index.has( selectedId ) || ! index.get( selectedId ).node.mutable ) { return Promise.reject( new Error( __( 'Select a static element or section first.' ) ) ); }
			return stageOperation( { type: 'proposal', id: selectedId, php: proposal.php, css: proposal.css || '' } );
		},
	};

	window.addEventListener( 'message', ( event ) => {
		if ( event.origin !== data.previewOrigin || event.source !== frame.contentWindow || ! event.data ) {
			return;
		}
		if ( event.data.type === 'imj:select' && ! busy ) {
			select( event.data.id, 'preview' );
		} else if ( event.data.type === 'imj:inline' && ! busy && ! lockBlocked ) {
			const entry = index.get( event.data.id );
			if ( entry && entry.node.type === 'text' && typeof event.data.text === 'string' && event.data.text.trim() ) { setText( entry.node, null, event.data.text ); if ( selectedId ) { renderProps( selectedId ); } }
		} else if ( event.data.type === 'imj:move' ) {
			moveNode( event.data.id, event.data.target, event.data.position );
		} else if ( event.data.type === 'imj:ready' ) {
			replayChanges();
			highlight( false );
			if ( selectedId && activeTab === 'style' ) {
				sendToPreview( { type: 'imj:inspect', id: selectedId, properties: CONTROLLED_PROPERTIES } );
			}
		} else if ( event.data.type === 'imj:computed' ) {
			showComputed( event.data.id, event.data.values );
		}
	} );

	saveButton.addEventListener( 'click', save );
	document.getElementById( 'imj-undo' ).addEventListener( 'click', () => travel( false ) );
	document.getElementById( 'imj-redo' ).addEventListener( 'click', () => travel( true ) );
	document.getElementById( 'imj-breakpoints' ).addEventListener( 'click', breakpointSettings );
	document.getElementById( 'imj-library' ).addEventListener( 'change', ( event ) => {
		if ( event.target.value ) { stageOperation( { type: 'library', name: event.target.value } ).catch( () => {} ); event.target.value = ''; }
	} );
	discardButton.addEventListener( 'click', discard );
	document.getElementById( 'imj-add-section' ).addEventListener( 'click', () => stageStructure( { type: 'insert', target: 'root', position: 'inside', starter: 'section' } ) );

	historyToggle.addEventListener( 'click', () => ( historyPanel.hidden ? openHistory() : closeHistory() ) );
	document.addEventListener( 'click', ( event ) => {
		if ( ! historyPanel.hidden && ! event.target.closest( '.imj-history' ) ) {
			closeHistory();
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( ( event.ctrlKey || event.metaKey ) && ! event.target.matches( 'input, textarea, [contenteditable]' ) && [ 'z', 'y' ].includes( event.key.toLowerCase() ) ) {
			event.preventDefault(); travel( event.key.toLowerCase() === 'y' || event.shiftKey ); return;
		}
		if ( ( event.ctrlKey || event.metaKey ) && event.key.toLowerCase() === 's' ) {
			event.preventDefault();
			save();
		} else if ( event.key === 'Escape' && ! historyPanel.hidden ) {
			closeHistory();
		}
	} );

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( changes.size || operations.length ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
	window.addEventListener( 'pagehide', () => {
		fetch( data.restUrl + '/lock', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.restNonce }, body: JSON.stringify( { action: 'release' } ) } ).catch( () => {} );
	} );

	/* Init */

	buildDatalists();
	renderDevices();
	fitFrame();
	new window.ResizeObserver( fitFrame ).observe( frameWrap.parentElement );
	renderTree();
	renderEmptyProps();
	updateToolbar();
	renewLock();
	window.setInterval( renewLock, 30000 );
} )( window.imajinerEditor );
