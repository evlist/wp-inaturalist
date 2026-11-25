/**
 * Block editor: a sidebar panel with a button that refreshes the observations of the post.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
( function ( win ) {
	'use strict';

	var wp = win.wp;
	var config = win.wpinatEditor;
	if ( ! wp || ! config || ! wp.plugins || ! wp.element || ! wp.data || ! wp.apiFetch ) {
		return;
	}
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}
	var strings = config.strings;
	var h = wp.element.createElement;

	function notify( type, text ) {
		wp.data.dispatch( 'core/notices' ).createNotice( type, text, { type: 'snackbar', isDismissible: true } );
	}

	/** Saves the post if it has unsaved changes (the server only sees the saved content), then asks for the refresh. */
	function refresh() {
		var editor = wp.data.select( 'core/editor' );
		var postId = editor.getCurrentPostId();
		var ready = Promise.resolve();
		if ( editor.isEditedPostDirty() ) {
			notify( 'info', strings.saving );
			ready = Promise.resolve( wp.data.dispatch( 'core/editor' ).savePost() );
		}
		return ready
			.then( function () {
				return wp.apiFetch( { path: '/wpinat/v1/refresh', method: 'POST', data: { post_id: postId } } );
			} )
			.then( function ( answer ) {
				var status = answer && answer.status;
				var ok = 'started' === status || 'queued' === status;
				notify( ok ? 'success' : 'warning', strings[ status ] || strings.error );
			} )
			.catch( function () {
				notify( 'error', strings.error );
			} );
	}

	function InaturalistPanel() {
		var busy = wp.element.useState( false );
		return h(
			Panel,
			{ name: 'wpinat-panel', title: strings.title },
			h(
				'button',
				{
					type: 'button',
					className: 'components-button is-secondary',
					disabled: busy[ 0 ],
					onClick: function () {
						busy[ 1 ]( true );
						refresh().then( function () {
							busy[ 1 ]( false );
						} );
					},
				},
				strings.button
			)
		);
	}

	wp.plugins.registerPlugin( 'wpinat', { render: InaturalistPanel } );
} )( window );
