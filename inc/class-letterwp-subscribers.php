<?php
/**
 * The subscribers: LetterWP's own table, apart from wp_users.
 *
 * A subscriber is a reader, not a WordPress account. They sign in with a
 * magic link (see LetterWP_Login), never a password, so nothing about them
 * can touch wp_users, its roles, or whatever else a site already keeps
 * there. user_id is the one bridge, empty until something needs it.
 *
 * Email is the key, here and at any sending tool: no tool's ids are stored.
 * Every write and read of the table goes through this class.
 *
 * Topics are categories, plus Announcements once a campaign has been sent
 * (see LetterWP_Setup), and everyone gets every topic
 * by default. So only opt-outs are stored, in a second table: a subscriber
 * who gets everything has no rows there at all.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Subscribers {

	/** Bump when the schema below changes: dbDelta() runs once per bump. */
	const DB_VERSION = '4';

	const DB_OPTION = 'letterwp_db_version';

	const PER_PAGE = 50;

	/** @return array<string,string> status => label. */
	public static function statuses() {
		return array(
			'active'       => __( 'Active', 'letterwp' ),
			'pending'      => __( 'Pending', 'letterwp' ),
			'unsubscribed' => __( 'Unsubscribed', 'letterwp' ),
			'bounced'      => __( 'Bounced', 'letterwp' ),
		);
	}

	/** @return string */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'letterwp_subscribers';
	}

	/** @return string One row per subscriber per category they turned off. */
	public static function optouts_table() {
		global $wpdb;

		return $wpdb->prefix . 'letterwp_topic_optouts';
	}

	/* ---------------------------------------------------------------------
	 * Schema. Checked on every load, not only on activation, because the
	 * plugin is also updated by git pull and zip upload, which skip the
	 * activation hook. The check is one autoloaded option.
	 * ------------------------------------------------------------------ */

	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// dbDelta is picky: one column per line, two spaces after PRIMARY KEY.
		// email is 191 characters so its unique index fits utf8mb4.
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(191) NOT NULL,
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				token varchar(64) NOT NULL,
				source varchar(20) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				confirmed_at datetime DEFAULT NULL,
				unsubscribed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY status (status),
				KEY user_id (user_id)
			) " . $wpdb->get_charset_collate() . ';

			CREATE TABLE ' . self::optouts_table() . ' (
				subscriber_id bigint(20) unsigned NOT NULL,
				term_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (subscriber_id,term_id),
				KEY term_id (term_id)
			) ' . $wpdb->get_charset_collate() . ';'
		);

		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Read.
	 * ------------------------------------------------------------------ */

	/** @return string Lowercased and trimmed, or '' when not an email. */
	public static function clean_email( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * First and last name, the same two fields Resend has. "First name"
	 * means what to call the reader: they decide what goes in it.
	 *
	 * @return string One line, at most 100 characters, '' when not given.
	 */
	public static function clean_name( $name ) {
		return mb_substr( trim( sanitize_text_field( (string) $name ) ), 0, 100 );
	}

	/** @return string "First Last", or '' when neither is known. */
	public static function full_name( $row ) {
		return trim( $row->first_name . ' ' . $row->last_name );
	}

	/** @return object|null */
	public static function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ) );
	}

	/** @return object|null */
	public static function get_by_email( $email ) {
		global $wpdb;

		$email = self::clean_email( $email );

		if ( '' === $email ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', self::table(), $email ) );
	}

	/**
	 * The reader's unsubscribe key, "id.hmac". It rides in every newsletter's
	 * footer link (Resend fills it in from the letterwp_key contact property)
	 * and opens the one-click unsubscribe, nothing else: it never signs in,
	 * so a forwarded newsletter gives away no account.
	 *
	 * Keyed with the row's token, so a new token voids old links. The token
	 * itself never leaves WordPress: it signs the session cookie too.
	 *
	 * @return string
	 */
	public static function key( $row ) {
		return $row->id . '.' . substr( hash_hmac( 'sha256', 'unsubscribe|' . $row->id, wp_salt( 'auth' ) . $row->token ), 0, 32 );
	}

	/** @return object|null The subscriber a key belongs to. */
	public static function by_key( $key ) {
		if ( ! preg_match( '/^(\d+)\.[a-f0-9]{32}$/', (string) $key, $m ) ) {
			return null;
		}

		$row = self::get( (int) $m[1] );

		return $row && hash_equals( self::key( $row ), $key ) ? $row : null;
	}

	/**
	 * One page of subscribers, newest signup first (imports keep their dates).
	 *
	 * @param array $args search (part of an email or name), status, source,
	 *                    page (from 1), per_page (default PER_PAGE), orderby
	 *                    (email, name, status or created_at), order (asc, desc).
	 * @return array{rows:object[],total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = "( email LIKE %s OR CONCAT( first_name, ' ', last_name ) LIKE %s )";
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['status'] ) && isset( self::statuses()[ $args['status'] ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = $args['source'];
		}

		// Ties break on id, so a page never repeats or skips a row.
		$columns = array(
			'email'      => array( 'email' ),
			'name'       => array( 'first_name', 'last_name' ),
			'status'     => array( 'status' ),
			'created_at' => array( 'created_at' ),
		);
		$by      = isset( $args['orderby'], $columns[ $args['orderby'] ] ) ? $args['orderby'] : 'created_at';
		$dir     = isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ? 'ASC' : 'DESC';
		$order   = implode( " $dir, ", array_merge( $columns[ $by ], array( 'id' ) ) ) . " $dir";

		$where  = implode( ' AND ', $where );
		$page   = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$per    = max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : self::PER_PAGE );
		$offset = ( $page - 1 ) * $per;

		// $where and $order are built above from fixed strings; every value is a placeholder.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", array_merge( array( self::table() ), $params ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY $order LIMIT %d OFFSET %d", array_merge( array( self::table() ), $params, array( $per, $offset ) ) ) );
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/** @return string[] Every source in use (admin, form, import…), for the list's filter. */
	public static function sources() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source FROM %i WHERE source <> '' ORDER BY source", self::table() ) );
	}

	/** @return object[] Every subscriber: id, email, names, status. For syncing. */
	public static function all() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, first_name, last_name, status, token FROM %i ORDER BY id', self::table() ) );
	}

	/**
	 * Which of these addresses are already in the list.
	 *
	 * @param string[] $emails Clean emails.
	 * @return array<string,true>
	 */
	public static function existing( array $emails ) {
		global $wpdb;

		$found = array();

		foreach ( array_chunk( $emails, 1000 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in is one %s per email, built above.
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT email FROM %i WHERE email IN ($in)", array_merge( array( self::table() ), $chunk ) ) ) as $email ) {
				$found[ $email ] = true;
			}
		}

		return $found;
	}

	/* -- Topics ----------------------------------------------------------- */

	/** @return int[] Every topic there is: the categories, and LetterWP_Setup::ANNOUNCE once made. */
	public static function topic_ids() {
		return array_keys( LetterWP_Setup::topic_names() );
	}

	/**
	 * The categories each subscriber turned off.
	 *
	 * @param int[] $ids Subscriber ids.
	 * @return array<int,int[]> id => term ids; subscribers who get everything are absent.
	 */
	public static function optouts( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );
		$out = array();

		if ( ! $ids ) {
			return $out;
		}

		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in is one %d per id, built above.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT subscriber_id, term_id FROM %i WHERE subscriber_id IN ($in)", array_merge( array( self::optouts_table() ), $ids ) ) ) as $r ) {
			$out[ (int) $r->subscriber_id ][] = (int) $r->term_id;
		}

		return $out;
	}

	/** A category is gone: nobody can be opted out of it any more. */
	public static function forget_topic( $term_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::optouts_table(), array( 'term_id' => (int) $term_id ) );
	}

	/** @return array<string,int> Every status, plus 'all'. */
	public static function counts() {
		global $wpdb;

		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::table() ) ) as $row ) {
			$counts[ $row->status ] = (int) $row->n;
		}

		return array( 'all' => array_sum( $counts ) ) + $counts;
	}

	/** @return int Active subscribers who have not turned this category off. */
	public static function count_for_topic( $term_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i s WHERE s.status = 'active' AND NOT EXISTS ( SELECT 1 FROM %i o WHERE o.subscriber_id = s.id AND o.term_id = %d )", self::table(), self::optouts_table(), $term_id ) );
	}

	/**
	 * count_for_topic() for every category at once, for the editor: the
	 * topic can change there without a reload. Two queries, however many
	 * categories.
	 *
	 * @return array{all:int,by_term:array<int,int>} all: every active
	 *               subscriber; by_term: only categories someone turned off.
	 */
	public static function readers_by_topic() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$all  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'active'", self::table() ) );
		$offs = $wpdb->get_results( $wpdb->prepare( "SELECT o.term_id, COUNT(*) AS n FROM %i o JOIN %i s ON s.id = o.subscriber_id WHERE s.status = 'active' GROUP BY o.term_id", self::optouts_table(), self::table() ) );
		// phpcs:enable

		$by_term = array();
		foreach ( $offs as $row ) {
			$by_term[ (int) $row->term_id ] = $all - (int) $row->n;
		}

		return array(
			'all'     => $all,
			'by_term' => (object) $by_term, // An object in JSON even when empty.
		);
	}

	/* ---------------------------------------------------------------------
	 * Write.
	 * ------------------------------------------------------------------ */

	/**
	 * Add one subscriber. An email already in the table is an error, never a
	 * silent overwrite: someone who unsubscribed stays unsubscribed.
	 *
	 * @param string $email  Any case; stored lowercased.
	 * @param string $status One of statuses().
	 * @param string $source admin, form, import…
	 * @param string $first  Optional.
	 * @param string $last   Optional.
	 * @return int|WP_Error The new id.
	 */
	public static function add( $email, $status = 'pending', $source = '', $first = '', $last = '' ) {
		global $wpdb;

		$email = self::clean_email( $email );

		if ( '' === $email ) {
			return new WP_Error( 'letterwp_bad_email', __( 'That is not a valid email address.', 'letterwp' ) );
		}

		if ( self::get_by_email( $email ) ) {
			/* translators: %s: email address. */
			return new WP_Error( 'letterwp_exists', sprintf( __( '%s is already in the list.', 'letterwp' ), $email ) );
		}

		$status = isset( self::statuses()[ $status ] ) ? $status : 'pending';
		$now    = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			self::table(),
			array(
				'email'        => $email,
				'first_name'   => self::clean_name( $first ),
				'last_name'    => self::clean_name( $last ),
				'status'       => $status,
				'token'        => bin2hex( random_bytes( 16 ) ),
				'source'       => sanitize_key( $source ),
				'created_at'   => $now,
				'updated_at'   => $now,
				'confirmed_at' => 'active' === $status ? $now : null,
			)
		);

		if ( ! $ok ) {
			return new WP_Error( 'letterwp_db', __( 'The subscriber could not be saved.', 'letterwp' ) );
		}

		$id = (int) $wpdb->insert_id;

		do_action( 'letterwp_subscriber_saved', self::get( $id ), array( 'created' ) );

		return $id;
	}

	/**
	 * Add many at once, from LetterWP_Import::read(). Like add(), an address
	 * already in the list is left exactly as it is: an import never brings
	 * back someone who unsubscribed. The date someone subscribed comes along,
	 * so the list keeps its history.
	 *
	 * Rows go in 500 to a query, quietly: letterwp_subscriber_saved would
	 * push each one to Resend on its own, minutes for a big file. Instead
	 * letterwp_subscribers_imported hands every new id over at the end.
	 *
	 * @param array[] $rows [email, first, last, status, created (GMT), topics_off].
	 * @return array{added:int[],existing:int} New ids, and how many were already here.
	 */
	public static function import( array $rows ) {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$slugs    = array_flip( LetterWP_Setup::topic_slugs( true ) );
		$added    = array();
		$existing = 0;

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$have   = self::existing( array_column( $chunk, 0 ) );
			$values = array();
			$params = array();
			$off    = array();

			foreach ( $chunk as $row ) {
				if ( isset( $have[ $row[0] ] ) ) {
					++$existing;
					continue;
				}

				$status   = isset( self::statuses()[ $row[3] ] ) ? $row[3] : 'active';
				$values[] = 'active' === $status ? '(%s,%s,%s,%s,%s,%s,%s,%s,%s)' : '(%s,%s,%s,%s,%s,%s,%s,%s,NULL)';
				array_push( $params, $row[0], self::clean_name( $row[1] ), self::clean_name( $row[2] ), $status, bin2hex( random_bytes( 16 ) ), 'import', $row[4], $now );
				if ( 'active' === $status ) {
					$params[] = $row[4]; // They confirmed when they subscribed, wherever that was.
				}

				// Our own export lists the topics someone turned off, by slug.
				foreach ( array_filter( array_map( 'trim', explode( ',', (string) $row[5] ) ) ) as $slug ) {
					if ( isset( $slugs[ $slug ] ) ) {
						$off[ $row[0] ][] = (int) $slugs[ $slug ];
					}
				}
			}

			if ( ! $values ) {
				continue;
			}

			// $values is one placeholder group per row and $in one %s per email, built here.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (email, first_name, last_name, status, token, source, created_at, updated_at, confirmed_at) VALUES ' . implode( ',', $values ), array_merge( array( self::table() ), $params ) ) );

			$emails = array_values( array_diff( array_column( $chunk, 0 ), array_keys( $have ) ) );
			$in     = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
			$ids    = $wpdb->get_results( $wpdb->prepare( "SELECT id, email FROM %i WHERE email IN ($in)", array_merge( array( self::table() ), $emails ) ) );

			foreach ( $ids as $r ) {
				$added[] = (int) $r->id;
				foreach ( isset( $off[ $r->email ] ) ? array_unique( $off[ $r->email ] ) : array() as $term_id ) {
					$wpdb->insert(
						self::optouts_table(),
						array(
							'subscriber_id' => (int) $r->id,
							'term_id'       => $term_id,
							'created_at'    => $now,
						)
					);
				}
			}
			// phpcs:enable
		}

		do_action( 'letterwp_subscribers_imported', $added );

		return array(
			'added'    => $added,
			'existing' => $existing,
		);
	}

	/**
	 * The statuses a site owner may move a subscriber to by hand. Consent is
	 * the reader's: nobody but the reader turns an unsubscribe or a bounce
	 * back into active.
	 *
	 * @return string[]
	 */
	public static function allowed_statuses( $current ) {
		$next = array(
			'active'       => array( 'active', 'unsubscribed' ),
			'pending'      => array( 'pending', 'active', 'unsubscribed' ),
			'unsubscribed' => array( 'unsubscribed' ),
			'bounced'      => array( 'bounced', 'unsubscribed' ),
		);

		return isset( $next[ $current ] ) ? $next[ $current ] : array( $current );
	}

	/**
	 * Change names and/or status, stamping when a status changed. Fires
	 * letterwp_subscriber_saved when anything did, with what changed, so the
	 * sending tool can follow.
	 *
	 * @param array $fields first_name, last_name, status, and topics: the
	 *                      category ids they get. Every category, or more,
	 *                      stores nothing. Others keys are ignored.
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		$row = self::get( $id );

		if ( ! $row ) {
			return false;
		}

		$now  = current_time( 'mysql', true );
		$data = array();

		foreach ( array( 'first_name', 'last_name' ) as $key ) {
			if ( isset( $fields[ $key ] ) && self::clean_name( $fields[ $key ] ) !== $row->$key ) {
				$data[ $key ] = self::clean_name( $fields[ $key ] );
			}
		}

		if ( isset( $fields['status'] ) && isset( self::statuses()[ $fields['status'] ] ) && $fields['status'] !== $row->status ) {
			$data['status'] = $fields['status'];

			if ( 'active' === $fields['status'] ) {
				$data['confirmed_at'] = $now;
			} elseif ( 'unsubscribed' === $fields['status'] ) {
				$data['unsubscribed_at'] = $now;
			}
		}

		$topics = false;

		if ( isset( $fields['topics'] ) ) {
			$want = array_values( array_diff( self::topic_ids(), array_map( 'intval', (array) $fields['topics'] ) ) );
			$have = isset( self::optouts( array( $id ) )[ $id ] ) ? self::optouts( array( $id ) )[ $id ] : array();
			sort( $want );
			sort( $have );
			$topics = $want !== $have ? $want : false;
		}

		if ( ! $data && false === $topics ) {
			return true;
		}

		$data['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( false === $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ) ) {
			return false;
		}

		if ( false !== $topics ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( self::optouts_table(), array( 'subscriber_id' => (int) $id ) );
			foreach ( $topics as $term_id ) {
				$wpdb->insert(
					self::optouts_table(),
					array(
						'subscriber_id' => (int) $id,
						'term_id'       => $term_id,
						'created_at'    => $now,
					)
				);
			}
			// phpcs:enable
		}

		$changed = array_diff( array_keys( $data ), array( 'updated_at', 'confirmed_at', 'unsubscribed_at' ) );

		if ( false !== $topics ) {
			$changed[] = 'topics';
		}

		do_action( 'letterwp_subscriber_saved', self::get( $id ), array_values( $changed ) );

		return true;
	}

	/** @return bool */
	public static function set_status( $id, $status ) {
		return self::update( $id, array( 'status' => $status ) );
	}

	/**
	 * Delete for good. letterwp_subscriber_deleted lets the sending tool
	 * forget the person too.
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$row = self::get( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row || ! $wpdb->delete( self::table(), array( 'id' => (int) $id ) ) ) {
			return false;
		}

		$wpdb->delete( self::optouts_table(), array( 'subscriber_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		do_action( 'letterwp_subscriber_deleted', $row );

		return true;
	}
}
