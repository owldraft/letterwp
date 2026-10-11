<?php
/**
 * The subscribe form: a block (letterwp/subscribe) and [letterwp_subscribe].
 *
 * It takes only an email. Pages that carry it stay cacheable: no nonce, and
 * it posts to a REST route with fetch(), or, without JavaScript, to the home
 * URL like every other LetterWP form. Honeypot and the sign-in rate limits
 * stand in for a nonce.
 *
 * The answer is always "check your inbox", so the form cannot tell anyone
 * who is subscribed. What the email says depends on who they are:
 *
 *   new           a pending row, and "Confirm your subscription"
 *   pending       "Confirm your subscription" again
 *   unsubscribed  "Confirm your subscription": opening it brings them back
 *   bounced       the same
 *   active        "You are already subscribed", with a sign-in link
 *
 * The link is LetterWP_Login's magic link, so confirming also signs the
 * reader in, and lands them on the account page to pick topics.
 *
 * A signed-in reader still sees the form, but disabled with their email in
 * it, and a small "Manage your subscription" link to the account page
 * after the small print. A page with the form is usually cached,
 * so that happens in the browser: when the HINT cookie says someone is
 * signed in, subscribe.js asks /letterwp/v1/me (never cached) and fills the
 * form in. A page rendered fresh for a signed-in reader comes filled in.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Subscribe {

	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
	}

	public function register() {
		wp_register_style( 'letterwp-front', LETTERWP_URL . 'assets/front.css', array(), LETTERWP_VERSION );
		wp_register_script(
			'letterwp-subscribe',
			LETTERWP_URL . 'assets/subscribe.js',
			array(),
			LETTERWP_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		register_block_type( LETTERWP_DIR . 'blocks/subscribe', array( 'render_callback' => array( __CLASS__, 'render_block' ) ) );
		add_shortcode( 'letterwp_subscribe', array( __CLASS__, 'shortcode' ) );
	}

	public function rest() {
		register_rest_route(
			'letterwp/v1',
			'/subscribe',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_subscribe' ),
			)
		);

		register_rest_route(
			'letterwp/v1',
			'/me',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_me' ),
			)
		);
	}

	/** @return WP_REST_Response Who is signed in, and the link to show under their disabled form. */
	public static function rest_me() {
		$row      = LetterWP_Login::current();
		$response = new WP_REST_Response(
			$row
				? array(
					'signed_in' => true,
					'email'     => $row->email,
					'html'      => self::status_html( $row ),
				)
				: array( 'signed_in' => false )
		);

		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/** Without JavaScript: the form posts to the home URL, and comes back with ?letterwp=sub-… */
	public function route() {
		// phpcs:disable WordPress.Security.NonceVerification -- open to anyone, like the REST route.
		if ( ! isset( $_POST['letterwp_action'] ) || 'subscribe' !== sanitize_key( wp_unslash( $_POST['letterwp_action'] ) ) ) {
			return;
		}

		$from   = self::clean_from( isset( $_POST['letterwp_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['letterwp_redirect'] ) ) : '' );
		$result = self::subscribe(
			isset( $_POST['letterwp_email'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_email'] ) ) : '',
			! empty( $_POST['letterwp_website'] ),
			$from
		);
		// phpcs:enable

		wp_safe_redirect( add_query_arg( LetterWP_Login::RESULT_ARG, 'sub-' . $result, $from ) );
		exit;
	}

	/** @return WP_REST_Response */
	public static function rest_subscribe( WP_REST_Request $request ) {
		$result = self::subscribe(
			(string) $request->get_param( 'email' ),
			'' !== (string) $request->get_param( 'website' ),
			self::clean_from( (string) $request->get_param( 'page' ) )
		);
		$codes  = array(
			'sent'       => 200,
			'bad_email'  => 400,
			'limited'    => 429,
			'unsendable' => 503,
		);

		return new WP_REST_Response(
			array(
				'result'  => $result,
				'message' => self::message( $result ),
			),
			$codes[ $result ]
		);
	}

	/** @return string A page on this site, without our own result args. */
	private static function clean_from( $url ) {
		$url = wp_validate_redirect( esc_url_raw( (string) $url ), home_url( '/' ) );

		return remove_query_arg( array( LetterWP_Login::RESULT_ARG, LetterWP_Login::FROM_ARG, LetterWP_Login::LINK_ARG ), $url );
	}

	/* ---------------------------------------------------------------------
	 * The process.
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $email    As typed.
	 * @param bool   $honeypot The hidden field was filled: a bot.
	 * @param string $from     The page they subscribed on.
	 * @return string sent, bad_email, limited or unsendable.
	 */
	public static function subscribe( $email, $honeypot, $from ) {
		// A bot is told it worked.
		if ( $honeypot ) {
			return 'sent';
		}

		$email = LetterWP_Subscribers::clean_email( $email );

		if ( '' === $email ) {
			return 'bad_email';
		}

		// Before any row is made: a site that cannot send would collect
		// pending readers who can never confirm.
		if ( '' === LetterWP_Login::sender() ) {
			return 'unsendable';
		}

		if ( ! LetterWP_Login::allow( $email ) ) {
			return 'limited';
		}

		$row = LetterWP_Subscribers::get_by_email( $email );

		if ( ! $row ) {
			$id  = LetterWP_Subscribers::add( $email, 'pending', 'form' );
			$row = is_wp_error( $id ) ? LetterWP_Subscribers::get_by_email( $email ) : LetterWP_Subscribers::get( $id );
		}

		if ( ! $row ) {
			return 'unsendable';
		}

		$sent = LetterWP_Login::send_magic( $row, 'active' === $row->status ? 'already' : 'subscribe', $from );

		return is_wp_error( $sent ) ? 'unsendable' : 'sent';
	}

	/** @return string What the reader is told. */
	public static function message( $result ) {
		$messages = array(
			'sent'       => __( 'Almost there. Check your inbox and tap the link to confirm.', 'letterwp' ),
			'confirmed'  => __( 'You are subscribed.', 'letterwp' ),
			'bad_email'  => __( 'That does not look like an email address.', 'letterwp' ),
			'limited'    => __( 'Too many tries. Please try again in a few hours.', 'letterwp' ),
			'unsendable' => __( 'This site cannot send email right now. Please try again later.', 'letterwp' ),
		);

		return isset( $messages[ $result ] ) ? $messages[ $result ] : '';
	}

	/* ---------------------------------------------------------------------
	 * Output.
	 * ------------------------------------------------------------------ */

	/**
	 * The small line under a signed-in reader's form: a link to the account
	 * page. Their email is already in the field, so it says nothing else.
	 *
	 * @return string '' when there is no account page.
	 */
	public static function status_html( $row ) {
		$account = LetterWP_Login::account_url();
		$link    = in_array( $row->status, array( 'active', 'pending' ), true )
			? __( 'Manage your subscription', 'letterwp' )
			: __( 'Subscribe again', 'letterwp' );

		return '' !== $account ? '<a href="' . esc_url( $account ) . '">' . esc_html( $link ) . '</a>' : '';
	}

	/** @return string */
	public static function render_block( $attributes ) {
		$size = isset( $attributes['size'] ) && in_array( $attributes['size'], array( 'small', 'large' ), true ) ? $attributes['size'] : 'normal';

		// Form styling travels as CSS variables, so front.css stays the one place that draws it.
		$vars = array();
		if ( isset( $attributes['radius'] ) && is_numeric( $attributes['radius'] ) ) {
			$vars[] = '--letterwp-radius:' . min( 40, max( 0, (int) $attributes['radius'] ) ) . 'px';
		}
		if ( isset( $attributes['maxWidth'] ) && is_numeric( $attributes['maxWidth'] ) && $attributes['maxWidth'] > 0 ) {
			$vars[] = 'max-width:' . min( 2000, max( 120, (int) $attributes['maxWidth'] ) ) . 'px';
		}
		foreach ( array( 'buttonBackground' => '--letterwp-button-bg', 'buttonColor' => '--letterwp-button-color' ) as $key => $var ) {
			$color = isset( $attributes[ $key ] ) ? self::color( $attributes[ $key ] ) : '';
			if ( '' !== $color ) {
				$vars[] = $var . ':' . $color;
			}
		}

		$extra = array( 'class' => 'letterwp-subscribe letterwp-size-' . $size );
		if ( $vars ) {
			$extra['style'] = implode( ';', $vars ) . ';';
		}

		return self::form(
			array(
				'heading' => isset( $attributes['heading'] ) ? $attributes['heading'] : '',
				'level'   => isset( $attributes['headingLevel'] ) ? $attributes['headingLevel'] : 2,
				'text'    => isset( $attributes['text'] ) ? $attributes['text'] : '',
				'note'    => isset( $attributes['note'] ) ? $attributes['note'] : '',
				'button'  => isset( $attributes['button'] ) ? $attributes['button'] : '',
				'wrapper' => get_block_wrapper_attributes( $extra ),
			)
		);
	}

	/**
	 * A colour from the block's colour picker: hex, rgb()/rgba() or a var(--…), else ''.
	 *
	 * @return string
	 */
	private static function color( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([0-9\s.,%]+\)|var\(--[a-z0-9_-]+\))$/i', $value ) ? $value : '';
	}

	/**
	 * The paper plane on the form's button.
	 *
	 * @return string
	 */
	public static function icon() {
		return '<svg class="letterwp-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>';
	}

	/**
	 * [letterwp_subscribe heading="Get the letter" level="2" text="A line above the form." note="Small print below it." button="Join"]
	 *
	 * @return string
	 */
	public static function shortcode( $atts ) {
		return self::form( shortcode_atts( array( 'heading' => '', 'level' => 2, 'text' => '', 'note' => '', 'button' => '' ), $atts, 'letterwp_subscribe' ) );
	}

	/**
	 * @param array $args heading, level (1-6, the heading's tag, default 2), text (a line above), note (small print below, for consent and legal
	 *                    links), button, wrapper (attributes for the outer div). Text and note may hold links.
	 * @return string
	 */
	public static function form( array $args = array() ) {
		static $n = 0;
		++$n;

		wp_enqueue_style( 'letterwp-front' );
		wp_enqueue_script( 'letterwp-subscribe' );

		$heading = isset( $args['heading'] ) ? trim( (string) $args['heading'] ) : '';
		$level   = isset( $args['level'] ) ? min( 6, max( 1, (int) $args['level'] ) ) : 2;
		$text    = isset( $args['text'] ) ? trim( (string) $args['text'] ) : '';
		$note    = isset( $args['note'] ) ? trim( (string) $args['note'] ) : '';
		$button  = isset( $args['button'] ) ? trim( wp_strip_all_tags( (string) $args['button'] ) ) : '';
		$button  = '' !== $button ? $button : __( 'Subscribe', 'letterwp' );
		$wrapper = isset( $args['wrapper'] ) ? $args['wrapper'] : 'class="letterwp-subscribe"';
		$result  = isset( $_GET[ LetterWP_Login::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ LetterWP_Login::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$result  = 0 === strpos( $result, 'sub-' ) ? substr( $result, 4 ) : '';
		$row     = LetterWP_Login::current();
		$result  = $row ? '' : $result;
		$done    = in_array( $result, array( 'sent', 'confirmed' ), true );
		$id      = 'letterwp-subscribe-' . $n;

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput -- from get_block_wrapper_attributes() or fixed. ?> data-me="<?php echo esc_url( rest_url( 'letterwp/v1/me' ) ); ?>">
			<?php if ( '' !== $heading ) : ?>
				<h<?php echo (int) $level; ?> class="letterwp-subscribe__heading"><?php echo wp_kses_post( $heading ); ?></h<?php echo (int) $level; ?>>
			<?php endif; ?>

			<?php if ( '' !== $text ) : ?>
				<p class="letterwp-subscribe__text"><?php echo wp_kses_post( $text ); ?></p>
			<?php endif; ?>

			<p class="letterwp-subscribe__message<?php echo $done || '' === $result ? '' : ' letterwp-error'; ?>" role="status" aria-live="polite"<?php echo '' === $result ? ' hidden' : ''; ?>><?php echo esc_html( self::message( $result ) ); ?></p>

			<?php if ( ! $done ) : ?>
				<form class="letterwp-subscribe__form letterwp-row" method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>" data-endpoint="<?php echo esc_url( rest_url( 'letterwp/v1/subscribe' ) ); ?>">
					<input type="hidden" name="letterwp_action" value="subscribe" />
					<input type="hidden" name="letterwp_redirect" value="<?php echo esc_url( LetterWP_Login::here() ); ?>" />
					<input type="email" id="<?php echo esc_attr( $id ); ?>" name="letterwp_email" required autocomplete="email" placeholder="<?php esc_attr_e( 'you@example.com', 'letterwp' ); ?>" aria-label="<?php esc_attr_e( 'Email address', 'letterwp' ); ?>" class="letterwp-input"<?php echo $row ? ' value="' . esc_attr( $row->email ) . '" disabled' : ''; ?> />
					<span class="letterwp-hp" aria-hidden="true"><input type="text" name="letterwp_website" tabindex="-1" autocomplete="off" /></span>
					<button type="submit" class="wp-element-button"<?php echo $row ? ' disabled' : ''; ?>><span><?php echo esc_html( $button ); ?></span><?php echo self::icon(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup. ?></button>
				</form>

				<?php if ( '' !== $note ) : ?>
					<p class="letterwp-subscribe__note"><?php echo wp_kses_post( $note ); ?></p>
				<?php endif; ?>

				<?php $status = $row ? self::status_html( $row ) : ''; ?>
				<p class="letterwp-subscribe__status"<?php echo '' === $status ? ' hidden' : ''; ?>><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
