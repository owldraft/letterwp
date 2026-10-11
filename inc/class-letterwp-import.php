<?php
/**
 * Reading a subscriber CSV from anywhere: Substack, beehiiv, Ghost,
 * Mailchimp, Kit, LetterWP's own export, or a plain list of emails.
 *
 * There is no "import from Substack" switch: the columns are recognised by
 * their names. Only an email column is required. Names, the date someone
 * subscribed and whether they still get emails come along when the file
 * has them.
 *
 * Nothing here writes: read() turns a file into rows for the review step,
 * and LetterWP_Subscribers::import() saves them.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Import {

	/**
	 * Column names we know, after lowercasing and dropping everything but
	 * letters and digits ("Email Address" → emailaddress). First match wins.
	 */
	const COLUMNS = array(
		'email'      => array( 'email', 'emailaddress', 'mail', 'subscriberemail', 'contactemail' ),
		'first_name' => array( 'firstname', 'first', 'givenname', 'fname' ),
		'last_name'  => array( 'lastname', 'last', 'surname', 'familyname', 'lname' ),
		'name'       => array( 'name', 'fullname', 'subscribername', 'displayname' ),
		'date'       => array( 'startdate', 'createdat', 'created', 'subscribedat', 'signupdate', 'subscriptiondate', 'dateadded', 'datesubscribed', 'joined', 'optintime' ),
		'status'     => array( 'status', 'subscriptionstatus', 'subscriberstatus' ),
		'email_off'  => array( 'emaildisabled' ),        // Substack's full export.
		'email_on'   => array( 'subscribedtoemails' ),   // Ghost.
		'topics_off' => array( 'topicsoff' ),            // Ours.
	);

	/** Status values that mean "no more emails", and "never said yes". Anything else is active. */
	const UNSUBSCRIBED = array( 'unsubscribed', 'unsubscribe', 'inactive', 'cancelled', 'canceled', 'optedout', 'optout', 'complained', 'spam' );
	const BOUNCED      = array( 'bounced', 'bounce', 'cleaned', 'hardbounce', 'invalid', 'undeliverable' );
	const UNCONFIRMED  = array( 'pending', 'unconfirmed', 'notconfirmed', 'awaitingconfirmation', 'unverified' );

	/** @return string "Email Address" → emailaddress. */
	private static function key( $header ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $header ) );
	}

	/**
	 * @param string $path The uploaded file.
	 * @return array|WP_Error {
	 *     columns:     array<string,string> field => the file's column name
	 *     rows:        array[] [email, first, last, status, created (GMT), topics_off]
	 *     invalid:     int, and invalid_samples: string[] (the first few)
	 *     duplicates:  int  the same email again further down the file
	 *     unconfirmed: int  pending at the old tool: left out, they never said yes
	 *     looks_like:  string 'Substack', or ''
	 * }
	 */
	public static function read( $path ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- a local upload, read line by line.
		$handle = fopen( $path, 'r' );

		if ( ! $handle ) {
			return new WP_Error( 'letterwp_import_unreadable', __( 'The file could not be read. Try uploading it again.', 'letterwp' ) );
		}

		$line = (string) fgets( $handle );
		rewind( $handle );

		// Excel in much of Europe saves with semicolons; some tools use tabs.
		$counts = array(
			','  => substr_count( $line, ',' ),
			';'  => substr_count( $line, ';' ),
			"\t" => substr_count( $line, "\t" ),
		);
		arsort( $counts );
		$delim = key( $counts );

		$header = fgetcsv( $handle, 0, $delim, '"', '' );

		if ( ! $header || array( null ) === $header ) {
			fclose( $handle );
			return new WP_Error( 'letterwp_import_empty', __( 'That file is empty.', 'letterwp' ) );
		}

		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] ); // Byte order mark.

		$map     = array();
		$columns = array();
		foreach ( $header as $i => $name ) {
			foreach ( self::COLUMNS as $field => $names ) {
				if ( ! isset( $map[ $field ] ) && in_array( self::key( $name ), $names, true ) ) {
					$map[ $field ]     = $i;
					$columns[ $field ] = trim( $name );
				}
			}
		}

		// No header row, just addresses: the first row is data.
		$first = null;
		if ( ! isset( $map['email'] ) ) {
			foreach ( $header as $i => $cell ) {
				if ( '' !== LetterWP_Subscribers::clean_email( $cell ) ) {
					$map     = array( 'email' => $i );
					$columns = array( 'email' => __( '(no header row)', 'letterwp' ) );
					$first   = $header;
					break;
				}
			}
		}

		if ( ! isset( $map['email'] ) ) {
			fclose( $handle );
			return new WP_Error( 'letterwp_import_no_email', __( 'No email column found. The first row should name the columns, one of them "Email".', 'letterwp' ) );
		}

		$out = array(
			'columns'         => $columns,
			'rows'            => array(),
			'invalid'         => 0,
			'invalid_samples' => array(),
			'duplicates'      => 0,
			'unconfirmed'     => 0,
			'looks_like'      => self::looks_like( array_map( array( __CLASS__, 'key' ), $header ) ),
		);
		$seen = array();
		$now  = time();

		while ( null !== $first || false !== ( $first = fgetcsv( $handle, 0, $delim, '"', '' ) ) ) {
			$cells = $first;
			$first = null;

			if ( array( null ) === $cells ) {
				continue; // A blank line.
			}

			$cell = function ( $field ) use ( $cells, $map ) {
				return isset( $map[ $field ], $cells[ $map[ $field ] ] ) ? trim( (string) $cells[ $map[ $field ] ] ) : '';
			};

			$email = LetterWP_Subscribers::clean_email( $cell( 'email' ) );

			if ( '' === $email ) {
				++$out['invalid'];
				if ( count( $out['invalid_samples'] ) < 5 && '' !== $cell( 'email' ) ) {
					$out['invalid_samples'][] = mb_substr( $cell( 'email' ), 0, 60 );
				}
				continue;
			}

			if ( isset( $seen[ $email ] ) ) {
				++$out['duplicates'];
				continue;
			}
			$seen[ $email ] = true;

			$status = self::status( $cell );

			if ( null === $status ) {
				++$out['unconfirmed'];
				continue;
			}

			$first_name = $cell( 'first_name' );
			$last_name  = $cell( 'last_name' );

			if ( '' === $first_name && '' === $last_name && '' !== $cell( 'name' ) ) {
				// "Ann Lee" → Ann + Lee: the first name is what an email says hello to.
				$parts      = preg_split( '/\s+/u', $cell( 'name' ), 2 );
				$first_name = $parts[0];
				$last_name  = isset( $parts[1] ) ? $parts[1] : '';
			}

			$out['rows'][] = array(
				$email,
				LetterWP_Subscribers::clean_name( $first_name ),
				LetterWP_Subscribers::clean_name( $last_name ),
				$status,
				gmdate( 'Y-m-d H:i:s', self::date( $cell( 'date' ), $now ) ),
				$cell( 'topics_off' ),
			);
		}

		fclose( $handle );
		// phpcs:enable

		return $out;
	}

	/**
	 * active, unsubscribed, bounced, or null for someone who never confirmed.
	 *
	 * @param callable $cell field => value.
	 * @return string|null
	 */
	private static function status( $cell ) {
		$yes = array( 'true', 'yes', 'y', '1', 't' );

		if ( in_array( strtolower( $cell( 'email_off' ) ), $yes, true ) ) {
			return 'unsubscribed';
		}

		if ( '' !== $cell( 'email_on' ) && ! in_array( strtolower( $cell( 'email_on' ) ), $yes, true ) ) {
			return 'unsubscribed';
		}

		$status = self::key( $cell( 'status' ) );

		if ( in_array( $status, self::UNSUBSCRIBED, true ) ) {
			return 'unsubscribed';
		}

		if ( in_array( $status, self::BOUNCED, true ) ) {
			return 'bounced';
		}

		return in_array( $status, self::UNCONFIRMED, true ) ? null : 'active';
	}

	/** @return int A timestamp; now when the value is missing, unreadable or in the future. */
	private static function date( $value, $now ) {
		if ( '' === $value ) {
			return $now;
		}

		$time = ctype_digit( $value ) && strlen( $value ) >= 9 ? (int) substr( $value, 0, 10 ) : strtotime( $value );

		return $time && $time > 0 && $time <= $now ? $time : $now;
	}

	/** @return string Where the file seems to come from, for the review step. */
	private static function looks_like( array $keys ) {
		if ( in_array( 'subscriptionsourcefree', $keys, true ) || ( in_array( 'activesubscription', $keys, true ) && in_array( 'emaildisabled', $keys, true ) ) ) {
			return 'Substack';
		}

		return '';
	}
}
