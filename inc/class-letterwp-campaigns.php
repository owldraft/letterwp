<?php
/**
 * Which post types send email. In LetterWP that is posts.
 *
 * A campaign is an email that is not a post: a launch, an event, an offer.
 * LetterWP does not register one. The name is kept here so an add-on that
 * registers the letterwp_campaign post type gets the rest for free: the
 * editor panel, Send test, Preview, the send lock and the results page all
 * ask this class, and a campaign goes out under the Announcements topic
 * (LetterWP_Setup::ANNOUNCE), never a category.
 *
 *   Not registered = types() is just posts, and is() is always false.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Campaigns {

	const TYPE = 'letterwp_campaign';

	/** @return string[] The post types that publishing emails. */
	public static function types() {
		$types = array( 'post' );

		if ( post_type_exists( self::TYPE ) ) {
			$types[] = self::TYPE;
		}

		return $types;
	}

	/** @return bool The post is a campaign. */
	public static function is( $post ) {
		$post = get_post( $post );

		return $post && self::TYPE === $post->post_type;
	}
}
