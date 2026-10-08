<?php
/**
 * Plugin Name:       Protect Uploads
 * Plugin URI:        https://protectuploads.com
 * Description:       Stop visitors from browsing your uploads directory, password-protect individual media files, add text watermarks to uploaded images, and discourage right-click copying.
 * Version:           0.8.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            alticreation
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       protect-uploads
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

function protect_uploads_activate() {

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-protect-uploads.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-protect-uploads-activator.php';
	$activation = new Alti_ProtectUploads_Activator();
	$activation->run();

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-protect-uploads-password-rules.php';
	Alti_ProtectUploads_Password_Rules::sync();

}

function protect_uploads_deactivate() {

	require_once plugin_dir_path( __FILE__ ) . 'admin/class-protect-uploads-admin.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-protect-uploads-deactivator.php';
	$deactivation = new Alti_ProtectUploads_Deactivator();
	$deactivation->run();

}

register_activation_hook( __FILE__, 'protect_uploads_activate' );
register_deactivation_hook( __FILE__, 'protect_uploads_deactivate' );

require plugin_dir_path( __FILE__ ) . 'includes/class-protect-uploads.php';

$protect_uploads_plugin = new Alti_ProtectUploads();
$protect_uploads_plugin->run();
