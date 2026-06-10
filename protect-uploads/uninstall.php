<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * This file may be updated more in future version of the Boilerplate; however, this is the
 * general skeleton and outline for how the file should work.
 *
 * For more information, see the following discussion:
 * https://github.com/tommcfarlin/WordPress-Plugin-Boilerplate/pull/123#issuecomment-28541913
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Plugin_Name
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

class Alti_ProtectUploads_Uninstall {

	public static function run() {
		$plugin_name = 'protect-uploads';
		if( is_admin()) delete_option( $plugin_name . '-protection' );

		// Upgrade-hint dismissal flags (added in 0.7.0).
		delete_option( 'protect_uploads_upsell_notice_dismissed' );
		delete_metadata( 'user', 0, 'protect_uploads_upsell_banner_dismissed', '', true );
	}

	

}

Alti_ProtectUploads_Uninstall::run();