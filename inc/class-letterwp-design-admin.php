<?php
/**
 * LetterWP > Email Design: options on the left, a live preview on the
 * right.
 *
 * The preview is the real email, built by LetterWP_Email from a real post
 * with the unsaved options laid over the saved ones, so what shows is what
 * subscribers get. Desktop and Mobile switch the preview's width, and the
 * email's own phone styles then apply.
 *
 * Send test email mails that same email, unsaved options and all, to one
 * inbox through Resend, to check it in a real mail client before saving.
 *
 * Saving posts through LetterWP_Admin, like every form on the LetterWP
 * screen.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Design_Admin {

	const TAB = 'email-design';

	const PREVIEW = 'letterwp_design_preview';

	const TEST = 'letterwp_design_test';

	/** One nonce for both: the same screen, the same user. */
	const NONCE = 'letterwp_design';

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::PREVIEW, array( $this, 'preview' ) );
		add_action( 'wp_ajax_' . self::TEST, array( $this, 'test' ) );
	}

	/** @return bool On this tab. */
	private static function is_screen() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only check.
		return isset( $_GET['page'], $_GET['tab'] ) && LetterWP_Admin::SLUG === $_GET['page'] && self::TAB === $_GET['tab'];
		// phpcs:enable
	}

	/* ---------------------------------------------------------------------
	 * Save, from LetterWP_Admin::handle_post().
	 * ------------------------------------------------------------------ */

	/** @return string What was done, for the notice. */
	public static function save( $reset ) {
		if ( $reset ) {
			LetterWP_Email_Design::reset();
			return __( 'Email Design reset to the defaults.', 'letterwp' );
		}

		$input = isset( $_POST['letterwp_design'] ) && is_array( $_POST['letterwp_design'] ) ? wp_unslash( $_POST['letterwp_design'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- checked in handle_post(), sanitized by LetterWP_Email_Design.
		LetterWP_Email_Design::update( $input );

		return __( 'Email Design saved. Every newsletter from now on uses it.', 'letterwp' );
	}

	/* ---------------------------------------------------------------------
	 * Preview: the form's unsaved values in, the email out.
	 * ------------------------------------------------------------------ */

	public function preview() {
		$email = self::requested_email( 'preview' );

		wp_send_json_success(
			array(
				'html'      => $email['html'],
				'subject'   => $email['subject'],
				'preheader' => $email['preheader'],
			)
		);
	}

	/** The same email, to one inbox, through Resend. */
	public function test() {
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in requested_email().

		$email = self::requested_email( 'test' );
		$from  = LetterWP_Addresses::get( 'nl' );

		if ( '' === LetterWP_Resend::key() || '' === $from ) {
			wp_send_json_error( array( 'message' => __( 'Connect Resend and set the newsletter address in Setup first.', 'letterwp' ) ), 400 );
		}

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'Type the email address to send the test to.', 'letterwp' ) ), 400 );
		}

		$id = LetterWP_Resend::send_email(
			array(
				'from'    => $from,
				'to'      => $to,
				/* translators: %s: email subject. */
				'subject' => sprintf( __( '[Test] %s', 'letterwp' ), $email['subject'] ),
				'html'    => $email['html'],
				'text'    => $email['text'],
			)
		);

		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				/* translators: %s: email address. */
				'message' => sprintf( __( 'Sent to %s. Check the inbox, and the spam folder too.', 'letterwp' ), $to ),
				'url'     => LetterWP_Resend::email_url( $id ),
			)
		);
	}

	/**
	 * The email for the post and unsaved options the screen sent. Ends the
	 * request with an error when they do not add up.
	 *
	 * @param string $mode preview or test.
	 * @return array LetterWP_Email::build()'s.
	 */
	private static function requested_email( $mode ) {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'letterwp' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$input = isset( $_POST['letterwp_design'] ) && is_array( $_POST['letterwp_design'] ) ? wp_unslash( $_POST['letterwp_design'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by LetterWP_Email_Design.
		$id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		// phpcs:enable

		$post = $id ? get_post( $id ) : null;

		if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'read_post', $post->ID ) ) {
			wp_send_json_error( array( 'message' => __( 'Pick a post to preview with.', 'letterwp' ) ), 400 );
		}

		return LetterWP_Email::build(
			$post,
			array(
				'mode'   => $mode,
				'design' => $input,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Assets.
	 * ------------------------------------------------------------------ */

	public function enqueue() {
		if ( ! self::is_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'letterwp-design', LETTERWP_URL . 'assets/email-design.js', array(), LETTERWP_VERSION, true );
		wp_add_inline_script(
			'letterwp-design',
			'window.letterwpDesign = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'preview' => self::PREVIEW,
					'test'    => self::TEST,
					'nonce'   => wp_create_nonce( self::NONCE ),
					'i18n'    => array(
						'pickLogo'  => __( 'Choose a logo', 'letterwp' ),
						'useLogo'   => __( 'Use this image', 'letterwp' ),
						'failed'    => __( 'The preview could not be built.', 'letterwp' ),
						'reset'     => __( 'Reset every option to the default? This cannot be undone.', 'letterwp' ),
						'leave'     => __( 'You have unsaved changes.', 'letterwp' ),
						'sending'   => __( 'Sending…', 'letterwp' ),
						'seeIt'     => __( 'See it at Resend.com ↗', 'letterwp' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/* ---------------------------------------------------------------------
	 * Render.
	 * ------------------------------------------------------------------ */

	public static function render() {
		$t     = LetterWP_Email_Design::get();
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'no_found_rows'  => true,
			)
		);
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$from  = LetterWP_Addresses::get( 'nl' );
		$can   = '' !== LetterWP_Resend::key() && '' !== $from;

		self::css();
		?>
		<p class="letterwp-design-intro"><?php esc_html_e( 'How every newsletter looks in the inbox. The preview on the right is the real email, built from one of your posts.', 'letterwp' ); ?></p>

		<div class="letterwp-design" data-device="desktop">

			<form id="letterwp-design-form" class="letterwp-design-options" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( LetterWP_Admin::ACTION ); ?>" />
				<?php wp_nonce_field( LetterWP_Admin::ACTION ); ?>

				<?php self::section_open( __( 'Header', 'letterwp' ) ); ?>
					<?php
					$logo = $t['logo'] ? wp_get_attachment_image_url( $t['logo'], 'medium' ) : '';
					?>
					<div class="letterwp-field">
						<span class="letterwp-label"><?php esc_html_e( 'Logo', 'letterwp' ); ?></span>
						<div class="letterwp-logo <?php echo $logo ? 'has-logo' : ''; ?>">
							<input type="hidden" name="letterwp_design[logo]" value="<?php echo esc_attr( $t['logo'] ); ?>" />
							<button type="button" class="letterwp-logo-pick">
								<img src="<?php echo esc_url( $logo ); ?>" alt="" />
								<span><?php esc_html_e( 'Choose a logo', 'letterwp' ); ?></span>
							</button>
							<button type="button" class="button-link button-link-delete letterwp-logo-remove"><?php esc_html_e( 'Remove', 'letterwp' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'Without one, the site icon shows. A PNG or JPG: Gmail and Outlook do not show SVG.', 'letterwp' ); ?></p>
					</div>
					<?php self::range_field( 'logo_width', __( 'Logo width', 'letterwp' ), $t, 40, LetterWP_Email::WIDTH - 2 * LetterWP_Email::PAD, 'letterwp-needs-logo' ); ?>
					<?php self::check_field( 'site_name', __( 'Show the site name', 'letterwp' ), $t ); ?>
				<?php self::section_close(); ?>

				<?php self::section_open( __( 'Color', 'letterwp' ) ); ?>
					<?php self::color_field( 'brand_color', __( 'Brand color', 'letterwp' ), $t ); ?>
					<p class="description"><?php esc_html_e( 'Links, categories and buttons: the Read more button and any Buttons block in a post.', 'letterwp' ); ?></p>
				<?php self::section_close(); ?>

				<?php self::section_open( __( 'Footer', 'letterwp' ) ); ?>
					<label class="letterwp-check-field">
						<input type="checkbox" checked disabled />
						<?php esc_html_e( 'Show "Newsletter easily with LetterWP"', 'letterwp' ); ?>
					</label>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to LetterWP Pro. */
							esc_html__( 'A small line under the unsubscribe link, linking to letterwp.com. It keeps LetterWP free. %s removes it.', 'letterwp' ),
							'<a href="https://letterwp.com/pricing/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'LetterWP Pro', 'letterwp' ) . '</a>'
						);
						?>
					</p>
				<?php self::section_close(); ?>

				<?php
				/**
				 * Fires inside the Email Design form, after LetterWP's own
				 * options. Fields named letterwp_design[key] save with them.
				 *
				 * @param array $t The current design.
				 */
				do_action( 'letterwp_email_design_fields', $t );
				?>

				<div class="letterwp-design-save">
					<button type="submit" class="button button-primary" name="letterwp_do" value="email_design"><?php esc_html_e( 'Save design', 'letterwp' ); ?></button>
					<span class="letterwp-dirty" hidden><?php esc_html_e( 'Unsaved changes', 'letterwp' ); ?></span>
					<button type="submit" class="button-link letterwp-design-reset" name="letterwp_do" value="email_design_reset" formnovalidate><?php esc_html_e( 'Reset to defaults', 'letterwp' ); ?></button>
				</div>
			</form>

			<div class="letterwp-design-preview">
				<div class="letterwp-preview-bar">
					<div class="letterwp-devices" role="tablist" aria-label="<?php esc_attr_e( 'Preview size', 'letterwp' ); ?>">
						<button type="button" role="tab" aria-selected="true" data-device="desktop"><span class="dashicons dashicons-desktop" aria-hidden="true"></span> <?php esc_html_e( 'Desktop', 'letterwp' ); ?></button>
						<button type="button" role="tab" aria-selected="false" data-device="mobile"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span> <?php esc_html_e( 'Mobile', 'letterwp' ); ?></button>
					</div>
					<?php if ( $posts ) : ?>
						<label class="letterwp-preview-post">
							<span><?php esc_html_e( 'Post', 'letterwp' ); ?></span>
							<select id="letterwp-preview-post">
								<?php foreach ( $posts as $post ) : ?>
									<option value="<?php echo esc_attr( $post->ID ); ?>"><?php echo esc_html( wp_trim_words( get_the_title( $post ), 8 ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<span class="letterwp-preview-status" aria-live="polite"></span>
				</div>

				<?php if ( $posts ) : ?>
					<div class="letterwp-test">
						<?php if ( $can ) : ?>
							<label for="letterwp-test-to" class="screen-reader-text"><?php esc_html_e( 'Send the test to', 'letterwp' ); ?></label>
							<input type="email" id="letterwp-test-to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" spellcheck="false" />
							<button type="button" class="button letterwp-test-send"><?php esc_html_e( 'Send test email', 'letterwp' ); ?></button>
							<span class="letterwp-test-result" aria-live="polite"></span>
						<?php else : ?>
							<span class="description">
								<?php
								printf(
									/* translators: %s: link to the Setup tab. */
									esc_html__( 'To send a test email, %s first.', 'letterwp' ),
									'<a href="' . esc_url( LetterWP_Admin::url( 'setup', array(), 'letterwp-resend' ) ) . '">' . esc_html__( 'connect Resend and set the newsletter address', 'letterwp' ) . '</a>'
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $posts ) : ?>
					<div class="letterwp-screen">
						<div class="letterwp-inbox">
							<span class="letterwp-avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $site, 0, 1 ) ) ); ?></span>
							<div>
								<div class="letterwp-inbox-from">
									<strong><?php echo esc_html( $site ); ?></strong>
									<?php if ( '' !== $from ) : ?>
										<span>&lt;<?php echo esc_html( $from ); ?>&gt;</span>
									<?php endif; ?>
								</div>
								<div class="letterwp-inbox-subject"></div>
								<div class="letterwp-inbox-preheader"></div>
							</div>
						</div>
						<iframe class="letterwp-frame" title="<?php esc_attr_e( 'Email preview', 'letterwp' ); ?>" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe>
					</div>
				<?php else : ?>
					<p class="letterwp-screen letterwp-no-posts"><?php esc_html_e( 'Publish a post to see the preview: it is built from a real one.', 'letterwp' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* -- Fields ----------------------------------------------------------- */

	private static function section_open( $title ) {
		echo '<fieldset class="letterwp-section"><legend>' . esc_html( $title ) . '</legend>';
	}

	private static function section_close() {
		echo '</fieldset>';
	}

	private static function range_field( $key, $label, array $t, $min, $max, $class = '' ) {
		?>
		<div class="letterwp-field letterwp-range <?php echo esc_attr( $class ); ?>">
			<label class="letterwp-label" for="letterwp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="range" id="letterwp-<?php echo esc_attr( $key ); ?>" name="letterwp_design[<?php echo esc_attr( $key ); ?>]" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" value="<?php echo (int) $t[ $key ]; ?>" />
			<output><?php echo (int) $t[ $key ]; ?>px</output>
		</div>
		<?php
	}

	private static function color_field( $key, $label, array $t ) {
		?>
		<div class="letterwp-field letterwp-color">
			<label class="letterwp-label" for="letterwp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="color" id="letterwp-<?php echo esc_attr( $key ); ?>" name="letterwp_design[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $t[ $key ] ); ?>" />
			<code><?php echo esc_html( $t[ $key ] ); ?></code>
		</div>
		<?php
	}

	private static function check_field( $key, $label, array $t ) {
		?>
		<label class="letterwp-check-field">
			<input type="hidden" name="letterwp_design[<?php echo esc_attr( $key ); ?>]" value="0" />
			<input type="checkbox" name="letterwp_design[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $t[ $key ] ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	/* -- Styles: one screen, inline, like the rest of LetterWP's screen. -- */

	private static function css() {
		?>
		<style>
			.letterwp-design-intro { max-width: 720px; }
			.letterwp-design { display: grid; grid-template-columns: minmax(300px, 360px) minmax(0, 1fr); gap: 24px; align-items: start; margin-top: 16px; }
			.letterwp-design-options { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
			.letterwp-section { margin: 0; padding: 14px 18px 6px; border: 0; border-bottom: 1px solid #f0f0f1; min-width: 0; }
			.letterwp-section legend { float: left; width: 100%; margin: 0 0 10px; padding: 0; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #646970; }
			.letterwp-section legend + * { clear: left; }
			.letterwp-field { margin: 0 0 12px; }
			.letterwp-label { display: flex; align-items: center; gap: 4px; margin: 0 0 4px; font-weight: 500; }
			.letterwp-label .dashicons { font-size: 15px; width: 15px; height: 15px; color: #8c8f94; }
			.letterwp-field select, .letterwp-field .regular-text { width: 100%; max-width: none; }
			.letterwp-range { display: grid; grid-template-columns: 1fr 48px; align-items: center; column-gap: 10px; }
			.letterwp-range .letterwp-label { grid-column: 1 / -1; }
			.letterwp-range input[type="range"] { width: 100%; margin: 0; }
			.letterwp-range output { font-variant-numeric: tabular-nums; color: #50575e; text-align: right; }
			.letterwp-color { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; }
			.letterwp-color .letterwp-label { margin: 0; }
			.letterwp-color input[type="color"] { width: 36px; height: 28px; padding: 2px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff; cursor: pointer; }
			.letterwp-color code { width: 64px; padding: 2px 4px; font-size: 12px; text-align: center; }
			.letterwp-check-field { display: block; margin: 0 0 10px; }
			.letterwp-logo { display: flex; align-items: center; gap: 12px; }
			.letterwp-logo-pick { display: flex; align-items: center; justify-content: center; min-width: 120px; min-height: 56px; max-width: 200px; padding: 8px; background: #f6f7f7; border: 1px dashed #c3c4c7; border-radius: 4px; color: #2271b1; cursor: pointer; }
			.letterwp-logo-pick:hover { border-color: #2271b1; }
			.letterwp-logo-pick img { display: none; max-width: 100%; max-height: 64px; }
			.letterwp-logo.has-logo .letterwp-logo-pick { border-style: solid; background: #fff; }
			.letterwp-logo.has-logo .letterwp-logo-pick img { display: block; }
			.letterwp-logo.has-logo .letterwp-logo-pick span, .letterwp-logo:not(.has-logo) .letterwp-logo-remove { display: none; }
			.letterwp-design:not(.has-logo) .letterwp-needs-logo { display: none; }
			.letterwp-design-save { position: sticky; bottom: 0; display: flex; align-items: center; gap: 12px; padding: 12px 18px; background: #fff; border-radius: 0 0 4px 4px; box-shadow: 0 -1px 0 #f0f0f1; }
			.letterwp-dirty, .letterwp-design-reset { white-space: nowrap; }
			.letterwp-dirty { color: #996800; font-size: 12px; }
			.letterwp-design-reset { margin-left: auto !important; color: #646970 !important; }
			.letterwp-design-preview { position: sticky; top: 46px; min-width: 0; }
			.letterwp-preview-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 0 0 12px; }
			.letterwp-devices { display: inline-flex; padding: 2px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
			.letterwp-devices button { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; background: none; border: 0; border-radius: 2px; color: #50575e; cursor: pointer; font-size: 13px; line-height: 20px; }
			.letterwp-devices button .dashicons { font-size: 16px; width: 16px; height: 16px; }
			.letterwp-devices button[aria-selected="true"] { background: #1d2327; color: #fff; }
			.letterwp-preview-post { display: inline-flex; align-items: center; gap: 6px; }
			.letterwp-preview-post select { max-width: 280px; }
			.letterwp-preview-status { color: #646970; font-size: 12px; }
			.letterwp-test { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0 0 12px; }
			.letterwp-test input[type="email"] { width: 260px; max-width: 100%; }
			.letterwp-test-result { font-size: 12px; color: #646970; }
			.letterwp-test-result.is-error { color: #d63638; }
			.letterwp-test-result.is-sent { color: #008a20; }
			.letterwp-screen { display: flex; flex-direction: column; height: calc(100vh - 170px); min-height: 480px; margin: 0 auto; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; overflow: hidden; transition: max-width .2s ease; }
			.letterwp-design[data-device="mobile"] .letterwp-screen { max-width: 390px; max-height: 800px; border: 10px solid #1d2327; border-radius: 36px; }
			.letterwp-inbox { display: flex; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #f0f0f1; background: #fbfbfc; }
			.letterwp-avatar { flex: none; width: 36px; height: 36px; border-radius: 50%; background: #1d2327; color: #fff; font-weight: 600; line-height: 36px; text-align: center; }
			.letterwp-inbox > div { min-width: 0; }
			.letterwp-inbox-from span { color: #646970; }
			.letterwp-inbox-subject { font-weight: 600; color: #1d2327; }
			.letterwp-inbox-from, .letterwp-inbox-subject, .letterwp-inbox-preheader { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
			.letterwp-inbox-preheader { color: #646970; font-size: 12px; }
			.letterwp-frame { flex: 1; width: 100%; border: 0; background: #fff; }
			.letterwp-no-posts { align-items: center; justify-content: center; height: 240px; min-height: 0; color: #646970; }
			@media (max-width: 1100px) {
				.letterwp-design { grid-template-columns: 1fr; }
				.letterwp-design-preview { position: static; }
				.letterwp-design-options { max-width: 560px; }
			}
		</style>
		<?php
	}
}
