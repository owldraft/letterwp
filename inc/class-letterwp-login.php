<?php
/**
 * Magic-link sign-in for subscribers. No passwords, no WordPress accounts.
 *
 * A subscriber types their email, gets a link, opens it, and is signed in on
 * that browser: a cookie that names their row in LetterWP_Subscribers. This
 * is the door everything a reader does for themselves will go through later
 * (manage their subscription), built before any of it needs it.
 *
 * The rules are owldraft-publisher's, which are Ghost's:
 *
 *   - A link lives 24 hours, and 10 minutes from its first use, 7 uses max.
 *     Tokens are 32 random bytes, stored only as a sha256 in a transient.
 *   - Opening a link (GET) changes nothing. Mail scanners open every link in
 *     an inbox, so the page it lands on submits itself as a POST, and the POST
 *     is what signs in. A scanner fetches; it does not submit forms.
 *   - Asking for a link says the same thing whether or not the address is
 *     subscribed, so the form cannot be used to find out who reads the site.
 *   - 8 requests per address and 30 per IP every 12 hours.
 *
 * The session cookie is "id|expires|hmac". The HMAC is keyed with the site's
 * auth salt plus the subscriber's own token, so deleting the row, or giving it
 * a new token, signs that subscriber out everywhere.
 *
 * One link, three emails: "sign in" (asked for here), "confirm your
 * subscription" and "you're already subscribed" (both from the subscribe
 * form, see LetterWP_Subscribe). Opening a subscribe link is the double
 * opt-in: it makes the subscriber active, even after an unsubscribe or a
 * bounce, because only the reader can open it. Opening any link confirms a
 * pending subscriber too.
 *
 * The account page (picked in Setup, like a shop or posts page; Setup
 * can make one) gets the account panel after its content. Signed out, one
 * email field: a subscriber gets a sign-in link, a new address the
 * "confirm your subscription" one, which signs them in too. Signed in, it
 * is where a reader sets their name, picks topics, unsubscribes, or
 * subscribes again.
 *
 * The Unsubscribe link in every newsletter is one click, no sign-in: it
 * carries the reader's key (LetterWP_Subscribers::key()), and its page
 * submits itself like a magic link's, so a scanner opening it unsubscribes
 * nobody. Then "You're unsubscribed", and a button to undo it. The key
 * opens only that: a forwarded newsletter signs nobody in. Picking topics
 * stays on the account page, behind sign-in.
 *
 * Every front-end request posts to the home URL with a letterwp_action field:
 * request (send a link, or subscribe), magic (redeem one), signout,
 * profile (names and topics), unsubscribe, resubscribe from the account
 * page, and unsub, unsub_undo from the newsletter's link.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Login {

	const TTL = DAY_IN_SECONDS;

	const TTL_AFTER_USE = 10 * MINUTE_IN_SECONDS;

	const MAX_USES = 7;

	const RATE_EMAIL = 8;

	const RATE_IP = 30;

	const RATE_WINDOW = 12 * HOUR_IN_SECONDS;

	const SESSION = 184 * DAY_IN_SECONDS;

	const COOKIE = 'letterwp_session';

	/**
	 * Says only "someone is signed in here", for JavaScript on cached pages
	 * (the session cookie is HttpOnly). It proves nothing: whoever asks
	 * /letterwp/v1/me is still answered from the session cookie.
	 */
	const HINT = 'letterwp_signed_in';

	/** The query arg a magic link carries its token in. */
	const LINK_ARG = 'letterwp_login';

	/** The query arg a newsletter's Unsubscribe link carries the reader's key in. */
	const UNSUB_ARG = 'letterwp_unsub';

	/** The query arg that tells the form what just happened. */
	const RESULT_ARG = 'letterwp';

	/** On the account page after subscribing: the page they subscribed from. */
	const FROM_ARG = 'letterwp_from';

	/** The account page's id. */
	const PAGE_OPTION = 'letterwp_account_page';

	/** Post meta on the page Setup made, so a reinstall finds it rather than making a second. */
	const PAGE_META = '_letterwp_account_page';

	/** @var object|null|false The signed-in subscriber; false until looked up. */
	private static $current = false;

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'append_to_page' ) );
	}

	/** The account page chosen in Setup → 6, whatever it holds. 0 = none. */
	public static function set_account_page( $id ) {
		$id = (int) $id;

		if ( $id && ( 'publish' !== get_post_status( $id ) || 'page' !== get_post_type( $id ) ) ) {
			return false;
		}

		return update_option( self::PAGE_OPTION, $id, true ) || (int) get_option( self::PAGE_OPTION ) === $id;
	}

	/** Any page can be the account page: the panel goes after its content. */
	public function append_to_page( $content ) {
		$id = self::account_page_id();

		if ( ! $id || ! is_page( $id ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return $content . $this->panel();
	}

	/* ---------------------------------------------------------------------
	 * The account page.
	 * ------------------------------------------------------------------ */

	/** @return int The published account page, or 0. */
	public static function account_page_id() {
		$id = (int) get_option( self::PAGE_OPTION );

		return $id && 'publish' === get_post_status( $id ) && 'page' === get_post_type( $id ) ? $id : 0;
	}

	/** @return string The account page's URL, or '' when there is none. */
	public static function account_url() {
		$id = self::account_page_id();

		return $id ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Make sure there is an account page: the stored one, else the one Setup
	 * made before (a reinstall), else a new one.
	 *
	 * @return bool True when a page was created.
	 */
	public static function ensure_account_page() {
		if ( self::account_page_id() ) {
			return false;
		}

		$found   = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'meta_key'    => self::PAGE_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- once, at setup.
				'fields'      => 'ids',
				'numberposts' => 1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		$id      = $found ? (int) $found[0] : 0;
		$created = false;

		if ( ! $id ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Account', 'letterwp' ),
					'post_name'    => 'account',
					'meta_input'   => array( self::PAGE_META => 1 ),
				)
			);
			$id      = is_wp_error( $id ) ? 0 : (int) $id;
			$created = (bool) $id;
		}

		if ( $id ) {
			update_option( self::PAGE_OPTION, $id, true );
		}

		return $created;
	}

	/**
	 * The one-click unsubscribe link, on the account page (any URL on the
	 * site would do: the route runs everywhere).
	 *
	 * @param string $key A key, or LetterWP_Email::KEY_TAG for Resend to fill in.
	 * @return string
	 */
	public static function unsubscribe_url( $key ) {
		$base = self::account_url();

		return add_query_arg( self::UNSUB_ARG, $key, '' !== $base ? $base : home_url( '/' ) );
	}

	/** "LetterWP Account Page" next to it in Pages, like WooCommerce's shop pages. */
	public static function post_state( $states, $post ) {
		if ( $post && (int) $post->ID === (int) get_option( self::PAGE_OPTION ) ) {
			$states['letterwp_account'] = __( 'LetterWP Account Page', 'letterwp' );
		}

		return $states;
	}

	/* ---------------------------------------------------------------------
	 * Who is signed in.
	 * ------------------------------------------------------------------ */

	/** @return object|null The signed-in subscriber's row. */
	public static function current() {
		if ( false !== self::$current ) {
			return self::$current;
		}

		self::$current = null;

		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$parts  = explode( '|', $cookie );

		if ( 3 !== count( $parts ) || (int) $parts[1] < time() ) {
			return null;
		}

		$row = LetterWP_Subscribers::get( (int) $parts[0] );

		if ( $row && hash_equals( self::sign( $row, (int) $parts[1] ), $parts[2] ) ) {
			self::$current = $row;
		}

		return self::$current;
	}

	/** @return string */
	private static function sign( $row, $expires ) {
		return hash_hmac( 'sha256', $row->id . '|' . $expires, wp_salt( 'auth' ) . $row->token );
	}

	private static function set_cookie( $row ) {
		$expires = time() + self::SESSION;

		self::cookie( $row->id . '|' . $expires . '|' . self::sign( $row, $expires ), $expires );
		self::$current = $row;
	}

	private static function clear_cookie() {
		self::cookie( '', time() - YEAR_IN_SECONDS );
		self::$current = null;
	}

	private static function cookie( $value, $expires ) {
		$args = array(
			'expires'  => $expires,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		setcookie( self::COOKIE, $value, $args );
		setcookie( self::HINT, '' === $value ? '' : '1', array( 'httponly' => false ) + $args );
	}

	/* ---------------------------------------------------------------------
	 * Routing.
	 * ------------------------------------------------------------------ */

	public function route() {
		// A page that knows who is reading it, or shows the result of a form,
		// must not be cached for the next reader. Page-cache plugins honour
		// DONOTCACHEPAGE; everything else honours the headers.
		// The account page is never cached at all: one cached copy of it,
		// signed out, would be served to signed-in readers.
		if ( isset( $_COOKIE[ self::COOKIE ] ) || isset( $_GET[ self::RESULT_ARG ] ) || isset( $_GET[ self::LINK_ARG ] ) || isset( $_GET[ self::UNSUB_ARG ] ) || ( self::account_page_id() && is_page( self::account_page_id() ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page caches read.
			nocache_headers();
		}

		// Sessions from before the hint cookie existed get it now.
		if ( isset( $_COOKIE[ self::COOKIE ] ) && ! isset( $_COOKIE[ self::HINT ] ) && self::current() ) {
			$args = array(
				'expires'  => time() + self::SESSION,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'samesite' => 'Lax',
			);
			setcookie( self::HINT, '1', $args );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- request is open to anyone; magic and signout check their own nonces.
		$action = isset( $_POST['letterwp_action'] ) ? sanitize_key( wp_unslash( $_POST['letterwp_action'] ) ) : '';

		if ( 'request' === $action ) {
			$this->do_request();
		} elseif ( 'magic' === $action ) {
			$this->do_magic();
		} elseif ( 'signout' === $action ) {
			$this->do_signout();
		} elseif ( in_array( $action, array( 'profile', 'unsubscribe', 'resubscribe' ), true ) ) {
			$this->do_account( $action );
		} elseif ( in_array( $action, array( 'unsub', 'unsub_undo' ), true ) ) {
			$this->do_unsub( $action );
		} elseif ( isset( $_GET[ self::LINK_ARG ] ) ) {
			$this->confirm_screen( sanitize_text_field( wp_unslash( $_GET[ self::LINK_ARG ] ) ) );
		} elseif ( isset( $_GET[ self::UNSUB_ARG ] ) ) {
			$this->unsub_screen( sanitize_text_field( wp_unslash( $_GET[ self::UNSUB_ARG ] ) ) );
		}
		// phpcs:enable
	}

	/** @return string Where to send the reader back to: a page on this site. */
	private static function posted_redirect() {
		$url = isset( $_POST['letterwp_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['letterwp_redirect'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		return remove_query_arg( self::RESULT_ARG, wp_validate_redirect( $url, home_url( '/' ) ) );
	}

	private static function back( $url, $result = '' ) {
		wp_safe_redirect( '' === $result ? $url : add_query_arg( self::RESULT_ARG, $result, $url ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * 1. Ask for a link.
	 * ------------------------------------------------------------------ */

	private function do_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- a public form on cacheable pages; rate limits stand in.
		$back  = self::posted_redirect();
		$email = LetterWP_Subscribers::clean_email( isset( $_POST['letterwp_email'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_email'] ) ) : '' );

		// Honeypot: a field people never see. A bot that fills it is told it worked.
		if ( ! empty( $_POST['letterwp_website'] ) ) {
			self::back( $back, 'sent' );
		}
		// phpcs:enable

		if ( '' === $email ) {
			self::back( $back, 'bad_email' );
		}

		$row = LetterWP_Subscribers::get_by_email( $email );

		// Not subscribed: subscribe them. Its confirm link signs them in too,
		// and the answer is "check your inbox" either way.
		if ( ! $row ) {
			self::back( $back, LetterWP_Subscribe::subscribe( $email, false, $back ) );
		}

		if ( ! self::allow( $email ) ) {
			self::back( $back, 'limited' );
		}

		$sent = self::send_magic( $row, 'signin', $back );

		self::back( $back, is_wp_error( $sent ) ? 'unsendable' : 'sent' );
	}

	/* -- Used by the subscribe form too. ---------------------------------- */

	/** @return bool False once this address or this IP asked for too many links; counts this one otherwise. */
	public static function allow( $email ) {
		return self::rate_ok( 'ip', self::ip(), self::RATE_IP ) && self::rate_ok( 'em', $email, self::RATE_EMAIL );
	}

	/** @return string The address links come from, or '' when this site cannot send yet. */
	public static function sender() {
		$addresses = LetterWP_Addresses::all();
		$from      = '' !== $addresses['tx'] ? $addresses['tx'] : $addresses['nl'];

		return '' !== LetterWP_Resend::key() ? $from : '';
	}

	/**
	 * Email a subscriber a link.
	 *
	 * @param object $row  A subscriber row.
	 * @param string $kind signin, subscribe (confirm a subscription) or already (subscribed: sign in to manage it).
	 * @param string $from The page the reader asked from; where signin comes back to.
	 * @return string|WP_Error The email's id at Resend.
	 */
	public static function send_magic( $row, $kind, $from ) {
		$account = self::account_url();

		if ( 'subscribe' === $kind ) {
			$redirect = '' !== $account
				? add_query_arg( array( self::RESULT_ARG => 'welcome', self::FROM_ARG => rawurlencode( $from ) ), $account )
				: add_query_arg( self::RESULT_ARG, 'sub-confirmed', $from );
		} elseif ( 'already' === $kind ) {
			$redirect = '' !== $account ? $account : $from;
		} else {
			$redirect = $from;
		}

		$token = self::issue(
			array(
				'sid'      => (int) $row->id,
				'email'    => $row->email,
				'redirect' => $redirect,
				'intent'   => 'subscribe' === $kind ? 'subscribe' : 'signin',
			)
		);

		return self::send_link( $row->email, add_query_arg( self::LINK_ARG, $token, home_url( '/' ) ), $kind );
	}

	/** @return string|WP_Error */
	private static function send_link( $email, $url, $kind ) {
		$from = self::sender();

		if ( '' === $from ) {
			return new WP_Error( 'letterwp_unsendable', 'Email is not set up.' );
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		if ( 'subscribe' === $kind ) {
			/* translators: %s: site name. */
			$subject = sprintf( __( 'Confirm your subscription to %s', 'letterwp' ), $site );
			/* translators: %s: site name. */
			$lead   = sprintf( __( 'Tap the button to start getting new posts from %s by email.', 'letterwp' ), $site );
			$button = __( 'Yes, subscribe me', 'letterwp' );
			$note   = __( 'This link works for 24 hours. If you did not ask to subscribe, ignore this email: you will not be subscribed, and you will not hear from us again.', 'letterwp' );
		} elseif ( 'already' === $kind ) {
			/* translators: %s: site name. */
			$subject = sprintf( __( 'You are already subscribed to %s', 'letterwp' ), $site );
			$lead    = __( 'Someone, hopefully you, asked to subscribe this address. It already gets every new post. To change what you get, sign in.', 'letterwp' );
			$button  = __( 'Sign in', 'letterwp' );
			$note    = __( 'This link works for 24 hours. If you did not ask, ignore this email: nothing changes.', 'letterwp' );
		} else {
			/* translators: %s: site name. */
			$subject = sprintf( __( 'Sign in to %s', 'letterwp' ), $site );
			$lead    = '';
			$button  = __( 'Sign in', 'letterwp' );
			$note    = __( 'This link works for 24 hours, on the device you open it on. If you did not ask to sign in, ignore this email: nothing happens until the link is opened.', 'letterwp' );
		}

		$html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#1d2327;max-width:560px;margin:0 auto;padding:24px;">'
			. '<h1 style="font-size:22px;margin:0 0 16px;">' . esc_html( $subject ) . '</h1>'
			. ( '' !== $lead ? '<p style="margin:0 0 24px;">' . esc_html( $lead ) . '</p>' : '' )
			. '<p style="margin:0 0 24px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1d2327;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">' . esc_html( $button ) . '</a></p>'
			. '<p style="margin:0;color:#646970;font-size:14px;">' . esc_html( $note ) . '</p>'
			. '</div>';

		return LetterWP_Resend::send_email(
			array(
				'from'    => $from,
				'to'      => $email,
				'subject' => $subject,
				'html'    => $html,
				'text'    => $subject . "\n\n" . ( '' !== $lead ? $lead . "\n\n" : '' ) . $button . ': ' . $url . "\n\n" . $note,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * 2. Open the link: a page that changes nothing, and submits itself.
	 * ------------------------------------------------------------------ */

	private function confirm_screen( $token ) {
		$payload = self::peek( $token );
		$reason  = self::check( $payload );

		if ( '' !== $reason ) {
			self::dead_link( $reason );
		}

		$subscribe = isset( $payload['intent'] ) && 'subscribe' === $payload['intent'];
		$title     = $subscribe ? __( 'Subscribing', 'letterwp' ) : __( 'Signing in', 'letterwp' );
		$button    = $subscribe ? __( 'Subscribe', 'letterwp' ) : __( 'Sign in', 'letterwp' );

		ob_start();
		?>
		<p id="letterwp-lead"><?php echo esc_html( $subscribe ? __( 'Confirm to finish subscribing.', 'letterwp' ) : __( 'Confirm to finish signing in.', 'letterwp' ) ); ?></p>
		<form id="letterwp-confirm" method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="hidden" name="letterwp_action" value="magic" />
			<input type="hidden" name="letterwp_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( 'letterwp_magic', 'letterwp_nonce' ); ?>
			<button type="submit" id="letterwp-go"><?php echo esc_html( $button ); ?></button>
		</form>
		<script>
			( function () {
				var f = document.getElementById( 'letterwp-confirm' );
				// Headless browsers say so; whatever does not still sees a button.
				if ( ! f || navigator.webdriver ) { return; }
				document.getElementById( 'letterwp-lead' ).textContent = <?php echo wp_json_encode( __( 'One moment…', 'letterwp' ) ); ?>;
				f.hidden = true;
				f.submit();
			}() );
		</script>
		<?php
		self::screen( $title, ob_get_clean() );
	}

	/* ---------------------------------------------------------------------
	 * 3. The POST: spend the token, sign in.
	 * ------------------------------------------------------------------ */

	private function do_magic() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified just below.
		$token = isset( $_POST['letterwp_token'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_token'] ) ) : '';
		$nonce = isset( $_POST['letterwp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_nonce'] ) ) : '';
		// phpcs:enable

		// A POST that did not come from the confirm page gets the confirm page.
		if ( ! wp_verify_nonce( $nonce, 'letterwp_magic' ) ) {
			$this->confirm_screen( $token );
		}

		$result = self::redeem( $token );

		if ( '' !== $result['reason'] ) {
			self::dead_link( $result['reason'] );
		}

		$payload = $result['payload'];
		$row     = LetterWP_Subscribers::get( (int) $payload['sid'] );

		if ( ! $row || $row->email !== $payload['email'] ) {
			self::dead_link( 'invalid' );
		}

		// Opening the link proves the inbox is theirs, which is all a pending
		// subscriber still owed: this is the confirm step of double opt-in.
		// A subscribe link also brings back someone who left or bounced:
		// they asked again, and only they could open it.
		$intent = isset( $payload['intent'] ) ? $payload['intent'] : 'signin';

		if ( 'pending' === $row->status || ( 'subscribe' === $intent && 'active' !== $row->status ) ) {
			LetterWP_Sync::consent( $row->email );
			LetterWP_Subscribers::set_status( $row->id, 'active' );
		}

		self::rate_reset_email( $row->email );
		self::set_cookie( $row );
		self::back( wp_validate_redirect( $payload['redirect'], home_url( '/' ) ) );
	}

	private function do_signout() {
		$nonce = isset( $_POST['letterwp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( wp_verify_nonce( $nonce, 'letterwp_signout' ) ) {
			self::clear_cookie();
		}

		self::back( self::posted_redirect() );
	}

	/* ---------------------------------------------------------------------
	 * 4. The account page's own forms. Signed in only.
	 * ------------------------------------------------------------------ */

	private function do_account( $action ) {
		$nonce = isset( $_POST['letterwp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$back  = self::posted_redirect();
		$row   = self::current();

		if ( ! $row || ! wp_verify_nonce( $nonce, 'letterwp_account' ) ) {
			self::back( $back );
		}

		// Names, and the topics when there was a choice of them.
		if ( 'profile' === $action ) {
			// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- checked above; names cleaned by update().
			$fields = array(
				'first_name' => isset( $_POST['letterwp_first_name'] ) ? wp_unslash( $_POST['letterwp_first_name'] ) : $row->first_name,
				'last_name'  => isset( $_POST['letterwp_last_name'] ) ? wp_unslash( $_POST['letterwp_last_name'] ) : $row->last_name,
			);

			if ( ! empty( $_POST['letterwp_has_topics'] ) ) {
				$posted = isset( $_POST['letterwp_topics'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['letterwp_topics'] ) ) : array();
				$topics = array_values( array_intersect( LetterWP_Subscribers::topic_ids(), $posted ) );

				if ( ! $topics ) {
					self::back( $back, 'no_topics' );
				}

				$fields['topics'] = $topics;
			}
			// phpcs:enable

			LetterWP_Subscribers::update( $row->id, $fields );
			self::back( $back, 'saved' );
		}

		if ( 'unsubscribe' === $action && in_array( $row->status, array( 'active', 'pending' ), true ) ) {
			LetterWP_Subscribers::set_status( $row->id, 'unsubscribed' );
			self::back( $back, 'unsubscribed' );
		}

		// Signed in means they opened a link sent to this inbox: that is consent enough.
		if ( 'resubscribe' === $action && in_array( $row->status, array( 'unsubscribed', 'bounced' ), true ) ) {
			LetterWP_Sync::consent( $row->email );
			LetterWP_Subscribers::set_status( $row->id, 'active' );
			self::back( $back, 'resubscribed' );
		}

		self::back( $back );
	}

	/* ---------------------------------------------------------------------
	 * 5. One-click unsubscribe, from the link in a newsletter.
	 * ------------------------------------------------------------------ */

	/**
	 * Still getting emails: a page that submits itself and unsubscribes.
	 * Already not: "You're unsubscribed" and the way back. Just came back:
	 * "Welcome back".
	 */
	private function unsub_screen( $key ) {
		$row = LetterWP_Subscribers::by_key( $key );

		if ( ! $row ) {
			self::dead_unsub();
		}

		$site    = get_bloginfo( 'name' );
		$getting = in_array( $row->status, array( 'active', 'pending' ), true );
		$result  = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$home    = '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html(
			/* translators: %s: site name. */
			sprintf( __( '← Back to %s', 'letterwp' ), $site )
		) . '</a></p>';

		if ( $getting && 'resubscribed' === $result ) {
			$account = self::account_url();
			$topics  = '' !== $account && count( LetterWP_Subscribers::topic_ids() ) > 1
				? '<p><a href="' . esc_url( $account ) . '">' . esc_html__( 'Only want some of the emails? Choose them on your account page.', 'letterwp' ) . '</a></p>'
				: '';

			self::screen(
				__( 'You are subscribed again', 'letterwp' ),
				/* translators: %s: email address. */
				'<p>' . esc_html( sprintf( __( 'Welcome back. New posts will come to %s again.', 'letterwp' ), $row->email ) ) . '</p>' . $topics . $home
			);
		}

		ob_start();

		if ( $getting ) {
			?>
			<p id="letterwp-lead"><?php echo esc_html( sprintf( /* translators: %s: email address. */ __( 'Stop all emails to %s?', 'letterwp' ), $row->email ) ); ?></p>
			<form id="letterwp-confirm" method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php self::unsub_fields( 'unsub', $key ); ?>
				<button type="submit" id="letterwp-go"><?php esc_html_e( 'Unsubscribe', 'letterwp' ); ?></button>
			</form>
			<script>
				( function () {
					var f = document.getElementById( 'letterwp-confirm' );
					// Headless browsers say so; whatever does not still sees a button.
					if ( ! f || navigator.webdriver ) { return; }
					document.getElementById( 'letterwp-lead' ).textContent = <?php echo wp_json_encode( __( 'One moment…', 'letterwp' ) ); ?>;
					f.hidden = true;
					f.submit();
				}() );
			</script>
			<?php
			self::screen( __( 'Unsubscribe', 'letterwp' ), ob_get_clean() );
		}

		?>
		<p><?php echo esc_html( sprintf( /* translators: 1: site name, 2: email address. */ __( 'You will not get any more emails from %1$s at %2$s.', 'letterwp' ), $site, $row->email ) ); ?></p>
		<form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php self::unsub_fields( 'unsub_undo', $key ); ?>
			<p><?php esc_html_e( 'Clicked by mistake?', 'letterwp' ); ?> <button type="submit"><?php esc_html_e( 'Subscribe again', 'letterwp' ); ?></button></p>
		</form>
		<?php
		self::screen( __( 'You are unsubscribed', 'letterwp' ), ob_get_clean() . $home );
	}

	/** Exits: a key that names nobody (cut short, or the reader was deleted). Signing in still works. */
	private static function dead_unsub() {
		$account = self::account_url();

		self::screen(
			__( 'That link did not work', 'letterwp' ),
			'<p>' . esc_html__( 'This unsubscribe link is not valid. It may have been copied only in part.', 'letterwp' ) . '</p>'
				. ( '' !== $account ? '<p><a href="' . esc_url( $account ) . '">' . esc_html__( 'Sign in with your email to unsubscribe', 'letterwp' ) . '</a></p>' : '' )
				. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( '← Back to the site', 'letterwp' ) . '</a></p>',
			410
		);
	}

	private static function unsub_fields( $action, $key ) {
		?>
		<input type="hidden" name="letterwp_action" value="<?php echo esc_attr( $action ); ?>" />
		<input type="hidden" name="letterwp_key" value="<?php echo esc_attr( $key ); ?>" />
		<?php wp_nonce_field( 'letterwp_unsub', 'letterwp_nonce' ); ?>
		<?php
	}

	/** The POSTs: unsubscribe, or undo it. Then back to the link's page, which shows where things stand. */
	private function do_unsub( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified just below.
		$key   = isset( $_POST['letterwp_key'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_key'] ) ) : '';
		$nonce = isset( $_POST['letterwp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_nonce'] ) ) : '';
		// phpcs:enable

		$row = LetterWP_Subscribers::by_key( $key );

		if ( ! $row ) {
			self::dead_unsub();
		}

		// A POST that did not come from the link's page gets the link's page.
		if ( ! wp_verify_nonce( $nonce, 'letterwp_unsub' ) ) {
			$this->unsub_screen( $key );
		}

		if ( 'unsub' === $action && in_array( $row->status, array( 'active', 'pending' ), true ) ) {
			LetterWP_Subscribers::set_status( $row->id, 'unsubscribed' );
		}

		// The key came in an email to this inbox: that is consent enough, as a signed-in reader's is.
		if ( 'unsub_undo' === $action && in_array( $row->status, array( 'unsubscribed', 'bounced' ), true ) ) {
			LetterWP_Sync::consent( $row->email );
			LetterWP_Subscribers::set_status( $row->id, 'active' );
			self::back( self::unsubscribe_url( $key ), 'resubscribed' );
		}

		self::back( self::unsubscribe_url( $key ) );
	}

	/* ---------------------------------------------------------------------
	 * Tokens.
	 * ------------------------------------------------------------------ */

	private static function transient( $token ) {
		return 'letterwp_link_' . hash( 'sha256', $token );
	}

	/** @return string The token, to put in the link. */
	private static function issue( array $payload ) {
		$token = bin2hex( random_bytes( 32 ) );

		$payload['created_at']    = time();
		$payload['used_count']    = 0;
		$payload['first_used_at'] = 0;

		set_transient( self::transient( $token ), $payload, self::TTL );

		return $token;
	}

	/** @return array|null A token's payload, without spending a use. */
	private static function peek( $token ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $token ) ) {
			return null;
		}

		$payload = get_transient( self::transient( $token ) );

		return is_array( $payload ) ? $payload : null;
	}

	/** @return string Why a token cannot be spent, or '' when it can. */
	private static function check( $payload ) {
		if ( ! $payload ) {
			return 'invalid';
		}

		$now = time();

		if ( (int) $payload['used_count'] >= self::MAX_USES ) {
			return 'used_up';
		}

		if ( $payload['first_used_at'] && $now - (int) $payload['first_used_at'] > self::TTL_AFTER_USE ) {
			return 'expired';
		}

		if ( $now - (int) $payload['created_at'] > self::TTL ) {
			return 'expired';
		}

		return '';
	}

	/** @return array{payload:array|null,reason:string} */
	private static function redeem( $token ) {
		$payload = self::peek( $token );
		$reason  = self::check( $payload );

		if ( '' !== $reason ) {
			return array(
				'payload' => null,
				'reason'  => $reason,
			);
		}

		$now = time();

		++$payload['used_count'];
		$payload['first_used_at'] = $payload['first_used_at'] ? $payload['first_used_at'] : $now;

		// Kept for the rest of its 24 hours, so a spent link still says
		// "expired" rather than "invalid".
		set_transient( self::transient( $token ), $payload, max( 60, self::TTL - ( $now - (int) $payload['created_at'] ) ) );

		return array(
			'payload' => $payload,
			'reason'  => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Rate limits: one bucket per address, one per IP. Signing in empties
	 * the address's bucket, never the IP's, or signing in to one known
	 * address would reset a scan of many unknown ones.
	 * ------------------------------------------------------------------ */

	/** @return string */
	private static function ip() {
		// Behind Cloudflare, REMOTE_ADDR is the edge, not the reader.
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = filter_var( wp_unslash( $_SERVER[ $key ] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( $ip ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/** @return bool False once the bucket is full; counts this request otherwise. */
	private static function rate_ok( $bucket, $id, $max ) {
		$key   = 'letterwp_rl_' . $bucket . '_' . md5( strtolower( $id ) );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return true;
	}

	private static function rate_reset_email( $email ) {
		delete_transient( 'letterwp_rl_em_' . md5( strtolower( $email ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Output.
	 * ------------------------------------------------------------------ */

	/** Exits with a page saying why a link no longer works. */
	private static function dead_link( $reason ) {
		$why = array(
			'invalid' => __( 'This link is not valid. It may have been copied only in part.', 'letterwp' ),
			'expired' => __( 'This link has expired.', 'letterwp' ),
			'used_up' => __( 'This link has already been used.', 'letterwp' ),
		);

		$account = self::account_url();
		$again   = '' !== $account
			? '<p><a href="' . esc_url( $account ) . '">' . esc_html__( 'Get a new link', 'letterwp' ) . '</a></p>'
			: '<p>' . esc_html__( 'Ask for a new one from the page you came from.', 'letterwp' ) . '</p>';

		self::screen(
			__( 'That link did not work', 'letterwp' ),
			'<p>' . esc_html( isset( $why[ $reason ] ) ? $why[ $reason ] : $why['invalid'] ) . '</p>'
				. $again
				. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( '← Back to the site', 'letterwp' ) . '</a></p>',
			410
		);
	}

	/** A small page of its own, outside the theme. Exits. */
	private static function screen( $title, $body, $status = 200 ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		$site = get_bloginfo( 'name' );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<meta name="robots" content="noindex" />
			<title><?php echo esc_html( $title . ' · ' . $site ); ?></title>
			<style>
				body { margin: 0; font: 18px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; background: #fff; }
				main { max-width: 440px; margin: 0 auto; padding: 18vh 24px 48px; }
				.site { margin: 0 0 40px; color: #646970; font-size: 16px; }
				h1 { margin: 0 0 16px; font-size: 32px; line-height: 1.2; letter-spacing: -.01em; }
				p, form { margin: 0 0 16px; }
				main > :last-child { margin-bottom: 0; }
				button { font: inherit; font-size: 16px; font-weight: 600; padding: 10px 20px; border: 0; border-radius: 6px; background: #1d2327; color: #fff; cursor: pointer; }
				button:disabled { opacity: .5; }
				[hidden] { display: none; }
				a { color: inherit; }
			</style>
		</head>
		<body>
			<main>
				<p class="site"><?php echo esc_html( $site ); ?></p>
				<h1><?php echo esc_html( $title ); ?></h1>
				<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by the callers. ?>
			</main>
		</body>
		</html>
		<?php
		exit;
	}

	/** @return string This page's URL, for the form to come back to. */
	public static function here() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return remove_query_arg( self::RESULT_ARG, set_url_scheme( 'http://' . $host . $uri ) );
	}

	/**
	 * The account panel, after the account page's content: signed out, one
	 * email field; signed in, what they get and how to change it.
	 *
	 * @return string
	 */
	public function panel() {
		wp_enqueue_style( 'letterwp-front' );

		$row    = self::current();
		$result = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.

		ob_start();
		?>
		<div class="letterwp-account">
			<?php if ( 'welcome' === $result ) : ?>
				<p class="letterwp-notice"><strong><?php esc_html_e( 'You are subscribed ✓', 'letterwp' ); ?></strong></p>
				<?php self::back_link(); ?>
			<?php endif; ?>

			<?php
			if ( $row ) {
				self::signed_in( $row, $result );
			} else {
				self::signed_out( $result );
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** "← Back to {post}" after subscribing, when we know where they came from. */
	private static function back_link() {
		$from = isset( $_GET[ self::FROM_ARG ] ) ? esc_url_raw( wp_unslash( $_GET[ self::FROM_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$from = '' !== $from ? wp_validate_redirect( $from, '' ) : '';

		if ( '' === $from || untrailingslashit( $from ) === untrailingslashit( self::account_url() ) ) {
			return;
		}

		$post  = url_to_postid( $from );
		$title = $post ? wp_strip_all_tags( get_the_title( $post ) ) : '';
		/* translators: %s: post title. */
		$label = '' !== $title ? sprintf( __( '← Back to “%s”', 'letterwp' ), $title ) : __( '← Back to where you were', 'letterwp' );

		echo '<p><a href="' . esc_url( $from ) . '">' . esc_html( $label ) . '</a></p>';
	}

	private static function signed_in( $row, $result ) {
		$site    = get_bloginfo( 'name' );
		$getting = in_array( $row->status, array( 'active', 'pending' ), true );
		$notes   = array(
			'saved'        => __( 'Saved.', 'letterwp' ),
			'unsubscribed' => __( 'You are unsubscribed. You will not get any more emails.', 'letterwp' ),
			'resubscribed' => __( 'Welcome back. You are subscribed again.', 'letterwp' ),
		);

		if ( isset( $notes[ $result ] ) ) {
			echo '<p class="letterwp-notice" role="status">' . esc_html( $notes[ $result ] ) . '</p>';
		} elseif ( 'no_topics' === $result ) {
			echo '<p class="letterwp-error" role="alert">' . esc_html__( 'Pick at least one, or unsubscribe from all emails below.', 'letterwp' ) . '</p>';
		}
		?>
		<p>
			<?php
			/* translators: %s: email address. */
			printf( esc_html__( 'Signed in as %s', 'letterwp' ), '<strong>' . esc_html( $row->email ) . '</strong>' );
			?>
		</p>

		<?php if ( $getting ) : ?>

			<?php
			$names   = LetterWP_Setup::topic_names();
			LetterWP_Sync::pull_topics( $row ); // They may have changed them on Resend's unsubscribe page.
			$row     = LetterWP_Subscribers::get( $row->id ); // And the names with them.
			$optouts = LetterWP_Subscribers::optouts( array( $row->id ) );
			$optouts = isset( $optouts[ $row->id ] ) ? $optouts[ $row->id ] : array();
			?>

			<form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="letterwp-profile">
				<?php self::account_fields( 'profile' ); ?>
				<div class="letterwp-account__names">
					<p>
						<label for="letterwp-first-name"><?php esc_html_e( 'First name', 'letterwp' ); ?></label>
						<input type="text" id="letterwp-first-name" name="letterwp_first_name" value="<?php echo esc_attr( $row->first_name ); ?>" maxlength="100" autocomplete="given-name" />
					</p>
					<p>
						<label for="letterwp-last-name"><?php esc_html_e( 'Last name', 'letterwp' ); ?></label>
						<input type="text" id="letterwp-last-name" name="letterwp_last_name" value="<?php echo esc_attr( $row->last_name ); ?>" maxlength="100" autocomplete="family-name" />
					</p>
				</div>

				<?php if ( count( $names ) > 1 ) : ?>
					<input type="hidden" name="letterwp_has_topics" value="1" />
					<fieldset>
						<legend>
							<?php
							/* translators: %s: site name. */
							echo esc_html( sprintf( __( 'Emails you get from %s:', 'letterwp' ), $site ) );
							?>
						</legend>
						<?php foreach ( $names as $topic => $name ) : ?>
							<label>
								<input type="checkbox" name="letterwp_topics[]" value="<?php echo esc_attr( $topic ); ?>" <?php checked( ! in_array( (int) $topic, $optouts, true ) ); ?> />
								<?php echo esc_html( $name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php else : ?>
					<?php /* translators: %s: site name. */ ?>
					<p><?php echo esc_html( sprintf( __( 'You get every new post from %s by email.', 'letterwp' ), $site ) ); ?></p>
				<?php endif; ?>

				<p class="letterwp-account__actions"><button type="submit" class="wp-element-button"><?php esc_html_e( 'Save', 'letterwp' ); ?></button><?php self::signout_button(); ?></p>
			</form>

			<?php // The newsletter's one-click link: a plain link the theme styles, and its page has the undo. ?>
			<p class="letterwp-account__actions"><a href="<?php echo esc_url( self::unsubscribe_url( LetterWP_Subscribers::key( $row ) ) ); ?>"><?php esc_html_e( 'Unsubscribe from all emails', 'letterwp' ); ?></a></p>

		<?php else : ?>

			<?php /* translators: %s: site name. */ ?>
			<p><?php echo esc_html( sprintf( __( 'You are not getting emails from %s.', 'letterwp' ), $site ) ); ?></p>
			<form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php self::account_fields( 'resubscribe' ); ?>
				<p class="letterwp-account__actions"><button type="submit" class="wp-element-button"><?php esc_html_e( 'Subscribe again', 'letterwp' ); ?></button><?php self::signout_button(); ?></p>
			</form>

		<?php endif; ?>

		<?php // Empty: Sign out sits beside the main button, and points here with form="". ?>
		<form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>" id="letterwp-signout">
			<input type="hidden" name="letterwp_action" value="signout" />
			<input type="hidden" name="letterwp_redirect" value="<?php echo esc_url( remove_query_arg( self::FROM_ARG, self::here() ) ); ?>" />
			<input type="hidden" name="letterwp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'letterwp_signout' ) ); ?>" />
		</form>
		<?php
	}

	/** Sign out, for the row of the main button. It submits #letterwp-signout, since forms cannot nest. */
	private static function signout_button() {
		echo '<button type="submit" form="letterwp-signout" class="letterwp-link letterwp-signout">' . esc_html__( 'Sign out', 'letterwp' ) . '</button>';
	}

	private static function account_fields( $action ) {
		?>
		<input type="hidden" name="letterwp_action" value="<?php echo esc_attr( $action ); ?>" />
		<input type="hidden" name="letterwp_redirect" value="<?php echo esc_url( remove_query_arg( self::FROM_ARG, self::here() ) ); ?>" />
		<input type="hidden" name="letterwp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'letterwp_account' ) ); ?>" />
		<?php
	}

	private static function signed_out( $result ) {
		if ( 'sent' === $result ) :
			?>
			<p><strong><?php esc_html_e( 'Check your inbox.', 'letterwp' ); ?></strong></p>
			<p><?php esc_html_e( 'Open the link we sent to sign in. It works for 24 hours.', 'letterwp' ); ?></p>
			<?php
			return;
		endif;

		$errors = array(
			'bad_email'  => __( 'That does not look like an email address.', 'letterwp' ),
			'limited'    => __( 'Too many links asked for. Try again in a few hours.', 'letterwp' ),
			'unsendable' => __( 'This site cannot send email right now. Please try again later.', 'letterwp' ),
		);
		if ( isset( $errors[ $result ] ) ) {
			echo '<p class="letterwp-error" role="alert">' . esc_html( $errors[ $result ] ) . '</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="letterwp-signin">
			<input type="hidden" name="letterwp_action" value="request" />
			<input type="hidden" name="letterwp_redirect" value="<?php echo esc_url( self::here() ); ?>" />
			<p>
				<label for="letterwp-email"><?php esc_html_e( 'Enter your email and we will send you a link to sign in. Not subscribed yet? The same link subscribes you.', 'letterwp' ); ?></label>
			</p>
			<p class="letterwp-row">
				<input type="email" id="letterwp-email" name="letterwp_email" required autocomplete="email" placeholder="you@example.com" class="letterwp-input" />
				<button type="submit" class="wp-element-button"><span><?php esc_html_e( 'Send me a link', 'letterwp' ); ?></span><?php echo LetterWP_Subscribe::icon(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup. ?></button>
			</p>
			<p class="letterwp-hp" aria-hidden="true">
				<input type="text" name="letterwp_website" tabindex="-1" autocomplete="off" />
			</p>
		</form>
		<?php
	}
}
