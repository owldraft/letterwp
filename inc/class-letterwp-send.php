<?php
/**
 * Publishing a post emails it: one Broadcast to the General segment, under
 * the post's topic, created and sent in one call. That is all.
 *
 * Send is send, sync is sync. Sending knows nothing about sync: no pull of
 * unsubscribes, no diff first. It sends to whatever the segment holds, and
 * Resend skips every contact with unsubscribed: true. Keeping the segment
 * right is LetterWP_Sync's job alone.
 *
 * _letterwp_sent is the lock and the record: {status, time, broadcast,
 * count, topic, error}. It is written before Resend is called, so a post is
 * never sent twice, and editing a published post never sends it again. The
 * one way to send again is "Try again" after a failure, by hand.
 *
 * A campaign (LetterWP_Campaigns) is the same, without the switch:
 * publishing one always sends it, under the Announcements topic.
 *
 * A post published without its email can still get it, once, by hand:
 * "Email this post" in the editor (send_published()), behind the same
 * lock. A post that was emailed is never emailed to everyone again.
 *
 *   sending  the call is in flight (only for the length of one request)
 *   sent     Resend took it: broadcast id, and N = active subscribers who
 *            had not turned the topic off, as WordPress counted them then
 *   failed   nothing to show for it; error says why
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Send {

	const META = '_letterwp_sent';

	/** A "sending" older than this died with its request: treat it as failed. */
	const STALE = 10 * MINUTE_IN_SECONDS;

	public function __construct() {
		// After meta and terms: the block editor saves the toggle in the same
		// request as the status, after transition_post_status has fired. Cron
		// (scheduled posts), wp-cli and the classic editor fire it too.
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_publish' ), 10, 4 );
	}

	/**
	 * @param int          $post_id
	 * @param WP_Post      $post
	 * @param bool         $update
	 * @param WP_Post|null $before
	 */
	public static function on_publish( $post_id, $post, $update, $before ) {
		if ( ! in_array( $post->post_type, LetterWP_Campaigns::types(), true ) || 'publish' !== $post->post_status ) {
			return;
		}

		if ( $before && 'publish' === $before->post_status ) {
			return;
		}

		// A campaign has no switch: sending is what it is for.
		if ( ! LetterWP_Campaigns::is( $post ) && ! get_post_meta( $post_id, LetterWP_Editor::META_SEND, true ) ) {
			return;
		}

		// The lock: add_post_meta( unique ) fails if any record exists.
		if ( ! add_post_meta( $post_id, self::META, array( 'status' => 'sending', 'time' => time() ), true ) ) {
			return;
		}

		self::send( $post );
	}

	/**
	 * Try again after a failure. Only a failed (or dead) send can be retried,
	 * and only one request wins the swap from failed to sending.
	 *
	 * @return array|WP_Error The new record.
	 */
	public static function retry( $post_id ) {
		$post = get_post( $post_id );
		$raw  = get_post_meta( $post_id, self::META, true );
		$sent = self::record( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'letterwp_not_published', __( 'Only a published post can be sent.', 'letterwp' ) );
		}

		if ( ! $sent || 'failed' !== $sent['status'] ) {
			return new WP_Error( 'letterwp_not_failed', __( 'This post has already been sent, or is being sent.', 'letterwp' ) );
		}

		// Compare-and-swap: the UPDATE matches only the value read above.
		if ( ! update_post_meta( $post_id, self::META, array( 'status' => 'sending', 'time' => time() ), $raw ) ) {
			return new WP_Error( 'letterwp_not_failed', __( 'This post is being sent already.', 'letterwp' ) );
		}

		return self::send( $post );
	}

	/**
	 * Email a post that was published without it: "Email this post" in the
	 * editor. Same lock as publishing, so only a post never sent, and only
	 * one request wins.
	 *
	 * @param int $term A category of the post to send it under (the editor's
	 *                  pick may be unsaved), or 0 for the saved one.
	 * @return array|WP_Error The new record.
	 */
	public static function send_published( $post_id, $term = 0 ) {
		$post = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'letterwp_not_published', __( 'Only a published post can be emailed this way.', 'letterwp' ) );
		}

		if ( '' !== $post->post_password ) {
			return new WP_Error( 'letterwp_password', __( 'Password-protected posts are not emailed.', 'letterwp' ) );
		}

		if ( $term && LetterWP_Setup::category_topics() ) {
			if ( ! in_array( (int) $term, array_map( 'intval', wp_get_post_categories( $post_id ) ), true ) ) {
				return new WP_Error( 'letterwp_bad_topic', __( 'That topic is not one of this post\'s categories.', 'letterwp' ) );
			}
			update_post_meta( $post_id, LetterWP_Editor::META_TOPIC, (int) $term );
		}

		if ( ! add_post_meta( $post_id, self::META, array( 'status' => 'sending', 'time' => time() ), true ) ) {
			return new WP_Error( 'letterwp_already_sent', __( 'This post has already been emailed, or is being emailed.', 'letterwp' ) );
		}

		// The switch says what happened, as on a post emailed when published.
		update_post_meta( $post_id, LetterWP_Editor::META_SEND, true );

		return self::send( $post );
	}

	/**
	 * The record, with a dead "sending" read as failed.
	 *
	 * @return array|null Null when the post was never sent.
	 */
	public static function record( $post_id ) {
		$sent = get_post_meta( $post_id, self::META, true );

		if ( ! is_array( $sent ) || empty( $sent['status'] ) ) {
			return null;
		}

		if ( 'sending' === $sent['status'] && time() - (int) $sent['time'] > self::STALE ) {
			$sent['status'] = 'failed';
			$sent['error']  = __( 'The send stopped before Resend answered. Check Resend → Broadcasts before trying again.', 'letterwp' );
		}

		return $sent;
	}

	/**
	 * One Broadcast, and the record of it. The lock is already held.
	 *
	 * @return array The record.
	 */
	private static function send( WP_Post $post ) {
		$campaign = LetterWP_Campaigns::is( $post );
		$term     = LetterWP_Editor::topic_term( $post->ID );
		$segment  = LetterWP_Setup::segment_id();
		$topic    = $term || $campaign ? LetterWP_Setup::topic_id( $term ) : '';
		$from     = LetterWP_Addresses::get( 'nl' );
		$error    = null;
		$why      = '';

		if ( $campaign && '' === $topic ) {
			// The first campaign makes the Announcements topic.
			$made  = LetterWP_Setup::announcements_topic();
			$why   = is_wp_error( $made ) ? $made->get_error_message() : '';
			$topic = is_wp_error( $made ) ? '' : $made;
		} elseif ( $term && '' === $topic ) {
			// A category made while Resend was unreachable has no topic yet.
			LetterWP_Setup::sync_category( $term );
			$topic = LetterWP_Setup::topic_id( $term );
		}

		if ( '' === $segment ) {
			$error = __( 'Setup has not run: there is no list to send to. Run it in LetterWP → Setup → 3. Your list in Resend.', 'letterwp' );
		} elseif ( '' === $topic && $campaign ) {
			/* translators: %s: Resend's error message, or nothing. */
			$error = trim( sprintf( __( 'The Announcements topic could not be made at Resend. %s', 'letterwp' ), $why ) );
		} elseif ( '' === $topic && $term ) {
			$error = __( 'This post\'s category has no topic at Resend yet. Run LetterWP → Setup → 3. Your list in Resend.', 'letterwp' );
		} elseif ( '' === $from ) {
			$error = __( 'Set the newsletter email address in LetterWP → Setup → 4. Sender addresses first.', 'letterwp' );
		} elseif ( '' !== $post->post_password ) {
			$error = __( 'Password-protected posts are not emailed.', 'letterwp' );
		}

		$record = array(
			'status' => 'failed',
			'time'   => time(),
			'topic'  => $term,
		);

		if ( null === $error ) {
			$count = $term || $campaign ? LetterWP_Subscribers::count_for_topic( $term ) : LetterWP_Subscribers::counts()['active'];
			$email = LetterWP_Email::build( $post, array( 'mode' => 'send' ) );
			$id    = LetterWP_Resend::send_broadcast(
				array(
					'from'       => $from,
					'segment_id' => $segment,
					'topic_id'   => $topic,
					'subject'    => $email['subject'],
					'html'       => $email['html'],
					'text'       => $email['text'],
					'name'       => $email['subject'],
				)
			);

			if ( is_wp_error( $id ) ) {
				$error = 'http_request_failed' === $id->get_error_code()
					/* translators: %s: error message. */
					? sprintf( __( 'Resend did not answer (%s). It may have sent anyway: check Resend → Broadcasts before trying again.', 'letterwp' ), $id->get_error_message() )
					: $id->get_error_message();
			} else {
				$record = array(
					'status'    => 'sent',
					'time'      => time(),
					'topic'     => $term,
					'broadcast' => $id,
					'count'     => $count,
				);
			}
		}

		if ( null !== $error ) {
			$record['error'] = $error;
		}

		update_post_meta( $post->ID, self::META, $record );

		// Webhook events name the Broadcast: this finds the post they count for.
		if ( ! empty( $record['broadcast'] ) ) {
			update_post_meta( $post->ID, LetterWP_Stats::META, $record['broadcast'] );
		}

		return $record;
	}
}
