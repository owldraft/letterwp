<?php
/**
 * Subscribers → Resend contacts. WordPress decides, Resend copies, with
 * one exception: an unsubscribe made at Resend comes back first, and wins.
 *
 *   active        contact in the General segment, unsubscribed: false
 *   unsubscribed  if the contact exists at Resend, unsubscribed: true.
 *   bounced       Never created just to say no.
 *   pending       never sent to Resend: they have not said yes yet.
 *   deleted       contact deleted at Resend, at once (privacy).
 *
 * A contact in the segment that WordPress does not know is taken out of
 * the segment, never deleted: it may be something the owner added by hand.
 * Every Broadcast goes to the segment, so the segment is what must match.
 *
 * first_name and last_name are the same two fields on both sides. Active
 * contacts also carry the reader's unsubscribe key (letterwp_key), which
 * the newsletter footer links to; see LetterWP_Subscribers::key().
 *
 * Topics: WordPress stores only opt-outs, and readers choose in two places:
 * the account page here, and Resend's unsubscribe page. Every change made
 * here is pushed at once (the whole set, each linked topic opt_in or
 * opt_out), so Resend always holds the latest choice, and pull_topics()
 * copies it back. Resend's page sends no webhook for topic changes (tested
 * 2026-10-01), so the pull happens where choices matter: the account page,
 * the subscriber's edit screen, and run(). A push sends topics only when
 * they changed here, never just because a name or status did: that would
 * undo a choice the reader made at Resend.
 *
 * Last change wins, by the clock. A topic change made here is stamped
 * (topics_changed_at()); a topic event Resend created before that stamp is
 * an echo of an older push and is ignored, and no pull runs for a minute
 * after it, because Resend's reads lag its writes by about a second.
 *
 * The one way back from an unsubscribe is the reader's own: a confirmed
 * subscribe link, or "Subscribe again" while signed in. LetterWP_Login calls
 * consent() first, and the push that follows tells Resend unsubscribed:false
 * instead of letting Resend's old unsubscribe win.
 *
 * push() applies the same rules to one subscriber, right after they are
 * added or changed. An import is too big for that: its new ids wait in a
 * queue, and drain() pushes them a batch at a time (the import screen calls
 * it until the queue is empty). run() is the full diff: the button on the
 * Subscribers screen today. Sending never calls either: it sends to whatever the
 * segment holds, and keeping the segment right is this class's job alone.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Sync {

	/** The last run's result, for the Subscribers screen. */
	const OPTION = 'letterwp_sync';

	/** Subscriber ids waiting for push(): imports, so far. */
	const QUEUE = 'letterwp_sync_queue';

	/** Stop after this many failed calls in a row: Resend is down or the key died. */
	const MAX_FAILS = 3;

	/** @var bool True while run() works: its own changes need no push. */
	private static $running = false;

	/** @var true|WP_Error|null The last push(), for the screen that caused it. */
	private static $pushed = null;

	/** A topic change made here stays unpulled this long: Resend's reads lag its writes. */
	const SETTLE = MINUTE_IN_SECONDS;

	/** @var array<string,true> Emails whose reader just said yes again, this request. */
	private static $consented = array();

	public function __construct() {
		add_action( 'letterwp_subscriber_saved', array( __CLASS__, 'push' ), 10, 2 );
		add_action( 'letterwp_subscriber_deleted', array( __CLASS__, 'forget' ) );
		add_action( 'letterwp_subscribers_imported', array( __CLASS__, 'enqueue' ) );
	}

	/** @return bool Resend is connected and set up, so there is somewhere to sync to. */
	public static function ready() {
		return '' !== LetterWP_Resend::key() && LetterWP_Setup::is_done();
	}

	/** The reader at this address just said yes themselves: their next push overrides an unsubscribe at Resend. */
	public static function consent( $email ) {
		self::$consented[ strtolower( $email ) ] = true;
	}

	/**
	 * Make a change without pushing it: it came from Resend, which has it
	 * already. The webhook uses this, so an event never echoes back.
	 *
	 * @return mixed What $change returns.
	 */
	public static function quietly( callable $change ) {
		$was           = self::$running;
		self::$running = true;

		try {
			return $change();
		} finally {
			self::$running = $was;
		}
	}

	/** @return float When this subscriber's topics last changed in WordPress (microtime), 0 if not lately. */
	public static function topics_changed_at( $id ) {
		return (float) get_transient( 'letterwp_topics_at_' . (int) $id );
	}

	/** @return true|WP_Error|null What the last push() in this request did; null if none ran. */
	public static function pushed() {
		return self::$pushed;
	}

	/**
	 * One subscriber → Resend, by the rules at the top. Two or three calls.
	 *
	 * @param object   $row     A subscriber row.
	 * @param string[] $changed What changed: field names, topics, or created.
	 * @return true|WP_Error|null Null when there is nothing to sync to.
	 */
	public static function push( $row, $changed = array() ) {
		if ( self::$running || ! self::ready() || ! $row ) {
			return null;
		}

		if ( in_array( 'topics', (array) $changed, true ) ) {
			// Before any call: an echo of an older push may arrive while this one runs.
			set_transient( 'letterwp_topics_at_' . (int) $row->id, microtime( true ), DAY_IN_SECONDS );
		}

		$segment = LetterWP_Setup::segment_id();
		$contact = LetterWP_Resend::contact( $row->email );
		$names   = self::fields( $row );

		if ( is_wp_error( $contact ) ) {
			return self::$pushed = $contact;
		}

		$consent = isset( self::$consented[ strtolower( $row->email ) ] );

		// An unsubscribe made at Resend wins over WordPress, unless the reader just said yes again.
		if ( 'active' === $row->status && $contact && ! empty( $contact['unsubscribed'] ) && ! $consent ) {
			self::$running = true;
			LetterWP_Subscribers::set_status( $row->id, 'unsubscribed' );
			self::$running = false;
			$row->status   = 'unsubscribed';
		}

		if ( 'active' === $row->status ) {
			if ( ! $contact ) {
				$result = LetterWP_Resend::create_contact(
					$names + array(
						'email'        => $row->email,
						'unsubscribed' => false,
						'segments'     => array( array( 'id' => $segment ) ),
					)
				);
				// A new contact gets every topic (they are opt-in): only opt-outs need telling.
				$topics = (bool) LetterWP_Subscribers::optouts( array( $row->id ) );
			} else {
				$result = LetterWP_Resend::update_contact( $row->email, $consent ? $names + array( 'unsubscribed' => false ) : $names );
				// Adding a member again is harmless at Resend.
				$result = is_wp_error( $result ) ? $result : LetterWP_Resend::add_to_segment( $row->email, $segment );
				$topics = in_array( 'topics', (array) $changed, true );
			}
			$result = is_wp_error( $result ) || ! $topics ? $result : self::send_topics( $row );
		} elseif ( ! $contact ) {
			$result = true; // Not active and not at Resend: nothing to tell it.
		} elseif ( 'pending' === $row->status ) {
			$result = LetterWP_Resend::remove_from_segment( $row->email, $segment );
		} else {
			$result = LetterWP_Resend::update_contact( $row->email, self::fields( $row, false ) + array( 'unsubscribed' => true ) );
		}

		return self::$pushed = is_wp_error( $result ) ? $result : true;
	}

	/** @return int[] Subscriber ids waiting to be pushed. */
	public static function queue() {
		$queue = get_option( self::QUEUE, array() );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Queue subscribers for drain(). Before Setup there is nowhere to push
	 * to, and "Sync to Resend now" sends everyone afterwards anyway.
	 *
	 * @param int[] $ids
	 */
	public static function enqueue( array $ids ) {
		if ( $ids && self::ready() ) {
			update_option( self::QUEUE, array_values( array_unique( array_merge( self::queue(), array_map( 'intval', $ids ) ) ) ), false );
		}
	}

	/**
	 * Push queued subscribers for up to $seconds, then stop. A new contact
	 * is two calls, one after the other, so Resend's speed sets the pace:
	 * 0.4 to 1.2 s a call (2026-10-01), 1 to 2 people a second, well under
	 * its 10 calls a second. 500 take 5 to 10 minutes, over many calls of
	 * this. The GET before the create stays: Resend's create overwrites a
	 * contact that exists, unsubscribe included (tested 2026-10-01), and
	 * push() needs to see that unsubscribe first.
	 *
	 * A subscriber Resend refuses is dropped from the queue with the error
	 * (run() tries everyone again); a few failures in a row stop the batch.
	 *
	 * @return array{done:int,failed:int,left:int,errors:string[],stopped:bool,busy:bool}
	 */
	public static function drain( $seconds = 20 ) {
		$out = array(
			'done'    => 0,
			'failed'  => 0,
			'left'    => count( self::queue() ),
			'errors'  => array(),
			'stopped' => false,
			'busy'    => false,
		);

		// Two tabs draining at once would push the same people twice.
		if ( ! $out['left'] || ! add_option( 'letterwp_sync_draining', time(), '', false ) ) {
			$since       = (int) get_option( 'letterwp_sync_draining' );
			$out['busy'] = $out['left'] && $since > time() - $seconds - 60;
			if ( $out['left'] && ! $out['busy'] ) {
				delete_option( 'letterwp_sync_draining' ); // Left over from a batch that died.
			}
			return $out;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $seconds + 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
		}

		$start = microtime( true );
		$fails = 0;
		$done  = array();

		// Taken off the queue as they go, so other ids queued meanwhile stay.
		$save = function () use ( &$done ) {
			update_option( self::QUEUE, array_values( array_diff( self::queue(), $done ) ), false );
		};

		foreach ( self::queue() as $id ) {
			if ( microtime( true ) - $start > $seconds || $fails >= self::MAX_FAILS ) {
				break;
			}

			$row    = LetterWP_Subscribers::get( $id );
			$result = $row ? self::push( $row, array( 'created' ) ) : true;

			if ( is_wp_error( $result ) && 'letterwp_resend_http_429' === $result->get_error_code() ) {
				sleep( 1 ); // Too fast: this one again next time.
				continue;
			}

			$done[] = $id;

			if ( is_wp_error( $result ) ) {
				++$fails;
				++$out['failed'];
				$out['errors'][] = $row->email . ': ' . $result->get_error_message();
			} else {
				$fails = 0;
				++$out['done'];
			}

			if ( 0 === count( $done ) % 20 ) {
				$save();
			}
		}

		$save();
		delete_option( 'letterwp_sync_draining' );

		$out['left']    = count( self::queue() );
		$out['errors']  = array_slice( array_unique( $out['errors'] ), 0, 5 );
		$out['stopped'] = $fails >= self::MAX_FAILS;

		return $out;
	}

	/**
	 * What a contact holds besides its status: the names, and for a reader
	 * who gets emails, their unsubscribe key, once Setup made its property.
	 *
	 * @return array
	 */
	private static function fields( $row, $key = true ) {
		$fields = array(
			'first_name' => $row->first_name,
			'last_name'  => $row->last_name,
		);

		if ( $key && LetterWP_Setup::has_key_property() ) {
			$fields['properties'] = array( LetterWP_Setup::KEY_PROPERTY => LetterWP_Subscribers::key( $row ) );
		}

		return $fields;
	}

	/** @return array|null time, counts, errors of the last run. */
	public static function last() {
		$last = get_option( self::OPTION );

		return is_array( $last ) ? $last : null;
	}

	/**
	 * @return array|WP_Error {
	 *     created, updated, joined, left, pulled: int
	 *     in_segment: int   active subscribers in the segment afterwards
	 *     strangers:  int   contacts at Resend that WordPress does not know
	 *     errors:     string[]
	 * }
	 */
	public static function run() {
		$segment = LetterWP_Setup::segment_id();

		if ( '' === LetterWP_Resend::key() ) {
			return new WP_Error( 'letterwp_sync_no_key', __( 'Connect Resend first, in LetterWP → Setup.', 'letterwp' ) );
		}

		if ( '' === $segment ) {
			return new WP_Error( 'letterwp_sync_no_setup', __( 'Run setup first, in LetterWP → Setup → 3. Your list in Resend.', 'letterwp' ) );
		}

		self::$running = true;

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions
		}

		$contacts = LetterWP_Resend::contacts();
		$members  = is_wp_error( $contacts ) ? $contacts : LetterWP_Resend::contacts( $segment );

		if ( is_wp_error( $members ) ) {
			self::$running = false;
			return $members;
		}

		$remote = array();
		foreach ( $contacts as $contact ) {
			$remote[ strtolower( $contact['email'] ) ] = $contact;
		}

		$joined = array();
		foreach ( $members as $contact ) {
			$joined[ strtolower( $contact['email'] ) ] = true;
		}

		$rows    = LetterWP_Subscribers::all();
		$optouts = LetterWP_Subscribers::optouts( wp_list_pluck( $rows, 'id' ) );

		$done  = array_fill_keys( array( 'created', 'updated', 'joined', 'left', 'pulled', 'topics', 'in_segment', 'strangers' ), 0 );
		$fails = 0;
		$errs  = array();
		$known = array();

		// One call; false when it failed. Stops the whole run after a few in a row.
		$call = function ( $result ) use ( &$fails, &$errs ) {
			if ( is_wp_error( $result ) ) {
				++$fails;
				$errs[] = $result->get_error_message();
				return false;
			}
			$fails = 0;
			usleep( 120000 ); // Resend allows 10 calls a second.
			return true;
		};

		foreach ( $rows as $row ) {
			if ( $fails >= self::MAX_FAILS ) {
				break;
			}

			$email           = $row->email;
			$known[ $email ] = true;
			$contact         = isset( $remote[ $email ] ) ? $remote[ $email ] : null;

			// An unsubscribe made at Resend wins over WordPress.
			if ( 'active' === $row->status && $contact && ! empty( $contact['unsubscribed'] ) ) {
				LetterWP_Subscribers::set_status( $row->id, 'unsubscribed' );
				$row->status = 'unsubscribed';
				++$done['pulled'];
			}

			if ( 'active' === $row->status ) {
				if ( ! $contact ) {
					$ok = $call(
						LetterWP_Resend::create_contact(
							self::fields( $row ) + array(
								'email'        => $email,
								'unsubscribed' => false,
								'segments'     => array( array( 'id' => $segment ) ),
							)
						)
					);
					$done['created']    += $ok ? 1 : 0;
					$done['in_segment'] += $ok ? 1 : 0;
					if ( $ok && isset( $optouts[ $row->id ] ) ) {
						$done['topics'] += $call( self::send_topics( $row, $optouts[ $row->id ] ) ) ? 1 : 0;
					}
					continue;
				}

				// Resend's contact list leaves out properties, so the key is
				// written every time: names that differ are counted as an update.
				$renamed = (string) $contact['first_name'] !== $row->first_name || (string) $contact['last_name'] !== $row->last_name;
				if ( $renamed || LetterWP_Setup::has_key_property() ) {
					$ok               = $call( LetterWP_Resend::update_contact( $email, self::fields( $row ) ) );
					$done['updated'] += $ok && $renamed ? 1 : 0;
				}

				if ( ! isset( $joined[ $email ] ) ) {
					if ( ! $call( LetterWP_Resend::add_to_segment( $email, $segment ) ) ) {
						continue;
					}
					++$done['joined'];
				}

				// Resend holds the latest choice (see the top): copy it here.
				$pulled = self::pull_topics( $row, isset( $optouts[ $row->id ] ) ? $optouts[ $row->id ] : array() );
				if ( $call( $pulled ) && true === $pulled ) {
					++$done['topics'];
				}

				++$done['in_segment'];
				continue;
			}

			if ( 'pending' === $row->status ) {
				if ( isset( $joined[ $email ] ) ) {
					$done['left'] += $call( LetterWP_Resend::remove_from_segment( $email, $segment ) ) ? 1 : 0;
				}
				continue;
			}

			// Unsubscribed or bounced.
			if ( $contact && empty( $contact['unsubscribed'] ) ) {
				$done['updated'] += $call( LetterWP_Resend::update_contact( $email, array( 'unsubscribed' => true ) ) ) ? 1 : 0;
			}
		}

		// In the segment, unknown to WordPress: out of the segment.
		foreach ( array_keys( $joined ) as $email ) {
			if ( $fails < self::MAX_FAILS && ! isset( $known[ $email ] ) ) {
				$done['left'] += $call( LetterWP_Resend::remove_from_segment( $email, $segment ) ) ? 1 : 0;
			}
		}

		$done['strangers'] = count( array_diff_key( $remote, $known ) );
		$done['errors']    = array_slice( array_unique( $errs ), 0, 5 );
		$done['stopped']   = $fails >= self::MAX_FAILS;

		update_option( self::OPTION, array( 'time' => time() ) + $done, false );
		self::$running = false;

		if ( ! $done['stopped'] ) {
			delete_option( self::QUEUE ); // Everyone was just pushed.
		}

		return $done;
	}

	/**
	 * Every linked topic, opt_in or opt_out, for one subscriber.
	 *
	 * @param object     $row     A subscriber row.
	 * @param array|null $optouts Their opted-out term ids, when already known.
	 * @return array|true|WP_Error
	 */
	private static function send_topics( $row, $optouts = null ) {
		$links = LetterWP_Setup::links()['topics'];

		if ( ! $links ) {
			return true;
		}

		if ( null === $optouts ) {
			$all     = LetterWP_Subscribers::optouts( array( $row->id ) );
			$optouts = isset( $all[ $row->id ] ) ? $all[ $row->id ] : array();
		}

		$topics = array();
		foreach ( $links as $term_id => $topic_id ) {
			$topics[ $topic_id ] = ! in_array( (int) $term_id, $optouts, true );
		}

		return LetterWP_Resend::set_contact_topics( $row->email, $topics );
	}

	/**
	 * Copy one active subscriber's topics from Resend. Quiet: nothing is
	 * pushed back. Topics Resend does not list keep what WordPress has.
	 *
	 * @param object     $row     A subscriber row.
	 * @param array|null $optouts Their opted-out term ids, when already known.
	 * @return bool|WP_Error|null True when WordPress changed, false when it
	 *                            already matched, null when there was nothing to ask.
	 */
	public static function pull_topics( $row, $optouts = null ) {
		$links = LetterWP_Setup::links()['topics'];

		if ( ! $row || 'active' !== $row->status || ! $links || ! self::ready() ) {
			return null;
		}

		if ( microtime( true ) - self::topics_changed_at( $row->id ) < self::SETTLE ) {
			return false; // Changed here a moment ago: Resend may still answer with the old choice.
		}

		$remote = LetterWP_Resend::contact_topics( $row->email );

		if ( null === $remote || is_wp_error( $remote ) ) {
			return $remote;
		}

		if ( null === $optouts ) {
			$all     = LetterWP_Subscribers::optouts( array( $row->id ) );
			$optouts = isset( $all[ $row->id ] ) ? $all[ $row->id ] : array();
		}

		$want = array_fill_keys( array_map( 'intval', $optouts ), true );
		$have = $want;

		foreach ( $links as $term_id => $topic_id ) {
			if ( ! isset( $remote[ $topic_id ] ) ) {
				continue;
			}
			if ( $remote[ $topic_id ] ) {
				unset( $want[ (int) $term_id ] );
			} else {
				$want[ (int) $term_id ] = true;
			}
		}

		ksort( $want );
		ksort( $have );

		if ( $want === $have ) {
			return false;
		}

		self::quietly(
			function () use ( $row, $want ) {
				LetterWP_Subscribers::update( $row->id, array( 'topics' => array_diff( LetterWP_Subscribers::topic_ids(), array_keys( $want ) ) ) );
			}
		);

		return true;
	}

	/** A subscriber deleted in WordPress is deleted at Resend too. */
	public static function forget( $row ) {
		if ( self::ready() ) {
			LetterWP_Resend::delete_contact( $row->email );
		}
	}
}
