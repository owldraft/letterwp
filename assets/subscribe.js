/**
 * The subscribe form, in the browser.
 *
 * 1. Signed in: pages with the form are usually cached, so the server could
 *    not say who is reading. When the hint cookie says someone is signed in,
 *    ask /letterwp/v1/me (never cached), disable the form with their email
 *    in it, and show a small "Manage your subscription" link under it.
 * 2. Submitting without a page load. Without this file, or when the request
 *    fails, the form posts to the home URL as plain HTML.
 *
 * Server side: LetterWP_Subscribe.
 */
( function () {
	var HINT = 'letterwp_signed_in';

	function signedIn() {
		return ( '; ' + document.cookie ).indexOf( '; ' + HINT + '=1' ) !== -1;
	}

	function showWho() {
		var boxes = document.querySelectorAll( '.letterwp-subscribe[data-me]' );
		var forms = document.querySelectorAll( '.letterwp-subscribe .letterwp-subscribe__form' );

		if ( ! window.fetch || ! boxes.length || ! forms.length || ! signedIn() ) {
			return;
		}

		fetch( boxes[ 0 ].getAttribute( 'data-me' ), { credentials: 'same-origin', cache: 'no-store' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( me ) {
				if ( ! me || ! me.signed_in ) {
					// A stale hint: the session is gone. Stop asking.
					document.cookie = HINT + '=; Max-Age=0; path=/';
					return;
				}
				Array.prototype.forEach.call( forms, function ( form ) {
					var status = form.closest( '.letterwp-subscribe' ).querySelector( '.letterwp-subscribe__status' );

					form.elements.letterwp_email.value = me.email;
					form.elements.letterwp_email.disabled = true;
					form.querySelector( 'button' ).disabled = true;

					if ( status && me.html ) {
						status.innerHTML = me.html;
						status.hidden = false;
					}
				} );
			} )
			.catch( function () {} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', showWho );
	} else {
		showWho();
	}

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;

		if ( ! window.fetch || ! form.matches || ! form.matches( '.letterwp-subscribe__form' ) ) {
			return;
		}

		e.preventDefault();

		var box = form.closest( '.letterwp-subscribe' );
		var msg = box.querySelector( '.letterwp-subscribe__message' );
		var button = form.querySelector( 'button' );

		button.disabled = true;

		fetch( form.getAttribute( 'data-endpoint' ), {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				email: form.elements.letterwp_email.value,
				website: form.elements.letterwp_website.value,
				page: window.location.href,
			} ),
		} )
			.then( function ( r ) {
				return r.json().then( function ( json ) {
					if ( ! json || ! json.message ) {
						throw new Error( 'letterwp' );
					}
					msg.textContent = json.message;
					msg.hidden = false;
					msg.classList.toggle( 'letterwp-error', ! r.ok );

					if ( r.ok ) {
						form.hidden = true;
						var note = box.querySelector( '.letterwp-subscribe__note' );
						if ( note ) {
							note.hidden = true;
						}
					} else {
						button.disabled = false;
					}
				} );
			} )
			.catch( function () {
				form.submit();
			} );
	} );
}() );
