/**
 * Block editor sidebar panel with an "Open Imajiner Editor" button.
 *
 * The server says which Imajiner template renders this post as saved: one
 * chosen under Template, or one assigned to the post type's single location.
 */
( function ( wp, config ) {
	'use strict';

	const el = wp.element.createElement;
	const { __, sprintf } = wp.i18n;
	const { useSelect } = wp.data;
	const { Button, ExternalLink } = wp.components;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || wp.editPost.PluginDocumentSettingPanel;

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
		if ( editedTemplate !== savedTemplate ) {
			content = el( 'p', null, __( 'Save the page to open its template in the Imajiner Editor.', 'imajiner-editor' ) );
		} else if ( config.templateName ) {
			content = [
				el(
					'p',
					{ key: 'name' },
					config.assigned
						? sprintf( __( 'Uses “%s”, assigned to this post type.', 'imajiner-editor' ), config.templateName )
						: sprintf( __( 'Uses “%s”.', 'imajiner-editor' ), config.templateName )
				),
				el(
					Button,
					{ key: 'open', variant: 'primary', href: config.editorUrl + postId, __next40pxDefaultSize: true },
					__( 'Open Imajiner Editor', 'imajiner-editor' )
				),
			];
		} else {
			content = [
				el( 'p', { key: 'help' }, __( 'Choose an Imajiner template under Template, or assign a single template to this post type.', 'imajiner-editor' ) ),
				el( ExternalLink, { key: 'link', href: config.builderUrl }, __( 'Imajiner Templates', 'imajiner-editor' ) ),
			];
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
