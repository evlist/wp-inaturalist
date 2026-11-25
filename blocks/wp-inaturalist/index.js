/**
 * Editor script of the block wpinat/wp-inaturalist (plain JavaScript, no build step).
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
( function ( win ) {
	'use strict';

	var wp = win.wp;
	var config = win.wpinatBlock;
	if ( ! wp || ! config || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.serverSideRender ) {
		return;
	}
	var strings = config.strings;
	var h = wp.element.createElement;
	var UNITS = [ 'day', 'week', 'month' ];

	function Edit( props ) {
		var attributes = props.attributes;
		var postId = wp.data && wp.data.select( 'core/editor' ) ? wp.data.select( 'core/editor' ).getCurrentPostId() : undefined;
		var blockProps = wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};

		return h(
			'div',
			blockProps,
			h(
				wp.blockEditor.InspectorControls,
				null,
				h(
					wp.components.PanelBody,
					{ title: strings.period, initialOpen: true },
					h( wp.components.TextControl, {
						label: strings.number,
						type: 'number',
						min: 1,
						max: 100,
						value: attributes.periodNumber,
						onChange: function ( value ) {
							var number = parseInt( value, 10 );
							props.setAttributes( { periodNumber: isNaN( number ) ? 1 : Math.max( 1, Math.min( 100, number ) ) } );
						},
					} ),
					h( wp.components.SelectControl, {
						label: strings.unit,
						value: attributes.periodUnit,
						options: UNITS.map( function ( unit ) {
							return { value: unit, label: strings[ unit ] };
						} ),
						help: strings.help,
						onChange: function ( value ) {
							props.setAttributes( { periodUnit: UNITS.indexOf( value ) >= 0 ? value : 'day' } );
						},
					} )
				)
			),
			h( wp.serverSideRender, {
				block: 'wpinat/wp-inaturalist',
				attributes: attributes,
				urlQueryArgs: { post_id: postId },
				EmptyResponsePlaceholder: function () {
					return h( 'p', { className: 'wpinat-hint' }, strings.empty_html );
				},
			} )
		);
	}

	wp.blocks.registerBlockType( 'wpinat/wp-inaturalist', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
} )( window );
