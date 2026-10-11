/**
 * The LetterWP panel in the post editor's sidebar, and its last word in the
 * publish check ("Are you ready to publish?"). No build step: plain
 * wp.element, so the file that ships is the file that is read.
 *
 * On a campaign (cfg.campaign) there is no switch and no topic to pick:
 * publishing sends it, under Announcements. The editor's own words say
 * Send instead of Publish: LetterWP_Editor::campaign_words(), which has
 * to run before the editor's first render, so it is not in this file.
 *
 * Server side: LetterWP_Editor.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var C = wp.components;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var PrePublish = ( wp.editor && wp.editor.PluginPrePublishPanel ) || ( wp.editPost && wp.editPost.PluginPrePublishPanel );
	var cfg = window.letterwpEditor || {};
	var campaign = !! cfg.campaign;

	if ( ! Panel ) {
		return;
	}


	/** The post as the editor has it now, saved or not. */
	function edited() {
		var e = wp.data.select( 'core/editor' );
		return {
			title: e.getEditedPostAttribute( 'title' ) || '',
			content: e.getEditedPostContent(),
			excerpt: e.getEditedPostAttribute( 'excerpt' ) || '',
			featured_media: e.getEditedPostAttribute( 'featured_media' ) || 0,
		};
	}

	/** Opens the email in a new tab: a form, so unsaved content fits. */
	function preview( postId ) {
		var form = document.createElement( 'form' );
		var fields = Object.assign( { action: 'letterwp_preview', post_id: postId, _wpnonce: cfg.previewNonce }, edited() );

		form.method = 'post';
		form.action = cfg.previewUrl;
		form.target = '_blank';

		Object.keys( fields ).forEach( function ( name ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = fields[ name ];
			form.appendChild( input );
		} );

		document.body.appendChild( form );
		form.submit();
		form.remove();
	}

	function Line( props ) {
		return el( 'p', { style: { margin: '8px 0 0', color: props.muted ? '#757575' : undefined } }, props.children );
	}

	/**
	 * What the server says about this post's email: can the site send, how
	 * many readers each topic reaches, what happened if it was sent. Asked
	 * again after every save, since publishing sends on the server.
	 *
	 * @return {Array} [ info or null while asking, setInfo ]
	 */
	function useEmailInfo( postId ) {
		var infoState = useState( null );
		var saving = useSelect( function ( s ) {
			var e = s( 'core/editor' );
			return e.isSavingPost() && ! e.isAutosavingPost();
		}, [] );
		var wasSaving = useRef( false );

		function load() {
			wp.apiFetch( { path: '/letterwp/v1/posts/' + postId + '/email' } )
				.then( infoState[ 1 ] )
				.catch( function ( err ) {
					infoState[ 1 ]( { message: err.message || __( 'Could not reach LetterWP.', 'letterwp' ) } );
				} );
		}

		useEffect( function () {
			if ( postId ) {
				load();
			}
		}, [ postId ] );

		useEffect( function () {
			if ( wasSaving.current && ! saving && postId ) {
				load();
			}
			wasSaving.current = saving;
		}, [ saving ] );

		return infoState;
	}

	/** The post's categories, by name, and the one it goes out under. A campaign's is Announcements. */
	function useTopic( meta ) {
		if ( campaign ) {
			return { cats: [], topic: { id: cfg.announce, name: cfg.announceName } };
		}

		var catIds = useSelect( function ( s ) { return s( 'core/editor' ).getEditedPostAttribute( 'categories' ); }, [] ) || [];
		var cats = useSelect( function ( s ) {
			return catIds.length ? s( 'core' ).getEntityRecords( 'taxonomy', 'category', { include: catIds, per_page: -1, orderby: 'name', order: 'asc', _fields: 'id,name' } ) : [];
		}, [ catIds.join( ',' ) ] ) || [];
		var picked = meta[ cfg.metaTopic ] || 0;

		if ( ! cfg.topics ) {
			return { cats: [], topic: null }; // Every subscriber gets every post.
		}

		return { cats: cats, topic: cats.filter( function ( c ) { return c.id === picked; } )[ 0 ] || cats[ 0 ] };
	}

	/** How many readers get it under this topic, as the send will count them. */
	function readersFor( info, topic ) {
		var r = info && info.readers;
		if ( ! r ) {
			return null;
		}
		return topic && undefined !== r.by_term[ topic.id ] ? r.by_term[ topic.id ] : r.all;
	}

	function setSend( editPost, v ) {
		var change = {};
		change[ cfg.metaSend ] = v;
		editPost( { meta: change } );
	}

	function LetterWPPanel() {
		var postId = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostId(); }, [] );
		var status = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostAttribute( 'status' ); }, [] );
		var meta = useSelect( function ( s ) { return s( 'core/editor' ).getEditedPostAttribute( 'meta' ); }, [] ) || {};
		var editPost = useDispatch( 'core/editor' ).editPost;
		var t = useTopic( meta );
		var cats = t.cats;
		var topic = t.topic;

		var infoState = useEmailInfo( postId );
		var info = infoState[ 0 ];
		var setInfo = infoState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var resultState = useState( null );
		var result = resultState[ 0 ];
		var setResult = resultState[ 1 ];
		var retryState = useState( false );
		var retrying = retryState[ 0 ];
		var setRetrying = retryState[ 1 ];
		var askState = useState( false );
		var asking = askState[ 0 ];
		var setAsking = askState[ 1 ];
		var title = useSelect( function ( s ) { return s( 'core/editor' ).getEditedPostAttribute( 'title' ); }, [] ) || '';

		// "Email this post", confirmed: the topic goes along, since the pick may be unsaved.
		function emailNow() {
			setRetrying( true );
			wp.apiFetch( { path: '/letterwp/v1/posts/' + postId + '/email-now', method: 'POST', data: { topic: topic ? topic.id : 0 } } )
				.then( function ( sent ) { setInfo( Object.assign( {}, info, { sent: sent } ) ); } )
				.catch( function ( err ) { setInfo( Object.assign( {}, info, { sent: { status: 'failed', error: err.message } } ) ); } )
				.finally( function () { setRetrying( false ); setAsking( false ); } );
		}

		function retry() {
			setRetrying( true );
			wp.apiFetch( { path: '/letterwp/v1/posts/' + postId + '/send', method: 'POST' } )
				.then( function ( sent ) { setInfo( Object.assign( {}, info, { sent: sent } ) ); } )
				.catch( function ( err ) { setInfo( Object.assign( {}, info, { sent: { status: 'failed', error: err.message } } ) ); } )
				.finally( function () { setRetrying( false ); } );
		}

		function sendTest() {
			setBusy( true );
			setResult( null );
			wp.apiFetch( { path: '/letterwp/v1/posts/' + postId + '/test', method: 'POST', data: edited() } )
				.then( function ( r ) { setResult( { ok: true, to: r.to, url: r.url } ); } )
				.catch( function ( err ) { setResult( { ok: false, message: err.message } ); } )
				.finally( function () { setBusy( false ); } );
		}

		// Until Resend has answered, everything waits but test and preview.
		var checking = ! info;
		info = info || { can_send: false, can_test: true, postal: true };

		var published = 'publish' === status || 'private' === status;
		var send = !! meta[ cfg.metaSend ];
		var sent = info.sent;
		var readers = readersFor( info, topic );
		// Published without its email: it can still get it, once, by hand. Not a campaign: publishing one is sending it.
		var late = ! campaign && 'publish' === status && ! sent && ! checking && info.can_send;
		var children = [];

		// Sent, sending or failed: what happened when it was published.
		if ( sent && 'sent' === sent.status ) {
			children.push( el( Line, { key: 'sent' },
				/* translators: 1: number of readers. 2: date and time. */
				sprintf( _n( 'Sent to %1$s reader on %2$s.', 'Sent to %1$s readers on %2$s.', sent.count, 'letterwp' ), sent.count, sent.when ), ' ',
				el( 'a', { href: sent.url, target: '_blank', rel: 'noopener noreferrer' }, __( 'See it at Resend ↗', 'letterwp' ) )
			) );
			// How it went, counted from Resend's events as they come in.
			if ( sent.results ) {
				children.push( el( Line, { key: 'results' },
					el( 'span', { style: { color: sent.results.warn ? '#cc1818' : '#757575' } }, sent.results.summary ), ' ',
					el( 'a', { href: sent.results.url }, __( 'Results', 'letterwp' ) )
				) );
			}
		} else if ( sent && 'sending' === sent.status ) {
			children.push( el( Line, { key: 'sent' }, el( C.Spinner, { style: { margin: '0 6px 0 0' } } ), __( 'Sending…', 'letterwp' ) ) );
		} else if ( sent ) {
			children.push( el( Line, { key: 'sent' }, el( 'span', { style: { color: '#cc1818' } }, __( 'Not sent.', 'letterwp' ), ' ', sent.error ) ) );
			children.push( el( 'div', { key: 'retry', style: { marginTop: '8px' } }, el( C.Button, {
				variant: 'secondary',
				isBusy: retrying,
				disabled: retrying || ! published,
				onClick: retry,
				__next40pxDefaultSize: true,
			}, retrying ? __( 'Sending…', 'letterwp' ) : __( 'Try again', 'letterwp' ) ) ) );
		}

		// Send as email.
		if ( late ) {
			children.push( el( Line, { key: 'late', muted: true }, __( 'Published without an email.', 'letterwp' ) ) );
			children.push( el( 'div', { key: 'email-now', style: { marginTop: '8px' } }, el( C.Button, {
				variant: 'secondary',
				onClick: function () { setAsking( true ); },
				__next40pxDefaultSize: true,
			}, __( 'Email this post…', 'letterwp' ) ) ) );
		} else if ( ! sent && campaign ) {
			children.push( el( Line, { key: 'send', muted: 'private' === status || ( ! checking && ! info.can_send ) },
				'private' === status
					? __( 'Private emails are not sent.', 'letterwp' )
					: null === readers
						? __( 'Sent to your readers when you press Send.', 'letterwp' )
						/* translators: %s: number of readers. */
						: sprintf( _n( 'Sent to %s reader when you press Send.', 'Sent to %s readers when you press Send.', readers, 'letterwp' ), readers )
			) );
		} else if ( ! sent ) {
			children.push( el( C.ToggleControl, {
				key: 'send',
				label: __( 'Send as email when published', 'letterwp' ),
				// Never shown on when it cannot send: what it shows is what happens.
				checked: send && ! published && ( checking || info.can_send ),
				disabled: ! info.can_send || published,
				onChange: function ( v ) { setSend( editPost, v ); },
				help: 'private' === status ? __( 'Private posts are not emailed.', 'letterwp' ) : undefined,
				__nextHasNoMarginBottom: true,
			} ) );
		}

		if ( checking ) {
			children.push( el( Line, { key: 'why', muted: true }, el( C.Spinner, { style: { margin: '0 6px 0 0' } } ), __( 'Checking Resend…', 'letterwp' ) ) );
		} else if ( info.message ) {
			children.push( el( Line, { key: 'why', muted: true },
				info.message, ' ',
				info.fix_url ? el( 'a', { href: info.fix_url }, info.fix_label ) : null
			) );
		}

		// Topic: which of the post's categories it goes out under.
		if ( info.can_send && ( ! published || late ) ) {
			if ( campaign ) {
				children.push( el( Line, { key: 'topic', muted: true },
					/* translators: %s: topic name, Announcements. */
					sprintf( __( 'Topic: %s. Readers who turned it off won\'t get it.', 'letterwp' ), topic.name )
				) );
			} else if ( cats.length > 1 ) {
				children.push( el( 'div', { key: 'topic', style: { marginTop: '16px' } }, el( C.SelectControl, {
					label: __( 'Topic', 'letterwp' ),
					value: topic ? String( topic.id ) : '',
					options: cats.map( function ( c ) { return { value: String( c.id ), label: c.name }; } ),
					onChange: function ( v ) {
						var change = {};
						change[ cfg.metaTopic ] = parseInt( v, 10 ) || 0;
						editPost( { meta: change } );
					},
					help: __( 'One of this post\'s categories. Readers who turned that topic off won\'t get it.', 'letterwp' ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ) ) );
			} else if ( topic ) {
				children.push( el( Line, { key: 'topic', muted: true },
					/* translators: %s: category name. */
					sprintf( __( 'Topic: %s. Readers who turned it off won\'t get it.', 'letterwp' ), topic.name )
				) );
			}
		}

		// Test and preview.
		children.push( el( 'div', { key: 'test', style: { marginTop: '16px', display: 'flex', gap: '12px', alignItems: 'center', flexWrap: 'wrap' } },
			el( C.Button, {
				variant: 'secondary',
				isBusy: busy,
				disabled: busy || ! info.can_test,
				onClick: sendTest,
				__next40pxDefaultSize: true,
			}, busy ? __( 'Sending…', 'letterwp' ) : __( 'Send test to me', 'letterwp' ) ),
			el( C.Button, { variant: 'link', onClick: function () { preview( postId ); } }, __( 'Preview email', 'letterwp' ) )
		) );

		if ( info.to && ! result ) {
			/* translators: %s: email address. */
			children.push( el( Line, { key: 'to', muted: true }, sprintf( __( 'Goes to %s, with your unsaved changes.', 'letterwp' ), info.to ) ) );
		}

		if ( result && result.ok ) {
			children.push( el( Line, { key: 'result' },
				/* translators: %s: email address. */
				sprintf( __( 'Sent to %s.', 'letterwp' ), result.to ), ' ',
				el( 'a', { href: result.url, target: '_blank', rel: 'noopener noreferrer' }, __( 'See it at Resend ↗', 'letterwp' ) )
			) );
		} else if ( result ) {
			children.push( el( Line, { key: 'result' }, el( 'span', { style: { color: '#cc1818' } }, result.message ) ) );
		}

		if ( ! info.postal ) {
			children.push( el( Line, { key: 'postal', muted: true },
				__( 'No postal address in the footer yet. Anti-spam laws ask for one.', 'letterwp' ), ' ',
				el( 'a', { href: info.postalUrl }, __( 'Add it', 'letterwp' ) )
			) );
		}

		if ( asking ) {
			children.push( el( C.Modal, {
				key: 'ask',
				title: __( 'Email this post?', 'letterwp' ),
				onRequestClose: function () { if ( ! retrying ) { setAsking( false ); } },
				size: 'small',
			},
				el( 'p', null,
					/* translators: 1: post title. 2: number of readers. */
					sprintf( _n( '“%1$s” goes to %2$s reader', '“%1$s” goes to %2$s readers', readers, 'letterwp' ), title, readers ),
					topic
						/* translators: %s: category name. */
						? ' ' + sprintf( __( 'who get %s.', 'letterwp' ), topic.name )
						: '.'
				),
				el( 'p', null, __( 'It goes out once: an email can\'t be unsent, and this post can\'t be emailed to everyone again.', 'letterwp' ) ),
				el( 'div', { style: { display: 'flex', justifyContent: 'flex-end', gap: '8px', marginTop: '24px' } },
					el( C.Button, { variant: 'tertiary', disabled: retrying, onClick: function () { setAsking( false ); }, __next40pxDefaultSize: true }, __( 'Cancel', 'letterwp' ) ),
					el( C.Button, { variant: 'primary', isBusy: retrying, disabled: retrying, onClick: emailNow, __next40pxDefaultSize: true },
						retrying
							? __( 'Sending…', 'letterwp' )
							/* translators: %s: number of readers. */
							: sprintf( _n( 'Email %s reader', 'Email %s readers', readers, 'letterwp' ), readers )
					)
				)
			) );
		}

		return el( Panel, { name: 'letterwp', title: campaign ? __( 'Email', 'letterwp' ) : __( 'Newsletter', 'letterwp' ), className: 'letterwp-panel' }, children );
	}

	/**
	 * In the publish check: one last look at who gets the email, and the
	 * switch to stop it. Shown open, with the answer in its title.
	 */
	function LetterWPPrePublish() {
		var postId = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostId(); }, [] );
		var meta = useSelect( function ( s ) { return s( 'core/editor' ).getEditedPostAttribute( 'meta' ); }, [] ) || {};
		var editPost = useDispatch( 'core/editor' ).editPost;
		var topic = useTopic( meta ).topic;
		var info = useEmailInfo( postId )[ 0 ];

		var send = !! meta[ cfg.metaSend ];
		var readers = readersFor( info, topic );
		var children = [];
		var title;

		if ( ! info ) {
			title = campaign ? __( 'Email', 'letterwp' ) : __( 'Newsletter', 'letterwp' );
			children.push( el( Line, { key: 'wait', muted: true }, el( C.Spinner, { style: { margin: '0 6px 0 0' } } ), __( 'Checking Resend…', 'letterwp' ) ) );
		} else if ( info.sent ) {
			// Published before, then unpublished: the lock holds.
			title = campaign ? __( 'Email: already sent', 'letterwp' ) : __( 'Newsletter: already sent', 'letterwp' );
			children.push( el( Line, { key: 'sent', muted: true }, campaign
				? __( 'This email was sent before. Sending it again does nothing.', 'letterwp' )
				: __( 'This post was emailed before. Publishing it again won\'t email it again.', 'letterwp' ) ) );
		} else if ( ! info.can_send ) {
			title = campaign ? __( 'Email: can\'t send yet', 'letterwp' ) : __( 'Newsletter: not emailed', 'letterwp' );
			children.push( el( Line, { key: 'why', muted: true },
				info.message, ' ',
				info.fix_url ? el( 'a', { href: info.fix_url }, info.fix_label ) : null
			) );
		} else if ( campaign ) {
			/* translators: %s: number of readers. */
			title = sprintf( _n( 'Email %s reader', 'Email %s readers', readers, 'letterwp' ), readers );
			children.push( el( Line, { key: 'who' },
				el( 'strong', null,
					/* translators: %s: number of readers. */
					sprintf( _n( 'Goes to %s reader', 'Goes to %s readers', readers, 'letterwp' ), readers )
				),
				/* translators: %s: topic name, Announcements. */
				' ' + sprintf( __( 'who get %s.', 'letterwp' ), topic.name ),
				' ', __( 'An email can\'t be unsent.', 'letterwp' )
			) );
			/* translators: %s: email address. */
			children.push( el( Line, { key: 'from', muted: true }, sprintf( __( 'From %s.', 'letterwp' ), info.from ) ) );
		} else {
			title = send
				/* translators: %s: number of readers. */
				? sprintf( _n( 'Newsletter: email %s reader', 'Newsletter: email %s readers', readers, 'letterwp' ), readers )
				: __( 'Newsletter: not emailed', 'letterwp' );

			children.push( el( C.ToggleControl, {
				key: 'send',
				label: __( 'Send as email', 'letterwp' ),
				checked: send,
				onChange: function ( v ) { setSend( editPost, v ); },
				__nextHasNoMarginBottom: true,
			} ) );

			if ( send ) {
				children.push( el( Line, { key: 'who' },
					el( 'strong', null,
						/* translators: %s: number of readers. */
						sprintf( _n( 'Goes to %s reader', 'Goes to %s readers', readers, 'letterwp' ), readers )
					),
					topic
						/* translators: %s: category name. */
						? ' ' + sprintf( __( 'who get %s.', 'letterwp' ), topic.name )
						: '.',
					' ', __( 'An email can\'t be unsent.', 'letterwp' )
				) );
				/* translators: %s: email address. */
				children.push( el( Line, { key: 'from', muted: true }, sprintf( __( 'From %s.', 'letterwp' ), info.from ) ) );
			} else {
				children.push( el( Line, { key: 'off', muted: true },
					/* translators: %s: number of readers. */
					sprintf( _n( 'Only published on the site. Turn on to email it to %s reader.', 'Only published on the site. Turn on to email it to %s readers.', readers, 'letterwp' ), readers )
				) );
			}
		}

		return el( PrePublish, { name: 'letterwp-prepublish', title: title, initialOpen: true, className: 'letterwp-prepublish' }, children );
	}

	wp.plugins.registerPlugin( 'letterwp', { render: LetterWPPanel, icon: 'email-alt' } );

	if ( PrePublish ) {
		wp.plugins.registerPlugin( 'letterwp-prepublish', { render: LetterWPPrePublish, icon: 'email-alt' } );
	}
}( window.wp ) );
