<?php
/**
 * Subscribers → Import/Export: upload a CSV, review what was found, import;
 * or download the list as CSV (LetterWP_Subscribers_Admin::export()).
 *
 * Three steps, each its own screen, so a refresh never imports twice:
 *   upload  the form (no step)
 *   review  what the file holds, read from a per-user transient
 *   done    what was imported, then the new people go to Resend in batches
 *           while the page is open (LetterWP_Sync::drain(), over AJAX)
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Import_Admin {

	const SLUG = 'letterwp-subscribers-import';

	const ACTION = 'letterwp_import';

	/** Rows shown in the review step. */
	const SAMPLE = 10;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 11 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_post' ) );
		add_action( 'wp_ajax_letterwp_sync_queue', array( $this, 'ajax_drain' ) );
	}

	public function admin_menu() {
		add_submenu_page(
			LetterWP_Subscribers_Admin::SLUG,
			__( 'Import/Export subscribers', 'letterwp' ),
			__( 'Import/Export', 'letterwp' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** @return string This screen, at a step. */
	public static function url( $step = '' ) {
		return add_query_arg( array_filter( array( 'page' => self::SLUG, 'step' => $step ) ), admin_url( 'admin.php' ) );
	}

	/** @return string Per user: two admins importing at once don't mix files. */
	private static function key( $what ) {
		return 'letterwp_import_' . $what . '_' . get_current_user_id();
	}

	/* ---------------------------------------------------------------------
	 * Save.
	 * ------------------------------------------------------------------ */

	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change this.', 'letterwp' ) );
		}

		check_admin_referer( self::ACTION );

		$do = isset( $_POST['letterwp_do'] ) ? sanitize_key( wp_unslash( $_POST['letterwp_do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked above.

		if ( 'read' === $do ) {
			$step = $this->read();
		} elseif ( 'import' === $do ) {
			$step = $this->import();
		} else {
			$step = '';
		}

		wp_safe_redirect( self::url( $step ) );
		exit;
	}

	/** @return string The step to go to. */
	private function read() {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- checked in handle_post(); a file upload, read as data.
		$file = isset( $_FILES['letterwp_file'] ) ? $_FILES['letterwp_file'] : null;
		// phpcs:enable

		if ( ! $file || UPLOAD_ERR_NO_FILE === $file['error'] ) {
			LetterWP_Subscribers_Admin::notice( 'error', __( 'Choose a CSV file first.', 'letterwp' ) );
			return '';
		}

		if ( in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			/* translators: %s: size, e.g. 2 MB. */
			LetterWP_Subscribers_Admin::notice( 'error', sprintf( __( 'That file is bigger than this site accepts (%s). Split it into smaller files, or raise upload_max_filesize.', 'letterwp' ), size_format( wp_max_upload_size() ) ) );
			return '';
		}

		if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			LetterWP_Subscribers_Admin::notice( 'error', __( 'The upload failed. Try again.', 'letterwp' ) );
			return '';
		}

		if ( ! preg_match( '/\.(csv|txt|tsv)$/i', (string) $file['name'] ) ) {
			LetterWP_Subscribers_Admin::notice( 'error', __( 'That is not a CSV file. Export your subscribers as CSV and upload that.', 'letterwp' ) );
			return '';
		}

		$read = LetterWP_Import::read( $file['tmp_name'] );

		if ( is_wp_error( $read ) ) {
			LetterWP_Subscribers_Admin::notice( 'error', $read->get_error_message() );
			return '';
		}

		$read['file'] = sanitize_file_name( $file['name'] );

		set_transient( self::key( 'file' ), $read, HOUR_IN_SECONDS );

		return 'review';
	}

	/** @return string The step to go to. */
	private function import() {
		$read = get_transient( self::key( 'file' ) );

		if ( ! is_array( $read ) ) {
			LetterWP_Subscribers_Admin::notice( 'error', __( 'That upload expired. Choose the file again.', 'letterwp' ) );
			return '';
		}

		delete_transient( self::key( 'file' ) );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
		}

		$done = LetterWP_Subscribers::import( $read['rows'] );

		$by_status = array();
		if ( $done['added'] ) {
			global $wpdb;
			$ids = array_map( 'intval', $done['added'] );
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in is one %d per id, built above.
			$by_status = wp_list_pluck( $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM %i WHERE id IN ($in) GROUP BY status", array_merge( array( LetterWP_Subscribers::table() ), $ids ) ) ), 'n', 'status' );
		}

		set_transient(
			self::key( 'done' ),
			array(
				'file'      => $read['file'],
				'added'     => count( $done['added'] ),
				'by_status' => array_map( 'intval', $by_status ),
				'existing'  => $done['existing'],
			),
			HOUR_IN_SECONDS
		);

		return 'done';
	}

	/** One batch of the Resend queue, for the progress bar. */
	public function ajax_drain() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'letterwp_sync_queue', false, false ) ) {
			wp_send_json_error( __( 'You are not allowed to do this. Reload the page.', 'letterwp' ), 403 );
		}

		wp_send_json_success( LetterWP_Sync::drain() );
	}

	/* ---------------------------------------------------------------------
	 * Render.
	 * ------------------------------------------------------------------ */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		?>
		<div class="wrap letterwp">
			<h1><?php esc_html_e( 'Import/Export subscribers', 'letterwp' ); ?></h1>
			<?php
			LetterWP_Subscribers_Admin::print_notice();

			if ( 'review' === $step && is_array( get_transient( self::key( 'file' ) ) ) ) {
				$this->render_review( get_transient( self::key( 'file' ) ) );
			} elseif ( 'done' === $step || 'sync' === $step ) {
				$this->render_done( 'done' === $step ? get_transient( self::key( 'done' ) ) : null );
			} else {
				$this->render_upload();
			}
			?>
		</div>
		<style>
			.letterwp-import { max-width: 760px; }
			.letterwp-import .card { max-width: none; }
			.letterwp-import-file { margin: 12px 0; }
			.letterwp-import h2 { margin-top: 28px; }
			.letterwp-import h2:first-child { margin-top: 16px; }
			#letterwp-export-status { margin-left: 8px; }
			.letterwp-import-facts td, .letterwp-import-facts th { padding: 6px 10px 6px 0; text-align: left; }
			.letterwp-import-sample { margin: 8px 0 16px; }
			.letterwp-import-skip { color: #646970; }
			.letterwp-progress { height: 8px; background: #f0f0f1; border-radius: 4px; overflow: hidden; margin: 12px 0 8px; }
			.letterwp-progress span { display: block; height: 100%; width: 0; background: #2271b1; transition: width .3s; }
			.letterwp-queue-errors { color: #b32d2e; }
			.letterwp-status { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; }
			.letterwp-status.is-active { background: #edfaef; color: #008a20; }
			.letterwp-status.is-unsubscribed { background: #f0f0f1; color: #50575e; }
			.letterwp-status.is-bounced { background: #fcf0f1; color: #b32d2e; }
		</style>
		<?php
	}

	private function render_upload() {
		?>
		<div class="letterwp-import">
			<h2><?php esc_html_e( 'Import', 'letterwp' ); ?></h2>
			<p><?php esc_html_e( 'Bring your list from Substack, beehiiv, Ghost, Mailchimp, Kit, or anywhere else: any CSV file with an email column works. Names and the date each person subscribed come along when the file has them.', 'letterwp' ); ?></p>
			<p><?php esc_html_e( 'Leaving Substack? Use the Export button on your Substack Subscribers page and upload that file here.', 'letterwp' ); ?></p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<input type="hidden" name="letterwp_do" value="read" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<p class="letterwp-import-file">
					<label class="screen-reader-text" for="letterwp-file"><?php esc_html_e( 'CSV file', 'letterwp' ); ?></label>
					<input type="file" id="letterwp-file" name="letterwp_file" accept=".csv,.txt,.tsv,text/csv" required />
				</p>
				<p class="description">
					<?php
					/* translators: %s: size, e.g. 2 MB. */
					echo esc_html( sprintf( __( 'Up to %s. Nothing is imported yet: the next screen shows what was found.', 'letterwp' ), size_format( wp_max_upload_size() ) ) );
					?>
				</p>
				<?php submit_button( __( 'Upload and review', 'letterwp' ) ); ?>
			</form>

			<?php $this->render_export(); ?>
		</div>
		<?php
	}

	/** Every column LetterWP keeps, as CSV: the same file Import reads back. */
	private function render_export() {
		$counts = LetterWP_Subscribers::counts();
		?>
		<h2><?php esc_html_e( 'Export', 'letterwp' ); ?></h2>
		<p><?php esc_html_e( 'Download your subscribers as a CSV file: email, names, status, the topics each person turned off, source and dates. Import reads the same file back, here or on another site.', 'letterwp' ); ?></p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( LetterWP_Subscribers_Admin::EXPORT ); ?>" />
			<?php wp_nonce_field( LetterWP_Subscribers_Admin::EXPORT, '_wpnonce', false ); ?>
			<p>
				<label for="letterwp-export-status"><?php esc_html_e( 'Subscribers', 'letterwp' ); ?></label>
				<select id="letterwp-export-status" name="status">
					<option value="">
						<?php
						/* translators: %s: number of subscribers. */
						echo esc_html( sprintf( __( 'All (%s)', 'letterwp' ), number_format_i18n( $counts['all'] ) ) );
						?>
					</option>
					<?php foreach ( LetterWP_Subscribers::statuses() as $status => $label ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( sprintf( '%1$s (%2$s)', $label, number_format_i18n( $counts[ $status ] ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php submit_button( __( 'Download CSV', 'letterwp' ), 'secondary' ); ?>
		</form>
		<?php
	}

	private function render_review( array $read ) {
		$labels   = array(
			'email'      => __( 'Email', 'letterwp' ),
			'first_name' => __( 'First name', 'letterwp' ),
			'last_name'  => __( 'Last name', 'letterwp' ),
			'name'       => __( 'Name (split into first and last)', 'letterwp' ),
			'date'       => __( 'Date subscribed', 'letterwp' ),
			'status'     => __( 'Status', 'letterwp' ),
			'email_off'  => __( 'Emails turned off', 'letterwp' ),
			'email_on'   => __( 'Gets emails', 'letterwp' ),
			'topics_off' => __( 'Topics turned off', 'letterwp' ),
		);
		$statuses = LetterWP_Subscribers::statuses();
		$have     = LetterWP_Subscribers::existing( array_column( $read['rows'], 0 ) );
		$new      = array();
		foreach ( $read['rows'] as $row ) {
			if ( ! isset( $have[ $row[0] ] ) ) {
				$new[ $row[3] ] = isset( $new[ $row[3] ] ) ? $new[ $row[3] ] + 1 : 1;
			}
		}
		$total = array_sum( $new );
		$date  = get_option( 'date_format' );
		?>
		<div class="letterwp-import">
			<h2>
				<?php
				echo esc_html( $read['file'] );
				if ( $read['looks_like'] ) {
					/* translators: %s: a service, e.g. Substack. */
					echo ' <span class="description">' . esc_html( sprintf( __( '· looks like a %s export', 'letterwp' ), $read['looks_like'] ) ) . '</span>';
				}
				?>
			</h2>

			<table class="letterwp-import-facts">
				<?php foreach ( $read['columns'] as $field => $column ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $labels[ $field ] ); ?></th>
						<td><?php /* translators: %s: a column name from the file. */ echo esc_html( sprintf( __( 'from the "%s" column', 'letterwp' ), $column ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h3><?php esc_html_e( 'What will happen', 'letterwp' ); ?></h3>
			<ul class="ul-disc">
				<?php if ( ! empty( $new['active'] ) ) : ?>
					<li><strong><?php echo esc_html( sprintf( /* translators: %s: number. */ _n( '%s new active subscriber', '%s new active subscribers', $new['active'], 'letterwp' ), number_format_i18n( $new['active'] ) ) ); ?></strong>: <?php esc_html_e( 'they get your next post, with no confirmation email.', 'letterwp' ); ?></li>
				<?php endif; ?>
				<?php foreach ( array( 'unsubscribed', 'bounced' ) as $status ) : ?>
					<?php if ( ! empty( $new[ $status ] ) ) : ?>
						<li><?php echo esc_html( sprintf( /* translators: 1: number. 2: status, e.g. Unsubscribed. */ __( '%1$s kept as %2$s: on the list, never emailed.', 'letterwp' ), number_format_i18n( $new[ $status ] ), strtolower( $statuses[ $status ] ) ) ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( $have ) : ?>
					<li class="letterwp-import-skip"><?php echo esc_html( sprintf( /* translators: %s: number. */ _n( '%s already on your list: left as they are.', '%s already on your list: left as they are.', count( $have ), 'letterwp' ), number_format_i18n( count( $have ) ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( $read['unconfirmed'] ) : ?>
					<li class="letterwp-import-skip"><?php echo esc_html( sprintf( /* translators: %s: number. */ _n( '%s never confirmed their subscription: left out.', '%s never confirmed their subscription: left out.', $read['unconfirmed'], 'letterwp' ), number_format_i18n( $read['unconfirmed'] ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( $read['duplicates'] ) : ?>
					<li class="letterwp-import-skip"><?php echo esc_html( sprintf( /* translators: %s: number. */ _n( '%s repeated row: imported once.', '%s repeated rows: imported once.', $read['duplicates'], 'letterwp' ), number_format_i18n( $read['duplicates'] ) ) ); ?></li>
				<?php endif; ?>
				<?php if ( $read['invalid'] ) : ?>
					<li class="letterwp-import-skip">
						<?php echo esc_html( sprintf( /* translators: %s: number. */ _n( '%s row without a valid email: left out.', '%s rows without a valid email: left out.', $read['invalid'], 'letterwp' ), number_format_i18n( $read['invalid'] ) ) ); ?>
						<?php if ( $read['invalid_samples'] ) : ?>
							(<code><?php echo implode( '</code>, <code>', array_map( 'esc_html', $read['invalid_samples'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the map. ?></code>)
						<?php endif; ?>
					</li>
				<?php endif; ?>
			</ul>

			<?php if ( $total ) : ?>
				<?php
				$sample = array();
				foreach ( $read['rows'] as $row ) {
					if ( ! isset( $have[ $row[0] ] ) ) {
						$sample[] = $row;
					}
					if ( count( $sample ) >= self::SAMPLE ) {
						break;
					}
				}
				?>
				<table class="widefat striped letterwp-import-sample">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Email', 'letterwp' ); ?></th>
							<th><?php esc_html_e( 'First name', 'letterwp' ); ?></th>
							<th><?php esc_html_e( 'Last name', 'letterwp' ); ?></th>
							<th><?php esc_html_e( 'Status', 'letterwp' ); ?></th>
							<th><?php esc_html_e( 'Subscribed', 'letterwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sample as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row[0] ); ?></td>
								<td><?php echo esc_html( $row[1] ); ?></td>
								<td><?php echo esc_html( $row[2] ); ?></td>
								<td><span class="letterwp-status is-<?php echo esc_attr( $row[3] ); ?>"><?php echo esc_html( $statuses[ $row[3] ] ); ?></span></td>
								<td><?php echo esc_html( get_date_from_gmt( $row[4], $date ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $total > count( $sample ) ) : ?>
					<p class="description"><?php echo esc_html( sprintf( /* translators: 1: shown. 2: total. */ __( 'The first %1$s of %2$s.', 'letterwp' ), number_format_i18n( count( $sample ) ), number_format_i18n( $total ) ) ); ?></p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<input type="hidden" name="letterwp_do" value="import" />
					<?php wp_nonce_field( self::ACTION ); ?>
					<p class="description"><?php esc_html_e( 'Only import people who subscribed to you. Mail to people who did not ask for it gets reported as spam, and that can get your domain blocked.', 'letterwp' ); ?></p>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %s: number. */ _n( 'Import %s subscriber', 'Import %s subscribers', $total, 'letterwp' ), number_format_i18n( $total ) ) ); ?></button>
						<a class="button-link" style="margin-left:12px" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Choose another file', 'letterwp' ); ?></a>
					</p>
				</form>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Nobody new to import from this file.', 'letterwp' ); ?></strong></p>
				<p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Choose another file', 'letterwp' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array|null $done What the import did; null when only the queue is left to send. */
	private function render_done( $done ) {
		$left     = count( LetterWP_Sync::queue() );
		$statuses = LetterWP_Subscribers::statuses();
		?>
		<div class="letterwp-import">
			<?php if ( is_array( $done ) ) : ?>
				<div class="notice notice-success inline">
					<p>
						<strong><?php echo esc_html( sprintf( /* translators: 1: number. 2: file name. */ _n( 'Imported %1$s subscriber from %2$s.', 'Imported %1$s subscribers from %2$s.', $done['added'], 'letterwp' ), number_format_i18n( $done['added'] ), $done['file'] ) ); ?></strong>
						<?php
						$parts = array();
						foreach ( $done['by_status'] as $status => $n ) {
							$parts[] = number_format_i18n( $n ) . ' ' . strtolower( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status );
						}
						if ( $done['existing'] ) {
							/* translators: %s: number. */
							$parts[] = sprintf( __( '%s already on the list, left as they were', 'letterwp' ), number_format_i18n( $done['existing'] ) );
						}
						echo esc_html( $parts ? implode( ', ', $parts ) . '.' : '' );
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $left ) : ?>
				<div id="letterwp-queue" data-total="<?php echo esc_attr( $left ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'letterwp_sync_queue' ) ); ?>">
					<h2><?php esc_html_e( 'Adding them to Resend', 'letterwp' ); ?></h2>
					<div class="letterwp-progress"><span></span></div>
					<p class="letterwp-queue-text"><?php echo esc_html( sprintf( /* translators: %s: number. */ __( '%s to go…', 'letterwp' ), number_format_i18n( $left ) ) ); ?></p>
					<p class="description"><?php esc_html_e( 'Resend takes them one at a time: a few minutes for every few hundred people. Keep this page open; if you leave, come back from Subscribers to carry on.', 'letterwp' ); ?></p>
					<p class="letterwp-queue-errors"></p>
					<p class="letterwp-queue-retry" hidden><button type="button" class="button"><?php esc_html_e( 'Try again', 'letterwp' ); ?></button></p>
				</div>
				<?php $this->queue_js(); ?>
			<?php elseif ( is_array( $done ) && ! LetterWP_Sync::ready() ) : ?>
				<p><?php esc_html_e( 'Resend is not set up yet, so nobody was sent there. After LetterWP → Setup, use "Sync to Resend now" on the Subscribers screen.', 'letterwp' ); ?></p>
			<?php elseif ( is_array( $done ) ) : ?>
				<p><?php esc_html_e( 'Everyone is in Resend. They get your next post.', 'letterwp' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Nothing is waiting for Resend.', 'letterwp' ); ?></p>
			<?php endif; ?>

			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . LetterWP_Subscribers_Admin::SLUG ) ); ?>"><?php esc_html_e( 'Go to Subscribers', 'letterwp' ); ?></a>
				<a class="button-link" style="margin-left:12px" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Import another file', 'letterwp' ); ?></a>
			</p>
		</div>
		<?php
	}

	/** Calls drain() until the queue is empty: each call is one batch of about 20 seconds. */
	private function queue_js() {
		?>
		<script>
			( function () {
				var box = document.getElementById( 'letterwp-queue' );
				var total = +box.dataset.total;
				var bar = box.querySelector( '.letterwp-progress span' );
				var text = box.querySelector( '.letterwp-queue-text' );
				var errors = box.querySelector( '.letterwp-queue-errors' );
				var retry = box.querySelector( '.letterwp-queue-retry' );
				var failed = 0;
				var t = <?php echo wp_json_encode( array(
					/* translators: 1: subscribers added so far, 2: all of them, 3: how many are left. */
					'left'    => __( '%1$s of %2$s added. %3$s to go…', 'letterwp' ),
					'done'    => __( 'Everyone is in Resend. They get your next post.', 'letterwp' ),
					'partly'  => __( 'Finished. Everyone else is in Resend.', 'letterwp' ),
					/* translators: %s: number of subscribers. */
					'failed'  => __( '%s could not be added. "Sync to Resend now" on Subscribers tries them again.', 'letterwp' ),
					'stopped' => __( 'Stopped: Resend kept refusing. Check the Resend key in LetterWP → Setup, then try again.', 'letterwp' ),
					'error'   => __( 'The connection dropped. Try again.', 'letterwp' ),
				) ); ?>;
				function fmt( s, a ) {
					return a.reduce( function ( out, v, i ) { return out.replace( '%' + ( i + 1 ) + '$s', v ).replace( '%s', v ); }, s );
				}
				function show( left ) {
					var done = Math.max( 0, total - left );
					bar.style.width = ( total ? 100 * done / total : 100 ) + '%';
					text.textContent = left ? fmt( t.left, [ done, total, left ] ) : ( failed ? t.partly : t.done );
				}
				function stop( message ) {
					errors.textContent = message;
					retry.hidden = false;
				}
				function step() {
					var body = new FormData();
					body.append( 'action', 'letterwp_sync_queue' );
					body.append( '_ajax_nonce', box.dataset.nonce );
					fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res.success ) { return stop( res.data || t.error ); }
							var d = res.data;
							failed += d.failed;
							if ( d.left > total ) { total = d.left; }
							show( d.left );
							errors.textContent = failed ? fmt( t.failed, [ failed ] ) + ( d.errors.length ? ' (' + d.errors.join( '; ' ) + ')' : '' ) : '';
							if ( d.stopped ) { return stop( t.stopped ); }
							if ( d.busy ) { return setTimeout( step, 5000 ); }
							if ( d.left ) { step(); }
						} )
						.catch( function () { stop( t.error ); } );
				}
				retry.querySelector( 'button' ).addEventListener( 'click', function () {
					retry.hidden = true;
					errors.textContent = '';
					step();
				} );
				step();
			} )();
		</script>
		<?php
	}
}
