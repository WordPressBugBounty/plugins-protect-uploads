<?php
/**
 * Fired when the plugin is deleted.
 *
 * Removes the plugin's settings, its password and access-log tables, and the
 * index.php files and .htaccess rules it wrote. Untouched copies of
 * watermarked images in uploads/protect-uploads-originals/ are kept.
 *
 * @package    Protect_Uploads
 * @since      0.1
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-protect-uploads-password-rules.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-protect-uploads-upsell.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/class-protect-uploads-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-protect-uploads-uninstaller.php';

Alti_ProtectUploads_Uninstaller::run();
