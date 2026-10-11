<?php
/**
 * Main plugin class.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP {

	/** @var LetterWP|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		LetterWP_Subscribers::maybe_install();
		LetterWP_Stats::maybe_install();

		new LetterWP_Login();
		new LetterWP_Subscribe();
		new LetterWP_Setup();
		new LetterWP_Sync();
		new LetterWP_Editor();
		new LetterWP_Send();
		new LetterWP_Webhook();
		new LetterWP_Stats();

		if ( is_admin() ) {
			new LetterWP_Admin();
			new LetterWP_Design_Admin();
			new LetterWP_Subscribers_Admin();
			new LetterWP_Import_Admin();
			new LetterWP_Stats_Admin();
		}
	}
}
