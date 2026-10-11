<?php
/**
 * The list's shape in Resend: one segment, and one topic per category.
 *
 *   Segment = who the site owner says a reader is. v1 has one, "General",
 *             and every subscriber is in it.
 *
 * Setup adopts a segment or topic by its name, and the sync takes a segment
 * contact WordPress does not know out of the segment. So two sites on one
 * Resend account must never share a name: on a network (where the sites
 * usually share one account) every name carries the site's name, see
 * scope().
 *   Topic   = what a reader wants. One per category, opt-in (everyone gets
 *             every category until they turn it off), public (shown on
 *             Resend's unsubscribe page, so readers can turn one off there).
 *             A post goes out under one topic: its category.
 *   Announcements = one more topic, for campaigns (LetterWP_Campaigns), so a
 *             reader can turn launches and offers off and keep the posts.
 *             It sits in the links where a term id would, under ANNOUNCE
 *             (0, never a term id), so sync, webhook and opt-outs treat it
 *             as one more topic. Made by the first campaign, not by Setup:
 *             a site that never sends one never shows readers the choice.
 *
 * WordPress decides, Resend copies: categories are created, renamed and
 * deleted here, and the topics follow. Only the links (which Resend id
 * belongs to what) are stored; whether they still hold is read live.
 *
 * Setup also makes the webhook that brings bounces, complaints and
 * unsubscribes back (LetterWP_Webhook), and the letterwp_key contact
 * property that carries each reader's unsubscribe key into the footer link
 * (LetterWP_Subscribers::key()).
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Setup {

	/** {segment: id, topics: {term_id: topic id}, key: bool}. */
	const OPTION = 'letterwp_links';

	const SEGMENT_NAME = 'General';

	/** The Announcements topic's key in the links and in opt-outs: no term has id 0. */
	const ANNOUNCE = 0;

	/** Its slug in the subscribers export and import, where categories have theirs. */
	const ANNOUNCE_SLUG = 'letterwp-announcements';

	/** The contact property holding each reader's unsubscribe key. */
	const KEY_PROPERTY = 'letterwp_key';

	public function __construct() {
		// Not only in wp-admin: the block editor creates categories over REST.
		add_action( 'created_category', array( __CLASS__, 'sync_category' ) );
		add_action( 'edited_category', array( __CLASS__, 'sync_category' ) );
		add_action( 'delete_category', array( __CLASS__, 'forget_category' ) );
	}

	/* ---------------------------------------------------------------------
	 * Names.
	 * ------------------------------------------------------------------ */

	/**
	 * What sets this site's names apart in a Resend account it shares: the
	 * site's name on a network, '' (the plain names) on a single site. A
	 * single site that shares its account with another site sets one with
	 * the letterwp_resend_scope filter. Only setup reads names (to adopt);
	 * after that the links are ids, so renaming the site renames the topics
	 * on the next Run setup and nothing is lost.
	 *
	 * @return string
	 */
	public static function scope() {
		$scope = is_multisite() ? wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) : '';

		return trim( (string) apply_filters( 'letterwp_resend_scope', $scope ) );
	}

	/** @return string The segment's name: "General", or "General · Site". */
	public static function segment_name() {
		$scope = self::scope();

		return '' === $scope ? self::SEGMENT_NAME : mb_substr( self::SEGMENT_NAME . ' · ' . $scope, 0, 50 );
	}

	/* ---------------------------------------------------------------------
	 * Links.
	 * ------------------------------------------------------------------ */

	/** @return array{segment:string,topics:array<int,string>,key:bool} */
	public static function links() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'segment' => isset( $stored['segment'] ) ? (string) $stored['segment'] : '',
			'topics'  => isset( $stored['topics'] ) && is_array( $stored['topics'] ) ? array_map( 'strval', $stored['topics'] ) : array(),
			'key'     => ! empty( $stored['key'] ),
		);
	}

	private static function save_links( array $links ) {
		update_option( self::OPTION, $links, true );
	}

	/** Called on disconnect: the links point into the old account. */
	public static function clear() {
		delete_option( self::OPTION );
		LetterWP_Webhook::clear();
	}

	/** @return bool Setup has run at least once on this account. */
	public static function is_done() {
		return '' !== self::links()['segment'];
	}

	/**
	 * @return bool The letterwp_key property exists at Resend, so contacts can
	 *              carry it: Resend refuses a contact with an unknown property.
	 */
	public static function has_key_property() {
		return self::links()['key'];
	}

	/** @return string The segment every Broadcast goes to. */
	public static function segment_id() {
		return self::links()['segment'];
	}

	/**
	 * @return bool One topic per category, so readers pick the categories
	 *              they get. Off in LetterWP: every subscriber gets every
	 *              post. LetterWP Pro turns it on with this filter.
	 */
	public static function category_topics() {
		return (bool) apply_filters( 'letterwp_category_topics', false );
	}

	/** @return string The topic a post in this category goes out under, or ''. */
	public static function topic_id( $term_id ) {
		$topics = self::links()['topics'];

		return isset( $topics[ $term_id ] ) ? $topics[ $term_id ] : '';
	}

	/** @return bool Some campaign has made the Announcements topic. */
	public static function has_announcements() {
		return isset( self::links()['topics'][ self::ANNOUNCE ] );
	}

	/** @return string What readers see for the Announcements topic. */
	public static function announcements_label() {
		return __( 'Announcements', 'letterwp' );
	}

	/**
	 * Every topic a reader can turn off: the categories by name, then
	 * Announcements once a campaign has made it.
	 *
	 * @return array<int,string> term id (or ANNOUNCE) => name, as stored (escape it).
	 */
	public static function topic_names() {
		$names = array();

		foreach ( self::categories() as $term ) {
			$names[ (int) $term->term_id ] = $term->name;
		}

		if ( self::has_announcements() ) {
			$names[ self::ANNOUNCE ] = self::announcements_label();
		}

		return $names;
	}

	/**
	 * The same topics by slug, for the subscribers CSV.
	 *
	 * @param bool $all Announcements even before a campaign made it: an import
	 *                  keeps an opt-out from another site's export.
	 * @return array<int,string> term id (or ANNOUNCE) => slug.
	 */
	public static function topic_slugs( $all = false ) {
		$slugs = array();

		foreach ( self::categories() as $term ) {
			$slugs[ (int) $term->term_id ] = $term->slug;
		}

		if ( $all || self::has_announcements() ) {
			$slugs[ self::ANNOUNCE ] = self::ANNOUNCE_SLUG;
		}

		return $slugs;
	}

	/** @return WP_Term[] The categories that are topics: none while category_topics() is off. */
	private static function categories() {
		if ( ! self::category_topics() ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		return is_array( $terms ) ? $terms : array();
	}

	/** @return array{name:string,description:string} What a category's topic should say. */
	private static function topic_fields( WP_Term $term ) {
		$name  = wp_specialchars_decode( $term->name, ENT_QUOTES );
		$scope = self::scope();

		return array(
			'name'        => mb_substr( '' === $scope ? $name : $scope . ' · ' . $name, 0, 50 ),
			'description' => mb_substr( trim( wp_strip_all_tags( $term->description ) ), 0, 200 ),
		);
	}

	/** @return array{name:string,description:string} What the Announcements topic should say. */
	private static function announcements_fields() {
		$name  = self::announcements_label();
		$scope = self::scope();

		return array(
			'name'        => mb_substr( '' === $scope ? $name : $scope . ' · ' . $name, 0, 50 ),
			'description' => __( 'News that is not a new post: launches, events and offers.', 'letterwp' ),
		);
	}

	/**
	 * The Announcements topic's id, made (or adopted by its name) the first
	 * time a campaign needs it.
	 *
	 * @return string|WP_Error '' before Setup has run.
	 */
	public static function announcements_topic() {
		$links = self::links();

		if ( isset( $links['topics'][ self::ANNOUNCE ] ) ) {
			return $links['topics'][ self::ANNOUNCE ];
		}

		if ( ! self::is_done() ) {
			return '';
		}

		$topics = LetterWP_Resend::topics();

		if ( is_wp_error( $topics ) ) {
			return $topics;
		}

		$want   = self::announcements_fields();
		$byname = array_column( $topics, 'id', 'name' );
		$id     = isset( $byname[ $want['name'] ] ) ? (string) $byname[ $want['name'] ] : '';

		if ( '' === $id ) {
			$made = LetterWP_Resend::create_topic(
				$want + array(
					'default_subscription' => 'opt_in',
					'visibility'           => 'public',
				)
			);
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$id = (string) $made['id'];
		}

		// Read again: the calls above took a while.
		$links                             = self::links();
		$links['topics'][ self::ANNOUNCE ] = $id;
		self::save_links( $links );

		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Status: what Resend has against what it should have. Two API calls.
	 * ------------------------------------------------------------------ */

	/**
	 * @return array|WP_Error {
	 *     segment: array|null  the linked segment, as Resend has it.
	 *     topics:  list of {term, topic, state}; state is ready, rename or missing.
	 *     announce: {topic, state} once a campaign has made it, else null.
	 *     orphans: topic ids linked to categories that no longer exist.
	 *     account: the account page's id, 0 when there is none yet.
	 *     webhook: LetterWP_Webhook::status().
	 *     key:     the letterwp_key contact property exists at Resend.
	 * }
	 */
	public static function status() {
		$segments = LetterWP_Resend::segments();
		$topics   = is_wp_error( $segments ) ? $segments : LetterWP_Resend::topics();
		$props    = is_wp_error( $topics ) ? $topics : LetterWP_Resend::contact_properties();
		$webhook  = is_wp_error( $props ) ? $props : LetterWP_Webhook::status();

		if ( is_wp_error( $webhook ) ) {
			return $webhook;
		}

		$links    = self::links();
		$segments = array_column( $segments, null, 'id' );
		$topics   = array_column( $topics, null, 'id' );
		$rows     = array();
		$terms    = array();

		foreach ( self::categories() as $term ) {
			$terms[ $term->term_id ] = true;

			$id    = isset( $links['topics'][ $term->term_id ] ) ? $links['topics'][ $term->term_id ] : '';
			$topic = '' !== $id && isset( $topics[ $id ] ) ? $topics[ $id ] : null;
			$state = 'missing';

			if ( $topic ) {
				$want  = self::topic_fields( $term );
				$state = $want['name'] === $topic['name'] && $want['description'] === (string) ( isset( $topic['description'] ) ? $topic['description'] : '' ) ? 'ready' : 'rename';
			}

			$rows[] = array(
				'term'  => $term,
				'topic' => $topic,
				'state' => $state,
			);
		}

		$announce = null;
		if ( isset( $links['topics'][ self::ANNOUNCE ] ) ) {
			$topic    = isset( $topics[ $links['topics'][ self::ANNOUNCE ] ] ) ? $topics[ $links['topics'][ self::ANNOUNCE ] ] : null;
			$want     = self::announcements_fields();
			$announce = array(
				'topic' => $topic,
				'state' => ! $topic ? 'missing' : ( $want['name'] === $topic['name'] ? 'ready' : 'rename' ),
			);
		}

		// With category topics off, the ones made earlier are left alone, not removed.
		$orphans = array();
		foreach ( self::category_topics() ? $links['topics'] : array() as $term_id => $id ) {
			if ( self::ANNOUNCE !== (int) $term_id && ! isset( $terms[ $term_id ] ) && isset( $topics[ $id ] ) ) {
				$orphans[] = $id;
			}
		}

		return array(
			'segment' => '' !== $links['segment'] && isset( $segments[ $links['segment'] ] ) ? $segments[ $links['segment'] ] : null,
			'topics'   => $rows,
			'announce' => $announce,
			'orphans'  => $orphans,
			'account' => LetterWP_Login::account_page_id(),
			'webhook' => $webhook,
			'key'     => in_array( self::KEY_PROPERTY, array_column( $props, 'key' ), true ),
		);
	}

	/** @return bool Nothing left for run() to do. */
	public static function is_complete( array $status ) {
		if ( ! $status['segment'] || $status['orphans'] || ! $status['key'] || 'ready' !== $status['webhook']['state'] ) {
			return false;
		}

		if ( $status['announce'] && 'ready' !== $status['announce']['state'] ) {
			return false;
		}

		foreach ( $status['topics'] as $row ) {
			if ( 'ready' !== $row['state'] ) {
				return false;
			}
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Run: make Resend match. Safe to run again: anything already right is
	 * left alone, and a segment or topic with the expected name is adopted
	 * rather than duplicated (say, after disconnecting and reconnecting).
	 * ------------------------------------------------------------------ */

	/** @return array{created:int,updated:int,deleted:int}|WP_Error */
	public static function run() {
		// The account page first: it is WordPress's own, and needs no Resend.
		$page = LetterWP_Login::ensure_account_page();

		$segments = LetterWP_Resend::segments();
		$topics   = is_wp_error( $segments ) ? $segments : LetterWP_Resend::topics();

		if ( is_wp_error( $topics ) ) {
			return $topics;
		}

		$links  = self::links();
		$done   = array(
			'created' => $page ? 1 : 0,
			'updated' => 0,
			'deleted' => 0,
		);
		$by_id  = array_column( $topics, null, 'id' );
		$byname = array_column( $topics, 'id', 'name' );

		// The segment.
		if ( '' === $links['segment'] || ! in_array( $links['segment'], array_column( $segments, 'id' ), true ) ) {
			$found = array_column( $segments, 'id', 'name' );

			if ( isset( $found[ self::segment_name() ] ) ) {
				$links['segment'] = $found[ self::segment_name() ];
			} else {
				$made = LetterWP_Resend::create_segment( self::segment_name() );
				if ( is_wp_error( $made ) ) {
					return $made;
				}
				$links['segment'] = (string) $made['id'];
				++$done['created'];
			}

			self::save_links( $links );
		}

		// One topic per category.
		$terms = array();
		foreach ( self::categories() as $term ) {
			$terms[ $term->term_id ] = true;

			$want = self::topic_fields( $term );
			$id   = isset( $links['topics'][ $term->term_id ] ) ? $links['topics'][ $term->term_id ] : '';

			if ( '' === $id || ! isset( $by_id[ $id ] ) ) {
				$id = isset( $byname[ $want['name'] ] ) ? $byname[ $want['name'] ] : '';
			}

			if ( '' === $id ) {
				$made = LetterWP_Resend::create_topic(
					$want + array(
						'default_subscription' => 'opt_in',
						'visibility'           => 'public',
					)
				);
				if ( is_wp_error( $made ) ) {
					return $made;
				}
				$id = (string) $made['id'];
				++$done['created'];
				self::pause();
			} elseif ( $want['name'] !== $by_id[ $id ]['name'] || $want['description'] !== (string) ( isset( $by_id[ $id ]['description'] ) ? $by_id[ $id ]['description'] : '' ) ) {
				$made = LetterWP_Resend::update_topic( $id, $want );
				if ( is_wp_error( $made ) ) {
					return $made;
				}
				++$done['updated'];
				self::pause();
			}

			$links['topics'][ $term->term_id ] = $id;
			self::save_links( $links );
		}

		// Announcements, once a campaign made it: found again, or renamed.
		if ( isset( $links['topics'][ self::ANNOUNCE ] ) ) {
			$want = self::announcements_fields();
			$id   = $links['topics'][ self::ANNOUNCE ];

			if ( ! isset( $by_id[ $id ] ) ) {
				$id = isset( $byname[ $want['name'] ] ) ? $byname[ $want['name'] ] : '';
			}

			if ( '' === $id ) {
				$made = LetterWP_Resend::create_topic(
					$want + array(
						'default_subscription' => 'opt_in',
						'visibility'           => 'public',
					)
				);
				if ( is_wp_error( $made ) ) {
					return $made;
				}
				$id = (string) $made['id'];
				++$done['created'];
				self::pause();
			} elseif ( $want['name'] !== $by_id[ $id ]['name'] ) {
				$made = LetterWP_Resend::update_topic( $id, $want );
				if ( is_wp_error( $made ) ) {
					return $made;
				}
				++$done['updated'];
				self::pause();
			}

			$links['topics'][ self::ANNOUNCE ] = $id;
			self::save_links( $links );
		}

		// Topics whose category is gone (left alone while category topics are off).
		foreach ( self::category_topics() ? $links['topics'] : array() as $term_id => $id ) {
			if ( self::ANNOUNCE === (int) $term_id || isset( $terms[ $term_id ] ) ) {
				continue;
			}
			if ( isset( $by_id[ $id ] ) ) {
				$gone = LetterWP_Resend::delete_topic( $id );
				if ( is_wp_error( $gone ) ) {
					return $gone;
				}
				++$done['deleted'];
				self::pause();
			}
			unset( $links['topics'][ $term_id ] );
			self::save_links( $links );
		}

		// The unsubscribe key's property, adopted when it is there already.
		$props = LetterWP_Resend::contact_properties();
		if ( is_wp_error( $props ) ) {
			return $props;
		}
		if ( ! in_array( self::KEY_PROPERTY, array_column( $props, 'key' ), true ) ) {
			$made = LetterWP_Resend::create_contact_property(
				array(
					'key'            => self::KEY_PROPERTY,
					'type'           => 'string',
					'fallback_value' => '',
				)
			);
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			++$done['created'];
		}
		if ( ! $links['key'] ) {
			$links['key'] = true;
			self::save_links( $links );
		}

		// The way back: bounces, complaints, unsubscribes.
		$hook = LetterWP_Webhook::ensure();
		if ( is_wp_error( $hook ) ) {
			return $hook;
		}
		if ( '' !== $hook ) {
			++$done[ $hook ];
		}

		return $done;
	}

	/** Resend allows 10 calls a second per account; stay well under. */
	private static function pause() {
		usleep( 150000 );
	}

	/* ---------------------------------------------------------------------
	 * Keeping up: a category created, renamed or deleted after setup moves
	 * its topic at once. A failure here is not lost: the setup screen shows
	 * the topic as missing or out of date, and Run setup fixes it.
	 * ------------------------------------------------------------------ */

	public static function sync_category( $term_id ) {
		$term = get_term( $term_id, 'category' );

		if ( ! self::category_topics() || ! self::is_done() || '' === LetterWP_Resend::key() || ! $term instanceof WP_Term ) {
			return;
		}

		$links = self::links();
		$want  = self::topic_fields( $term );
		$id    = isset( $links['topics'][ $term_id ] ) ? $links['topics'][ $term_id ] : '';

		if ( '' !== $id ) {
			LetterWP_Resend::update_topic( $id, $want );
			return;
		}

		$made = LetterWP_Resend::create_topic(
			$want + array(
				'default_subscription' => 'opt_in',
				'visibility'           => 'public',
			)
		);

		if ( ! is_wp_error( $made ) && ! empty( $made['id'] ) ) {
			$links['topics'][ $term_id ] = (string) $made['id'];
			self::save_links( $links );
		}
	}

	public static function forget_category( $term_id ) {
		LetterWP_Subscribers::forget_topic( $term_id );

		$links = self::links();

		if ( ! isset( $links['topics'][ $term_id ] ) ) {
			return;
		}

		if ( '' !== LetterWP_Resend::key() ) {
			$gone = LetterWP_Resend::delete_topic( $links['topics'][ $term_id ] );

			// Kept as a link if Resend did not answer, so Run setup deletes it later.
			if ( is_wp_error( $gone ) && false === strpos( $gone->get_error_code(), 'http_404' ) ) {
				return;
			}
		}

		unset( $links['topics'][ $term_id ] );
		self::save_links( $links );
	}
}
