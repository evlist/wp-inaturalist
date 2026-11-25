// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

// Tests of js/editor.js with a fake wp global (as wp-whobird does for its block). The real block editor was never tried.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const source = fs.readFileSync( path.join( __dirname, '..', '..', 'js', 'editor.js' ), 'utf8' );

const STRINGS = {
	title: 'iNaturalist',
	button: 'Refresh observations',
	saving: 'Saving…',
	started: 'Started.',
	queued: 'Queued.',
	no_block: 'No block.',
	no_login: 'No login.',
	bad_range: 'Bad date.',
	error: 'Error.',
};

/** Builds a fake wp global and runs the script; returns what the test needs to drive it. */
function load( { dirty = false, answer = { status: 'started' }, fail = false, panelIn = 'editor', config = { strings: STRINGS } } = {} ) {
	const log = { notices: [], fetches: [], saves: 0, plugin: null, state: [ false ] };
	const Panel = function Panel() {};
	const wp = {
		element: {
			createElement: ( type, props, ...children ) => ( { type, props, children } ),
			useState: ( initial ) => [ log.state[ 0 ], ( v ) => { log.state[ 0 ] = v; } ],
		},
		plugins: { registerPlugin: ( name, settings ) => { log.plugin = { name, settings }; } },
		data: {
			select: () => ( { getCurrentPostId: () => 42, isEditedPostDirty: () => dirty } ),
			dispatch: ( store ) => ( store === 'core/notices'
				? { createNotice: ( type, text, opts ) => log.notices.push( { type, text, opts } ) }
				: { savePost: () => { log.saves++; return Promise.resolve(); } } ),
		},
		apiFetch: ( o ) => { log.fetches.push( o ); return fail ? Promise.reject( new Error( 'x' ) ) : Promise.resolve( answer ); },
	};
	if ( panelIn === 'editor' ) {
		wp.editor = { PluginDocumentSettingPanel: Panel };
	} else if ( panelIn === 'editPost' ) {
		wp.editPost = { PluginDocumentSettingPanel: Panel };
	}
	const win = { wp, wpinatEditor: config };
	vm.runInNewContext( source, { window: win, Promise } );
	return { log, Panel };
}

/** Objects created inside the vm context have another prototype: compare their JSON. */
const same = ( actual, expected ) => assert.equal( JSON.stringify( actual ), JSON.stringify( expected ) );

const tick = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

/** Renders the plugin and returns its button props. */
function button( log ) {
	const tree = log.plugin.settings.render();
	return { tree, props: tree.children[ 0 ].props, text: tree.children[ 0 ].children[ 0 ] };
}

test( 'it registers a panel with the translated title and button', () => {
	const { log, Panel } = load();
	assert.equal( log.plugin.name, 'wpinat' );
	const { tree, text } = button( log );
	assert.equal( tree.type, Panel );
	assert.equal( tree.props.title, 'iNaturalist' );
	assert.equal( text, 'Refresh observations' );
} );

test( 'the panel also works with the older wp.editPost location', () => {
	const { log, Panel } = load( { panelIn: 'editPost' } );
	assert.equal( button( log ).tree.type, Panel );
} );

test( 'a click on a saved post sends only the post id, with no dates', async () => {
	const { log } = load();
	button( log ).props.onClick();
	await tick();
	same( log.fetches, [ { path: '/wpinat/v1/refresh', method: 'POST', data: { post_id: 42 } } ] );
	assert.equal( log.saves, 0 );
	same( log.notices.map( ( n ) => [ n.type, n.text ] ), [ [ 'success', 'Started.' ] ] );
} );

test( 'a post with unsaved changes is saved first, then refreshed', async () => {
	const { log } = load( { dirty: true } );
	button( log ).props.onClick();
	await tick();
	assert.equal( log.saves, 1 );
	assert.equal( log.fetches.length, 1 );
	same( log.notices.map( ( n ) => n.text ), [ 'Saving…', 'Started.' ] );
} );

for ( const [ status, type, text ] of [ [ 'queued', 'success', 'Queued.' ], [ 'no_block', 'warning', 'No block.' ], [ 'no_login', 'warning', 'No login.' ], [ 'bad_range', 'warning', 'Bad date.' ], [ 'whatever', 'warning', 'Error.' ] ] ) {
	test( 'status ' + status + ' gives a ' + type + ' notice', async () => {
		const { log } = load( { answer: { status } } );
		button( log ).props.onClick();
		await tick();
		same( log.notices.map( ( n ) => [ n.type, n.text ] ), [ [ type, text ] ] );
	} );
}

test( 'a failing request gives an error notice', async () => {
	const { log } = load( { fail: true } );
	button( log ).props.onClick();
	await tick();
	same( log.notices.map( ( n ) => [ n.type, n.text ] ), [ [ 'error', 'Error.' ] ] );
} );

test( 'the button is disabled while a request is running', async () => {
	const { log } = load();
	button( log ).props.onClick();
	assert.equal( log.state[ 0 ], true );
	await tick();
	assert.equal( log.state[ 0 ], false );
} );

test( 'without the data or the panel component the script does nothing and does not throw', () => {
	assert.equal( load( { config: null } ).log.plugin, null );
	assert.equal( load( { panelIn: 'none' } ).log.plugin, null );
} );
