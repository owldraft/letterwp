<?php
/**
 * What happens at Resend comes back: bounces, complaints, unsubscribes and
 * topic choices made on Resend's unsubscribe page.
 *
 * WordPress decides everything else; these are the reader's (or their mail
 * server's) answers, so they win:
 *
 *   email.bounced           permanent bounce: active or pending → bounced
 *   email.complained        marked as spam: anything → unsubscribed
 *   contact.updated         unsubscribed: true: active → unsubscribed.
 *                           unsubscribed: false is ignored: only the reader's
 *                           own confirm link brings someone back.
 *   contact.topics.updated  opt-outs follow, topic by topic. Resend sends
 *                           this for API changes only, not for its own
 *                           unsubscribe page (tested 2026-10-01): those
 *                           are pulled instead, LetterWP_Sync::pull_topics().
 *   email.delivered, …      a post's email, counted (LetterWP_Stats). Every
 *                           email event of a Broadcast goes there first.
 *
 * An unsubscribe always wins: nothing here moves a subscriber out of
 * unsubscribed. An event older than the subscriber's last confirm is
 * ignored, so a late retry cannot undo a reader who just said yes again.
 *
 * Bounces and complaints are pushed back to Resend (the contact becomes
 * unsubscribed: true, by the sync rules). Contact events are applied
 * quietly: Resend has them already. Our own pushes come back as events too:
 * the latest falls through as no change, and an older one arriving late is
 * ignored by time (LetterWP_Sync::topics_changed_at()).
 *
 * Signed the Svix way: HMAC-SHA256 of "id.timestamp.body" with the secret
 * Resend gave when Setup created the webhook. Unsigned or stale = 401.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Webhook {

	/** {id, secret}: the link to the webhook at Resend, and how to check its signature. */
	const OPTION = 'letterwp_webhook';

	/** The last event received: {time, type, result}. For the setup screen. */
	const LAST = 'letterwp_webhook_last';

	const ROUTE = 'webhook';

	/** Svix's own tolerance for the timestamp, either way. */
	const TOLERANCE = 5 * MINUTE_IN_SECONDS;

	public function __construct() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/** @return string[] The events LetterWP listens to: its own, and the ones each post's numbers are counted from. */
	public static function events() {
		return array_values( array_unique( array_merge( array( 'email.bounced', 'email.complained', 'contact.updated', 'contact.topics.updated' ), array_keys( LetterWP_Stats::events() ) ) ) );
	}

	/** @return string Where Resend posts. */
	public static function endpoint() {
		return rest_url( LetterWP_Editor::REST . '/' . self::ROUTE );
	}

	/** @return array{id:string,secret:string} */
	public static function link() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'id'     => isset( $stored['id'] ) ? (string) $stored['id'] : '',
			'secret' => isset( $stored['secret'] ) ? (string) $stored['secret'] : '',
		);
	}

	/** Called on disconnect: the webhook lives in the old account. */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/** @return array|null {time, type, result} of the last event. */
	public static function last() {
		$last = get_option( self::LAST );

		return is_array( $last ) ? $last : null;
	}

	/* ---------------------------------------------------------------------
	 * Setup: one webhook, pointing here, with our events, enabled.
	 * ------------------------------------------------------------------ */

	/**
	 * @param array[]|null $hooks Resend's list, when already fetched.
	 * @return array|WP_Error {state: ready|update|missing, webhook: array|null}
	 */
	public static function status( $hooks = null ) {
		$hooks = null === $hooks ? LetterWP_Resend::webhooks() : $hooks;

		if ( is_wp_error( $hooks ) ) {
			return $hooks;
		}

		$hook = self::find( $hooks );

		if ( ! $hook || '' === self::link()['secret'] || self::link()['id'] !== $hook['id'] ) {
			return array(
				'state'   => 'missing',
				'webhook' => $hook,
			);
		}

		return array(
			'state'   => self::is_right( $hook ) ? 'ready' : 'update',
			'webhook' => $hook,
		);
	}

	/**
	 * Make the webhook exist and be right. Adopts one already pointing here
	 * (say, after disconnecting and reconnecting) rather than adding another.
	 *
	 * @return string|WP_Error created, updated or '' when nothing changed.
	 */
	public static function ensure() {
		$hooks = LetterWP_Resend::webhooks();

		if ( is_wp_error( $hooks ) ) {
			return $hooks;
		}

		$hook = self::find( $hooks );

		if ( ! $hook ) {
			$made = LetterWP_Resend::create_webhook( self::endpoint(), self::events() );

			if ( is_wp_error( $made ) ) {
				return $made;
			}

			self::save( (string) $made['id'], (string) $made['signing_secret'] );

			return 'created';
		}

		$done = '';

		if ( ! self::is_right( $hook ) ) {
			$made = LetterWP_Resend::update_webhook(
				$hook['id'],
				array(
					'endpoint' => self::endpoint(),
					'events'   => self::events(),
					'status'   => 'enabled',
				)
			);

			if ( is_wp_error( $made ) ) {
				return $made;
			}

			$done = 'updated';
		}

		$link = self::link();

		if ( $link['id'] !== $hook['id'] || '' === $link['secret'] ) {
			$full = LetterWP_Resend::webhook( $hook['id'] );

			if ( is_wp_error( $full ) ) {
				return $full;
			}

			self::save( (string) $hook['id'], (string) $full['signing_secret'] );
			$done = '' === $done ? 'updated' : $done;
		}

		return $done;
	}

	/** @return array|null The webhook pointing here: the linked one, else one with this endpoint. */
	private static function find( array $hooks ) {
		$id = self::link()['id'];

		foreach ( $hooks as $hook ) {
			if ( '' !== $id && $id === $hook['id'] ) {
				return $hook;
			}
		}

		foreach ( $hooks as $hook ) {
			if ( isset( $hook['endpoint'] ) && untrailingslashit( $hook['endpoint'] ) === untrailingslashit( self::endpoint() ) ) {
				return $hook;
			}
		}

		return null;
	}

	/** @return bool Pointing here, listening to at least our events, and on. */
	private static function is_right( array $hook ) {
		$events = isset( $hook['events'] ) && is_array( $hook['events'] ) ? $hook['events'] : array();

		return isset( $hook['status'] ) && 'enabled' === $hook['status']
			&& untrailingslashit( (string) $hook['endpoint'] ) === untrailingslashit( self::endpoint() )
			&& ! array_diff( self::events(), $events );
	}

	private static function save( $id, $secret ) {
		update_option(
			self::OPTION,
			array(
				'id'     => $id,
				'secret' => $secret,
			),
			false
		);
	}

	/* ---------------------------------------------------------------------
	 * Receive.
	 * ------------------------------------------------------------------ */

	public static function register_route() {
		register_rest_route(
			LetterWP_Editor::REST,
			'/' . self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				'permission_callback' => '__return_true', // The signature is the permission.
			)
		);
	}

	/** @return WP_REST_Response */
	public static function receive( WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( ! self::verify( $body, $request->get_header( 'svix-id' ), $request->get_header( 'svix-timestamp' ), $request->get_header( 'svix-signature' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'bad signature' ), 401 );
		}

		$event  = json_decode( $body, true );
		$type   = is_array( $event ) && isset( $event['type'] ) ? (string) $event['type'] : '';
		$result = self::handle( $event );

		update_option(
			self::LAST,
			array(
				'time'   => time(),
				'type'   => $type,
				'result' => $result,
			),
			false
		);

		return new WP_REST_Response( array( 'result' => $result ), 200 );
	}

	/**
	 * Svix signatures: "v1,<base64>" entries, space-separated (more than one
	 * while a secret is being rotated); any match is enough.
	 *
	 * @return bool
	 */
	public static function verify( $body, $id, $timestamp, $signature ) {
		$secret = self::link()['secret'];

		if ( '' === $secret || ! $id || ! $timestamp || ! $signature || ! ctype_digit( (string) $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$key      = base64_decode( 0 === strpos( $secret, 'whsec_' ) ? substr( $secret, 6 ) : $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$expected = base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		foreach ( explode( ' ', (string) $signature ) as $part ) {
			$pieces = explode( ',', $part, 2 );

			if ( 2 === count( $pieces ) && 'v1' === $pieces[0] && hash_equals( $expected, $pieces[1] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $event The decoded payload.
	 * @return string What came of it, in a word or two, for the log.
	 */
	public static function handle( $event ) {
		if ( ! is_array( $event ) || empty( $event['type'] ) || ! isset( $event['data'] ) || ! is_array( $event['data'] ) ) {
			return 'malformed';
		}

		$data    = $event['data'];
		$when    = isset( $event['created_at'] ) ? (string) $event['created_at'] : '';
		$counted = LetterWP_Stats::record( $event );
		$also    = null === $counted ? '' : '; ' . $counted;

		switch ( $event['type'] ) {
			case 'email.bounced':
				// Resend sends this for permanent rejections; a soft one is not a reason.
				if ( isset( $data['bounce']['type'] ) && 'Permanent' !== $data['bounce']['type'] ) {
					return 'soft bounce' . $also;
				}
				return self::each_recipient( $data, 'bounced', array( 'active', 'pending' ) ) . $also;

			case 'email.complained':
				return self::each_recipient( $data, 'unsubscribed', array( 'active', 'pending', 'bounced' ) ) . $also;

			case 'contact.updated':
				if ( empty( $data['unsubscribed'] ) || empty( $data['email'] ) ) {
					return 'no change';
				}
				$at = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : $when;
				return LetterWP_Sync::quietly(
					function () use ( $data, $at ) {
						return self::apply( $data['email'], 'unsubscribed', array( 'active' ), $at );
					}
				);

			case 'contact.topics.updated':
				if ( empty( $data['email'] ) || empty( $data['topics'] ) || ! is_array( $data['topics'] ) ) {
					return 'no change';
				}
				return LetterWP_Sync::quietly(
					function () use ( $data, $when ) {
						return self::topics( $data['email'], $data['topics'], $when );
					}
				);
		}

		return null === $counted ? 'ignored' : $counted;
	}

	/** An email event: every address it went to. The time is when the email was sent. */
	private static function each_recipient( array $data, $status, array $from ) {
		$to      = isset( $data['to'] ) ? (array) $data['to'] : array();
		$at      = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$results = array();

		foreach ( $to as $email ) {
			$results[] = self::apply( $email, $status, $from, $at );
		}

		return $results ? implode( ', ', array_unique( $results ) ) : 'no recipient';
	}

	/**
	 * One subscriber to one status, if the rules allow.
	 *
	 * @param string[] $from The statuses this event may move them out of.
	 * @param string   $at   ISO 8601: when it happened at Resend.
	 * @return string
	 */
	private static function apply( $email, $status, array $from, $at ) {
		$row = LetterWP_Subscribers::get_by_email( $email );

		if ( ! $row ) {
			return 'unknown';
		}

		if ( $status === $row->status ) {
			return 'no change';
		}

		if ( ! in_array( $row->status, $from, true ) ) {
			return 'kept ' . $row->status;
		}

		$happened  = strtotime( $at );
		$confirmed = $row->confirmed_at ? strtotime( $row->confirmed_at . ' UTC' ) : 0;

		if ( $happened && $confirmed && $happened < $confirmed ) {
			return 'older than confirm';
		}

		LetterWP_Subscribers::set_status( $row->id, $status );

		return $status;
	}

	/**
	 * Topic choices from Resend's page. Only the topics that changed come in;
	 * the others keep what WordPress has.
	 *
	 * @param array[] $topics {id, subscription: opt_in|opt_out}.
	 * @param string  $at     ISO 8601: when Resend made the event.
	 * @return string
	 */
	private static function topics( $email, array $topics, $at ) {
		$row = LetterWP_Subscribers::get_by_email( $email );

		if ( ! $row ) {
			return 'unknown';
		}

		// An echo of an older push, arriving after a newer change made here.
		if ( self::microtime( $at ) < LetterWP_Sync::topics_changed_at( $row->id ) ) {
			return 'older than our change';
		}

		$terms   = array_flip( LetterWP_Setup::links()['topics'] );
		$all     = LetterWP_Subscribers::optouts( array( $row->id ) );
		$optouts = isset( $all[ $row->id ] ) ? array_fill_keys( $all[ $row->id ], true ) : array();
		$before  = $optouts;

		foreach ( $topics as $topic ) {
			if ( empty( $topic['id'] ) || ! isset( $terms[ $topic['id'] ] ) ) {
				continue; // Not one of ours.
			}

			$term = (int) $terms[ $topic['id'] ];

			if ( isset( $topic['subscription'] ) && 'opt_out' === $topic['subscription'] ) {
				$optouts[ $term ] = true;
			} else {
				unset( $optouts[ $term ] );
			}
		}

		ksort( $optouts );
		ksort( $before );

		if ( $optouts === $before ) {
			return 'no change';
		}

		LetterWP_Subscribers::update( $row->id, array( 'topics' => array_diff( LetterWP_Subscribers::topic_ids(), array_keys( $optouts ) ) ) );

		return 'topics';
	}

	/** @return float An ISO 8601 time with its milliseconds, or 0 when unreadable. */
	private static function microtime( $iso ) {
		try {
			return (float) ( new DateTime( (string) $iso ) )->format( 'U.u' );
		} catch ( Exception $e ) {
			return 0.0;
		}
	}
}
