/**
 * LetterWP > Email Design: rebuild the preview as the options change,
 * switch it between desktop and mobile, and pick the header logo.
 *
 * The preview is built on the server by the same code that sends, so each
 * change asks for it again, a moment after the last one. Send test email
 * mails that same email, unsaved options and all.
 */
( function () {
	var cfg = window.letterwpDesign;
	var root = document.querySelector( '.letterwp-design' );
	var form = document.getElementById( 'letterwp-design-form' );

	if ( ! cfg || ! root || ! form ) {
		return;
	}

	var frame = root.querySelector( '.letterwp-frame' );
	var postPick = document.getElementById( 'letterwp-preview-post' );
	var status = root.querySelector( '.letterwp-preview-status' );
	var dirtyNote = form.querySelector( '.letterwp-dirty' );
	var timer = 0;
	var asked = 0;
	var saving = false;

	function serialize() {
		return new URLSearchParams( new FormData( form ) ).toString();
	}

	var saved = serialize();

	/* -- What shows depends on other options ------------------------------ */

	function sync() {
		var logo = form.querySelector( '[name="letterwp_design[logo]"]' ).value;
		var excerpt = form.querySelector( '[name="letterwp_design[content]"]:checked' );

		root.classList.toggle( 'has-logo', '' !== logo && '0' !== logo );
		root.classList.toggle( 'is-excerpt', !! excerpt && 'excerpt' === excerpt.value );

		form.querySelectorAll( '.letterwp-range' ).forEach( function ( field ) {
			field.querySelector( 'output' ).textContent = field.querySelector( 'input' ).value + 'px';
		} );
		form.querySelectorAll( '.letterwp-color' ).forEach( function ( field ) {
			field.querySelector( 'code' ).textContent = field.querySelector( 'input' ).value;
		} );

		var dirty = serialize() !== saved;
		dirtyNote.hidden = ! dirty;
		return dirty;
	}

	/* -- The preview ------------------------------------------------------ */

	/** The form's unsaved options and the picked post, to one of our actions. */
	function ask( action, extra ) {
		var body = new FormData( form );

		body.set( 'action', action );
		body.set( '_ajax_nonce', cfg.nonce );
		body.set( 'post_id', postPick.value );
		body.delete( '_wpnonce' );
		body.delete( 'letterwp_do' );
		Object.keys( extra || {} ).forEach( function ( k ) {
			body.set( k, extra[ k ] );
		} );

		return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	function render() {
		if ( ! frame || ! postPick ) {
			return;
		}

		var id = ++asked;

		status.textContent = '…';

		ask( cfg.preview )
			.then( function ( r ) {
				if ( id !== asked ) {
					return; // A newer one is on its way.
				}
				if ( ! r || ! r.success ) {
					throw new Error( r && r.data && r.data.message ? r.data.message : cfg.i18n.failed );
				}
				show( r.data );
				status.textContent = '';
			} )
			.catch( function ( e ) {
				if ( id === asked ) {
					status.textContent = e.message || cfg.i18n.failed;
				}
			} );
	}

	/** Swap the email in, keeping the reader's place in it. */
	function show( data ) {
		var y = 0;
		try {
			y = frame.contentWindow ? frame.contentWindow.scrollY : 0;
		} catch ( e ) {}

		// Links open in a new tab, not inside the preview.
		frame.srcdoc = data.html.replace( /<head>/i, '<head><base target="_blank" />' );
		frame.onload = function () {
			try {
				frame.contentWindow.scrollTo( 0, y );
			} catch ( e ) {}
		};

		root.querySelector( '.letterwp-inbox-subject' ).textContent = data.subject;
		root.querySelector( '.letterwp-inbox-preheader' ).textContent = data.preheader;
	}

	function later() {
		sync();
		clearTimeout( timer );
		timer = setTimeout( render, 250 );
	}

	form.addEventListener( 'input', later );
	form.addEventListener( 'change', later );
	if ( postPick ) {
		postPick.addEventListener( 'change', render );
	}

	/* -- Send test email -------------------------------------------------- */

	var testButton = root.querySelector( '.letterwp-test-send' );

	if ( testButton ) {
		var testTo = document.getElementById( 'letterwp-test-to' );
		var testResult = root.querySelector( '.letterwp-test-result' );

		var sendTest = function () {
			testButton.disabled = true;
			testResult.className = 'letterwp-test-result';
			testResult.textContent = cfg.i18n.sending;

			ask( cfg.test, { to: testTo.value } )
				.then( function ( r ) {
					if ( ! r || ! r.success ) {
						throw new Error( r && r.data && r.data.message ? r.data.message : cfg.i18n.failed );
					}
					testResult.className = 'letterwp-test-result is-sent';
					testResult.textContent = r.data.message + ' ';
					var a = document.createElement( 'a' );
					a.href = r.data.url;
					a.target = '_blank';
					a.rel = 'noopener noreferrer';
					a.textContent = cfg.i18n.seeIt;
					testResult.appendChild( a );
				} )
				.catch( function ( e ) {
					testResult.className = 'letterwp-test-result is-error';
					testResult.textContent = e.message || cfg.i18n.failed;
				} )
				.then( function () {
					testButton.disabled = false;
				} );
		};

		testButton.addEventListener( 'click', sendTest );
		testTo.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				sendTest();
			}
		} );
	}

	/* -- Desktop / Mobile ------------------------------------------------- */

	var tabs = root.querySelectorAll( '.letterwp-devices [role="tab"]' );

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			root.dataset.device = tab.dataset.device;
			tabs.forEach( function ( t ) {
				t.setAttribute( 'aria-selected', t === tab ? 'true' : 'false' );
			} );
		} );
	} );

	/* -- Logo ------------------------------------------------------------- */

	var logoBox = form.querySelector( '.letterwp-logo' );
	var logoInput = logoBox.querySelector( 'input' );
	var mediaFrame = null;

	function setLogo( id, url ) {
		logoInput.value = id;
		logoBox.querySelector( 'img' ).src = url || '';
		logoBox.classList.toggle( 'has-logo', !! id );
		later();
	}

	logoBox.querySelector( '.letterwp-logo-pick' ).addEventListener( 'click', function () {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		if ( ! mediaFrame ) {
			mediaFrame = wp.media( {
				title: cfg.i18n.pickLogo,
				button: { text: cfg.i18n.useLogo },
				library: { type: 'image' },
				multiple: false,
			} );
			mediaFrame.on( 'select', function () {
				var a = mediaFrame.state().get( 'selection' ).first().toJSON();
				var url = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url;
				setLogo( a.id, url );
			} );
		}
		mediaFrame.open();
	} );

	logoBox.querySelector( '.letterwp-logo-remove' ).addEventListener( 'click', function () {
		setLogo( 0, '' );
	} );

	/* -- Save, reset, leave ----------------------------------------------- */

	form.querySelector( '.letterwp-design-reset' ).addEventListener( 'click', function ( e ) {
		if ( ! window.confirm( cfg.i18n.reset ) ) {
			e.preventDefault();
		}
	} );

	form.addEventListener( 'submit', function () {
		saving = true;
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( ! saving && sync() ) {
			e.preventDefault();
			e.returnValue = cfg.i18n.leave;
		}
	} );

	sync();
	render();
} )();
