<?php
/**
 * The Subscribers list, drawn by WordPress's own WP_List_Table, so it looks
 * and works like Posts: views, search, bulk actions, a filter, sortable
 * columns, row actions on hover, and Screen Options (per page, columns).
 *
 * Loaded only on that screen: WP_List_Table is not part of every request.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class LetterWP_Subscribers_Table extends WP_List_Table {

	/** @var array<int,int[]> Topics each row on this page turned off. */
	private $optouts = array();

	/** @var array<string,int> */
	private $counts = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);
	}

	/** @return string This screen, with the given args. */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => LetterWP_Subscribers_Admin::SLUG ), array_filter( $args ) ), admin_url( 'admin.php' ) );
	}

	/** @return array{search:string,status:string,source:string,orderby:string,order:string} The filters asked for. */
	public static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';

		return array(
			'search'  => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'status'  => isset( LetterWP_Subscribers::statuses()[ $status ] ) ? $status : '',
			'source'  => isset( $_REQUEST['source'] ) ? sanitize_key( wp_unslash( $_REQUEST['source'] ) ) : '',
			'orderby' => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '',
			'order'   => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : '',
		);
		// phpcs:enable
	}

	public function prepare_items() {
		$per_page     = $this->get_items_per_page( LetterWP_Subscribers_Admin::PER_PAGE_OPTION, 20 );
		$this->counts = LetterWP_Subscribers::counts();
		$result       = LetterWP_Subscribers::query(
			self::filters() + array(
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);

		$this->items   = $result['rows'];
		$this->optouts = LetterWP_Subscribers::optouts( wp_list_pluck( $this->items, 'id' ) );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'email'      => __( 'Email', 'letterwp' ),
			'name'       => __( 'Name', 'letterwp' ),
			'topics'     => __( 'Topics', 'letterwp' ),
			'status'     => __( 'Status', 'letterwp' ),
			'source'     => __( 'Source', 'letterwp' ),
			'created_at' => __( 'Added', 'letterwp' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'email'      => array( 'email', false ),
			'name'       => array( 'name', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true, '', '', 'desc' ), // Newest first by default, marked like Date on Posts.
		);
	}

	protected function get_default_primary_column_name() {
		return 'email';
	}

	/* -- Above the table ------------------------------------------------- */

	protected function get_views() {
		$current = self::filters()['status'];
		$views   = array();

		foreach ( array( '' => __( 'All', 'letterwp' ) ) + LetterWP_Subscribers::statuses() as $key => $label ) {
			$count = $this->counts[ '' === $key ? 'all' : $key ];

			if ( '' !== $key && ! $count ) {
				continue;
			}

			$views[ '' === $key ? 'all' : $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( self::url( array( 'status' => $key ) ) ),
				$key === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	protected function get_bulk_actions() {
		return array(
			'unsubscribe' => __( 'Unsubscribe', 'letterwp' ),
			'delete'      => __( 'Delete permanently', 'letterwp' ),
		);
	}

	/** The source filter, where Posts has its date and category filters. */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$sources = LetterWP_Subscribers::sources();

		if ( count( $sources ) < 2 ) {
			return;
		}

		$current = self::filters()['source'];
		?>
		<div class="alignleft actions">
			<label for="filter-by-source" class="screen-reader-text"><?php esc_html_e( 'Filter by source', 'letterwp' ); ?></label>
			<select name="source" id="filter-by-source">
				<option value=""><?php esc_html_e( 'All sources', 'letterwp' ); ?></option>
				<?php foreach ( $sources as $source ) : ?>
					<option value="<?php echo esc_attr( $source ); ?>" <?php selected( $current, $source ); ?>><?php echo esc_html( self::source_label( $source ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'letterwp' ), '', 'filter_action', false, array( 'id' => 'subscriber-query-submit' ) ); ?>
		</div>
		<?php
	}

	public function no_items() {
		$filters = self::filters();

		if ( $filters['search'] || $filters['status'] || $filters['source'] ) {
			esc_html_e( 'No subscribers found.', 'letterwp' );
		} else {
			esc_html_e( 'No subscribers yet. Add yourself, or import a list.', 'letterwp' );
		}
	}

	/* -- Cells ----------------------------------------------------------- */

	protected function column_cb( $item ) {
		printf(
			'<label class="label-covers-full-cell" for="cb-select-%1$d"><span class="screen-reader-text">%2$s</span></label><input id="cb-select-%1$d" type="checkbox" name="subscriber[]" value="%1$d" />',
			(int) $item->id,
			/* translators: %s: email address. */
			esc_html( sprintf( __( 'Select %s', 'letterwp' ), $item->email ) )
		);
	}

	protected function column_email( $item ) {
		printf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
			esc_url( self::url( array( 'edit' => $item->id ) ) ),
			esc_html( $item->email )
		);
	}

	protected function column_name( $item ) {
		$name = LetterWP_Subscribers::full_name( $item );

		echo '' !== $name ? esc_html( $name ) : '<span aria-hidden="true">—</span>';
	}

	protected function column_topics( $item ) {
		echo LetterWP_Subscribers_Admin::topics_label( $item, isset( $this->optouts[ $item->id ] ) ? $this->optouts[ $item->id ] : array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}

	protected function column_status( $item ) {
		$statuses = LetterWP_Subscribers::statuses();

		printf(
			'<span class="letterwp-status is-%1$s">%2$s</span>',
			esc_attr( $item->status ),
			esc_html( isset( $statuses[ $item->status ] ) ? $statuses[ $item->status ] : $item->status )
		);
	}

	protected function column_source( $item ) {
		echo esc_html( self::source_label( $item->source ) );
	}

	/** Like the Date column on Posts: the date, then the time under it. */
	protected function column_created_at( $item ) {
		echo esc_html( get_date_from_gmt( $item->created_at, get_option( 'date_format' ) ) );
		echo '<br />';
		echo esc_html( get_date_from_gmt( $item->created_at, get_option( 'time_format' ) ) );
	}

	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$actions = array(
			'edit' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url( array( 'edit' => $item->id ) ) ), esc_html__( 'Edit', 'letterwp' ) ),
		);

		if ( in_array( 'unsubscribed', LetterWP_Subscribers::allowed_statuses( $item->status ), true ) && 'unsubscribed' !== $item->status ) {
			$actions['unsubscribe'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::action_url( 'unsubscribe', $item->id ) ), esc_html__( 'Unsubscribe', 'letterwp' ) );
		}

		$actions['delete'] = sprintf(
			'<a class="submitdelete" href="%1$s" onclick="return confirm(%2$s);">%3$s</a>',
			esc_url( self::action_url( 'delete', $item->id ) ),
			/* translators: %s: email address. */
			esc_attr( wp_json_encode( sprintf( __( 'Delete %s? This cannot be undone. To stop emailing someone but keep the record, unsubscribe them instead.', 'letterwp' ), $item->email ) ) ),
			esc_html__( 'Delete permanently', 'letterwp' )
		);

		return $this->row_actions( $actions );
	}

	/** @return string A row action: the bulk action, on one subscriber. */
	private static function action_url( $action, $id ) {
		return add_query_arg(
			array(
				'action'       => $action,
				'subscriber[]' => (int) $id,
				'_wpnonce'     => wp_create_nonce( 'bulk-subscribers' ),
			),
			self::url()
		);
	}

	/** @return string */
	private static function source_label( $source ) {
		$labels = array(
			'admin'  => __( 'Added by hand', 'letterwp' ),
			'form'   => __( 'Subscribe form', 'letterwp' ),
			'import' => __( 'Import', 'letterwp' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}
}
