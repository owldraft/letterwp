<?php
/**
 * What became of each post's email: delivered, delayed, bounced, complained,
 * failed, suppressed, and who unsubscribed after it. Deliverability only:
 * opens and clicks are not tracked (no pixels, no rewritten links).
 *
 * Resend keeps no stats a plugin can read: its API returns a Broadcast
 * without numbers. But every email event its webhook sends carries the
 * broadcast_id, so the numbers are counted here, as the events come in
 * (LetterWP_Webhook passes them on).
 *
 * One row per post per recipient, holding the furthest the email got:
 *
 *   delayed < delivered < suppressed < failed < bounced < complained
 *
 * A row only moves up, so events arriving twice or out of order count
 * once, and a complaint (which follows a delivery) still reads as one.
 *
 * Unsubscribed is apart, a flag on the row: a reader who leaves, by any
 * way (our footer link, Gmail's button, Resend's page, a complaint), is
 * counted against the last post they got in the 30 days before. As
 * Buttondown does it: the email that made them go.
 *
 * Rows are keyed by email, like everything else, and go with the
 * subscriber when they are deleted.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Stats {

	const DB_VERSION = '1';

	const DB_OPTION = 'letterwp_stats_db_version';

	/** Post meta: the Broadcast id alone, so an event finds its post in one query. */
	const META = '_letterwp_broadcast';

	/** How long after an email an unsubscribe still counts against it. */
	const WINDOW = 30 * DAY_IN_SECONDS;

	/** Bounce rate Resend asks senders to stay under. */
	const BOUNCE_LIMIT = 0.04;

	/** Complaint rate Resend asks senders to stay under. */
	const COMPLAINT_LIMIT = 0.0008;

	/** @return array<string,string> Webhook event => what it says, lowest first. */
	public static function events() {
		return array(
			'email.delivery_delayed' => 'delayed',
			'email.delivered'        => 'delivered',
			'email.suppressed'       => 'suppressed',
			'email.failed'           => 'failed',
			'email.bounced'          => 'bounced',
			'email.complained'       => 'complained',
		);
	}

	/** @return array<string,string> status => label, in the order they show. */
	public static function statuses() {
		return array(
			'delivered'  => __( 'Delivered', 'letterwp' ),
			'delayed'    => __( 'Delayed', 'letterwp' ),
			'bounced'    => __( 'Bounced', 'letterwp' ),
			'complained' => __( 'Marked as spam', 'letterwp' ),
			'failed'     => __( 'Failed', 'letterwp' ),
			'suppressed' => __( 'Suppressed', 'letterwp' ),
		);
	}

	/** @return string */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'letterwp_deliveries';
	}

	public function __construct() {
		add_action( 'letterwp_subscriber_saved', array( __CLASS__, 'on_saved' ), 10, 2 );
		add_action( 'letterwp_subscriber_deleted', array( __CLASS__, 'on_deleted' ) );
	}

	/* ---------------------------------------------------------------------
	 * Schema: checked on every load, like the subscribers' tables.
	 * ------------------------------------------------------------------ */

	public static function maybe_install() {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				post_id bigint(20) unsigned NOT NULL,
				email varchar(191) NOT NULL,
				status varchar(20) NOT NULL DEFAULT '',
				unsubscribed tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (post_id,email),
				KEY email (email),
				KEY post_status (post_id,status)
			) " . $wpdb->get_charset_collate() . ';'
		);

		// Posts emailed before this table existed: give them their lookup.
		$sent = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", LetterWP_Send::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $sent as $row ) {
			$record = maybe_unserialize( $row->meta_value );

			if ( is_array( $record ) && ! empty( $record['broadcast'] ) ) {
				update_post_meta( (int) $row->post_id, self::META, (string) $record['broadcast'] );
			}
		}

		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Write: from the webhook, and from subscribers changing.
	 * ------------------------------------------------------------------ */

	/** @return int The post a Broadcast was for, 0 when not one of ours. */
	public static function post_for( $broadcast ) {
		global $wpdb;

		if ( '' === (string) $broadcast ) {
			return 0;
		}

		$found = wp_cache_get( $broadcast, 'letterwp_broadcast' );

		if ( false === $found ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", self::META, $broadcast ) );
			wp_cache_set( $broadcast, $found, 'letterwp_broadcast' );
		}

		return (int) $found;
	}

	/**
	 * One email event, for every address it went to.
	 *
	 * @param array $event The decoded webhook payload.
	 * @return string|null What came of it, or null when it is not a post's email.
	 */
	public static function record( array $event ) {
		$events = self::events();
		$type   = isset( $event['type'] ) ? (string) $event['type'] : '';
		$data   = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();

		if ( ! isset( $events[ $type ] ) || empty( $data['broadcast_id'] ) ) {
			return null;
		}

		$post_id = self::post_for( (string) $data['broadcast_id'] );

		if ( ! $post_id ) {
			return 'not our broadcast';
		}

		$done = 0;

		foreach ( isset( $data['to'] ) ? (array) $data['to'] : array() as $email ) {
			$email = LetterWP_Subscribers::clean_email( $email );

			if ( '' !== $email ) {
				self::upsert( $post_id, $email, $events[ $type ] );
				++$done;
			}
		}

		return $done ? 'counted' : 'no recipient';
	}

	/** A row moves only up the order: a repeat or a late, lesser event changes nothing. */
	private static function upsert( $post_id, $email, $status ) {
		global $wpdb;

		$order = "'" . implode( "','", array_values( self::events() ) ) . "'";
		$now   = current_time( 'mysql', true );

		// updated_at is set first, while status still holds the old value.
		// $order is events(), our own constant list, quoted above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (post_id, email, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE
					updated_at = IF( FIELD( VALUES(status), $order ) > FIELD( status, $order ), VALUES(updated_at), updated_at ),
					status = IF( FIELD( VALUES(status), $order ) > FIELD( status, $order ), VALUES(status), status )",
				self::table(),
				$post_id,
				$email,
				$status,
				$now,
				$now
			)
		);
		// phpcs:enable
	}

	/** A reader who just unsubscribed counts against the last post they got. */
	public static function on_saved( $row, $changed ) {
		global $wpdb;

		if ( ! $row || 'unsubscribed' !== $row->status || ! in_array( 'status', (array) $changed, true ) ) {
			return;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE email = %s AND created_at >= %s ORDER BY created_at DESC LIMIT 1', self::table(), $row->email, $since ) );

		if ( $post_id ) {
			$wpdb->update( self::table(), array( 'unsubscribed' => 1 ), array( 'post_id' => $post_id, 'email' => $row->email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** Deleting a subscriber deletes what we knew about their emails too. */
	public static function on_deleted( $row ) {
		global $wpdb;

		if ( $row ) {
			$wpdb->delete( self::table(), array( 'email' => $row->email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/* ---------------------------------------------------------------------
	 * Read.
	 * ------------------------------------------------------------------ */

	/**
	 * The numbers for posts, in one query.
	 *
	 * @param int[] $post_ids
	 * @return array<int,array> post id => numbers, as numbers() shapes them.
	 */
	public static function for_posts( array $post_ids ) {
		global $wpdb;

		$post_ids = array_filter( array_map( 'intval', $post_ids ) );
		$out      = array();

		if ( ! $post_ids ) {
			return $out;
		}

		$in = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $in is one %d per post, built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, status, COUNT(*) AS n, SUM(unsubscribed) AS unsub FROM %i WHERE post_id IN ($in) GROUP BY post_id, status", array_merge( array( self::table() ), $post_ids ) ) );

		$grouped = array();

		foreach ( $rows as $row ) {
			$grouped[ (int) $row->post_id ][] = $row;
		}

		foreach ( $post_ids as $id ) {
			$out[ $id ] = self::numbers( $id, isset( $grouped[ $id ] ) ? $grouped[ $id ] : array() );
		}

		return $out;
	}

	/** @return array|null The numbers for one post, or null if it was never sent. */
	public static function for_post( $post_id ) {
		$all = self::for_posts( array( $post_id ) );

		return isset( $all[ $post_id ] ) ? $all[ $post_id ] : null;
	}

	/**
	 * Counts, rates and warnings for one post.
	 *
	 *   recipients  as WordPress counted them at send time, or how many
	 *               Resend reported on if that is more (an address in the
	 *               segment that WordPress does not know)
	 *   waiting     recipients with no event yet
	 *   delivered   reached the inbox server (a complaint counts: it was
	 *               delivered first). Not the same as the inbox itself.
	 *
	 * @param object[] $rows {status, n, unsub} for this post.
	 * @return array|null Null when the post was never sent.
	 */
	private static function numbers( $post_id, array $rows ) {
		$sent = LetterWP_Send::record( $post_id );

		if ( ! $sent || 'sent' !== $sent['status'] ) {
			return null;
		}

		$counts = array_fill_keys( array_values( self::events() ), 0 );
		$unsub  = 0;

		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
			}
			$unsub += (int) $row->unsub;
		}

		$reported   = array_sum( $counts );
		$recipients = max( isset( $sent['count'] ) ? (int) $sent['count'] : 0, $reported );
		$delivered  = $counts['delivered'] + $counts['complained'];
		$rate       = function ( $n ) use ( $recipients ) {
			return $recipients ? $n / $recipients : 0;
		};

		return array(
			'recipients'   => $recipients,
			'waiting'      => $recipients - $reported,
			'delivered'    => $delivered,
			'delayed'      => $counts['delayed'],
			'bounced'      => $counts['bounced'],
			'complained'   => $counts['complained'],
			'failed'       => $counts['failed'],
			'suppressed'   => $counts['suppressed'],
			'unsubscribed' => $unsub,
			'rates'        => array(
				'delivered'    => $rate( $delivered ),
				'bounced'      => $rate( $counts['bounced'] ),
				'complained'   => $rate( $counts['complained'] ),
				'unsubscribed' => $rate( $unsub ),
			),
			'warn'         => array(
				'bounced'    => $recipients && $rate( $counts['bounced'] ) >= self::BOUNCE_LIMIT,
				'complained' => $recipients && $rate( $counts['complained'] ) >= self::COMPLAINT_LIMIT,
			),
			// No news at all an hour on: the webhook is off, or the post went
			// out before LetterWP counted results.
			'silent'       => $recipients && ! $reported && time() - (int) $sent['time'] > HOUR_IN_SECONDS,
			'time'         => (int) $sent['time'],
			'broadcast'    => isset( $sent['broadcast'] ) ? (string) $sent['broadcast'] : '',
		);
	}

	/**
	 * One page of a post's recipients, newest news first, with the
 * subscriber_id of each (null once the subscriber is gone).
	 *
	 * @param array $args status (or 'unsubscribed'), search, page (from 1).
	 * @return array{rows:object[],total:int}
	 */
	public static function recipients( $post_id, array $args = array() ) {
		global $wpdb;

		$where  = array( 'd.post_id = %d' );
		$params = array( (int) $post_id );

		if ( ! empty( $args['status'] ) ) {
			if ( 'unsubscribed' === $args['status'] ) {
				$where[] = 'd.unsubscribed = 1';
			} elseif ( 'delivered' === $args['status'] ) {
				$where[] = "d.status IN ('delivered','complained')";
			} elseif ( in_array( $args['status'], self::events(), true ) ) {
				$where[]  = 'd.status = %s';
				$params[] = $args['status'];
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'd.email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$per   = LetterWP_Subscribers::PER_PAGE;
		$page  = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$where = implode( ' AND ', $where );

		// $where is built above from fixed strings; every value is a placeholder.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i d WHERE $where", array_merge( array( self::table() ), $params ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT d.*, s.id AS subscriber_id FROM %i d LEFT JOIN %i s ON s.email = d.email WHERE $where ORDER BY d.updated_at DESC, d.email ASC LIMIT %d OFFSET %d", array_merge( array( self::table(), LetterWP_Subscribers::table() ), $params, array( $per, ( $page - 1 ) * $per ) ) ) );
		// phpcs:enable

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/** @return string "95%", "0.12%": more digits where small numbers matter. */
	public static function percent( $rate ) {
		$pct = $rate * 100;

		if ( 0.0 === (float) $pct ) {
			return '0%';
		}

		return ( $pct < 1 ? number_format_i18n( $pct, 2 ) : number_format_i18n( $pct, $pct < 10 ? 1 : 0 ) ) . '%';
	}
}
