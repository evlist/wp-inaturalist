/**
 * Settings page: the "Check" button next to the iNaturalist login.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
( function ( win, doc ) {
	'use strict';

	function init() {
		var input = doc.getElementById( 'wpinat_login' );
		var button = doc.getElementById( 'wpinat-verify' );
		var result = doc.getElementById( 'wpinat-verify-result' );
		var config = win.wpinatSettings;
		if ( ! input || ! button || ! result || ! config || ! win.wp || ! win.wp.apiFetch ) {
			return;
		}
		var strings = config.strings;
		button.hidden = false;

		function show( status, login ) {
			var text = strings[ status ] || strings.error;
			result.textContent = text.replace( '%s', login || '' );
			result.className = 'found' === status ? 'wpinat-ok' : 'wpinat-ko';
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.className = '';
			result.textContent = strings.checking;
			win.wp
				.apiFetch( { path: '/wpinat/v1/verify-login', method: 'POST', data: { login: input.value } } )
				.then( function ( answer ) {
					show( answer && answer.status, answer && answer.login );
				} )
				.catch( function () {
					show( 'error' );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	if ( 'loading' === doc.readyState ) {
		doc.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
