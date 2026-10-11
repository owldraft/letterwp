<?php
/**
 * Updates from letterwp.com, for the copy downloaded there.
 *
 * The plugin header's Update URI (https://letterwp.com/update/letterwp)
 * makes WordPress call the update_plugins_letterwp.com filter during its
 * normal 12-hour check, and never ask WordPress.org about this slug. The
 * answer comes from letterwp.com/update/letterwp, which names the current
 * version and its zip: Dashboard → Updates then works as for any plugin.
 *
 * Once LetterWP is on WordPress.org, letterwp.com answers with the
 * WordPress.org zip, which has no Update URI and no copy of this file, so
 * the next update moves the site over for good.
 *
 * The WordPress.org build leaves this file out (see bin/build.sh).
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Updater {

	const URL = 'https://letterwp.com/update/letterwp';

	const CACHE = 'letterwp_update';

	public function __construct() {
		add_filter( 'update_plugins_letterwp.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 0 );
	}

	/** @return array|null What letterwp.com says is current, cached for 12 hours. */
	private static function latest( $fresh = false ) {
		$cached = get_site_transient( self::CACHE );

		if ( ! $fresh && is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			add_query_arg( 'version', LETTERWP_VERSION, self::URL ),
			array( 'timeout' => 10 )
		);

		$data = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$data = is_array( $body ) && ! empty( $body['version'] ) && ! empty( $body['package'] ) ? $body : null;
		}

		// A failure is cached too, for an hour, so a slow letterwp.com never slows wp-admin.
		set_site_transient( self::CACHE, $data ? $data : array(), $data ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * @param array|false $update      What another filter found, or false.
	 * @param array       $plugin_data This plugin's headers.
	 * @param string      $file        This plugin's basename.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $file ) {
		if ( plugin_basename( LETTERWP_FILE ) !== $file ) {
			return $update;
		}

		// WordPress runs this filter only when it really checks (every 12 hours,
		// or "Check again"), so it always asks afresh.
		$data = self::latest( true );

		if ( ! $data ) {
			return $update;
		}

		return array(
			'slug'         => 'letterwp',
			'version'      => (string) $data['version'],
			'url'          => isset( $data['url'] ) ? (string) $data['url'] : 'https://letterwp.com',
			'package'      => (string) $data['package'],
			'tested'       => isset( $data['tested'] ) ? (string) $data['tested'] : '',
			'requires_php' => isset( $data['requires_php'] ) ? (string) $data['requires_php'] : '',
			'icons'        => array( 'default' => LETTERWP_URL . 'assets/letterwp-icon.png' ),
		);
	}

	/** "View details" on the Plugins screen: answered here, not by WordPress.org. */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'letterwp' !== $args->slug ) {
			return $result;
		}

		$data = self::latest();

		if ( ! $data ) {
			return $result;
		}

		return (object) array(
			'name'          => 'LetterWP',
			'slug'          => 'letterwp',
			'version'       => (string) $data['version'],
			'author'        => '<a href="https://owldraft.com">Owldraft</a>',
			'homepage'      => 'https://letterwp.com',
			'requires'      => isset( $data['requires'] ) ? (string) $data['requires'] : '',
			'requires_php'  => isset( $data['requires_php'] ) ? (string) $data['requires_php'] : '',
			'tested'        => isset( $data['tested'] ) ? (string) $data['tested'] : '',
			'download_link' => (string) $data['package'],
			'sections'      => array(
				'changelog' => isset( $data['changelog'] ) ? wp_kses_post( $data['changelog'] ) : '',
			),
		);
	}

	/** After an update, the next check asks again. */
	public function forget() {
		delete_site_transient( self::CACHE );
	}
}
