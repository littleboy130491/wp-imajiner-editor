/* Actual editor runtime with a small DOM and REST contract double; not browser coverage. */
'use strict';
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const path = require( 'node:path' );
const { test } = require( 'node:test' );
const { setImmediate: tick } = require( 'node:timers/promises' );

class Element {
	constructor( tag = 'div' ) {
		this.tag = tag; this.children = []; this.attributes = {}; this.dataset = {}; this.listeners = {};
		this.style = { setProperty() {} }; this.className = ''; this.value = ''; this.clientWidth = 1400; this.clientHeight = 900;
		this.classList = {
			contains: ( name ) => this.className.split( ' ' ).includes( name ),
			add: ( name ) => { if ( ! this.classList.contains( name ) ) this.className += ' ' + name; },
			remove: ( name ) => { this.className = this.className.split( ' ' ).filter( ( item ) => item !== name ).join( ' ' ); },
			toggle: ( name, on ) => { if ( on ) this.classList.add( name ); else this.classList.remove( name ); },
		};
	}
	append( ...nodes ) { for ( const node of nodes ) { if ( node instanceof Element ) node.parentElement = this; this.children.push( node ); } }
	replaceChildren( ...nodes ) { this.children = []; this.append( ...nodes ); }
	setAttribute( key, value ) { this.attributes[ key ] = String( value ); if ( key.startsWith( 'data-' ) ) this.dataset[ key.slice( 5 ).replace( /-([a-z])/g, ( _, letter ) => letter.toUpperCase() ) ] = String( value ); }
	getAttribute( key ) { return this.attributes[ key ]; }
	removeAttribute( key ) { delete this.attributes[ key ]; }
	addEventListener( name, callback ) { ( this.listeners[ name ] ||= [] ).push( callback ); }
	async fire( name ) { for ( const callback of this.listeners[ name ] || [] ) await callback( { target: this, currentTarget: this, preventDefault() {}, stopPropagation() {} } ); }
	matches( selector ) { return selector.startsWith( '.' ) ? this.classList.contains( selector.slice( 1 ) ) : this.tag === selector; }
	querySelectorAll( selector ) { const nodes = this.children.filter( ( child ) => child instanceof Element ); return nodes.flatMap( ( child ) => [ ...( child.matches( selector ) ? [ child ] : [] ), ...child.querySelectorAll( selector ) ] ); }
	querySelector( selector ) { return this.querySelectorAll( selector )[0] || null; }
	scrollIntoView() {}
	focus() {}
}

function structure( text ) {
	return { php: [], warnings: [], tree: [ { type: 'element', tag: 'p', id: 'e0', level: 'static', mutable: true, attrs: { class: 'runtime-heading' }, children: [ { type: 'text', id: 't0', text, level: 'static', mutable: true } ] } ] };
}

