<?php
/**
 * The sender addresses: who email comes from.
 *
 * Kept apart from any sending tool on purpose. The addresses are the site
 * owner's choice; whether a tool can send from them right now (Resend today,
 * maybe another later) is a signal read live from that tool, never stored.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Addresses {

	const OPTION = 'letterwp_addresses';

	/**
	 * The shape of the store, with what each address is for. From names are
	 * not stored: the sender's name is the site's name.
	 *
	 * @return array<string,array{label:string,help:string,suggest:string}>
	 */
	public static function fields() {
		return array(
			'tx' => array(
				'label'   => __( 'Transactional email address', 'letterwp' ),
				'help'    => __( 'Confirm-your-subscription links and anything else sent to one reader at a time.', 'letterwp' ),
				'suggest' => 'noreply',
			),
			'nl' => array(
				'label'   => __( 'Newsletter email address', 'letterwp' ),
				'help'    => __( 'Every issue sent to your subscribers comes from this address. Replies go here too.', 'letterwp' ),
				'suggest' => 'newsletter',
			),
		);
	}

	/** @return array<string,string> 'tx' and 'nl', '' when not set. */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		foreach ( array_keys( self::fields() ) as $which ) {
			$out[ $which ] = isset( $stored[ $which ] ) ? (string) $stored[ $which ] : '';
		}

		return $out;
	}

	/** @return string One address, '' when not set. */
	public static function get( $which ) {
		$all = self::all();

		return isset( $all[ $which ] ) ? $all[ $which ] : '';
	}

	/** @param array<string,string> $addresses Already validated. */
	public static function update( array $addresses ) {
		update_option( self::OPTION, array_intersect_key( $addresses, self::fields() ), true );
	}

	/** @return string The part after the @, lowercased, or ''. */
	public static function domain_of( $email ) {
		$at = strrpos( (string) $email, '@' );

		return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
	}

	/** @return string e.g. newsletter@thissite.com, shown as a placeholder. */
	public static function suggestion( $which ) {
		$fields = self::fields();

		return $fields[ $which ]['suggest'] . '@' . LetterWP_Resend::site_domain();
	}
}
