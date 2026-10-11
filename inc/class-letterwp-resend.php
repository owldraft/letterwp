<?php
/**
 * Resend settings and the API calls the settings screen needs.
 *
 * Everything LetterWP remembers about Resend lives in one option. Read it
 * through settings()/get(), write it through update(); nothing else touches
 * the option name.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Resend {

	/** The one option. Autoloaded: every send will read it. */
	const OPTION = 'letterwp_resend';

	const API = 'https://api.resend.com';

	const TIMEOUT = 15;

	/**
	 * The shape of the store. Only inputs live here: domains, DNS records and
	 * their status are Resend's, and are read live through the key.
	 *
	 * @return array<string,string>
	 */
	public static function defaults() {
		return array(
			'api_key' => '',
		);
	}

	/** @return array<string,string> The whole store, defaults filled in, unknown keys dropped. */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? array_intersect_key( $stored, self::defaults() ) : array();

		return array_merge( self::defaults(), $stored );
	}

	/** @return string */
	public static function get( $key ) {
		$all = self::settings();

		return isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
	}

	/**
	 * Merge changes into the store. Unknown keys are dropped.
	 *
	 * @param array $changes Key => value.
	 */
	public static function update( array $changes ) {
		$settings = array_merge( self::settings(), array_intersect_key( $changes, self::defaults() ) );

		update_option( self::OPTION, $settings, true );
	}

	/**
	 * The API key. A LETTERWP_RESEND_API_KEY constant in wp-config wins over
	 * the database, because a credential that need not live in the options
	 * table should not.
	 *
	 * @return string
	 */
	public static function key() {
		if ( self::key_is_constant() ) {
			return (string) LETTERWP_RESEND_API_KEY;
		}

		return self::get( 'api_key' );
	}

	/** @return bool */
	public static function key_is_constant() {
		return defined( 'LETTERWP_RESEND_API_KEY' ) && LETTERWP_RESEND_API_KEY;
	}

	/**
	 * One call to Resend.
	 *
	 * @param string     $method GET, POST, PATCH or DELETE.
	 * @param string     $path   Starting with a slash, e.g. /domains.
	 * @param array|null $body   Sent as JSON.
	 * @param string     $key    Defaults to the stored key.
	 * @param int        $timeout Seconds.
	 * @return array|WP_Error The decoded JSON, or what went wrong.
	 */
	public static function request( $method, $path, $body = null, $key = null, $timeout = self::TIMEOUT ) {
		$key = trim( null === $key ? self::key() : (string) $key );

		if ( '' === $key ) {
			return new WP_Error( 'letterwp_resend_no_key', __( 'Connect Resend first.', 'letterwp' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		// wp_remote_* sends a WordPress User-Agent; api.resend.com is behind
		// Cloudflare and blocks generic ones with "error code: 1010".
		$response = wp_remote_request( self::API . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		$name    = ( is_array( $data ) && ! empty( $data['name'] ) ) ? (string) $data['name'] : '';
		$message = self::error_message( $data, $raw, $code );

		if ( 'restricted_api_key' === $name ) {
			return new WP_Error(
				'letterwp_resend_restricted_key',
				__( 'That is a Sending access key. It can send email but cannot manage domains. Create a Full access key at Resend and paste that instead.', 'letterwp' )
			);
		}

		// An unknown key comes back 400 validation_error, not 401, so the
		// status alone does not mean "bad key".
		if ( in_array( $code, array( 400, 401, 403 ), true ) && false !== stripos( $message, 'api key' ) ) {
			return new WP_Error(
				'letterwp_resend_bad_key',
				__( 'Resend does not recognise that key. Check it was copied whole: Resend shows a key only once.', 'letterwp' )
			);
		}

		return new WP_Error(
			'letterwp_resend_http_' . $code,
			sprintf(
				/* translators: 1: Resend's error message. 2: HTTP status code. */
				__( 'Resend: %1$s (HTTP %2$d)', 'letterwp' ),
				$message,
				$code
			)
		);
	}

	/**
	 * Does this key work, and can it do more than send?
	 *
	 * GET /domains answers both at once: a "Sending access" key authenticates
	 * fine but cannot read or create domains, and the setup needs to.
	 *
	 * @param string $key The key to try, before it is stored.
	 * @return true|WP_Error
	 */
	public static function check_key( $key ) {
		if ( '' === trim( (string) $key ) ) {
			return new WP_Error( 'letterwp_resend_no_key', __( 'Paste an API key first.', 'letterwp' ) );
		}

		$result = self::request( 'GET', '/domains', null, $key );

		return is_wp_error( $result ) ? $result : true;
	}

	/* ---------------------------------------------------------------------
	 * Domains. Create, read, update; never delete from WordPress: one Resend
	 * account often sends for other sites and apps too, so deleting stays in
	 * Resend's own dashboard (delete_url()).
	 * ------------------------------------------------------------------ */

	/**
	 * Every domain in the account, without records. Asked once per page load:
	 * the domain list and every address signal share the answer.
	 *
	 * @return array[]|WP_Error
	 */
	public static function domains() {
		static $cache = array();

		$key = md5( self::key() );

		if ( ! isset( $cache[ $key ] ) ) {
			$result = self::request( 'GET', '/domains' );

			if ( ! is_wp_error( $result ) ) {
				$result = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
			}

			$cache[ $key ] = $result;
		}

		return $cache[ $key ];
	}

	/**
	 * Can Resend send from this address right now? Read live, never stored.
	 *
	 * The domain must match exactly: a verified example.com does not cover
	 * news.example.com.
	 *
	 * @return array{ready:bool,reason:string,domain:array|null,error:string}
	 *               reason: no_key, error, no_domain, not_ready or ready.
	 */
	public static function sender_status( $email ) {
		$out = array(
			'ready'  => false,
			'reason' => 'no_key',
			'domain' => null,
			'error'  => '',
		);

		if ( '' === self::key() ) {
			return $out;
		}

		$domains = self::domains();

		if ( is_wp_error( $domains ) ) {
			$out['reason'] = 'error';
			$out['error']  = $domains->get_error_message();
			return $out;
		}

		$name = LetterWP_Addresses::domain_of( $email );

		foreach ( $domains as $domain ) {
			if ( isset( $domain['name'] ) && strtolower( $domain['name'] ) === $name ) {
				$out['domain'] = $domain;
				$out['ready']  = self::is_ready( $domain );
				$out['reason'] = $out['ready'] ? 'ready' : 'not_ready';
				return $out;
			}
		}

		$out['reason'] = 'no_domain';

		return $out;
	}

	/** @return array|WP_Error One domain, with its DNS records. */
	public static function domain( $id ) {
		return self::request( 'GET', '/domains/' . rawurlencode( $id ) );
	}

	/** @return array|WP_Error The new domain, with the DNS records to add. */
	public static function add_domain( $name ) {
		return self::request( 'POST', '/domains', array( 'name' => $name ) );
	}

	/** Ask Resend to look at the DNS again. The answer arrives later, not here. */
	public static function verify_domain( $id ) {
		return self::request( 'POST', '/domains/' . rawurlencode( $id ) . '/verify' );
	}

	/**
	 * Ready to send = verified, and sending not switched off. capabilities is
	 * absent on older responses; absent means enabled.
	 *
	 * @return bool
	 */
	public static function is_ready( array $domain ) {
		$sending = isset( $domain['capabilities']['sending'] ) ? $domain['capabilities']['sending'] : 'enabled';

		return isset( $domain['status'] ) && 'verified' === $domain['status'] && 'enabled' === $sending;
	}

	/** @return string Resend's status word, said plainly. */
	public static function status_label( array $domain ) {
		if ( self::is_ready( $domain ) ) {
			return __( 'Ready to send', 'letterwp' );
		}

		$status = isset( $domain['status'] ) ? (string) $domain['status'] : '';

		switch ( $status ) {
			case 'verified':
				return __( 'Verified, but sending is off in Resend', 'letterwp' );
			case 'not_started':
				return __( 'Not ready: add the DNS records', 'letterwp' );
			case 'pending':
				return __( 'Not ready: waiting for DNS', 'letterwp' );
			case 'failed':
				return __( 'Not ready: DNS check failed', 'letterwp' );
			case 'temporary_failure':
				return __( 'Not ready: DNS check failed for now, Resend will retry', 'letterwp' );
		}

		/* translators: %s: Resend's status word. */
		return sprintf( __( 'Not ready: %s', 'letterwp' ), str_replace( '_', ' ', $status ) );
	}

	/** The DMARC value LetterWP suggests: says the domain checks its mail, never blocks any. */
	const DMARC_SUGGESTED = 'v=DMARC1; p=none;';

	/**
	 * The domain's DMARC record, read from public DNS, not from Resend: Resend
	 * verifies a domain on SPF + DKIM only, so "Ready to send" says nothing
	 * about it. Gmail and Yahoo want one, and require it above 5,000 a day.
	 *
	 * Looked up on the domain, then on each parent (a root domain's record
	 * covers its subdomains), as DMARC's own tree walk does. The suggested
	 * name follows Resend's own record names ("resend._domainkey.news" on
	 * news.example.com → "_dmarc.news"), when the records are there.
	 *
	 * @param array $domain A domain from domains(), or with its records from domain().
	 * @return array{found:bool,host:string,value:string,name:string}
	 *               host/value: where it was found and what it says.
	 *               name: what to type at the DNS host, the way Resend names its records.
	 */
	public static function dmarc( array $domain ) {
		$host = strtolower( isset( $domain['name'] ) ? (string) $domain['name'] : '' );
		$sub  = '';

		foreach ( isset( $domain['records'] ) && is_array( $domain['records'] ) ? $domain['records'] : array() as $record ) {
			$name = isset( $record['name'] ) ? strtolower( (string) $record['name'] ) : '';

			if ( 0 === strpos( $name, 'resend._domainkey' ) ) {
				$sub = ltrim( substr( $name, strlen( 'resend._domainkey' ) ), '.' );
				break;
			}
		}

		$hosts  = array();
		$labels = explode( '.', $host );

		while ( count( $labels ) >= 2 ) {
			$hosts[] = implode( '.', $labels );
			array_shift( $labels );
		}

		$out = array(
			'found' => false,
			'host'  => '',
			'value' => '',
			'name'  => '_dmarc' . ( '' !== $sub ? '.' . $sub : '' ),
		);

		foreach ( $hosts as $lookup ) {
			$records = @dns_get_record( '_dmarc.' . $lookup, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing name warns.

			foreach ( is_array( $records ) ? $records : array() as $record ) {
				$value = isset( $record['entries'] ) ? implode( '', (array) $record['entries'] ) : ( isset( $record['txt'] ) ? (string) $record['txt'] : '' );

				if ( 0 === stripos( trim( $value ), 'v=DMARC1' ) ) {
					$out['found'] = true;
					$out['host']  = $lookup;
					$out['value'] = trim( $value );
					return $out;
				}
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Segments and topics. LetterWP assumes one Resend account per site, so
	 * the segment and topics it created are its own to change and delete.
	 * ------------------------------------------------------------------ */

	/** @return array[]|WP_Error Every segment in the account. */
	public static function segments() {
		return self::list_of( '/segments' );
	}

	/** @return array|WP_Error {id}. */
	public static function create_segment( $name ) {
		return self::request( 'POST', '/segments', array( 'name' => $name ) );
	}

	/** @return array[]|WP_Error Every topic in the account. */
	public static function topics() {
		return self::list_of( '/topics' );
	}

	/**
	 * @param array $fields name, description, default_subscription, visibility.
	 * @return array|WP_Error {id}.
	 */
	public static function create_topic( array $fields ) {
		return self::request( 'POST', '/topics', $fields );
	}

	/** @return array|WP_Error */
	public static function update_topic( $id, array $fields ) {
		return self::request( 'PATCH', '/topics/' . rawurlencode( $id ), $fields );
	}

	/** @return array|WP_Error */
	public static function delete_topic( $id ) {
		return self::request( 'DELETE', '/topics/' . rawurlencode( $id ) );
	}

	/** @return array[]|WP_Error Every custom contact property in the account. */
	public static function contact_properties() {
		return self::list_of( '/contact-properties' );
	}

	/**
	 * @param array $fields key, type (string or number), fallback_value.
	 * @return array|WP_Error {id}.
	 */
	public static function create_contact_property( array $fields ) {
		return self::request( 'POST', '/contact-properties', $fields );
	}

	/* ---------------------------------------------------------------------
	 * Contacts. Referenced by email in paths: email is the key, Resend's
	 * contact ids are never stored.
	 * ------------------------------------------------------------------ */

	/**
	 * Every contact, following Resend's cursor 100 at a time.
	 *
	 * @param string $segment_id Only this segment's contacts, or '' for all.
	 * @return array[]|WP_Error
	 */
	public static function contacts( $segment_id = '' ) {
		$path  = '' === $segment_id ? '/contacts' : '/segments/' . rawurlencode( $segment_id ) . '/contacts';
		$all   = array();
		$after = '';

		do {
			$result = self::request( 'GET', $path . '?limit=100' . ( '' === $after ? '' : '&after=' . rawurlencode( $after ) ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$page  = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
			$all   = array_merge( $all, $page );
			$last  = end( $page );
			$after = $last && ! empty( $last['id'] ) ? (string) $last['id'] : '';
		} while ( ! empty( $result['has_more'] ) && '' !== $after );

		return $all;
	}

	/** @return array|null|WP_Error One contact, or null when Resend has no such contact. */
	public static function contact( $email ) {
		$result = self::request( 'GET', '/contacts/' . rawurlencode( $email ) );

		if ( is_wp_error( $result ) && 'letterwp_resend_http_404' === $result->get_error_code() ) {
			return null;
		}

		return $result;
	}

	/**
	 * @param array $fields email, first_name, last_name, unsubscribed, segments ([{id}]),
	 *                      properties ({key: value}; each key must exist at Resend first).
	 * @return array|WP_Error
	 */
	public static function create_contact( array $fields ) {
		return self::request( 'POST', '/contacts', $fields );
	}

	/** @return array|WP_Error */
	public static function update_contact( $email, array $fields ) {
		return self::request( 'PATCH', '/contacts/' . rawurlencode( $email ), $fields );
	}

	/**
	 * Set a contact's topics. Every topic is listed, so the result does not
	 * depend on what Resend does with unlisted ones.
	 *
	 * @param array<string,bool> $topics topic id => gets it.
	 * @return array|WP_Error
	 */
	public static function set_contact_topics( $email, array $topics ) {
		$body = array();

		foreach ( $topics as $id => $on ) {
			$body[] = array(
				'id'           => $id,
				'subscription' => $on ? 'opt_in' : 'opt_out',
			);
		}

		return self::request( 'PATCH', '/contacts/' . rawurlencode( $email ) . '/topics', $body );
	}

	/**
	 * A contact's topics, as Resend has them now. Resend lists every topic,
	 * with the default filled in for the ones never set.
	 *
	 * @return array<string,bool>|null|WP_Error topic id => gets it; null when Resend has no such contact.
	 */
	public static function contact_topics( $email ) {
		$result = self::request( 'GET', '/contacts/' . rawurlencode( $email ) . '/topics?limit=100' );

		if ( is_wp_error( $result ) ) {
			return 'letterwp_resend_http_404' === $result->get_error_code() ? null : $result;
		}

		$topics = array();
		foreach ( isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array() as $topic ) {
			if ( ! empty( $topic['id'] ) ) {
				$topics[ (string) $topic['id'] ] = isset( $topic['subscription'] ) && 'opt_out' !== $topic['subscription'];
			}
		}

		return $topics;
	}

	/** @return array|WP_Error */
	public static function delete_contact( $email ) {
		return self::request( 'DELETE', '/contacts/' . rawurlencode( $email ) );
	}

	/** @return array|WP_Error */
	public static function add_to_segment( $email, $segment_id ) {
		return self::request( 'POST', '/contacts/' . rawurlencode( $email ) . '/segments/' . rawurlencode( $segment_id ) );
	}

	/** @return array|WP_Error */
	public static function remove_from_segment( $email, $segment_id ) {
		return self::request( 'DELETE', '/contacts/' . rawurlencode( $email ) . '/segments/' . rawurlencode( $segment_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Webhooks: how bounces, complaints and unsubscribes come back.
	 * ------------------------------------------------------------------ */

	/** @return array[]|WP_Error Every webhook: id, endpoint, events, status. No secrets. */
	public static function webhooks() {
		return self::list_of( '/webhooks' );
	}

	/** @return array|WP_Error One webhook, with its signing_secret. */
	public static function webhook( $id ) {
		return self::request( 'GET', '/webhooks/' . rawurlencode( $id ) );
	}

	/** @return array|WP_Error {id, signing_secret}. */
	public static function create_webhook( $endpoint, array $events ) {
		return self::request(
			'POST',
			'/webhooks',
			array(
				'endpoint' => $endpoint,
				'events'   => $events,
			)
		);
	}

	/**
	 * @param array $fields endpoint, events, status (enabled or disabled).
	 * @return array|WP_Error
	 */
	public static function update_webhook( $id, array $fields ) {
		return self::request( 'PATCH', '/webhooks/' . rawurlencode( $id ), $fields );
	}

	/** @return array[]|WP_Error The data array of a list endpoint. */
	private static function list_of( $path ) {
		$result = self::request( 'GET', $path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	}

	/* ---------------------------------------------------------------------
	 * Sending.
	 * ------------------------------------------------------------------ */

	/**
	 * Send one email. The sender's name is the site's name.
	 *
	 * @param array $email from (address), to, subject, html, text.
	 * @return string|WP_Error Resend's id for the email.
	 */
	public static function send_email( array $email ) {
		$result = self::request(
			'POST',
			'/emails',
			array(
				'from'    => self::from_header( $email['from'] ),
				'to'      => array( $email['to'] ),
				'subject' => $email['subject'],
				'html'    => $email['html'],
				'text'    => $email['text'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['id'] ) ? (string) $result['id'] : '';
	}

	/**
	 * Create a Broadcast and send it at once, in one call: there is no draft
	 * left behind at Resend if anything fails.
	 *
	 * Longer timeout than other calls: the body is a whole post. A timeout
	 * does not mean nothing went out, so callers must not retry blindly.
	 *
	 * @param array $broadcast from (address), segment_id, topic_id, subject, html, text, name.
	 * @return string|WP_Error Resend's id for the Broadcast.
	 */
	public static function send_broadcast( array $broadcast ) {
		$body = array(
			'from'       => self::from_header( $broadcast['from'] ),
			'segment_id' => $broadcast['segment_id'],
			'subject'    => $broadcast['subject'],
			'html'       => $broadcast['html'],
			'text'       => $broadcast['text'],
			'name'       => $broadcast['name'],
			'send'       => true,
		);

		if ( ! empty( $broadcast['topic_id'] ) ) {
			$body['topic_id'] = $broadcast['topic_id'];
		}

		$result = self::request( 'POST', '/broadcasts', $body, null, 45 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['id'] ) ? (string) $result['id'] : '';
	}

	/** @return string One Broadcast in Resend's dashboard. */
	public static function broadcast_url( $id ) {
		return 'https://resend.com/broadcasts/' . rawurlencode( $id );
	}

	/**
	 * "Site Name <address>", with the characters that would break the header
	 * taken out of the name.
	 *
	 * @return string
	 */
	public static function from_header( $address ) {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name = trim( str_replace( array( '"', '<', '>', "\r", "\n" ), '', $name ) );

		return '' === $name ? $address : '"' . $name . '" <' . $address . '>';
	}

	/** @return string One sent email in Resend's dashboard. */
	public static function email_url( $id ) {
		return 'https://resend.com/emails/' . rawurlencode( $id );
	}

	/** @return string Where the domain is deleted: Resend's dashboard. */
	public static function delete_url( $id ) {
		return 'https://resend.com/domains/' . rawurlencode( $id );
	}

	/**
	 * Turn what people paste ("https://www.Example.com/", "me@example.com")
	 * into a bare domain name, or '' if nothing domain-like is left.
	 *
	 * @return string
	 */
	public static function clean_domain( $input ) {
		$name = strtolower( trim( (string) $input ) );
		$name = preg_replace( '#^[a-z]+://#', '', $name );
		$name = preg_replace( '#[/?\#].*$#', '', $name );

		if ( false !== strpos( $name, '@' ) ) {
			$name = substr( $name, strrpos( $name, '@' ) + 1 );
		}

		$name = rtrim( $name, '.' );

		if ( 0 === strpos( $name, 'www.' ) ) {
			$name = substr( $name, 4 );
		}

		return preg_match( '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $name ) ? $name : '';
	}

	/**
	 * Resend's own error sentence, or the raw body (a Cloudflare block is not
	 * JSON), trimmed.
	 *
	 * @return string
	 */
	private static function error_message( $data, $raw, $code ) {
		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			return (string) $data['message'];
		}

		$raw = trim( wp_strip_all_tags( $raw ) );

		if ( '' !== $raw ) {
			return mb_substr( $raw, 0, 300 );
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Resend answered HTTP %d with no explanation.', 'letterwp' ), $code );
	}

	/**
	 * First four and last four: enough to recognise a key, not to use it.
	 *
	 * @return string
	 */
	public static function mask( $value ) {
		$value = (string) $value;
		$len   = strlen( $value );

		if ( $len < 12 ) {
			return str_repeat( '•', $len );
		}

		return substr( $value, 0, 4 ) . str_repeat( '•', 8 ) . substr( $value, -4 );
	}

	/** @return string This site's own domain, the suggestion for a first domain. */
	public static function site_domain() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
