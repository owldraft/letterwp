<?php
/**
 * The post editor's LetterWP panel, and what it calls.
 *
 *   Send as email   _letterwp_send: whether publishing this post emails it.
 *                   On for a new post opened in the block editor, once the
 *                   site can send (default_on()); off everywhere else.
 *                   Unavailable until the newsletter address can send and
 *                   Setup has run; locked once the post is published,
 *                   because editing a published post never sends it again.
 *                   The sending itself is LetterWP_Send's.
 *   Sent            "Sent to N on date", how it went ("95% delivered ·
 *                   1 bounced", LetterWP_Stats) with a link to the post's
 *                   results, or why it failed and Try again.
 *   Email this post a published post that was never emailed: once, by
 *                   hand, after a confirmation (LetterWP_Send::send_published()).
 *   Topic           _letterwp_topic: which of the post's categories it goes
 *                   out under (Resend takes one topic per Broadcast). 0, or
 *                   a category the post no longer has, means the first.
 *   Send test       the post as it is in the editor, saved or not, to the
 *                   signed-in user's own inbox.
 *   Preview         the same, in a browser tab.
 *
 * A campaign (LetterWP_Campaigns) gets the same panel without the switch
 * or the topic: publishing it sends it, under Announcements, and the
 * editor says Send where it says Publish.
 *
 * The same switch shows once more in the publish check ("Are you ready to
 * publish?"), with how many readers it goes to: the last look before an
 * email that cannot be taken back.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Editor {

	const META_SEND = '_letterwp_send';

	const META_TOPIC = '_letterwp_topic';

	const PREVIEW = 'letterwp_preview';

	const REST = 'letterwp/v1';

	public function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'wp_insert_post', array( __CLASS__, 'default_on' ), 10, 3 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::PREVIEW, array( $this, 'preview' ) );
	}

	public function register_meta() {
		$auth = function ( $allowed, $key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		register_post_meta(
			'post',
			self::META_SEND,
			array(
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => $auth,
			)
		);

		register_post_meta(
			'post',
			self::META_TOPIC,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $auth,
			)
		);
	}

	/**
	 * New posts are emailed by default, as on Ghost and Substack: "Add New"
	 * in the block editor starts the switch on, once the site can send.
	 * Only there. The meta's own default stays off, so old drafts, an old
	 * post published again, imports, wp-cli and the classic editor (which
	 * has no panel to show it) never email anyone by surprise.
	 */
	public static function default_on( $post_id, $post, $update ) {
		if ( $update || 'post' !== $post->post_type || 'auto-draft' !== $post->post_status ) {
			return;
		}

		if ( ! function_exists( 'use_block_editor_for_post' ) || ! use_block_editor_for_post( $post ) ) {
			return;
		}

		if ( self::readiness()['can_send'] ) {
			update_post_meta( $post_id, self::META_SEND, true );
		}
	}

	/**
	 * The category a post goes out under: the one picked in the panel if the
	 * post still has it, else its first by name (the order the panel lists
	 * them in). A campaign's is always Announcements.
	 *
	 * @return int A term id, or 0 when the post has no category, or
	 *             LetterWP_Setup::ANNOUNCE for a campaign.
	 */
	public static function topic_term( $post_id ) {
		if ( LetterWP_Campaigns::is( $post_id ) ) {
			return LetterWP_Setup::ANNOUNCE;
		}

		if ( ! LetterWP_Setup::category_topics() ) {
			return 0; // Every subscriber gets every post.
		}

		$cats   = wp_get_post_categories( $post_id, array( 'orderby' => 'name' ) );
		$picked = (int) get_post_meta( $post_id, self::META_TOPIC, true );

		if ( $picked && in_array( $picked, array_map( 'intval', $cats ), true ) ) {
			return $picked;
		}

		return $cats ? (int) $cats[0] : 0;
	}

	/* ---------------------------------------------------------------------
	 * REST.
	 * ------------------------------------------------------------------ */

	public function routes() {
		$can = function ( WP_REST_Request $request ) {
			return current_user_can( 'edit_post', (int) $request['id'] );
		};

		register_rest_route(
			self::REST,
			'/posts/(?P<id>\d+)/email',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => $can,
			)
		);

		register_rest_route(
			self::REST,
			'/posts/(?P<id>\d+)/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_retry' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					return current_user_can( 'publish_post', (int) $request['id'] );
				},
			)
		);

		register_rest_route(
			self::REST,
			'/posts/(?P<id>\d+)/email-now',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_email_now' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					return current_user_can( 'publish_post', (int) $request['id'] );
				},
				'args'                => array(
					'topic' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::REST,
			'/posts/(?P<id>\d+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_test' ),
				'permission_callback' => $can,
			)
		);
	}

	/**
	 * What the panel needs to know: can this site send, and if not, the one
	 * thing to fix; how many readers each topic reaches; what happened if
	 * the post was sent.
	 *
	 * @return array
	 */
	public function rest_status( WP_REST_Request $request ) {
		$settings = admin_url( 'admin.php?page=' . LetterWP_Admin::SLUG );

		return self::readiness() + array(
			'to'        => wp_get_current_user()->user_email,
			'readers'   => LetterWP_Subscribers::readers_by_topic(),
			'postal'    => '' !== LetterWP_Email::postal_address(),
			'postalUrl' => $settings . '#letterwp-addresses',
			'sent'      => self::sent( (int) $request['id'] ),
		);
	}

	/**
	 * Can this site send, and if not, the one thing to fix. Read live from
	 * Resend, one call, and a yes is remembered for a few minutes.
	 *
	 * @return array{from:string,can_send:bool,can_test:bool,message:string,fix_url:string,fix_label:string}
	 */
	private static function readiness() {
		$from     = LetterWP_Addresses::get( 'nl' );
		$settings = admin_url( 'admin.php?page=' . LetterWP_Admin::SLUG );
		$out      = array(
			'from'      => $from,
			'can_send'  => false,
			'can_test'  => false,
			'message'   => '',
			'fix_url'   => '',
			'fix_label' => '',
		);

		if ( '' === LetterWP_Resend::key() ) {
			$out['message']   = __( 'Connect Resend to send posts as email.', 'letterwp' );
			$out['fix_url']   = $settings;
			$out['fix_label'] = __( 'Connect Resend', 'letterwp' );
			return $out;
		}

		if ( '' === $from ) {
			$out['message']   = __( 'Set the newsletter email address first.', 'letterwp' );
			$out['fix_url']   = $settings . '#letterwp-addresses';
			$out['fix_label'] = __( 'Set the address', 'letterwp' );
			return $out;
		}

		// A test is one email: Resend says itself if the domain cannot send.
		$out['can_test'] = true;

		// Ready rarely stops being ready, and Resend's domain list can take
		// seconds to answer: remember a yes for a few minutes, never a no.
		$cache  = 'letterwp_ready_' . md5( $from );
		$status = get_transient( $cache ) ? array( 'ready' => true ) : LetterWP_Resend::sender_status( $from );

		if ( $status['ready'] ) {
			set_transient( $cache, 1, 10 * MINUTE_IN_SECONDS );
		} else {
			$out['message']   = 'error' === $status['reason']
				/* translators: %s: error message. */
				? sprintf( __( 'Could not ask Resend: %s', 'letterwp' ), $status['error'] )
				/* translators: %s: domain name. */
				: sprintf( __( '%s is not ready to send yet.', 'letterwp' ), LetterWP_Addresses::domain_of( $from ) );
			$out['fix_url']   = $settings;
			$out['fix_label'] = __( 'Check the domain', 'letterwp' );
			return $out;
		}

		if ( ! LetterWP_Setup::is_done() ) {
			$out['message']   = __( 'Run Setup once, so posts have a list to go to.', 'letterwp' );
			$out['fix_url']   = $settings . '#letterwp-setup';
			$out['fix_label'] = __( 'Run Setup', 'letterwp' );
			return $out;
		}

		$out['can_send'] = true;

		return $out;
	}

	/** @return array|WP_Error The new sent record, as sent() shapes it. */
	public function rest_retry( WP_REST_Request $request ) {
		$result = LetterWP_Send::retry( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return self::sent( (int) $request['id'] );
	}

	/** @return array|WP_Error The new sent record, as sent() shapes it. */
	public function rest_email_now( WP_REST_Request $request ) {
		$result = LetterWP_Send::send_published( (int) $request['id'], (int) $request['topic'] );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return self::sent( (int) $request['id'] );
	}

	/**
	 * The post's send record, ready for the panel.
	 *
	 * @return array|null status, when (formatted), count, url, error, results.
	 */
	private static function sent( $post_id ) {
		$sent = LetterWP_Send::record( $post_id );

		if ( ! $sent ) {
			return null;
		}

		$numbers = 'sent' === $sent['status'] ? LetterWP_Stats::for_post( $post_id ) : null;

		return array(
			'status'  => $sent['status'],
			'when'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sent['time'] ),
			'count'   => isset( $sent['count'] ) ? (int) $sent['count'] : 0,
			'url'     => empty( $sent['broadcast'] ) ? '' : LetterWP_Resend::broadcast_url( $sent['broadcast'] ),
			'error'   => isset( $sent['error'] ) ? (string) $sent['error'] : '',
			'results' => $numbers ? array(
				'summary' => wp_strip_all_tags( implode( ' · ', LetterWP_Stats_Admin::summary( $numbers ) ) ),
				'warn'    => in_array( true, $numbers['warn'], true ),
				'url'     => LetterWP_Stats_Admin::url( $post_id ),
			) : null,
		);
	}

	/** @return array|WP_Error */
	public function rest_test( WP_REST_Request $request ) {
		$post = $this->edited_post( (int) $request['id'], $request->get_json_params() );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$from = LetterWP_Addresses::get( 'nl' );
		$to   = wp_get_current_user()->user_email;

		if ( '' === $from ) {
			return new WP_Error( 'letterwp_no_from', __( 'Set the newsletter email address in LetterWP first.', 'letterwp' ), array( 'status' => 400 ) );
		}

		$email = LetterWP_Email::build(
			$post['post'],
			array(
				'mode'         => 'test',
				'thumbnail_id' => $post['thumbnail_id'],
			)
		);

		$id = LetterWP_Resend::send_email(
			array(
				'from'    => $from,
				'to'      => $to,
				/* translators: %s: email subject. */
				'subject' => sprintf( __( '[Test] %s', 'letterwp' ), $email['subject'] ),
				'html'    => $email['html'],
				'text'    => $email['text'],
			)
		);

		if ( is_wp_error( $id ) ) {
			return new WP_Error( $id->get_error_code(), $id->get_error_message(), array( 'status' => 400 ) );
		}

		return array(
			'to'  => $to,
			'id'  => $id,
			'url' => LetterWP_Resend::email_url( $id ),
		);
	}

	/**
	 * The post as the editor has it: saved fields, with the unsaved title,
	 * content, excerpt and featured image laid over them. Nothing is saved.
	 *
	 * @param array $fields title, content, excerpt, featured_media.
	 * @return array{post:WP_Post,thumbnail_id:int|null}|WP_Error
	 */
	private function edited_post( $id, $fields ) {
		$saved = get_post( $id );

		if ( ! $saved || ! in_array( $saved->post_type, LetterWP_Campaigns::types(), true ) ) {
			return new WP_Error( 'letterwp_no_post', __( 'That post does not exist.', 'letterwp' ), array( 'status' => 404 ) );
		}

		$fields = is_array( $fields ) ? $fields : array();
		$data   = get_object_vars( $saved );
		$map    = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		);

		foreach ( $map as $from => $to ) {
			if ( isset( $fields[ $from ] ) && is_string( $fields[ $from ] ) ) {
				$data[ $to ] = $fields[ $from ];
			}
		}

		return array(
			'post'         => new WP_Post( (object) $data ),
			'thumbnail_id' => isset( $fields['featured_media'] ) ? absint( $fields['featured_media'] ) : null,
		);
	}

	/* ---------------------------------------------------------------------
	 * Preview: the panel posts the editor's content here in a new tab.
	 * ------------------------------------------------------------------ */

	public function preview() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified just below.
		$id    = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::PREVIEW ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'This preview link has expired. Reload the editor and try again.', 'letterwp' ), 403 );
		}

		// The editor's own unsaved content, from someone allowed to edit this
		// post: the same trust as the editor's own preview.
		$fields = array();
		foreach ( array( 'title', 'content', 'excerpt', 'featured_media' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$fields[ $key ] = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		}
		// phpcs:enable

		$post = $this->edited_post( $id, $fields );

		if ( is_wp_error( $post ) ) {
			wp_die( esc_html( $post->get_error_message() ), 404 );
		}

		$email = LetterWP_Email::build(
			$post['post'],
			array(
				'mode'         => 'preview',
				'thumbnail_id' => $post['thumbnail_id'],
			)
		);

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $email['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by LetterWP_Email.
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Assets.
	 * ------------------------------------------------------------------ */

	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->post_type, LetterWP_Campaigns::types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'letterwp-editor',
			LETTERWP_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-api-fetch', 'wp-i18n', 'wp-hooks' ),
			LETTERWP_VERSION,
			true
		);

		wp_add_inline_script(
			'letterwp-editor',
			'window.letterwpEditor = ' . wp_json_encode(
				array(
					'previewUrl'   => admin_url( 'admin-post.php' ),
					'previewNonce' => wp_create_nonce( self::PREVIEW ),
					'metaSend'     => self::META_SEND,
					'metaTopic'    => self::META_TOPIC,
					'campaign'     => LetterWP_Campaigns::TYPE === $screen->post_type,
					'topics'       => LetterWP_Setup::category_topics(),
					'announce'     => LetterWP_Setup::ANNOUNCE,
					'announceName' => LetterWP_Setup::announcements_label(),
				)
			) . ';',
			'before'
		);

		wp_set_script_translations( 'letterwp-editor', 'letterwp' );

		if ( LetterWP_Campaigns::TYPE === $screen->post_type ) {
			$this->campaign_words();
		}
	}

	/**
	 * A campaign is sent, not published: the editor's own buttons and checks
	 * say so. Right after wp-i18n, in the head, so the filter is there before
	 * the editor renders anything; editor.js loads too late for it.
	 */
	private function campaign_words() {
		$words = array(
			'Publish'                                       => __( 'Send', 'letterwp' ),
			'Publish…'                                      => __( 'Send…', 'letterwp' ),
			'Publishing…'                                   => __( 'Sending…', 'letterwp' ),
			'Published'                                     => __( 'Sent', 'letterwp' ),
			'Are you ready to publish?'                     => __( 'Ready to send?', 'letterwp' ),
			'Double-check your settings before publishing.' => __( 'Double-check it first: an email can\'t be unsent.', 'letterwp' ),
		);

		wp_add_inline_script(
			'wp-i18n',
			'( function ( words ) {'
				// Core's strings: __() with no domain, which only the _default filter is sure to see.
				. 'wp.hooks.addFilter( "i18n.gettext_default", "letterwp/campaign-words", function ( translation, text ) {'
				. 'return words[ text ] || translation;'
				. '} );'
			. '}( ' . wp_json_encode( $words ) . ' ) );',
			'after'
		);
	}
}