async function fixture( denyLock = false ) {
	const nodes = new Map();
	const body = new Element( 'body' );
	const get = ( id ) => { if ( ! nodes.has( id ) ) { const node = new Element(); nodes.set( id, node ); body.append( node ); } return nodes.get( id ); };
	const frame = get( 'imj-preview' ); frame.contentWindow = { postMessage() {} };
	const calls = [], events = [], listeners = {};
	const initial = { template: { key: 'runtime-disposable' }, structure: structure( 'Original' ), hash: 'saved-hash', styles: {}, tokens: {}, breakpoints: [ { name: 'desktop', label: 'Desktop', media: '', width: 1280 } ], previewUrl: 'http://localhost/preview', previewOrigin: 'http://localhost', restUrl: 'http://localhost/templates/runtime-disposable', restNonce: 'contract-only' };
	const win = {
		imajinerEditor: initial,
		wp: { i18n: { __: ( value ) => value, sprintf: ( format, value ) => format.replace( /%[sd]/, String( value ) ), _n: ( one, many, count ) => count === 1 ? one : many } },
		getComputedStyle: () => ( { paddingLeft: '0', paddingRight: '0', paddingTop: '0', paddingBottom: '0' } ),
		ResizeObserver: class { observe() {} },
		CustomEvent: class { constructor( type, options ) { this.type = type; this.detail = options.detail; } },
		addEventListener: ( name, callback ) => { ( listeners[ name ] ||= [] ).push( callback ); },
		dispatchEvent: ( event ) => { events.push( event ); }, setInterval() {},
	};
	const fetch = async ( url, options ) => {
		const request = options.body ? JSON.parse( options.body ) : null;
		calls.push( { url, request } );
		if ( url.endsWith( '/lock' ) ) return { ok: ! denyLock, status: denyLock ? 423 : 200, json: async () => denyLock ? { message: 'Contract lock refused' } : {} };
		if ( url.endsWith( '/state' ) ) return { ok: true, json: async () => ( { hash: 'fresh-hash', structure: structure( 'Saved later' ), styles: {} } ) };
		assert.ok( url.endsWith( '/stage' ), 'Only staging/lock/state may be called before Save' );
		const proposal = request.changes.find( ( change ) => change.type === 'proposal' );
		return { ok: true, json: async () => ( { hash: 'saved-hash', stage: 'stage-' + calls.length, structure: structure( proposal ? 'Staged proposal' : 'Original' ), styles: {}, styleRules: [] } ) };
	};
	vm.runInNewContext( fs.readFileSync( path.join( __dirname, '../assets/js/editor.js' ), 'utf8' ), { window: win, document: { body, getElementById: get, createElement: ( tag ) => new Element( tag ), addEventListener() {}, querySelectorAll: ( selector ) => body.querySelectorAll( selector ) }, Node: Element, URL, fetch, console, setTimeout, clearTimeout } );
	await tick();
	const select = () => listeners.message[0]( { origin: 'http://localhost', source: frame.contentWindow, data: { type: 'imj:select', id: 'e0' } } );
	return { api: win.imajinerEditor, calls, events, get, select };
}

test( 'staged editor proposal stays unsaved, dispatches state and supports undo/redo', async () => {
	const f = await fixture();
	f.select();
	const before = f.api.getState();
	assert.equal( before.selectedId, 'e0' );
	await f.api.applyProposal( { php: '<p>Staged proposal</p>', css: '', id: 'e0', hash: before.hash, stage: before.stage } );
	assert.equal( f.api.getState().hash, 'saved-hash' );
	assert.equal( f.api.getState().dirty, true );
	assert.equal( f.api.getState().structure.tree[0].children[0].text, 'Staged proposal' );
	assert.equal( f.calls.at( -1 ).request.changes[0].type, 'proposal' );
	assert.equal( f.calls.some( ( call ) => call.url.endsWith( '/save' ) ), false );
	assert.ok( f.events.some( ( event ) => event.type === 'imajiner:selection' && event.detail.dirty ) );
	await f.get( 'imj-undo' ).fire( 'click' );
	assert.equal( f.api.getState().dirty, false );
	assert.equal( f.api.getState().structure.tree[0].children[0].text, 'Original' );
	await f.get( 'imj-redo' ).fire( 'click' );
	assert.equal( f.api.getState().dirty, true );
	assert.equal( f.api.getState().structure.tree[0].children[0].text, 'Staged proposal' );
} );

test( 'editor API rejects stale proposals and lock denial; clean reload fetches saved state', async () => {
	const f = await fixture();
	f.select();
	const count = f.calls.length;
	await assert.rejects( f.api.applyProposal( { id: 'e99', php: '<p>Wrong</p>' } ), /selection changed/ );
	await assert.rejects( f.api.applyProposal( { id: 'e0', hash: 'stale', php: '<p>Wrong</p>' } ), /selection changed/ );
	await assert.rejects( f.api.applyProposal( { id: 'e0', stage: 'stale', php: '<p>Wrong</p>' } ), /selection changed/ );
	assert.equal( f.calls.length, count );
	await f.api.reload();
	assert.equal( f.api.getState().hash, 'fresh-hash' );
	assert.equal( f.api.getState().dirty, false );
	const locked = await fixture( true ); locked.select();
	await assert.rejects( locked.api.applyProposal( { php: '<p>Wrong</p>' } ), /editor lock/ );
	assert.equal( locked.calls.length, 1 );
} );
