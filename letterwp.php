<?php
/**
 * Plugin Name:       LetterWP
 * Plugin URI:        https://letterwp.com
 * Description:       Publish a post and it goes out as a beautiful email, through your own Resend account. Your list stays in your own WordPress.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Owldraft
 * Author URI:        https://owldraft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       letterwp
 * Update URI:        https://letterwp.com/update/letterwp
 */

defined( 'ABSPATH' ) || exit;

define( 'LETTERWP_VERSION', '1.0.0' );
define( 'LETTERWP_FILE', __FILE__ );
define( 'LETTERWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LETTERWP_URL', plugin_dir_url( __FILE__ ) );

require_once LETTERWP_DIR . 'inc/class-letterwp-resend.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-addresses.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-setup.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-sync.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-admin.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-subscribers.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-subscribers-admin.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-import.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-import-admin.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-login.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-subscribe.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-email-design.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-email.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-design-admin.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-campaigns.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-editor.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-send.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-webhook.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-stats.php';
require_once LETTERWP_DIR . 'inc/class-letterwp-stats-admin.php';
require_once LETTERWP_DIR . 'inc/class-letterwp.php';

// Only in the copy from letterwp.com: the WordPress.org build leaves it out.
if ( is_readable( LETTERWP_DIR . 'inc/class-letterwp-updater.php' ) ) {
	require_once LETTERWP_DIR . 'inc/class-letterwp-updater.php';
	new LetterWP_Updater();
}

LetterWP::instance();
