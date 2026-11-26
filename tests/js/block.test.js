// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

// Tests of blocks/wp-inaturalist/index.js with a fake wp global. The real block editor was never tried.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( path.join( __dirname, '..', '..', 'blocks', 'wp-inaturalist', 'index.js' ), 'utf8' );

const STRINGS = { error: 'Preview error.', period: 'Period', number: 'Number', unit: 'Unit', day: 'Days', week: 'Weeks', month: 'Months', help: 'Help', empty_html: 'Nothing yet.' };

function load( { config = { strings: STRINGS }, omit = null, currentId = 42 } = {} ) {
	const registered = [];
	const names = { InspectorControls: 'IC', PanelBody: 'PB', TextControl: 'TC', SelectControl: 'SC' };
	const wp = {
		blocks: { registerBlockType: ( name, settings ) => registered.push( { name, settings } ) },
		element: { createElement: ( type, props, ...children ) => ( { type, props, children } ) },
		blockEditor: { InspectorControls: names.InspectorControls, useBlockProps: () => ( { className: 'bp' } ) },
		components: { PanelBody: names.PanelBody, TextControl: names.TextControl, SelectControl: names.SelectControl },
		serverSideRender: 'SSR',
		data: { select: () => ( { getCurrentPostId: () => currentId } ) },
	};
	if ( omit ) {
		delete wp[ omit ];
	}
	vm.runInNewContext( source, { window: { wp, wpinatBlock: config } } );
	return registered;
}

/** Walks the element tree and returns the elements of a type. */
function find( node, type, out = [] ) {
	if ( node && typeof node === 'object' ) {
		if ( node.type === type ) {
			out.push( node );
		}
		( node.children || [] ).forEach( ( child ) => find( child, type, out ) );
	}
	return out;
}

function render( attributes = { periodNumber: 1, periodUnit: 'day' }, options = {} ) {
	const calls = [];
	const [ block ] = load( options );
	const tree = block.settings.edit( { attributes, setAttributes: ( a ) => calls.push( a ) } );
	return { block, tree, calls };
}

/** Arrays created inside the vm context have another prototype: compare their JSON. */
const same = ( actual, expected ) => assert.equal( JSON.stringify( actual ), JSON.stringify( expected ) );

test( 'it registers the dynamic block under the name PHP looks for', () => {
	const [ block ] = load();
	assert.equal( block.name, 'wpinat/wp-inaturalist' );
	assert.equal( block.settings.save(), null, 'rendered by PHP' );
} );

test( 'the preview is server-side rendered for the post being edited', () => {
	const attributes = { periodNumber: 3, periodUnit: 'week' };
	const { tree } = render( attributes );
	const [ ssr ] = find( tree, 'SSR' );
	assert.equal( ssr.props.block, 'wpinat/wp-inaturalist' );
	assert.equal( ssr.props.attributes.periodNumber, 3 );
	assert.equal( ssr.props.urlQueryArgs.post_id, 42 );
	assert.equal( ssr.props.EmptyResponsePlaceholder().children[ 0 ], 'Nothing yet.' );
} );

test( 'in the Site Editor the id is the text of a template: no post_id is sent (the route only accepts integers)', () => {
	for ( const id of [ 'twentytwentyfive//single', '42', 0, -3, 1.5, NaN, null ] ) {
		const { tree } = render( undefined, { currentId: id } );
		const [ ssr ] = find( tree, 'SSR' );
		same( ssr.props.urlQueryArgs, {} );
	}
	const [ ssr ] = find( render( undefined, { currentId: 7 } ).tree, 'SSR' );
	same( ssr.props.urlQueryArgs, { post_id: 7 } );
} );

test( 'an error of the route is shown as readable text, never as [object Object]', () => {
	const { tree } = render();
	const [ ssr ] = find( tree, 'SSR' );
	const shown = ( props ) => ssr.props.ErrorResponsePlaceholder( props ).children[ 0 ];
	assert.equal( shown( { response: { error: true, errorMsg: 'Invalid parameter(s): post_id' } } ), 'Preview error. (Invalid parameter(s): post_id)' );
	assert.equal( shown( { response: { error: true } } ), 'Preview error.' );
	assert.equal( shown( { response: { errorMsg: { nested: 'object' } } } ), 'Preview error.' );
	assert.equal( shown( undefined ), 'Preview error.' );
	assert.doesNotMatch( shown( { response: { errorMsg: { a: 1 } } } ), /object/i );
} );

test( 'the number control clamps what is typed to 1..100', () => {
	const { tree, calls } = render();
	const [ number ] = find( tree, 'TC' );
	assert.equal( number.props.label, 'Number' );
	for ( const [ typed, expected ] of [ [ '5', 5 ], [ '0', 1 ], [ '-3', 1 ], [ '5000', 100 ], [ 'abc', 1 ], [ '', 1 ] ] ) {
		number.props.onChange( typed );
		assert.equal( calls.pop().periodNumber, expected, 'typed ' + typed );
	}
} );

test( 'the unit control offers day, week and month and refuses anything else', () => {
	const { tree, calls } = render();
	const [ unit ] = find( tree, 'SC' );
	same( unit.props.options.map( ( o ) => o.value ), [ 'day', 'week', 'month' ] );
	same( unit.props.options.map( ( o ) => o.label ), [ 'Days', 'Weeks', 'Months' ] );
	unit.props.onChange( 'month' );
	assert.equal( calls.pop().periodUnit, 'month' );
	unit.props.onChange( 'year' );
	assert.equal( calls.pop().periodUnit, 'day' );
} );

test( 'the controls are in the sidebar panel', () => {
	const { tree } = render();
	const [ controls ] = find( tree, 'IC' );
	assert.equal( find( controls, 'PB' ).length, 1 );
	assert.equal( find( controls, 'PB' )[ 0 ].props.title, 'Period' );
} );

test( 'without the data or an editor package it registers nothing and does not throw', () => {
	assert.equal( load( { config: null } ).length, 0 );
	assert.equal( load( { omit: 'serverSideRender' } ).length, 0 );
	assert.equal( load( { omit: 'blockEditor' } ).length, 0 );
} );
