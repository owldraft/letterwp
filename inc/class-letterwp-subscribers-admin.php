<?php
/**
 * LetterWP → Subscribers: count, search, add by hand, delete, export.
 * Import is its own screen, LetterWP_Import_Admin.
 *
 * Same shape as the settings screen: forms post to admin-post.php and
 * redirect back, so a refresh never resubmits.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Subscribers_Admin {

	const SLUG = 'letterwp-subscribers';

	const ACTION = 'letterwp_subscribers';

	const EXPORT = 'letterwp_subscribers_export';

	/** Screen Options: rows per page, per user. */
	const PER_PAGE_OPTION = 'letterwp_subscribers_per_page';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_post' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'export' ) );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( $this, 'save_per_page' ), 10, 3 );
	}

	/** Top level, right below LetterWP: the list is visited daily, settings once. */
	public function admin_menu() {
		$hook = add_menu_page(
			__( 'Subscribers', 'letterwp' ),
			__( 'Subscribers', 'letterwp' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-groups',
			'26.1'
		);

		// The first item names the list, not the menu again; Import follows it.
		add_submenu_page( self::SLUG, __( 'Subscribers', 'letterwp' ), __( 'All subscribers', 'letterwp' ), 'manage_options', self::SLUG, array( $this, 'render' ) );

		add_action( 'load-' . $hook, array( $this, 'load' ) );
	}

	/** @var LetterWP_Subscribers_Table|null The list, built before the page header. */
	private $table = null;

	/**
	 * Before any output: run a bulk or row action and redirect, as Posts
	 * does, and register Screen Options. The table is built here so its
	 * columns show in Screen Options too.
	 */
	public function load() {
		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
			return;
		}

		require_once LETTERWP_DIR . 'inc/class-letterwp-subscribers-table.php';

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Subscribers per page', 'letterwp' ),
				'default' => 20,
				'option'  => self::PER_PAGE_OPTION,
			)
		);

		$this->table = new LetterWP_Subscribers_Table();
		$action      = $this->table->current_action();

		if ( in_array( $action, array( 'delete', 'unsubscribe' ), true ) ) {
			check_admin_referer( 'bulk-subscribers' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to change this.', 'letterwp' ) );
			}

			$ids = isset( $_REQUEST['subscriber'] ) ? array_filter( array_map( 'absint', (array) $_REQUEST['subscriber'] ) ) : array();
			$this->bulk( $action, $ids );

			$back = wp_get_referer() ? wp_get_referer() : LetterWP_Subscribers_Table::url();
			wp_safe_redirect( remove_query_arg( array( 'action', 'action2', 'subscriber', '_wpnonce', '_wp_http_referer' ), $back ) );
			exit;
		}

		// Like edit.php: a filter or search submit carries the form's nonce
		// and referer; drop them so the address stays clean.
		if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) {
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2', 'filter_action' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only args removed, redirect is validated.
			exit;
		}

		$this->table->prepare_items();
	}

	/** Screen Options: keep the number, within reason. */
	public function save_per_page( $keep, $option, $value ) {
		return min( 999, max( 1, (int) $value ) );
	}

	/** Delete or unsubscribe, one by one: each change reaches Resend on its own. */
	private function bulk( $action, array $ids ) {
		$done = 0;

		foreach ( $ids as $id ) {
			$row = LetterWP_Subscribers::get( $id );

			if ( ! $row ) {
				continue;
			}

			if ( 'delete' === $action ) {
				$done += LetterWP_Subscribers::delete( $id ) ? 1 : 0;
			} elseif ( 'unsubscribed' !== $row->status && in_array( 'unsubscribed', LetterWP_Subscribers::allowed_statuses( $row->status ), true ) ) {
				$done += LetterWP_Subscribers::update( $id, array( 'status' => 'unsubscribed' ) ) ? 1 : 0;
			}
		}

		$this->notice(
			'success',
			'delete' === $action
				/* translators: %s: number of subscribers. */
				? sprintf( _n( '%s subscriber deleted.', '%s subscribers deleted.', $done, 'letterwp' ), number_format_i18n( $done ) )
				/* translators: %s: number of subscribers. */
				: sprintf( _n( '%s subscriber unsubscribed.', '%s subscribers unsubscribed.', $done, 'letterwp' ), number_format_i18n( $done ) )
		);
	}

	/** @return string This screen, keeping the given filters. */
	private function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), array_filter( $args ) ), admin_url( 'admin.php' ) );
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
		$do   = isset( $_POST['letterwp_do'] ) ? sanitize_key( wp_unslash( $_POST['letterwp_do'] ) ) : '';
		$back = isset( $_POST['letterwp_back'] ) ? esc_url_raw( wp_unslash( $_POST['letterwp_back'] ) ) : '';

		if ( 'add' === $do ) {
			$email = isset( $_POST['letterwp_email'] ) ? wp_unslash( $_POST['letterwp_email'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by clean_email().
			$first = isset( $_POST['letterwp_first_name'] ) ? wp_unslash( $_POST['letterwp_first_name'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by clean_name().
			$last  = isset( $_POST['letterwp_last_name'] ) ? wp_unslash( $_POST['letterwp_last_name'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by clean_name().
			$this->add( $email, $first, $last );
			$back = $this->url();
		} elseif ( 'save' === $do ) {
			$id   = isset( $_POST['letterwp_id'] ) ? absint( $_POST['letterwp_id'] ) : 0;
			$back = $this->save( $id ) ? $this->url( array( 'edit' => $id ) ) : $back;
		} elseif ( 'sync' === $do ) {
			$this->sync();
		} elseif ( 'delete' === $do ) {
			$this->delete( isset( $_POST['letterwp_id'] ) ? absint( $_POST['letterwp_id'] ) : 0 );
		}
		// phpcs:enable

		// wp_validate_redirect() passes '' through, and redirecting to '' does nothing.
		wp_safe_redirect( '' === $back ? $this->url() : wp_validate_redirect( $back, $this->url() ) );
		exit;
	}

	/**
	 * Added by hand means the site owner vouches for the address: active
	 * straight away, no confirmation email.
	 */
	private function add( $email, $first, $last ) {
		$id = LetterWP_Subscribers::add( $email, 'active', 'admin', $first, $last );

		if ( is_wp_error( $id ) ) {
			$this->notice( 'error', $id->get_error_message() );
			return;
		}

		/* translators: %s: email address. */
		$this->notice_synced( sprintf( __( '%s added as an active subscriber.', 'letterwp' ), LetterWP_Subscribers::get( $id )->email ) );
	}

	/** @return bool Whether there is still a subscriber to go back to. */
	private function save( $id ) {
		$row = LetterWP_Subscribers::get( $id );

		if ( ! $row ) {
			$this->notice( 'error', __( 'That subscriber no longer exists.', 'letterwp' ) );
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- checked in handle_post(); names cleaned by clean_name().
		$fields = array(
			'first_name' => isset( $_POST['letterwp_first_name'] ) ? wp_unslash( $_POST['letterwp_first_name'] ) : '',
			'last_name'  => isset( $_POST['letterwp_last_name'] ) ? wp_unslash( $_POST['letterwp_last_name'] ) : '',
		);
		$status = isset( $_POST['letterwp_status'] ) ? sanitize_key( wp_unslash( $_POST['letterwp_status'] ) ) : $row->status;

		// Unchecked boxes are not posted, so a marker says the topics were on the form.
		if ( isset( $_POST['letterwp_topics_shown'] ) ) {
			$fields['topics'] = isset( $_POST['letterwp_topics'] ) ? array_map( 'absint', (array) $_POST['letterwp_topics'] ) : array();
		}
		// phpcs:enable

		if ( in_array( $status, LetterWP_Subscribers::allowed_statuses( $row->status ), true ) ) {
			$fields['status'] = $status;
		}

		if ( ! LetterWP_Subscribers::update( $id, $fields ) ) {
			$this->notice( 'error', __( 'The changes could not be saved.', 'letterwp' ) );
			return true;
		}

		$this->notice_synced( __( 'Saved.', 'letterwp' ) );

		return true;
	}

	/** A success notice that also says what Resend made of the change. */
	private function notice_synced( $message ) {
		$pushed = LetterWP_Sync::pushed();

		if ( is_wp_error( $pushed ) ) {
			/* translators: 1: what was done. 2: Resend's error. */
			$this->notice( 'error', sprintf( __( '%1$s But Resend did not take the change: %2$s. "Sync to Resend now" will try again.', 'letterwp' ), $message, $pushed->get_error_message() ) );
			return;
		}

		$this->notice( 'success', true === $pushed ? $message . ' ' . __( 'Synced to Resend.', 'letterwp' ) : $message );
	}

	private function sync() {
		$done = LetterWP_Sync::run();

		if ( is_wp_error( $done ) ) {
			$this->notice( 'error', $done->get_error_message() );
			return;
		}

		if ( $done['errors'] ) {
			/* translators: %s: Resend's error messages. */
			$this->notice( 'error', sprintf( __( 'Synced in part. Resend said: %s', 'letterwp' ), implode( ' / ', $done['errors'] ) ) );
			return;
		}

		/* translators: 1: contacts created. 2: updated. 3: added to the segment. 4: taken out of it. 5: unsubscribes brought back. */
		$this->notice( 'success', sprintf( __( 'Synced to Resend: %1$d created, %2$d updated, %3$d added to the segment, %4$d taken out, %5$d unsubscribes brought back.', 'letterwp' ), $done['created'], $done['updated'], $done['joined'], $done['left'], $done['pulled'] ) );
	}

	/**
	 * Above the list, only when something needs doing: setup not run, or an
	 * import still on its way to Resend.
	 */
	private function sync_alert() {
		if ( ! LetterWP_Setup::is_done() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				sprintf(
					/* translators: %s: link to the setup step. */
					esc_html__( 'Subscribers are not synced to Resend yet: run %s first.', 'letterwp' ),
					'<a href="' . esc_url( LetterWP_Admin::url( 'setup', array(), 'letterwp-setup' ) ) . '">' . esc_html__( 'LetterWP → Setup', 'letterwp' ) . '</a>'
				)
			);
			return;
		}

		$queued = count( LetterWP_Sync::queue() );

		if ( $queued ) {
			printf(
				'<div class="notice notice-warning inline"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				/* translators: %s: number of subscribers. */
				esc_html( sprintf( _n( '%s imported subscriber is not in Resend yet.', '%s imported subscribers are not in Resend yet.', $queued, 'letterwp' ), number_format_i18n( $queued ) ) ),
				esc_url( LetterWP_Import_Admin::url( 'sync' ) ),
				esc_html__( 'Carry on', 'letterwp' )
			);
		}
	}

	/** Below the list: where Resend stands, and the button. */
	private function sync_status() {
		if ( ! LetterWP_Setup::is_done() ) {
			return;
		}

		$last = LetterWP_Sync::last();
		?>
		<div class="letterwp-sync">
			<?php $this->form_open(); ?>
				<input type="hidden" name="letterwp_do" value="sync" />
				<strong><?php esc_html_e( 'Resend', 'letterwp' ); ?></strong>
				<span>
					<?php
					if ( ! $last ) {
						esc_html_e( 'Not synced yet.', 'letterwp' );
					} else {
						printf(
							/* translators: 1: number of subscribers. 2: segment name. 3: time ago, e.g. "5 mins". */
							esc_html__( '%1$s in the %2$s segment · synced %3$s ago', 'letterwp' ),
							esc_html( number_format_i18n( $last['in_segment'] ) ),
							esc_html( LetterWP_Setup::segment_name() ),
							esc_html( human_time_diff( $last['time'] ) )
						);
						if ( ! empty( $last['strangers'] ) ) {
							echo ' · ';
							/* translators: %s: number of contacts. */
							echo esc_html( sprintf( _n( '%s contact at Resend is not a subscriber here', '%s contacts at Resend are not subscribers here', $last['strangers'], 'letterwp' ), number_format_i18n( $last['strangers'] ) ) );
						}
					}
					?>
				</span>
				<button type="submit" class="button"><?php esc_html_e( 'Sync to Resend now', 'letterwp' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function delete( $id ) {
		$row = LetterWP_Subscribers::get( $id );

		if ( ! $row || ! LetterWP_Subscribers::delete( $id ) ) {
			$this->notice( 'error', __( 'That subscriber could not be deleted. It may already be gone.', 'letterwp' ) );
			return;
		}

		/* translators: %s: email address. */
		$this->notice( 'success', sprintf( __( '%s deleted.', 'letterwp' ), $row->email ) );
	}

	/* ---------------------------------------------------------------------
	 * Export: every column we keep, as CSV, from the form on Import/Export
	 * (status, and search or source when given). The import reads it back:
	 * topics_off holds the slugs of the categories someone turned off.
	 * ------------------------------------------------------------------ */

	public function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'letterwp' ) );
		}

		check_admin_referer( self::EXPORT );

		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$args = array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'source'   => isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '',
			'per_page' => 1000,
		);
		// phpcs:enable

		$slugs = LetterWP_Setup::topic_slugs();
		$date  = function ( $gmt ) {
			return $gmt ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $gmt . ' UTC' ) ) : '';
		};
		// A spreadsheet runs a cell that starts like a formula: a name is never one.
		$cell = function ( $value ) {
			return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
		};

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="subscribers-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming to the response.
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // So Excel reads names as UTF-8.
		fputcsv( $out, array( 'email', 'first_name', 'last_name', 'status', 'topics_off', 'source', 'created_at', 'confirmed_at', 'unsubscribed_at' ), ',', '"', '' );

		for ( $page = 1; ; ++$page ) {
			$result  = LetterWP_Subscribers::query( array( 'page' => $page ) + $args );
			$optouts = LetterWP_Subscribers::optouts( wp_list_pluck( $result['rows'], 'id' ) );

			foreach ( $result['rows'] as $row ) {
				$off = isset( $optouts[ $row->id ] ) ? array_intersect_key( $slugs, array_flip( $optouts[ $row->id ] ) ) : array();
				fputcsv(
					$out,
					array(
						$row->email,
						$cell( $row->first_name ),
						$cell( $row->last_name ),
						$row->status,
						implode( ', ', $off ),
						$row->source,
						$date( $row->created_at ),
						$date( $row->confirmed_at ),
						$date( $row->unsubscribed_at ),
					),
					',',
					'"',
					''
				);
			}

			if ( count( $result['rows'] ) < $args['per_page'] ) {
				break;
			}
		}

		fclose( $out );
		// phpcs:enable
		exit;
	}

	/* -- Notices survive the redirect in a short per-user transient. ----- */

	/** Also used by the import screen. */
	public static function notice( $type, $message ) {
		set_transient( 'letterwp_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	public static function print_notice() {
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

		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
			$this->render_edit( absint( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$table   = $this->table;
		$filters = LetterWP_Subscribers_Table::filters();
		$counts  = LetterWP_Subscribers::counts();
		?>
		<div class="wrap letterwp">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'letterwp' ); ?></h1>
			<?php // Import and export are one-off jobs: they live under Subscribers → Import/Export, not here. ?>
			<a class="page-title-action letterwp-add-toggle" href="#letterwp-add" aria-controls="letterwp-add" aria-expanded="false"><?php esc_html_e( 'Add subscriber', 'letterwp' ); ?></a>
			<?php if ( '' !== $filters['search'] ) : ?>
				<span class="subtitle">
					<?php
					/* translators: %s: search keywords. */
					printf( esc_html__( 'Search results for: %s', 'letterwp' ), '<strong>' . esc_html( $filters['search'] ) . '</strong>' );
					?>
				</span>
			<?php endif; ?>
			<hr class="wp-header-end" />

			<?php $this->print_notice(); ?>
			<?php $this->sync_alert(); ?>

			<?php // Rarely used, so closed until "Add subscriber" (or :target, without JS); open on an empty list. ?>
			<div id="letterwp-add" class="letterwp-add-panel <?php echo $counts['all'] ? '' : 'is-open'; ?>">
				<?php $this->form_open(); ?>
					<input type="hidden" name="letterwp_do" value="add" />
					<div class="letterwp-add-fields">
						<p>
							<label for="letterwp-add-email"><?php esc_html_e( 'Email address', 'letterwp' ); ?></label>
							<input type="email" id="letterwp-add-email" name="letterwp_email" placeholder="reader@example.com" required spellcheck="false" />
						</p>
						<p>
							<label for="letterwp-add-first"><?php esc_html_e( 'First name', 'letterwp' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'letterwp' ); ?></span></label>
							<input type="text" id="letterwp-add-first" name="letterwp_first_name" />
						</p>
						<p>
							<label for="letterwp-add-last"><?php esc_html_e( 'Last name', 'letterwp' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'letterwp' ); ?></span></label>
							<input type="text" id="letterwp-add-last" name="letterwp_last_name" />
						</p>
						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Add subscriber', 'letterwp' ); ?></button>
						</p>
					</div>
					<p class="description"><?php esc_html_e( 'Added as active, with no confirmation email. Only add people who asked to subscribe.', 'letterwp' ); ?></p>
				</form>
			</div>

			<?php $table->views(); ?>

			<form id="letterwp-subscribers-filter" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<?php if ( $filters['status'] ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
				<?php endif; ?>
				<?php $table->search_box( __( 'Search Subscribers', 'letterwp' ), 'subscriber' ); ?>
				<?php $table->display(); ?>
			</form>

			<?php $this->sync_status(); ?>

			<p class="description letterwp-how">
				<?php
				printf(
					/* translators: %s: the [letterwp_subscribe] shortcode. */
					esc_html__( 'Readers subscribe with the Subscribe block (or %s) and confirm by email. They sign in with a link, no password, on the Account page that Setup makes.', 'letterwp' ),
					'<code>[letterwp_subscribe]</code>'
				);
				?>
			</p>
		</div>
		<style>
			.letterwp-add-panel:not(.is-open):not(:target) { display: none; }
			.letterwp-add-panel { margin: 16px 0 8px; padding: 4px 16px 8px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
			.letterwp-add-fields { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0 12px; }
			.letterwp-add-fields p { margin: 12px 0 0; }
			.letterwp-add-fields label { display: block; margin-bottom: 4px; font-weight: 600; }
			.letterwp-add-fields label .description { font-weight: 400; }
			#letterwp-add-email { width: 280px; }
			.letterwp-add-panel > form > .description { margin: 8px 0 4px; }
			.wp-list-table.subscribers .column-email { width: 30%; }
			.wp-list-table.subscribers .column-email strong { overflow-wrap: anywhere; }
			.wp-list-table.subscribers .column-topics,
			.wp-list-table.subscribers .column-status,
			.wp-list-table.subscribers .column-source { width: 12%; }
			.wp-list-table.subscribers .column-created_at { width: 14%; }
			.column-topics .is-all { color: #8c8f94; }
			.letterwp-status { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; }
			.letterwp-status.is-active { background: #edfaef; color: #008a20; }
			.letterwp-status.is-pending { background: #fcf9e8; color: #996800; }
			.letterwp-status.is-unsubscribed { background: #f0f0f1; color: #50575e; }
			.letterwp-status.is-bounced { background: #fcf0f1; color: #b32d2e; }
			.letterwp-sync { margin: 20px 0 0; padding: 10px 16px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; }
			.letterwp-sync form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
			.letterwp-sync .button { margin-left: auto; }
			.letterwp-how { margin-top: 16px; }
		</style>
		<script>
			( function () {
				var toggle = document.querySelector( '.letterwp-add-toggle' );
				var panel  = document.getElementById( 'letterwp-add' );
				if ( ! toggle || ! panel ) { return; }
				toggle.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var open = panel.classList.toggle( 'is-open' );
					toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
					if ( open ) { document.getElementById( 'letterwp-add-email' ).focus(); }
				} );
			} )();
		</script>
		<?php
	}

	/**
	 * "All", the topics they get, or "None". Only active and pending people
	 * get newsletters, so for everyone else there is nothing to say.
	 *
	 * @param int[] $off Term ids they turned off.
	 * @return string HTML.
	 */
	public static function topics_label( $row, array $off ) {
		static $names = null;

		if ( null === $names ) {
			$names = LetterWP_Setup::topic_names();
		}

		if ( ! in_array( $row->status, array( 'active', 'pending' ), true ) ) {
			return '—';
		}

		if ( ! $off ) {
			return '<span class="is-all">' . esc_html__( 'All', 'letterwp' ) . '</span>';
		}

		$on = array_diff_key( $names, array_flip( $off ) );

		return $on ? esc_html( implode( ', ', $on ) ) : esc_html__( 'None', 'letterwp' );
	}

	/* -- One subscriber ----------------------------------------------- */

	private function render_edit( $id ) {
		$row = LetterWP_Subscribers::get( $id );

		echo '<div class="wrap letterwp">';
		echo '<p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( '← All subscribers', 'letterwp' ) . '</a></p>';

		if ( ! $row ) {
			echo '<h1>' . esc_html__( 'Subscriber not found', 'letterwp' ) . '</h1></div>';
			return;
		}

		$statuses = LetterWP_Subscribers::statuses();
		$allowed  = LetterWP_Subscribers::allowed_statuses( $row->status );
		$date     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$when     = function ( $gmt ) use ( $date ) {
			return $gmt ? get_date_from_gmt( $gmt, $date ) : '—';
		};
		?>
			<h1><?php echo esc_html( LetterWP_Subscribers::full_name( $row ) ? LetterWP_Subscribers::full_name( $row ) : $row->email ); ?></h1>

			<?php $this->print_notice(); ?>

			<?php $this->form_open(); ?>
				<input type="hidden" name="letterwp_do" value="save" />
				<input type="hidden" name="letterwp_id" value="<?php echo esc_attr( $row->id ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'letterwp' ); ?></th>
						<td>
							<code><?php echo esc_html( $row->email ); ?></code>
							<p class="description"><?php esc_html_e( 'The email is how this person is known here and at Resend, so it cannot be changed. To use another address, add it as a new subscriber and delete this one.', 'letterwp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="letterwp-first"><?php esc_html_e( 'First name', 'letterwp' ); ?></label></th>
						<td><input type="text" id="letterwp-first" class="regular-text" name="letterwp_first_name" value="<?php echo esc_attr( $row->first_name ); ?>" maxlength="100" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="letterwp-last"><?php esc_html_e( 'Last name', 'letterwp' ); ?></label></th>
						<td><input type="text" id="letterwp-last" class="regular-text" name="letterwp_last_name" value="<?php echo esc_attr( $row->last_name ); ?>" maxlength="100" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="letterwp-status"><?php esc_html_e( 'Status', 'letterwp' ); ?></label></th>
						<td>
							<select id="letterwp-status" name="letterwp_status" <?php disabled( count( $allowed ) < 2 ); ?>>
								<?php foreach ( $allowed as $key ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row->status, $key ); ?>><?php echo esc_html( $statuses[ $key ] ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( in_array( $row->status, array( 'unsubscribed', 'bounced' ), true ) ) : ?>
								<p class="description"><?php esc_html_e( 'Only the reader can subscribe again, from a subscribe form. That is their consent, not ours to give.', 'letterwp' ); ?></p>
							<?php elseif ( 'pending' === $row->status ) : ?>
								<p class="description"><?php esc_html_e( 'Pending means they have not opened their confirmation link yet. Set Active only if they asked you directly.', 'letterwp' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Topics', 'letterwp' ); ?></th>
						<td>
							<?php
							LetterWP_Sync::pull_topics( $row ); // The reader may have changed them on Resend's unsubscribe page.
							$off   = LetterWP_Subscribers::optouts( array( $row->id ) );
							$off   = isset( $off[ $row->id ] ) ? $off[ $row->id ] : array();
							$names = LetterWP_Setup::topic_names();
							?>
							<input type="hidden" name="letterwp_topics_shown" value="1" />
							<fieldset>
								<?php foreach ( $names as $topic => $name ) : ?>
									<label class="letterwp-topic">
										<input type="checkbox" name="letterwp_topics[]" value="<?php echo esc_attr( $topic ); ?>" <?php checked( ! in_array( (int) $topic, $off, true ) ); ?> />
										<?php echo esc_html( $name ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'One topic per category, and Announcements for campaigns once one is sent. Everyone gets every topic unless they turn one off: unchecked means no emails for posts in that category, or no campaigns.', 'letterwp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'At Resend', 'letterwp' ); ?></th>
						<td><?php $this->resend_status( $row ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'History', 'letterwp' ); ?></th>
						<td class="letterwp-history">
							<?php
							/* translators: 1: date. 2: source, e.g. form. */
							echo esc_html( sprintf( __( 'Added %1$s, from %2$s', 'letterwp' ), $when( $row->created_at ), $row->source ? $row->source : '—' ) ) . '<br />';
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Confirmed %s', 'letterwp' ), $when( $row->confirmed_at ) ) ) . '<br />';
							/* translators: %s: date. */
							echo esc_html( sprintf( __( 'Unsubscribed %s', 'letterwp' ), $when( $row->unsubscribed_at ) ) );
							?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save changes', 'letterwp' ) ); ?>
			</form>
		</div>
		<style>
			.letterwp-history { color: #50575e; line-height: 1.9; }
			.letterwp-topic { display: inline-block; margin: 0 16px 4px 0; }
			.letterwp-pill { display: inline-block; margin-right: 6px; padding: 1px 8px; border-radius: 10px; font-size: 12px; }
			.letterwp-pill.is-ready { background: #edfaef; color: #008a20; }
			.letterwp-pill.is-waiting { background: #fcf9e8; color: #996800; }
		</style>
		<?php
	}

	/** What Resend knows about this person, read live. */
	private function resend_status( $row ) {
		if ( ! LetterWP_Sync::ready() ) {
			esc_html_e( 'Not synced: Resend is not connected and set up yet.', 'letterwp' );
			return;
		}

		$contact = LetterWP_Resend::contact( $row->email );

		if ( is_wp_error( $contact ) ) {
			echo esc_html( $contact->get_error_message() );
			return;
		}

		if ( ! $contact ) {
			echo esc_html( 'active' === $row->status ? __( 'Not a contact yet. Saving, or Sync to Resend now, adds them.', 'letterwp' ) : __( 'Not a contact. Only active subscribers are sent to Resend.', 'letterwp' ) );
			return;
		}

		$segments = LetterWP_Resend::request( 'GET', '/contacts/' . rawurlencode( $row->email ) . '/segments' );
		$in       = ! is_wp_error( $segments ) && in_array( LetterWP_Setup::segment_id(), array_column( isset( $segments['data'] ) ? $segments['data'] : array(), 'id' ), true );
		$name     = trim( $contact['first_name'] . ' ' . $contact['last_name'] );

		printf(
			'<span class="letterwp-pill %1$s">%2$s</span> %3$s',
			empty( $contact['unsubscribed'] ) && $in ? 'is-ready' : 'is-waiting',
			esc_html( empty( $contact['unsubscribed'] ) ? __( 'Subscribed', 'letterwp' ) : __( 'Unsubscribed', 'letterwp' ) ),
			esc_html(
				/* translators: 1: segment name. 2: the name Resend has. */
				sprintf( $in ? __( 'in %1$s · name: %2$s', 'letterwp' ) : __( 'not in %1$s · name: %2$s', 'letterwp' ), LetterWP_Setup::segment_name(), '' !== $name ? $name : '—' )
			)
		);
	}

	private function form_open() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION, '_wpnonce', false ); ?>
		<?php
	}
}
