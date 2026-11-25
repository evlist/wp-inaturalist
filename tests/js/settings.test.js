// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later

// Tests of js/settings.js with jsdom and a fake wp.apiFetch. The real settings page was never tried.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const source = fs.readFileSync( path.join( __dirname, '..', '..', 'js', 'settings.js' ), 'utf8' );

const STRINGS = {
	checking: 'Checking…',
	found: 'Account found: %s',
	not_found: 'No account.',
	invalid: 'Invalid.',
	unavailable: 'Unavailable.',
	error: 'Error.',
};

const PAGE = '<input id="wpinat_login" value="jean"><button id="wpinat-verify" hidden>Check</button><span id="wpinat-verify-result"></span>';

async function load( { html = PAGE, apiFetch, config = { strings: STRINGS } } = {} ) {
	const dom = new JSDOM( '<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only' } );
	const win = dom.window;
	win.wpinatSettings = config;
	if ( apiFetch ) {
		win.wp = { apiFetch };
	}
	win.eval( source );
	// The script waits for DOMContentLoaded, as it does in a real page where it is loaded in the footer.
	await new Promise( ( resolve ) => win.addEventListener( 'load', resolve ) );
	return win;
}

/** Objects created inside the jsdom window have another prototype: compare their JSON. */
const same = ( actual, expected ) => assert.equal( JSON.stringify( actual ), JSON.stringify( expected ) );

const tick = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

test( 'the button is shown when the script runs and does nothing until clicked', async () => {
	const calls = [];
	const win = await load( { apiFetch: ( o ) => { calls.push( o ); return Promise.resolve( {} ); } } );
	assert.equal( win.document.getElementById( 'wpinat-verify' ).hidden, false );
	assert.equal( calls.length, 0 );
} );

test( 'it sends the typed login to the route and shows the exact login found', async () => {
	const calls = [];
	const win = await load( { apiFetch: ( o ) => { calls.push( o ); return Promise.resolve( { status: 'found', login: 'Jean' } ); } } );
	win.document.getElementById( 'wpinat_login' ).value = 'typed-not-saved';
	const button = win.document.getElementById( 'wpinat-verify' );
	button.click();
	assert.equal( button.disabled, true, 'disabled while waiting' );
	assert.equal( win.document.getElementById( 'wpinat-verify-result' ).textContent, 'Checking…' );
	await tick();
	same( calls, [ { path: '/wpinat/v1/verify-login', method: 'POST', data: { login: 'typed-not-saved' } } ] );
	const result = win.document.getElementById( 'wpinat-verify-result' );
	assert.equal( result.textContent, 'Account found: Jean' );
	assert.equal( result.className, 'wpinat-ok' );
	assert.equal( button.disabled, false );
} );

for ( const [ status, expected ] of [ [ 'not_found', 'No account.' ], [ 'invalid', 'Invalid.' ], [ 'unavailable', 'Unavailable.' ], [ 'surprise', 'Error.' ] ] ) {
	test( 'status ' + status + ' shows its message', async () => {
		const win = await load( { apiFetch: () => Promise.resolve( { status } ) } );
		win.document.getElementById( 'wpinat-verify' ).click();
		await tick();
		const result = win.document.getElementById( 'wpinat-verify-result' );
		assert.equal( result.textContent, expected );
		assert.equal( result.className, 'wpinat-ko' );
	} );
}

test( 'a failing request shows the error message and re-enables the button', async () => {
	const win = await load( { apiFetch: () => Promise.reject( new Error( 'boom' ) ) } );
	const button = win.document.getElementById( 'wpinat-verify' );
	button.click();
	await tick();
	assert.equal( win.document.getElementById( 'wpinat-verify-result' ).textContent, 'Error.' );
	assert.equal( button.disabled, false );
} );

test( 'a hostile login coming back is shown as text, not as markup', async () => {
	const win = await load( { apiFetch: () => Promise.resolve( { status: 'found', login: '<img src=x onerror=alert(1)>' } ) } );
	win.document.getElementById( 'wpinat-verify' ).click();
	await tick();
	const result = win.document.getElementById( 'wpinat-verify-result' );
	assert.equal( result.querySelector( 'img' ), null );
	assert.match( result.textContent, /<img/ );
} );

test( 'without the data, the API or the elements the script does nothing and does not throw', async () => {
	const noApi = await load( {} );
	assert.equal( noApi.document.getElementById( 'wpinat-verify' ).hidden, true );
	const noConfig = await load( { apiFetch: () => Promise.resolve( {} ), config: null } );
	assert.equal( noConfig.document.getElementById( 'wpinat-verify' ).hidden, true );
	await load( { html: '<p>other page</p>', apiFetch: () => Promise.resolve( {} ) } );
} );
