<?php
/**
 * Plugin Name: QR Redirect Manager
 * Description: Map QR code parameters to WordPress pages. Update destinations anytime. Tracks scans with date/time. Generates QR images.
 * Version:     2.1.0
 * Author:      Your Church
 * License:     GPL2
 */

defined( 'ABSPATH' ) || exit;

define( 'QRM_VERSION', '2.1.0' );
define( 'QRM_PATH', plugin_dir_path( __FILE__ ) );
define( 'QRM_URL', plugin_dir_url( __FILE__ ) );

require_once QRM_PATH . 'includes/database.php';
require_once QRM_PATH . 'includes/redirect.php';
require_once QRM_PATH . 'includes/admin.php';

register_activation_hook( __FILE__, 'qrm_activate' );
register_deactivation_hook( __FILE__, 'qrm_deactivate' );

function qrm_activate() {
    qrm_create_tables();
    qrm_add_rewrite_rules();
    flush_rewrite_rules();
}

function qrm_deactivate() {
    flush_rewrite_rules();
}

// Also create tables on init if they don't exist yet (catches cases where
// activation hook didn't fire properly, e.g. plugin uploaded via FTP)
add_action( 'init', function() {
    if ( get_option( 'qrm_db_version' ) !== QRM_VERSION ) {
        qrm_create_tables();
        qrm_add_rewrite_rules();
        flush_rewrite_rules();
        update_option( 'qrm_db_version', QRM_VERSION );
    }
} );
