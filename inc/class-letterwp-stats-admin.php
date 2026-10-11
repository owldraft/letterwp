<?php
/**
 * Each post's email numbers, where posts are: an "Email" column in Posts
 * (and in Campaigns), and one page per post (the column's link) with the
 * numbers and, for admins, who got it and what happened.
 *
 * The numbers are LetterWP_Stats'. The editor's panel shows the same, short.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Stats_Admin {

	const SLUG = 'letterwp-email';

	const COLUMN = 'letterwp_email';

	/** @var array<int,array|null>|null Numbers for the posts on this list page. */
	private $page_numbers = null;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		foreach ( LetterWP_Campaigns::types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'cell' ), 10, 2 );
		}
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_head-edit.php', array( $this, 'list_css' ) );
		add_action( 'admin_head', array( $this, 'hide_menu_entry' ) );
	}

	/**
	 * A page with no menu entry of its own: it is reached from a post. It is
	 * filed under Posts (and its entry hidden) so Posts stays open while
	 * on it: WordPress resets a hidden page's parent after the parent_file
	 * filter has run.
	 */
	public function admin_menu() {
		add_submenu_page(
			'edit.php',
			__( 'Email results', 'letterwp' ),
			__( 'Email results', 'letterwp' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** @return string The results page of one post. */
	public static function url( $post_id, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'post' => (int) $post_id ), array_filter( $args ) ), admin_url( 'admin.php' ) );
	}

	public function hide_menu_entry() {
		echo '<style>#menu-posts li:has(> a[href$="page=' . esc_attr( self::SLUG ) . '"]) { display: none; }</style>';
	}

	/* ---------------------------------------------------------------------
	 * Posts list.
	 * ------------------------------------------------------------------ */

	/** Before Date, after the comments bubble. */
	public function columns( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out[ self::COLUMN ] = __( 'Email', 'letterwp' );
			}
			$out[ $key ] = $label;
		}

		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Email', 'letterwp' );
		}

		return $out;
	}

	/** @return array|null The numbers for a post on this list page, all asked in one query. */
	private function numbers( $post_id ) {
		global $wp_query;

		if ( null === $this->page_numbers ) {
			$ids                = $wp_query && $wp_query->posts ? wp_list_pluck( $wp_query->posts, 'ID' ) : array();
			$this->page_numbers = LetterWP_Stats::for_posts( $ids );
		}

		if ( ! array_key_exists( $post_id, $this->page_numbers ) ) {
			$this->page_numbers[ $post_id ] = LetterWP_Stats::for_post( $post_id );
		}

		return $this->page_numbers[ $post_id ];
	}

	/**
	 * "21 sent", then how it went: "95% delivered", and anything wrong.
	 * Not sent yet, or never: a dash.
	 */
	public function cell( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$sent = LetterWP_Send::record( $post_id );

		if ( ! $sent ) {
			echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not emailed', 'letterwp' ) . '</span>';
			return;
		}

		if ( 'sending' === $sent['status'] ) {
			echo esc_html__( 'Sending…', 'letterwp' );
			return;
		}

		if ( 'failed' === $sent['status'] ) {
			echo '<span class="letterwp-bad">' . esc_html__( 'Not sent', 'letterwp' ) . '</span>';
			return;
		}

		$n = $this->numbers( $post_id );

		if ( ! $n ) {
			return;
		}

		printf(
			'<a href="%1$s" class="letterwp-sent">%2$s</a>',
			esc_url( self::url( $post_id ) ),
			/* translators: %s: number of readers. */
			esc_html( sprintf( _n( '%s sent', '%s sent', $n['recipients'], 'letterwp' ), number_format_i18n( $n['recipients'] ) ) )
		);

		echo '<span class="letterwp-how">' . wp_kses_post( implode( ' · ', self::summary( $n ) ) ) . '</span>';
	}

	/**
	 * The short version: delivered, then only what went wrong. Shared with
	 * the editor's panel through LetterWP_Editor.
	 *
	 * @return string[] HTML pieces.
	 */
	public static function summary( array $n ) {
		$parts = array();

		if ( $n['silent'] ) {
			return array( esc_html__( 'No results', 'letterwp' ) );
		}

		if ( $n['recipients'] && $n['waiting'] === $n['recipients'] ) {
			return array( esc_html__( 'Waiting for Resend…', 'letterwp' ) );
		}

		/* translators: %s: percentage. */
		$parts[] = '<span>' . esc_html( sprintf( __( '%s delivered', 'letterwp' ), LetterWP_Stats::percent( $n['rates']['delivered'] ) ) ) . '</span>';

		$bad = array(
			/* translators: %s: number of emails. */
			'bounced'      => _n_noop( '%s bounced', '%s bounced', 'letterwp' ),
			/* translators: %s: number of emails. */
			'complained'   => _n_noop( '%s spam report', '%s spam reports', 'letterwp' ),
			/* translators: %s: number of readers. */
			'unsubscribed' => _n_noop( '%s unsubscribed', '%s unsubscribed', 'letterwp' ),
			/* translators: %s: number of emails. */
			'failed'       => _n_noop( '%s failed', '%s failed', 'letterwp' ),
			/* translators: %s: number of emails. */
			'suppressed'   => _n_noop( '%s suppressed', '%s suppressed', 'letterwp' ),
		);

		foreach ( $bad as $key => $label ) {
			if ( $n[ $key ] ) {
				$text    = esc_html( sprintf( translate_nooped_plural( $label, $n[ $key ], 'letterwp' ), number_format_i18n( $n[ $key ] ) ) );
				$parts[] = ! empty( $n['warn'][ $key ] ) ? '<span class="letterwp-bad">' . $text . '</span>' : '<span>' . $text . '</span>';
			}
		}

		return $parts;
	}

	public function row_action( $actions, $post ) {
		if ( in_array( $post->post_type, LetterWP_Campaigns::types(), true ) && current_user_can( 'edit_post', $post->ID ) && $this->numbers( $post->ID ) ) {
			$actions['letterwp_email'] = '<a href="' . esc_url( self::url( $post->ID ) ) . '">' . esc_html__( 'Email results', 'letterwp' ) . '</a>';
		}

		return $actions;
	}

	public function list_css() {
		if ( ! in_array( get_current_screen()->post_type, LetterWP_Campaigns::types(), true ) ) {
			return;
		}
		?>
		<style>
			.fixed .column-<?php echo esc_attr( self::COLUMN ); ?> { width: 11%; }
			.column-<?php echo esc_attr( self::COLUMN ); ?> .letterwp-sent { font-weight: 600; }
			.column-<?php echo esc_attr( self::COLUMN ); ?> .letterwp-how { display: block; color: #646970; font-size: 12px; line-height: 1.5; margin-top: 2px; }
			.column-<?php echo esc_attr( self::COLUMN ); ?> .letterwp-how > span { white-space: nowrap; }
			.letterwp-bad { color: #b32d2e; }
		</style>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * One post's results.
	 * ------------------------------------------------------------------ */

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only view.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, LetterWP_Campaigns::types(), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'That post does not exist, or you cannot see it.', 'letterwp' ) );
		}

		$n        = LetterWP_Stats::for_post( $post_id );
		$campaign = LetterWP_Campaigns::is( $post );

		echo '<div class="wrap letterwp-results">';
		echo $campaign
			? '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . LetterWP_Campaigns::TYPE ) ) . '">' . esc_html__( '← All campaigns', 'letterwp' ) . '</a></p>'
			: '<p><a href="' . esc_url( admin_url( 'edit.php' ) ) . '">' . esc_html__( '← All posts', 'letterwp' ) . '</a></p>';
		echo '<h1>' . esc_html( get_the_title( $post ) ) . '</h1>';

		if ( ! $n ) {
			echo '<p>' . ( $campaign ? esc_html__( 'This email has not been sent.', 'letterwp' ) : esc_html__( 'This post has not been emailed.', 'letterwp' ) ) . ' <a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . ( $campaign ? esc_html__( 'Edit email', 'letterwp' ) : esc_html__( 'Edit post', 'letterwp' ) ) . '</a></p></div>';
			return;
		}

		$this->header( $post, $n );

		if ( $n['silent'] ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: link to Setup → 3. Your list in Resend. */
					__( 'No results came back from Resend for this email. Either it went out before LetterWP counted results, or the webhook is off: check its row in %s.', 'letterwp' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . LetterWP_Admin::SLUG . '#letterwp-setup' ) ) . '">' . esc_html__( 'Setup → 3. Your list in Resend', 'letterwp' ) . '</a>'
				)
			) . '</p></div>';
			echo '</div>';
			$this->css();
			return;
		}

		$this->tiles( $n );
		$this->warnings( $n );

		if ( current_user_can( 'manage_options' ) ) {
			$this->recipients( $post_id, $n, $status, $search, $paged );
		}

		echo '<p class="description letterwp-note">'
			. esc_html__( 'Delivered means the reader\'s mail server accepted the email; it can\'t tell the inbox from the spam folder. Opens and clicks are not tracked: no tracking pixel, no rewritten links. An unsubscribe counts against the last post the reader got in the 30 days before.', 'letterwp' )
			. '</p>';
		echo '</div>';

		$this->css();
	}

	/** Sent when, to how many, under which topic, and the links out. */
	private function header( WP_Post $post, array $n ) {
		$sent  = LetterWP_Send::record( $post->ID );
		$names = LetterWP_Setup::topic_names();
		$topic = isset( $sent['topic'], $names[ (int) $sent['topic'] ] ) ? $names[ (int) $sent['topic'] ] : '';
		$links = LetterWP_Campaigns::is( $post )
			? array( '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'Edit email', 'letterwp' ) . '</a>' )
			: array(
				'<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html__( 'View post', 'letterwp' ) . '</a>',
				'<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'Edit post', 'letterwp' ) . '</a>',
			);

		if ( '' !== $n['broadcast'] ) {
			$links[] = '<a href="' . esc_url( LetterWP_Resend::broadcast_url( $n['broadcast'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See it at Resend ↗', 'letterwp' ) . '</a>';
		}

		echo '<p class="letterwp-meta">';
		printf(
			/* translators: 1: date and time. 2: number of readers. */
			esc_html( _n( 'Sent %1$s to %2$s reader', 'Sent %1$s to %2$s readers', $n['recipients'], 'letterwp' ) ),
			esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $n['time'] ) ),
			esc_html( number_format_i18n( $n['recipients'] ) )
		);

		if ( '' !== $topic ) {
			/* translators: %s: topic name: a category, or Announcements. */
			echo esc_html( sprintf( __( ', under %s', 'letterwp' ), $topic ) );
		}

		echo '. ' . implode( ' · ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/** The numbers, big. The quiet ones only when they are not zero. */
	private function tiles( array $n ) {
		$tiles = array(
			array( __( 'Recipients', 'letterwp' ), $n['recipients'], '' , '' ),
			array( __( 'Delivered', 'letterwp' ), $n['delivered'], $n['rates']['delivered'], '' ),
			array( __( 'Bounced', 'letterwp' ), $n['bounced'], $n['rates']['bounced'], $n['warn']['bounced'] ? 'is-bad' : '' ),
			array( __( 'Marked as spam', 'letterwp' ), $n['complained'], $n['rates']['complained'], $n['warn']['complained'] ? 'is-bad' : '' ),
			array( __( 'Unsubscribed', 'letterwp' ), $n['unsubscribed'], $n['rates']['unsubscribed'], '' ),
		);

		$quiet = array(
			'waiting'    => __( 'No news yet', 'letterwp' ),
			'delayed'    => __( 'Delayed', 'letterwp' ),
			'failed'     => __( 'Failed', 'letterwp' ),
			'suppressed' => __( 'Suppressed', 'letterwp' ),
		);

		foreach ( $quiet as $key => $label ) {
			if ( $n[ $key ] > 0 ) {
				$tiles[] = array( $label, $n[ $key ], '', 'is-quiet' );
			}
		}

		echo '<div class="letterwp-tiles">';

		foreach ( $tiles as $tile ) {
			list( $label, $count, $rate, $class ) = $tile;

			echo '<div class="letterwp-tile ' . esc_attr( $class ) . '">';
			echo '<div class="letterwp-tile-label">' . esc_html( $label ) . '</div>';
			echo '<div class="letterwp-tile-value">' . esc_html( number_format_i18n( $count ) ) . '</div>';
			echo '<div class="letterwp-tile-rate">' . ( '' === $rate ? '&nbsp;' : esc_html( LetterWP_Stats::percent( $rate ) ) ) . '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/** Over Resend's limits: what it means and what to do. */
	private function warnings( array $n ) {
		if ( $n['warn']['bounced'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: this email's bounce rate. 2: Resend's limit. */
					__( 'Bounce rate %1$s: Resend asks senders to stay under %2$s and may pause sending above it. Bounced addresses are already marked as bounced and won\'t be emailed again. If many came from an import, check where that list came from.', 'letterwp' ),
					LetterWP_Stats::percent( $n['rates']['bounced'] ),
					LetterWP_Stats::percent( LetterWP_Stats::BOUNCE_LIMIT )
				)
			) . '</p></div>';
		}

		if ( $n['warn']['complained'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: this email's spam rate. 2: Resend's limit. */
					__( 'Spam rate %1$s: Resend asks senders to stay under %2$s. Readers who marked it as spam are unsubscribed already. A clear "why you get this" and an easy unsubscribe keep this down.', 'letterwp' ),
					LetterWP_Stats::percent( $n['rates']['complained'] ),
					LetterWP_Stats::percent( LetterWP_Stats::COMPLAINT_LIMIT )
				)
			) . '</p></div>';
		}
	}

	/** Who got it and what happened, by result. For admins: these are readers' addresses. */
	private function recipients( $post_id, array $n, $status, $search, $paged ) {
		$filters = array(
			''             => array( __( 'All', 'letterwp' ), $n['recipients'] - $n['waiting'] ),
			'delivered'    => array( __( 'Delivered', 'letterwp' ), $n['delivered'] ),
			'bounced'      => array( __( 'Bounced', 'letterwp' ), $n['bounced'] ),
			'complained'   => array( __( 'Marked as spam', 'letterwp' ), $n['complained'] ),
			'unsubscribed' => array( __( 'Unsubscribed', 'letterwp' ), $n['unsubscribed'] ),
			'delayed'      => array( __( 'Delayed', 'letterwp' ), $n['delayed'] ),
			'failed'       => array( __( 'Failed', 'letterwp' ), $n['failed'] ),
			'suppressed'   => array( __( 'Suppressed', 'letterwp' ), $n['suppressed'] ),
		);
		$status  = isset( $filters[ $status ] ) ? $status : '';
		$result  = LetterWP_Stats::recipients(
			$post_id,
			array(
				'status' => $status,
				'search' => $search,
				'page'   => $paged,
			)
		);
		$labels  = LetterWP_Stats::statuses();
		$links   = array();

		foreach ( $filters as $key => $filter ) {
			// The rare results only once something has them.
			if ( '' !== $key && ! $filter[1] && $key !== $status && ! in_array( $key, array( 'delivered', 'bounced', 'complained', 'unsubscribed' ), true ) ) {
				continue;
			}

			$links[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( self::url( $post_id, array( 'status' => $key ) ) ),
				$key === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $filter[0] ),
				esc_html( number_format_i18n( $filter[1] ) )
			);
		}
		?>
		<h2><?php esc_html_e( 'Recipients', 'letterwp' ); ?></h2>

		<ul class="subsubsub"><?php echo implode( ' |</li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?></ul>

		<form method="get" class="search-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="post" value="<?php echo esc_attr( $post_id ); ?>" />
			<?php if ( $status ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>" />
			<?php endif; ?>
			<p class="search-box">
				<label class="screen-reader-text" for="letterwp-search"><?php esc_html_e( 'Search recipients', 'letterwp' ); ?></label>
				<input type="search" id="letterwp-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email…', 'letterwp' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'letterwp' ); ?></button>
			</p>
		</form>

		<table class="widefat striped letterwp-recipients">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Email', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Result', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Unsubscribed after', 'letterwp' ); ?></th>
					<th><?php esc_html_e( 'Last news', 'letterwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $result['rows'] ) : ?>
					<tr><td colspan="4">
						<?php
						echo esc_html(
							$search || $status
								? __( 'No recipients match.', 'letterwp' )
								: __( 'No news from Resend yet. Results come in over a few minutes after sending.', 'letterwp' )
						);
						?>
					</td></tr>
				<?php endif; ?>

				<?php foreach ( $result['rows'] as $row ) : ?>
					<tr>
						<td>
							<?php if ( $row->subscriber_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LetterWP_Subscribers_Admin::SLUG . '&edit=' . (int) $row->subscriber_id ) ); ?>"><?php echo esc_html( $row->email ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row->email ); ?>
							<?php endif; ?>
						</td>
						<td><span class="letterwp-result is-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( isset( $labels[ $row->status ] ) ? $labels[ $row->status ] : $row->status ); ?></span></td>
						<td><?php echo $row->unsubscribed ? esc_html__( 'Yes', 'letterwp' ) : '<span aria-hidden="true">—</span>'; ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $row->updated_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $result['total'] / LetterWP_Subscribers::PER_PAGE );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( $post_id, array( 'status' => $status, 's' => $search ) ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	private function css() {
		?>
		<style>
			.letterwp-results { max-width: 960px; }
			.letterwp-meta { color: #50575e; }
			.letterwp-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin: 20px 0; }
			.letterwp-tile { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 14px 16px; }
			.letterwp-tile-label { color: #50575e; font-size: 13px; }
			.letterwp-tile-value { font-size: 28px; line-height: 1.3; font-weight: 600; color: #1d2327; margin-top: 4px; }
			.letterwp-tile-rate { color: #646970; font-size: 13px; }
			.letterwp-tile.is-bad { border-color: #d63638; }
			.letterwp-tile.is-bad .letterwp-tile-value, .letterwp-tile.is-bad .letterwp-tile-rate { color: #b32d2e; }
			.letterwp-tile.is-quiet { background: #f6f7f7; }
			.letterwp-tile.is-quiet .letterwp-tile-value { font-size: 20px; color: #50575e; }
			.letterwp-results .notice { max-width: 960px; }
			.letterwp-results h2 { margin-top: 28px; }
			.letterwp-results .search-form { float: right; margin-top: 6px; }
			.letterwp-results .search-box { margin: 0; }
			.letterwp-recipients { clear: both; }
			.letterwp-result { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #f0f0f1; color: #50575e; }
			.letterwp-result.is-delivered { background: #edfaef; color: #008a20; }
			.letterwp-result.is-delayed { background: #fcf9e8; color: #996800; }
			.letterwp-result.is-bounced, .letterwp-result.is-complained, .letterwp-result.is-failed { background: #fcf0f1; color: #b32d2e; }
			.letterwp-note { margin-top: 24px; }
		</style>
		<?php
	}
}
