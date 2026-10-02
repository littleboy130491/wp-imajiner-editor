/**
 * Block editor sidebar panel with an "Open Imajiner Editor" button.
 */
( function ( wp, config ) {
	'use strict';

	const el = wp.element.createElement;
	const { __ } = wp.i18n;
	const { useSelect } = wp.data;
	const { Button } = wp.components;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || wp.editPost.PluginDocumentSettingPanel;

	function isImajinerTemplate( template ) {
		return typeof template === 'string' && template.indexOf( config.templateDir ) === 0;
	}

	function ImajinerPanel() {
		const { postId, savedTemplate, editedTemplate } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return {
				postId: editor.getCurrentPostId(),
				savedTemplate: editor.getCurrentPostAttribute( 'template' ),
				editedTemplate: editor.getEditedPostAttribute( 'template' ),
			};
		}, [] );

		let content;
		if ( ! isImajinerTemplate( editedTemplate ) ) {
			content = el( 'p', null, __( 'Choose an Imajiner template under Template to edit this page visually.', 'imajiner-editor' ) );
		} else if ( editedTemplate !== savedTemplate ) {
			content = el( 'p', null, __( 'Save the page to open it in the Imajiner Editor.', 'imajiner-editor' ) );
		} else {
			content = el(
				Button,
				{ variant: 'primary', href: config.editorUrl + postId, __next40pxDefaultSize: true },
				__( 'Open Imajiner Editor', 'imajiner-editor' )
			);
		}

		return el( Panel, { name: 'imajiner-editor', title: __( 'Imajiner Editor', 'imajiner-editor' ) }, content );
	}

	wp.plugins.registerPlugin( 'imajiner-editor', { render: ImajinerPanel } );

	// Plugin panels start collapsed. Open ours the first time a user sees it;
	// after that WordPress remembers whether they keep it open.
	const panelName = 'imajiner-editor/imajiner-editor';
	const seenKey = 'imajinerPanelSeen';
	try {
		if ( ! window.localStorage.getItem( seenKey ) ) {
			window.localStorage.setItem( seenKey, '1' );
			if ( ! wp.data.select( 'core/editor' ).isEditorPanelOpened( panelName ) ) {
				wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( panelName );
			}
		}
	} catch ( error ) {
		// Storage unavailable: leave the panel collapsed.
	}
} )( window.wp, window.imajinerBlockEditor );
