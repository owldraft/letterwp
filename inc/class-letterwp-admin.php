<?php
/**
 * The LetterWP admin screen, in tabs. Only inputs are stored: everything
 * Resend knows (domains, DNS records, status) is read live through the key.
 *
 * Overview (the default): what a returning owner wants. A checklist while
 * anything is left to do, then subscriber counts and the last newsletters.
 * Its checks are local, plus one Resend call; the tabs below hold the truth.
 *
 * Setup, once, in steps; each depends only on the steps above it:
 *   1. Resend key: create (connect), read (masked), delete (disconnect).
 *      Never update: to change keys, disconnect and connect again.
 *   2. Domains: list, add, view, update. Deleting stays in Resend's dashboard.
 *   3. Your list in Resend: one segment and a topic per category
 *      (LetterWP_Setup), and the webhook that brings bounces, complaints and
 *      unsubscribes back (LetterWP_Webhook).
 *   4. Sender addresses: stored on their own, independent of any sending
 *      tool. Each shows a live signal of whether Resend can send from it.
 *      The postal address for the newsletter footer lives here too.
 *   5. Test email: one real email, from either address, to any inbox.
 *   6. Account page: where readers sign in.
 *
 * Email Design: how every newsletter looks, with a live preview
 * (LetterWP_Design_Admin).
 *
 * There was a Settings tab for 4-6; old links to it (tab=settings) open Setup.
 *
 * Every form posts to admin-post.php and redirects back, so a refresh does
 * not resubmit.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Admin {

	const SLUG = 'letterwp';

	const ACTION = 'letterwp_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_post' ) );
		add_filter( 'submenu_file', array( $this, 'submenu_file' ) );
	}

	public function admin_menu() {
		add_menu_page(
			__( 'LetterWP', 'letterwp' ),
			__( 'LetterWP', 'letterwp' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-email-alt',
			26
		);

		// The first item names the default tab, not the menu again.
		add_submenu_page( self::SLUG, __( 'LetterWP', 'letterwp' ), __( 'Overview', 'letterwp' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		add_submenu_page( self::SLUG, __( 'LetterWP Setup', 'letterwp' ), __( 'Setup', 'letterwp' ), 'manage_options', self::menu_url( 'setup' ) );
		add_submenu_page( self::SLUG, __( 'LetterWP Email Design', 'letterwp' ), __( 'Email Design', 'letterwp' ), 'manage_options', self::menu_url( LetterWP_Design_Admin::TAB ) );
	}

	/** @return string A tab as a menu entry: relative, as WordPress expects. */
	private static function menu_url( $tab ) {
		return 'admin.php?page=' . self::SLUG . '&tab=' . $tab;
	}

	/** On a tab with its own menu entry, that entry is the current one, not Overview. */
	public function submenu_file( $file ) {
		global $plugin_page;

		return self::SLUG === $plugin_page && 'overview' !== self::tab() ? self::menu_url( self::tab() ) : $file;
	}

	/** @return array<string,string> slug => label, in order. */
	private static function tabs() {
		return array(
			'overview' => __( 'Overview', 'letterwp' ),
			'setup'    => __( 'Setup', 'letterwp' ),
			LetterWP_Design_Admin::TAB => __( 'Email Design', 'letterwp' ),
		);
	}

	/** @return string The tab asked for, Overview when none or unknown. */
	private static function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		$tab = 'settings' === $tab ? 'setup' : $tab; // The old Settings tab.
		$tab = 'email-template' === $tab ? LetterWP_Design_Admin::TAB : $tab; // Its first name.

		return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
	}

	/**
	 * Which tab each section lives on. Links from elsewhere (the editor
	 * panel, Subscribers) may still point at page=letterwp#section: the
	 * Overview sends them on to the right tab.
	 *
	 * @return array<string,string> section id => tab.
	 */
	private static function sections() {
		return array(
			'letterwp-resend'    => 'setup',
			'letterwp-domains'   => 'setup',
			'letterwp-setup'     => 'setup',
			'letterwp-addresses' => 'setup',
			'letterwp-test'      => 'setup',
			'letterwp-account'   => 'setup',
		);
	}

	/**
	 * @param string $tab     overview, setup or email-design.
	 * @param array  $args    More query args, such as the domain on Setup.
	 * @param string $section A section id to jump to, without the #.
	 * @return string A tab of this screen.
	 */
	public static function url( $tab = 'overview', array $args = array(), $section = '' ) {
		$args = array_merge( array( 'page' => self::SLUG ), 'overview' === $tab ? array() : array( 'tab' => $tab ), array_filter( $args ) );

		return add_query_arg( $args, admin_url( 'admin.php' ) ) . ( '' !== $section ? '#' . $section : '' );
	}

	/** @return string One domain, on the Setup tab. */
	private static function domain_url( $id ) {
		return self::url( 'setup', array( 'domain' => $id ) );
	}

	/** Resend domain ids are UUIDs. @return string */
	private static function clean_id( $id ) {
		return preg_replace( '/[^a-f0-9-]/', '', strtolower( (string) $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Save.
	 * ------------------------------------------------------------------ */

	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change this.', 'letterwp' ) );
		}

		check_admin_referer( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$do = isset( $_POST['letterwp_do'] ) ? sanitize_key( wp_unslash( $_POST['letterwp_do'] ) ) : '';
		$id = isset( $_POST['letterwp_domain_id'] ) ? self::clean_id( sanitize_text_field( wp_unslash( $_POST['letterwp_domain_id'] ) ) ) : '';
		// phpcs:enable

		$back = self::url( 'setup' );

		switch ( $do ) {
			case 'connect':
				$this->connect();
				break;

			case 'disconnect':
				if ( ! LetterWP_Resend::key_is_constant() ) {
					LetterWP_Resend::update( array( 'api_key' => '' ) );
					LetterWP_Setup::clear();
					$this->notice( 'success', __( 'Resend disconnected. The API key has been removed.', 'letterwp' ) );
				}
				break;

			case 'add_domain':
				$back = $this->add_domain();
				break;

			case 'verify_domain':
				$this->verify_domain( $id );
				$back = self::domain_url( $id );
				break;

			case 'addresses':
				$this->save_addresses();
				$back = self::url( 'setup', array(), 'letterwp-addresses' );
				break;

			case 'test_email':
				$this->send_test();
				$back = self::url( 'setup', array(), 'letterwp-test' );
				break;

			case 'setup':
				$this->run_setup();
				$back = self::url( 'setup', array(), 'letterwp-setup' );
				break;

			case 'account_page':
				$page = isset( $_POST['letterwp_account_page'] ) ? (int) $_POST['letterwp_account_page'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
				if ( LetterWP_Login::set_account_page( $page ) ) {
					$this->notice( 'success', $page ? __( 'Account page saved.', 'letterwp' ) : __( 'No account page now. Subscribe forms will not link to one.', 'letterwp' ) );
				} else {
					$this->notice( 'error', __( 'Pick a published page.', 'letterwp' ) );
				}
				$back = self::url( 'setup', array(), 'letterwp-account' );
				break;

			case 'email_design':
			case 'email_design_reset':
				$this->notice( 'success', LetterWP_Design_Admin::save( 'email_design_reset' === $do ) );
				$back = self::url( LetterWP_Design_Admin::TAB );
				break;

			case 'account_create':
				LetterWP_Login::set_account_page( 0 );
				LetterWP_Login::ensure_account_page();
				$this->notice( 'success', __( 'Account page ready.', 'letterwp' ) );
				$back = self::url( 'setup', array(), 'letterwp-account' );
				break;
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * A key is stored only after Resend has accepted it, so a stored key is
	 * always a working one, and a bad paste never replaces a good key.
	 */
	private function connect() {
		if ( LetterWP_Resend::key_is_constant() ) {
			return;
		}

		$key    = isset( $_POST['letterwp_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['letterwp_api_key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle_post().
		$result = LetterWP_Resend::check_key( $key );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
			return;
		}

		LetterWP_Resend::update( array( 'api_key' => $key ) );
		$this->notice( 'success', __( 'Connected to Resend.', 'letterwp' ) );
	}

	/** @return string Where to go next: the new domain, so its DNS records show. */
	private function add_domain() {
		$input = isset( $_POST['letterwp_domain_name'] ) ? sanitize_text_field( wp_unslash( $_POST['letterwp_domain_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle_post().
		$name  = LetterWP_Resend::clean_domain( $input );

		if ( '' === $name ) {
			$this->notice( 'error', __( 'That does not look like a domain. Type it like example.com or news.example.com.', 'letterwp' ) );
			return self::url( 'setup' );
		}

		$result = LetterWP_Resend::add_domain( $name );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
			return self::url( 'setup' );
		}

		/* translators: %s: domain name. */
		$this->notice( 'success', sprintf( __( '%s added to Resend. Now add the DNS records below at your DNS host.', 'letterwp' ), $name ) );

		return isset( $result['id'] ) ? self::domain_url( self::clean_id( $result['id'] ) ) : self::url( 'setup' );
	}

	private function verify_domain( $id ) {
		$result = LetterWP_Resend::verify_domain( $id );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
			return;
		}

		$this->notice( 'success', __( 'Resend is checking the DNS records now. Reload this page in a minute to see the result.', 'letterwp' ) );
	}

	/**
	 * Both or neither: a half-saved pair is worse than an unsaved one. Empty is
	 * allowed and means "not set up yet". Saved whatever Resend says: the
	 * signal next to each address tells whether it can send yet.
	 */
	private function save_addresses() {
		$changes = array();

		foreach ( LetterWP_Addresses::fields() as $which => $field ) {
			$value = isset( $_POST[ 'letterwp_address_' . $which ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ 'letterwp_address_' . $which ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in handle_post().

			if ( '' !== $value && ! is_email( $value ) ) {
				/* translators: %s: field label. */
				$this->notice( 'error', sprintf( __( '%s is not a valid email address. Nothing was saved.', 'letterwp' ), $field['label'] ) );
				return;
			}

			$changes[ $which ] = strtolower( sanitize_email( $value ) );
		}

		LetterWP_Addresses::update( $changes );
		LetterWP_Email::update_postal_address( isset( $_POST['letterwp_postal_address'] ) ? wp_unslash( $_POST['letterwp_postal_address'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- checked in handle_post(), sanitized there.
		$this->notice( 'success', __( 'Addresses saved.', 'letterwp' ) );
	}

	/**
	 * One real email through Resend. Nothing is checked here that Resend
	 * checks itself: an unverified domain comes back as Resend's own error.
	 */
	private function send_test() {
		// phpcs:disable WordPress.Security.NonceVerification -- checked in handle_post().
		$which = isset( $_POST['letterwp_test_from'] ) ? sanitize_key( wp_unslash( $_POST['letterwp_test_from'] ) ) : '';
		$to    = isset( $_POST['letterwp_test_to'] ) ? sanitize_email( wp_unslash( $_POST['letterwp_test_to'] ) ) : '';
		// phpcs:enable

		$from = LetterWP_Addresses::get( $which );

		if ( '' === $from ) {
			$this->notice( 'error', __( 'Save a sender address first.', 'letterwp' ) );
			return;
		}

		if ( ! is_email( $to ) ) {
			$this->notice( 'error', __( 'Type the email address to send the test to.', 'letterwp' ) );
			return;
		}

		$fields = LetterWP_Addresses::fields();
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$when   = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Test email from %s', 'letterwp' ), $site );
		$lines   = array(
			__( 'If you are reading this in your inbox, this site can send email.', 'letterwp' ),
			/* translators: 1: address label, e.g. "Newsletter email address". 2: the address. */
			sprintf( __( 'Sent from the %1$s, %2$s, through Resend.', 'letterwp' ), strtolower( $fields[ $which ]['label'] ), $from ),
			/* translators: 1: site URL. 2: date and time. */
			sprintf( __( 'Sent by LetterWP on %1$s at %2$s.', 'letterwp' ), home_url( '/' ), $when ),
			__( 'Landed in spam? Mark it "Not spam", then check the domain\'s DNS records in LetterWP.', 'letterwp' ),
		);

		$html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#1d2327;max-width:560px;margin:0 auto;padding:24px;">'
			. '<h1 style="font-size:22px;margin:0 0 16px;">' . esc_html( $subject ) . '</h1>';
		foreach ( $lines as $line ) {
			$html .= '<p style="margin:0 0 12px;">' . esc_html( $line ) . '</p>';
		}
		$html .= '</div>';

		$id = LetterWP_Resend::send_email(
			array(
				'from'    => $from,
				'to'      => $to,
				'subject' => $subject,
				'html'    => $html,
				'text'    => $subject . "\n\n" . implode( "\n\n", $lines ),
			)
		);

		if ( is_wp_error( $id ) ) {
			$this->notice( 'error', $id->get_error_message() );
			return;
		}

		/* translators: 1: recipient. 2: sender address. */
		$this->notice( 'success', sprintf( __( 'Sent to %1$s from %2$s. Check the inbox, and the spam folder too.', 'letterwp' ), $to, $from ) );
		set_transient( 'letterwp_last_test_' . get_current_user_id(), $id, 10 * MINUTE_IN_SECONDS );
	}

	private function run_setup() {
		$done = LetterWP_Setup::run();

		if ( is_wp_error( $done ) ) {
			$this->notice( 'error', $done->get_error_message() );
			return;
		}

		if ( ! array_sum( $done ) ) {
			$this->notice( 'success', __( 'Everything was already set up in Resend.', 'letterwp' ) );
			return;
		}

		/* translators: 1: number created. 2: number updated. 3: number deleted. */
		$this->notice( 'success', sprintf( __( 'Resend is set up: %1$d created, %2$d updated, %3$d deleted.', 'letterwp' ), $done['created'], $done['updated'], $done['deleted'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Notices survive the redirect in a short per-user transient, so Resend's
	 * own error text never has to travel in a URL.
	 * ------------------------------------------------------------------ */

	private function notice( $type, $message ) {
		set_transient( 'letterwp_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	private function print_notice() {
		$key    = 'letterwp_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'error' === $notice[0] ? 'error' : 'success' ),
			esc_html( $notice[1] )
		);
	}

	/* ---------------------------------------------------------------------
	 * Render.
	 * ------------------------------------------------------------------ */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab       = self::tab();
		$connected = '' !== LetterWP_Resend::key();
		?>
		<div class="wrap letterwp">
			<h1><?php esc_html_e( 'LetterWP', 'letterwp' ); ?></h1>

			<?php $this->print_notice(); ?>
			<?php $this->css(); ?>

			<nav class="nav-tab-wrapper letterwp-tabs" aria-label="<?php esc_attr_e( 'LetterWP', 'letterwp' ); ?>">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>" <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'setup':
					$this->setup_tab( $connected );
					break;

				case LetterWP_Design_Admin::TAB:
					LetterWP_Design_Admin::render();
					break;

				default:
					$this->overview_tab( $connected );
			}
			?>
		</div>
		<?php
		$this->copy_js();
	}

	/* -- Tabs ------------------------------------------------------------ */

	private function setup_tab( $connected ) {
		$domain_id = isset( $_GET['domain'] ) ? self::clean_id( sanitize_text_field( wp_unslash( $_GET['domain'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		?>
		<h2 id="letterwp-resend"><?php esc_html_e( '1. Resend', 'letterwp' ); ?></h2>
		<?php $this->connection_form(); ?>

		<h2 id="letterwp-domains" class="<?php echo $connected ? '' : 'letterwp-locked'; ?>"><?php esc_html_e( '2. Domains', 'letterwp' ); ?></h2>
		<?php
		if ( ! $connected ) {
			echo '<p class="description">' . esc_html__( 'Do step 1 first: your domains are read from your Resend account.', 'letterwp' ) . '</p>';
		} elseif ( '' !== $domain_id ) {
			$this->domain_view( $domain_id );
		} else {
			$this->domains_list();
		}
		?>

		<h2 id="letterwp-setup" class="<?php echo $connected ? '' : 'letterwp-locked'; ?>"><?php esc_html_e( '3. Your list in Resend', 'letterwp' ); ?></h2>
		<?php $this->setup_section( $connected ); ?>

		<h2 id="letterwp-addresses"><?php esc_html_e( '4. Sender addresses', 'letterwp' ); ?></h2>
		<?php $this->addresses_form(); ?>

		<h2 id="letterwp-test" class="<?php echo $connected ? '' : 'letterwp-locked'; ?>"><?php esc_html_e( '5. Test email', 'letterwp' ); ?></h2>
		<?php $this->test_form( $connected ); ?>

		<h2 id="letterwp-account"><?php esc_html_e( '6. Account page', 'letterwp' ); ?></h2>
		<?php
		$this->account_section();
	}

	private function overview_tab( $connected ) {
		$steps = $this->checklist( $connected );
		$done  = count( array_filter( wp_list_pluck( $steps, 'done' ) ) );

		if ( $done < count( $steps ) ) {
			$this->checklist_card( $steps, $done );
		} else {
			$active = LetterWP_Subscribers::counts()['active'];
			?>
			<p class="letterwp-ready">
				<span class="letterwp-pill is-ready">&#10003; <?php esc_html_e( 'Ready to send', 'letterwp' ); ?></span>
				<?php
				/* translators: %s: number of subscribers. */
				echo esc_html( sprintf( _n( 'New posts are emailed to %s active subscriber when you publish them.', 'New posts are emailed to %s active subscribers when you publish them.', $active, 'letterwp' ), number_format_i18n( $active ) ) );
				?>
				<a href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>"><?php esc_html_e( 'Write a post', 'letterwp' ); ?></a>
			</p>
			<?php
		}

		$this->subscriber_numbers();
		$this->recent_newsletters();
		$this->section_redirect_js();
	}

	/* -- Overview -------------------------------------------------------- */

	/**
	 * What is left before the first newsletter, in order. Local checks plus
	 * one Resend call (the domain list); the Setup tab checks Resend in full.
	 *
	 * @return array<string,array{label:string,hint:string,done:bool,url:string,action:string}>
	 */
	private function checklist( $connected ) {
		$domains = $connected ? LetterWP_Resend::domains() : array();
		$domains = is_wp_error( $domains ) ? array() : $domains;
		$ready   = array_filter( $domains, array( 'LetterWP_Resend', 'is_ready' ) );

		$senders = true;
		foreach ( LetterWP_Addresses::all() as $address ) {
			$senders = $senders && '' !== $address && LetterWP_Resend::sender_status( $address )['ready'];
		}

		return array(
			'resend'    => array(
				'label'  => __( 'Connect Resend', 'letterwp' ),
				'hint'   => __( 'Your own Resend account sends every email. Paste an API key.', 'letterwp' ),
				'done'   => $connected,
				'url'    => self::url( 'setup', array(), 'letterwp-resend' ),
				'action' => __( 'Connect', 'letterwp' ),
			),
			'domain'    => array(
				'label'  => __( 'Verify a sending domain', 'letterwp' ),
				'hint'   => __( 'Add your domain to Resend and its DNS records where your DNS is managed.', 'letterwp' ),
				'done'   => (bool) $ready,
				'url'    => self::url( 'setup', array(), 'letterwp-domains' ),
				'action' => __( 'Add a domain', 'letterwp' ),
			),
			'list'      => array(
				'label'  => __( 'Set up your list in Resend', 'letterwp' ),
				'hint'   => __( 'One click: a segment, a topic per category, and the webhook that brings unsubscribes back.', 'letterwp' ),
				'done'   => LetterWP_Setup::is_done() && LetterWP_Setup::has_key_property() && '' !== LetterWP_Webhook::link()['id'],
				'url'    => self::url( 'setup', array(), 'letterwp-setup' ),
				'action' => __( 'Run setup', 'letterwp' ),
			),
			'addresses' => array(
				'label'  => __( 'Choose sender addresses', 'letterwp' ),
				'hint'   => $senders
					? __( 'Add the postal address printed in every newsletter\'s footer.', 'letterwp' )
					: __( 'Both addresses on a verified domain, and the postal address for the footer.', 'letterwp' ),
				'done'   => $senders && '' !== LetterWP_Email::postal_address(),
				'url'    => self::url( 'setup', array(), 'letterwp-addresses' ),
				'action' => $senders ? __( 'Add postal address', 'letterwp' ) : __( 'Set addresses', 'letterwp' ),
			),
			'account'   => array(
				'label'  => __( 'Pick an account page', 'letterwp' ),
				'hint'   => __( 'Where readers sign in, pick their topics and unsubscribe.', 'letterwp' ),
				'done'   => (bool) LetterWP_Login::account_page_id(),
				'url'    => self::url( 'setup', array(), 'letterwp-account' ),
				'action' => __( 'Pick a page', 'letterwp' ),
			),
		);
	}

	private function checklist_card( array $steps, $done ) {
		$next = true;
		?>
		<div class="letterwp-card">
			<h2>
				<?php esc_html_e( 'Get ready to send', 'letterwp' ); ?>
				<span class="letterwp-count">
					<?php
					/* translators: 1: steps done. 2: all steps. */
					echo esc_html( sprintf( __( '%1$d of %2$d done', 'letterwp' ), $done, count( $steps ) ) );
					?>
				</span>
			</h2>
			<ol class="letterwp-checklist">
				<?php foreach ( $steps as $step ) : ?>
					<li class="<?php echo $step['done'] ? 'is-done' : ''; ?>">
						<span class="letterwp-check" aria-hidden="true"><?php echo $step['done'] ? '&#10003;' : ''; ?></span>
						<div>
							<strong><?php echo esc_html( $step['label'] ); ?></strong>
							<?php if ( $step['done'] ) : ?>
								<span class="screen-reader-text"><?php esc_html_e( '(done)', 'letterwp' ); ?></span>
							<?php else : ?>
								<p class="description"><?php echo esc_html( $step['hint'] ); ?></p>
							<?php endif; ?>
						</div>
						<?php if ( ! $step['done'] ) : ?>
							<a class="button <?php echo $next ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['action'] ); ?></a>
							<?php $next = false; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	private function subscriber_numbers() {
		$counts = LetterWP_Subscribers::counts();
		$list   = admin_url( 'admin.php?page=' . LetterWP_Subscribers_Admin::SLUG );
		?>
		<h2><?php esc_html_e( 'Subscribers', 'letterwp' ); ?></h2>
		<div class="letterwp-numbers">
			<?php foreach ( LetterWP_Subscribers::statuses() as $status => $label ) : ?>
				<a class="letterwp-number" href="<?php echo esc_url( add_query_arg( 'status', $status, $list ) ); ?>">
					<strong><?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?></strong>
					<span><?php echo esc_html( $label ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<p>
			<a href="<?php echo esc_url( $list ); ?>">
				<?php
				/* translators: %s: number of subscribers. */
				echo esc_html( sprintf( __( 'All subscribers (%s)', 'letterwp' ), number_format_i18n( $counts['all'] ) ) );
				?>
			</a>
		</p>
		<?php
	}

	private function recent_newsletters() {
		$posts  = get_posts(
			array(
				'post_type'      => LetterWP_Campaigns::types(),
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'meta_key'       => LetterWP_Send::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- five posts, on one admin screen.
				'no_found_rows'  => true,
			)
		);
		$labels = array(
			'sent'    => __( 'Sent', 'letterwp' ),
			'sending' => __( 'Sending', 'letterwp' ),
			'failed'  => __( 'Failed', 'letterwp' ),
		);
		?>
		<h2><?php esc_html_e( 'Latest newsletters', 'letterwp' ); ?></h2>
		<?php if ( ! $posts ) : ?>
			<p class="description"><?php esc_html_e( 'None yet. Publish a post, or send a campaign, and it is emailed to your subscribers.', 'letterwp' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped letterwp-recent">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Email', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Sent to', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'When', 'letterwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $posts as $post ) : ?>
					<?php
					$record = LetterWP_Send::record( $post->ID );
					$status = $record && isset( $labels[ $record['status'] ] ) ? $record['status'] : 'failed';
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( get_edit_post_link( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></strong>
							<?php if ( LetterWP_Campaigns::is( $post ) ) : ?>
								<span class="letterwp-kind"><?php esc_html_e( 'Campaign', 'letterwp' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $record['broadcast'] ) ) : ?>
								<div class="row-actions visible">
									<a href="<?php echo esc_url( LetterWP_Resend::broadcast_url( $record['broadcast'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See it at Resend.com ↗', 'letterwp' ); ?></a>
								</div>
							<?php endif; ?>
						</td>
						<td>
							<span class="letterwp-pill <?php echo 'sent' === $status ? 'is-ready' : 'is-waiting'; ?>"><?php echo esc_html( $labels[ $status ] ); ?></span>
							<?php if ( 'failed' === $status && ! empty( $record['error'] ) ) : ?>
								<p class="description"><?php echo esc_html( $record['error'] ); ?></p>
							<?php endif; ?>
						</td>
						<td><?php echo isset( $record['count'] ) ? esc_html( number_format_i18n( (int) $record['count'] ) ) : '—'; ?></td>
						<td>
							<?php
							/* translators: %s: time ago, e.g. "5 mins". */
							echo ! empty( $record['time'] ) ? esc_html( sprintf( __( '%s ago', 'letterwp' ), human_time_diff( (int) $record['time'] ) ) ) : '—';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Old links (page=letterwp#letterwp-addresses) land on the section's tab. */
	private function section_redirect_js() {
		$tabs = array();
		foreach ( self::sections() as $section => $tab ) {
			$tabs[ $section ] = self::url( $tab, array(), $section );
		}
		?>
		<script>
			( function ( to ) {
				var go = to[ location.hash.slice( 1 ) ];
				if ( go ) { location.replace( go ); }
			} )( <?php echo wp_json_encode( $tabs ); ?> );
		</script>
		<?php
	}

	private function form_open() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>
		<?php
	}

	/* -- Step 1: the key ------------------------------------------------- */

	private function connection_form() {
		$key = LetterWP_Resend::key();

		$this->form_open();
		?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="letterwp-api-key"><?php esc_html_e( 'API key', 'letterwp' ); ?></label></th>
					<td>
						<?php if ( '' !== $key ) : ?>

							<?php // Connected is read-only: only a key that passed the check is ever stored. ?>
							<code><?php echo esc_html( LetterWP_Resend::mask( $key ) ); ?></code>
							<span class="letterwp-pill is-ready"><?php esc_html_e( 'Connected', 'letterwp' ); ?></span>

							<?php if ( LetterWP_Resend::key_is_constant() ) : ?>
								<p class="description"><?php esc_html_e( 'Set by LETTERWP_RESEND_API_KEY in wp-config.php. Remove the constant to disconnect.', 'letterwp' ); ?></p>
							<?php else : ?>
								<p>
									<button type="submit" class="button-link button-link-delete" name="letterwp_do" value="disconnect"
										onclick="return confirm('<?php echo esc_js( __( 'Disconnect Resend? This site will stop sending email until a key is added again.', 'letterwp' ) ); ?>');">
										<?php esc_html_e( 'Disconnect', 'letterwp' ); ?>
									</button>
								</p>
							<?php endif; ?>

						<?php else : ?>

							<?php
							/* Never rendered with a value, and not type="password":
							   a password field makes Chrome offer to save the API
							   key as this site's login. Masking is CSS instead. */
							?>
							<input type="text" id="letterwp-api-key" class="regular-text letterwp-secret"
								name="letterwp_api_key" value=""
								placeholder="re_…"
								spellcheck="false" autocomplete="off" autocapitalize="off" autocorrect="off"
								data-lpignore="true" data-1p-ignore data-bwignore />
							<button type="submit" class="button" name="letterwp_do" value="connect"><?php esc_html_e( 'Connect', 'letterwp' ); ?></button>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to Resend's API keys page. */
									esc_html__( 'A Full access key from your own Resend account: %s. It is checked with Resend before it is saved.', 'letterwp' ),
									'<a href="https://resend.com/api-keys?new=true" target="_blank" rel="noopener noreferrer">' . esc_html__( 'create one', 'letterwp' ) . '</a>'
								);
								?>
							</p>

						<?php endif; ?>
					</td>
				</tr>
			</table>
		</form>
		<?php
	}

	/* -- Step 2: domains ------------------------------------------------- */

	private function status_pill( array $domain ) {
		printf(
			'<span class="letterwp-pill %1$s">%2$s</span>',
			LetterWP_Resend::is_ready( $domain ) ? 'is-ready' : 'is-waiting',
			esc_html( LetterWP_Resend::status_label( $domain ) )
		);
	}

	private function domains_list() {
		$domains = LetterWP_Resend::domains();

		if ( is_wp_error( $domains ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $domains->get_error_message() ) . '</p></div>';
			return;
		}

		$names   = wp_list_pluck( $domains, 'name' );
		$suggest = isset( $_GET['add'] ) ? LetterWP_Resend::clean_domain( sanitize_text_field( wp_unslash( $_GET['add'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- prefill only.
		$suggest = '' !== $suggest ? $suggest : LetterWP_Resend::site_domain();
		?>
		<p><?php esc_html_e( 'The domains in your Resend account. Email can only be sent from a domain that is ready.', 'letterwp' ); ?></p>

		<?php if ( $domains ) : ?>
			<table class="widefat striped letterwp-domains">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Domain', 'letterwp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'letterwp' ); ?></th>
						<th><?php esc_html_e( 'Region', 'letterwp' ); ?></th>
						<th><?php esc_html_e( 'Added', 'letterwp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $domains as $domain ) : ?>
						<?php $id = self::clean_id( isset( $domain['id'] ) ? $domain['id'] : '' ); ?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( self::domain_url( $id ) ); ?>"><?php echo esc_html( $domain['name'] ); ?></a></strong>
								<div class="row-actions visible">
									<a href="<?php echo esc_url( self::domain_url( $id ) ); ?>"><?php esc_html_e( 'DNS records & settings', 'letterwp' ); ?></a>
									|
									<a href="<?php echo esc_url( LetterWP_Resend::delete_url( $id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Delete in Resend ↗', 'letterwp' ); ?></a>
								</div>
							</td>
							<td>
								<?php $this->status_pill( $domain ); ?>
								<?php if ( ! LetterWP_Resend::dmarc( $domain )['found'] ) : ?>
									<a class="letterwp-pill is-waiting" href="<?php echo esc_url( self::url( 'setup', array( 'domain' => $id ), 'letterwp-dmarc' ) ); ?>"><?php esc_html_e( 'No DMARC', 'letterwp' ); ?></a>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( isset( $domain['region'] ) ? $domain['region'] : '' ); ?></td>
							<td><?php echo esc_html( isset( $domain['created_at'] ) ? mysql2date( get_option( 'date_format' ), $domain['created_at'] ) : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'No domains in this Resend account yet. Add the one you will send from.', 'letterwp' ); ?></p>
		<?php endif; ?>

		<?php $this->form_open(); ?>
			<input type="hidden" name="letterwp_do" value="add_domain" />
			<p class="letterwp-add">
				<label for="letterwp-domain-name"><strong><?php esc_html_e( 'Add a domain', 'letterwp' ); ?></strong></label><br />
				<input type="text" id="letterwp-domain-name" class="regular-text" name="letterwp_domain_name"
					value="<?php echo esc_attr( in_array( $suggest, $names, true ) ? '' : $suggest ); ?>"
					placeholder="example.com" spellcheck="false" autocapitalize="off" />
				<button type="submit" class="button"><?php esc_html_e( 'Add to Resend', 'letterwp' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Resend then gives you a few DNS records to add where your domain\'s DNS is managed (Cloudflare, your registrar…).', 'letterwp' ); ?></p>
		</form>
		<?php
	}

	private function domain_view( $id ) {
		$domain = LetterWP_Resend::domain( $id );

		echo '<p><a href="' . esc_url( self::url( 'setup', array(), 'letterwp-domains' ) ) . '">' . esc_html__( '← All domains', 'letterwp' ) . '</a></p>';

		if ( is_wp_error( $domain ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $domain->get_error_message() ) . '</p></div>';
			return;
		}

		$records = isset( $domain['records'] ) && is_array( $domain['records'] ) ? $domain['records'] : array();
		$ready   = LetterWP_Resend::is_ready( $domain );
		$dmarc   = LetterWP_Resend::dmarc( $domain );
		?>
		<h3 class="letterwp-domain-title">
			<?php echo esc_html( $domain['name'] ); ?>
			<?php $this->status_pill( $domain ); ?>
		</h3>

		<?php if ( ! $ready ) : ?>
			<p><?php esc_html_e( 'Add these records where this domain\'s DNS is managed, then press Check again. DNS can take a few minutes to a few hours to show up.', 'letterwp' ); ?></p>
			<p class="description"><?php esc_html_e( 'On Cloudflare: set every record to DNS only (grey cloud). A proxied (orange) record makes the check fail.', 'letterwp' ); ?></p>
		<?php endif; ?>

		<table class="widefat striped letterwp-records">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Name', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Value', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Priority', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'letterwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $records as $record ) : ?>
					<?php
					$name   = isset( $record['name'] ) && '' !== $record['name'] ? $record['name'] : '@';
					$value  = isset( $record['value'] ) ? (string) $record['value'] : '';
					$status = isset( $record['status'] ) ? (string) $record['status'] : '';
					?>
					<tr>
						<td><?php echo esc_html( isset( $record['type'] ) ? $record['type'] : '' ); ?></td>
						<td><?php $this->copyable( $name ); ?></td>
						<td class="letterwp-value"><?php $this->copyable( $value ); ?></td>
						<td><?php echo esc_html( isset( $record['priority'] ) ? $record['priority'] : '' ); ?></td>
						<td><span class="letterwp-pill <?php echo 'verified' === $status ? 'is-ready' : 'is-waiting'; ?>"><?php echo esc_html( str_replace( '_', ' ', $status ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				<tr id="letterwp-dmarc">
					<td>TXT</td>
					<?php if ( $dmarc['found'] ) : ?>
						<td><code>_dmarc.<?php echo esc_html( $dmarc['host'] ); ?></code></td>
						<td class="letterwp-value"><code><?php echo esc_html( $dmarc['value'] ); ?></code></td>
						<td></td>
						<td><span class="letterwp-pill is-ready"><?php esc_html_e( 'DMARC found', 'letterwp' ); ?></span></td>
					<?php else : ?>
						<td><?php $this->copyable( $dmarc['name'] ); ?></td>
						<td class="letterwp-value"><?php $this->copyable( LetterWP_Resend::DMARC_SUGGESTED ); ?></td>
						<td></td>
						<td><span class="letterwp-pill is-waiting"><?php esc_html_e( 'Recommended', 'letterwp' ); ?></span></td>
					<?php endif; ?>
				</tr>
			</tbody>
		</table>

		<?php if ( ! $dmarc['found'] ) : ?>
			<p class="description letterwp-dmarc-note">
				<?php esc_html_e( 'DMARC: not needed to send, so Resend does not check it, but Gmail and Yahoo trust a domain more with one, and require it once you send over 5,000 emails a day. p=none never blocks anything: it only tells inboxes this domain checks its mail. One record on your root domain covers every subdomain too. Just added it? It can take up to 30 minutes to show here.', 'letterwp' ); ?>
			</p>
		<?php endif; ?>

		<?php $this->form_open(); ?>
			<input type="hidden" name="letterwp_domain_id" value="<?php echo esc_attr( $id ); ?>" />
			<p>
				<button type="submit" class="button <?php echo $ready ? '' : 'button-primary'; ?>" name="letterwp_do" value="verify_domain"><?php esc_html_e( 'Check again', 'letterwp' ); ?></button>
				<a class="letterwp-resend-link" href="https://resend.com/domains" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See all domains at Resend.com ↗', 'letterwp' ); ?></a>
			</p>
		</form>

		<p class="letterwp-delete">
			<?php
			printf(
				/* translators: %s: link to the domain in Resend's dashboard. */
				esc_html__( 'To delete this domain, do it in %s. LetterWP never deletes domains: your Resend account may send for other sites too.', 'letterwp' ),
				'<a href="' . esc_url( LetterWP_Resend::delete_url( $id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Resend ↗', 'letterwp' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/* -- Step 3: sender addresses ---------------------------------------- */

	private function addresses_form() {
		$addresses = LetterWP_Addresses::all();

		$this->form_open();
		?>
			<input type="hidden" name="letterwp_do" value="addresses" />
			<table class="form-table" role="presentation">
				<?php foreach ( LetterWP_Addresses::fields() as $which => $field ) : ?>
					<tr>
						<th scope="row"><label for="letterwp-address-<?php echo esc_attr( $which ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td>
							<input type="email" id="letterwp-address-<?php echo esc_attr( $which ); ?>" class="regular-text"
								name="letterwp_address_<?php echo esc_attr( $which ); ?>"
								value="<?php echo esc_attr( $addresses[ $which ] ); ?>"
								placeholder="<?php echo esc_attr( LetterWP_Addresses::suggestion( $which ) ); ?>"
								spellcheck="false" autocomplete="off" />
							<?php $this->address_signal( $addresses[ $which ] ); ?>
							<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="letterwp-postal-address"><?php esc_html_e( 'Postal address', 'letterwp' ); ?></label></th>
					<td>
						<textarea id="letterwp-postal-address" class="regular-text" name="letterwp_postal_address" rows="3"
							placeholder="<?php esc_attr_e( "Your name or business\nStreet, city, postcode\nCountry", 'letterwp' ); ?>"><?php echo esc_textarea( LetterWP_Email::postal_address() ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Printed in the footer of every newsletter. Anti-spam laws (CAN-SPAM in the US, CASL in Canada) ask for a real postal address; a PO box is fine.', 'letterwp' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="description">
				<?php
				printf(
					/* translators: %s: the site name. */
					esc_html__( 'Emails are sent from “%s”, your site\'s name.', 'letterwp' ),
					esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) )
				);
				?>
			</p>
			<?php submit_button( __( 'Save addresses', 'letterwp' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The signal next to an address: can it send right now, and if not, the
	 * one step that fixes it. Read live from Resend on every page load.
	 */
	private function address_signal( $email ) {
		if ( '' === $email ) {
			return;
		}

		$status = LetterWP_Resend::sender_status( $email );
		$name   = LetterWP_Addresses::domain_of( $email );
		$id     = isset( $status['domain']['id'] ) ? self::clean_id( $status['domain']['id'] ) : '';
		$link   = '';

		switch ( $status['reason'] ) {
			case 'ready':
				$text = __( 'Can send via Resend', 'letterwp' );
				break;

			case 'no_key':
				$text = __( 'Can\'t send yet: connect Resend in Setup', 'letterwp' );
				break;

			case 'no_domain':
				/* translators: %s: domain name. */
				$text = sprintf( __( 'Can\'t send yet: %s is not in your Resend account', 'letterwp' ), $name );
				$link = sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url( 'setup', array( 'add' => $name ), 'letterwp-domain-name' ) ), esc_html__( 'Add it in Setup', 'letterwp' ) );
				break;

			case 'not_ready':
				/* translators: 1: domain name. 2: its status, e.g. "Not ready: waiting for DNS". */
				$text = sprintf( __( 'Can\'t send yet: %1$s is %2$s', 'letterwp' ), $name, lcfirst( LetterWP_Resend::status_label( $status['domain'] ) ) );
				$link = sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::domain_url( $id ) ), esc_html__( 'See its DNS records', 'letterwp' ) );
				break;

			default:
				/* translators: %s: error message. */
				$text = sprintf( __( 'Could not ask Resend: %s', 'letterwp' ), $status['error'] );
		}

		printf(
			'<p class="letterwp-signal"><span class="letterwp-pill %1$s">%2$s %3$s</span> %4$s</p>',
			$status['ready'] ? 'is-ready' : 'is-waiting',
			$status['ready'] ? '&#10003;' : '&#9888;',
			esc_html( $text ),
			wp_kses_post( $link )
		);
	}

	/* -- Step 4: test email ---------------------------------------------- */

	private function test_form( $connected ) {
		if ( ! $connected ) {
			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: link to the Setup tab. */
					esc_html__( '%s first: the test goes out through your Resend account.', 'letterwp' ),
					'<a href="' . esc_url( self::url( 'setup', array(), 'letterwp-resend' ) ) . '">' . esc_html__( 'Connect Resend in Setup', 'letterwp' ) . '</a>'
				)
			);
			return;
		}

		$addresses = array_filter( LetterWP_Addresses::all() );

		if ( ! $addresses ) {
			echo '<p class="description">' . esc_html__( 'Save an address to send from above first.', 'letterwp' ) . '</p>';
			return;
		}

		$fields = LetterWP_Addresses::fields();
		$last   = get_transient( 'letterwp_last_test_' . get_current_user_id() );

		$this->form_open();
		?>
			<input type="hidden" name="letterwp_do" value="test_email" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="letterwp-test-from"><?php esc_html_e( 'From', 'letterwp' ); ?></label></th>
					<td>
						<select id="letterwp-test-from" name="letterwp_test_from">
							<?php foreach ( $addresses as $which => $address ) : ?>
								<option value="<?php echo esc_attr( $which ); ?>" <?php selected( 'nl', $which ); ?>>
									<?php echo esc_html( $address . ' (' . $fields[ $which ]['label'] . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="letterwp-test-to"><?php esc_html_e( 'To', 'letterwp' ); ?></label></th>
					<td>
						<input type="email" id="letterwp-test-to" class="regular-text" name="letterwp_test_to"
							value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" spellcheck="false" />
						<p class="description"><?php esc_html_e( 'Use an inbox you read, ideally Gmail: it is the strictest about spam.', 'letterwp' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Send test email', 'letterwp' ); ?></button>
				<?php if ( $last ) : ?>
					<a class="letterwp-resend-link" href="<?php echo esc_url( LetterWP_Resend::email_url( $last ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See the last test at Resend.com ↗', 'letterwp' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/* -- Step 5: setup -------------------------------------------------- */

	private function setup_section( $connected ) {
		if ( ! $connected ) {
			echo '<p class="description">' . esc_html__( 'Do step 1 first: the segment and topics are made in your Resend account.', 'letterwp' ) . '</p>';
			return;
		}

		$status = LetterWP_Setup::status();

		if ( is_wp_error( $status ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $status->get_error_message() ) . '</p></div>';
			return;
		}

		$complete = LetterWP_Setup::is_complete( $status );
		$labels   = array(
			'ready'   => __( 'Ready', 'letterwp' ),
			'rename'  => __( 'Out of date: will be renamed', 'letterwp' ),
			'missing' => __( 'Not in Resend yet', 'letterwp' ),
		);
		?>
		<p><?php esc_html_e( 'How your list is organised in Resend. WordPress decides; Resend follows.', 'letterwp' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Segment', 'letterwp' ); ?></th>
				<td>
					<strong><?php echo esc_html( $status['segment'] ? $status['segment']['name'] : LetterWP_Setup::segment_name() ); ?></strong>
					<span class="letterwp-pill <?php echo $status['segment'] ? 'is-ready' : 'is-waiting'; ?>"><?php echo esc_html( $status['segment'] ? $labels['ready'] : $labels['missing'] ); ?></span>
					<p class="description"><?php esc_html_e( 'Every subscriber is in it, and every newsletter is sent to it.', 'letterwp' ); ?></p>
				</td>
			</tr>
			<?php if ( LetterWP_Setup::category_topics() || $status['announce'] ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Topics', 'letterwp' ); ?></th>
				<td>
					<?php if ( $status['topics'] ) : ?>
						<table class="widefat striped letterwp-topics">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Category', 'letterwp' ); ?></th>
									<th><?php esc_html_e( 'Posts', 'letterwp' ); ?></th>
									<th><?php esc_html_e( 'Topic in Resend', 'letterwp' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $status['topics'] as $row ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( get_edit_term_link( $row['term'], 'category' ) ); ?>"><?php echo esc_html( $row['term']->name ); ?></a></td>
										<td><?php echo esc_html( number_format_i18n( $row['term']->count ) ); ?></td>
										<td><span class="letterwp-pill <?php echo 'ready' === $row['state'] ? 'is-ready' : 'is-waiting'; ?>"><?php echo esc_html( $labels[ $row['state'] ] ); ?></span></td>
									</tr>
								<?php endforeach; ?>
								<?php if ( $status['announce'] ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . LetterWP_Campaigns::TYPE ) ); ?>"><?php echo esc_html( LetterWP_Setup::announcements_label() ); ?></a></td>
										<td><?php echo esc_html( number_format_i18n( wp_count_posts( LetterWP_Campaigns::TYPE )->publish ) ); ?></td>
										<td><span class="letterwp-pill <?php echo 'ready' === $status['announce']['state'] ? 'is-ready' : 'is-waiting'; ?>"><?php echo esc_html( $labels[ $status['announce']['state'] ] ); ?></span></td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No categories yet.', 'letterwp' ); ?></p>
					<?php endif; ?>

					<?php if ( $status['orphans'] ) : ?>
						<p>
							<?php
							/* translators: %d: number of topics. */
							echo esc_html( sprintf( _n( '%d topic belongs to a deleted category and will be removed from Resend.', '%d topics belong to deleted categories and will be removed from Resend.', count( $status['orphans'] ), 'letterwp' ), count( $status['orphans'] ) ) );
							?>
						</p>
					<?php endif; ?>

					<p class="description"><?php esc_html_e( 'One topic per category. Everyone gets every topic until they turn one off on the unsubscribe page. A post is sent under its category\'s topic. New, renamed and deleted categories update Resend by themselves.', 'letterwp' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Unsubscribe links', 'letterwp' ); ?></th>
				<td>
					<code><?php echo esc_html( LetterWP_Setup::KEY_PROPERTY ); ?></code>
					<span class="letterwp-pill <?php echo $status['key'] ? 'is-ready' : 'is-waiting'; ?>"><?php echo esc_html( $status['key'] ? $labels['ready'] : $labels['missing'] ); ?></span>
					<p class="description"><?php esc_html_e( 'A contact property holding each reader\'s own key, so the Unsubscribe link in every newsletter unsubscribes them in one click, on your site.', 'letterwp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook', 'letterwp' ); ?></th>
				<td>
					<?php
					$hook  = $status['webhook'];
					$last  = LetterWP_Webhook::last();
					$hooks = array(
						'ready'   => $labels['ready'],
						'update'  => __( 'Out of date: will be fixed', 'letterwp' ),
						'missing' => $labels['missing'],
					);
					?>
					<code><?php echo esc_html( LetterWP_Webhook::endpoint() ); ?></code>
					<span class="letterwp-pill <?php echo 'ready' === $hook['state'] ? 'is-ready' : 'is-waiting'; ?>"><?php echo esc_html( $hooks[ $hook['state'] ] ); ?></span>
					<?php if ( $hook['webhook'] ) : ?>
						· <a href="<?php echo esc_url( 'https://resend.com/webhooks/' . rawurlencode( $hook['webhook']['id'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See it at Resend.com ↗', 'letterwp' ); ?></a>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'How bounces, spam complaints and unsubscribes at Resend come back to your Subscribers list, within seconds. An unsubscribe always wins.', 'letterwp' ); ?>
						<?php if ( $last ) : ?>
							<br />
							<?php
							/* translators: 1: time ago, e.g. "5 mins". 2: event type, e.g. contact.updated. 3: what came of it. */
							echo esc_html( sprintf( __( 'Last event: %1$s ago, %2$s (%3$s).', 'letterwp' ), human_time_diff( $last['time'] ), $last['type'], $last['result'] ) );
							?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>

		<?php $this->form_open(); ?>
			<input type="hidden" name="letterwp_do" value="setup" />
			<p>
				<?php if ( $complete ) : ?>
					<span class="letterwp-pill is-ready letterwp-done">&#10003; <?php esc_html_e( 'Everything is set up', 'letterwp' ); ?></span>
					<button type="submit" class="button"><?php esc_html_e( 'Run setup again', 'letterwp' ); ?></button>
				<?php else : ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run setup', 'letterwp' ); ?></button>
				<?php endif; ?>
				<a class="letterwp-resend-link" href="https://resend.com/audience/topics" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See topics at Resend.com ↗', 'letterwp' ); ?></a>
			</p>
		</form>
		<?php
	}

	/* -- Step 6: account page -------------------------------------------- */

	private function account_section() {
		$page = LetterWP_Login::account_page_id();
		$post = $page ? get_post( $page ) : null;
		?>
		<p><?php esc_html_e( 'Where readers sign in, pick their topics and unsubscribe. They land here after confirming, and every subscribe form links here once they are signed in.', 'letterwp' ); ?></p>

		<?php $this->form_open(); ?>
			<input type="hidden" name="letterwp_do" value="account_page" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="letterwp-account-page"><?php esc_html_e( 'Page', 'letterwp' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages(
							array(
								'name'              => 'letterwp_account_page',
								'id'                => 'letterwp-account-page',
								'selected'          => absint( $page ),
								'show_option_none'  => esc_html__( '— None —', 'letterwp' ),
								'option_none_value' => '0',
							)
						);
						?>
						<button type="submit" class="button"><?php esc_html_e( 'Save', 'letterwp' ); ?></button>
						<?php if ( $post ) : ?>
							<a href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'letterwp' ); ?></a>
							· <a href="<?php echo esc_url( get_edit_post_link( $post ) ); ?>"><?php esc_html_e( 'Edit', 'letterwp' ); ?></a>
						<?php endif; ?>
						<p class="description">
							<?php
							esc_html_e( 'Any page works. The account panel is added after the page\'s content.', 'letterwp' );
							?>
						</p>
					</td>
				</tr>
			</table>
		</form>

		<?php if ( ! $post ) : ?>
			<?php $this->form_open(); ?>
				<input type="hidden" name="letterwp_do" value="account_create" />
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create an Account page', 'letterwp' ); ?></button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	/** A value with a Copy button, since every DNS record gets pasted somewhere else. */
	private function copyable( $text ) {
		printf(
			'<code>%1$s</code> <button type="button" class="button-link letterwp-copy" data-copy="%2$s">%3$s</button>',
			esc_html( $text ),
			esc_attr( $text ),
			esc_html__( 'Copy', 'letterwp' )
		);
	}

	/* -- Assets, inline: one screen does not earn files. ------------------ */

	/**
	 * Dots on the key field while it is not focused. Chrome/Edge honour
	 * -webkit-text-security; elsewhere the field simply shows what is typed,
	 * which is only ever while pasting, since it loads empty.
	 */
	private function css() {
		?>
		<style>
			.letterwp-secret { -webkit-text-security: disc; font-family: monospace; }
			.letterwp-secret:focus,
			.letterwp-secret::placeholder { -webkit-text-security: none; }
			.letterwp-pill { display: inline-block; margin-left: 8px; padding: 1px 8px; border-radius: 10px; font-size: 12px; font-weight: 400; vertical-align: middle; }
			.letterwp-pill.is-ready { background: #edfaef; color: #008a20; }
			.letterwp-pill.is-waiting { background: #fcf9e8; color: #996800; }
			.letterwp td .letterwp-pill { margin-left: 0; }
			.letterwp td .letterwp-pill + .letterwp-pill { margin-left: 4px; }
			a.letterwp-pill { text-decoration: none; }
			.letterwp-kind { margin-left: 6px; color: #646970; font-size: 12px; }
			.letterwp-locked { color: #a7aaad; }
			.letterwp-domains, .letterwp-records { max-width: 960px; margin-top: 12px; }
			.letterwp-add { margin-top: 20px; }
			.letterwp-value code { word-break: break-all; }
			.letterwp-records td { vertical-align: top; }
			.letterwp-domain-title { font-size: 1.2em; }
			.letterwp-delete { margin-top: 24px; color: #646970; }
			.letterwp-signal { margin: 6px 0 0; }
			.letterwp-signal .letterwp-pill { margin-left: 0; }
			.letterwp-resend-link { margin-left: 12px; line-height: 30px; }
			.letterwp-topics { max-width: 640px; }
			.letterwp-done { margin: 0 12px 0 0; line-height: 24px; }
			.letterwp-tabs { margin-bottom: 8px; }
			.letterwp-card { max-width: 720px; margin: 20px 0; padding: 4px 20px 12px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
			.letterwp-card h2 { display: flex; align-items: baseline; gap: 12px; }
			.letterwp-count { font-size: 13px; font-weight: 400; color: #646970; }
			.letterwp-checklist { margin: 0; list-style: none; }
			.letterwp-checklist li { display: flex; align-items: center; gap: 12px; margin: 0; padding: 12px 0; border-top: 1px solid #f0f0f1; }
			.letterwp-checklist li > div { flex: 1; }
			.letterwp-checklist .description { margin: 2px 0 0; }
			.letterwp-checklist .is-done strong { color: #646970; font-weight: 400; }
			.letterwp-check { flex: none; width: 20px; height: 20px; border: 2px solid #c3c4c7; border-radius: 50%; text-align: center; line-height: 20px; font-size: 12px; }
			.is-done .letterwp-check { background: #008a20; border-color: #008a20; color: #fff; }
			.letterwp-ready { margin: 20px 0; }
			.letterwp-ready .letterwp-pill { margin: 0 8px 0 0; }
			.letterwp-numbers { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; max-width: 720px; }
			.letterwp-number { padding: 12px 16px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; text-decoration: none; color: #1d2327; }
			.letterwp-number:hover { border-color: #2271b1; }
			.letterwp-number strong { display: block; font-size: 24px; line-height: 1.3; }
			.letterwp-number span { color: #646970; }
			.letterwp-recent { max-width: 960px; }
			.letterwp-recent .description { margin: 4px 0 0; }
		</style>
		<?php
	}

	private function copy_js() {
		?>
		<script>
			document.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '.letterwp-copy' );
				if ( ! b || ! navigator.clipboard ) { return; }
				navigator.clipboard.writeText( b.dataset.copy ).then( function () {
					var t = b.textContent;
					b.textContent = <?php echo wp_json_encode( __( 'Copied', 'letterwp' ) ); ?>;
					setTimeout( function () { b.textContent = t; }, 1200 );
				} );
			} );
		</script>
		<?php
	}
}
