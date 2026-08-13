/* global igbzFront */
( function () {
	'use strict';

	if ( typeof igbzFront === 'undefined' ) {
		return;
	}

	function post( body ) {
		var data = new FormData();
		data.append( 'action', 'igbz_otp' );
		data.append( 'nonce', igbzFront.nonce );
		Object.keys( body ).forEach( function ( key ) {
			data.append( key, body[ key ] );
		} );

		return fetch( igbzFront.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function initOtpForm( form ) {
		var phone = form.querySelector( 'input[name="phone"]' );
		var code = form.querySelector( 'input[name="code"]' );
		var step2 = form.querySelector( '.igbz-otp-step-2' );
		var sendBtn = form.querySelector( '.igbz-otp-send' );
		var verifyBtn = form.querySelector( '.igbz-otp-verify' );
		var message = form.querySelector( '.igbz-otp-message' );
		var timer = null;

		function say( text, isError ) {
			message.textContent = text;
			message.className = 'igbz-otp-message ' + ( isError ? 'igbz-error' : 'igbz-success' );
		}

		function countdown( seconds ) {
			window.clearInterval( timer );
			sendBtn.disabled = true;

			timer = window.setInterval( function () {
				seconds -= 1;
				if ( seconds <= 0 ) {
					window.clearInterval( timer );
					sendBtn.disabled = false;
					sendBtn.textContent = igbzFront.i18n.sendCode;
					return;
				}
				sendBtn.textContent = seconds + 's';
			}, 1000 );
		}

		sendBtn.addEventListener( 'click', function () {
			if ( ! phone.value ) {
				return;
			}
			sendBtn.disabled = true;
			sendBtn.textContent = igbzFront.i18n.sending;

			post( { step: 'send', phone: phone.value } ).then( function ( res ) {
				if ( ! res.success ) {
					say( res.data.message, true );
					if ( res.data.retryAfter ) {
						countdown( res.data.retryAfter );
					} else {
						sendBtn.disabled = false;
						sendBtn.textContent = igbzFront.i18n.sendCode;
					}
					return;
				}
				say( res.data.message, false );
				step2.hidden = false;
				verifyBtn.hidden = false;
				code.focus();
				countdown( res.data.expiresIn || 120 );
			} ).catch( function () {
				sendBtn.disabled = false;
				sendBtn.textContent = igbzFront.i18n.sendCode;
			} );
		} );

		verifyBtn.addEventListener( 'click', function () {
			verifyBtn.disabled = true;
			var label = verifyBtn.textContent;
			verifyBtn.textContent = igbzFront.i18n.verifying;

			post( { step: 'verify', phone: phone.value, code: code.value } ).then( function ( res ) {
				if ( ! res.success ) {
					say( res.data.message, true );
					verifyBtn.disabled = false;
					verifyBtn.textContent = label;
					return;
				}
				window.location.href = res.data.redirect;
			} ).catch( function () {
				verifyBtn.disabled = false;
				verifyBtn.textContent = label;
			} );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			( step2.hidden ? sendBtn : verifyBtn ).click();
		} );
	}

	function initCopy( input ) {
		input.addEventListener( 'click', function () {
			input.select();
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( input.value );
				input.setAttribute( 'title', igbzFront.i18n.copied );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '.igbz-otp-form' ), initOtpForm );
		Array.prototype.forEach.call( document.querySelectorAll( '.igbz-copy' ), initCopy );
	} );
}() );
