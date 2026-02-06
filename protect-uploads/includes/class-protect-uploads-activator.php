<?php
/**
 * Fired during plugin activation
 *
 * @link       https://example.com
 * @since      0.5.2
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      0.5.2
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 * @author     Your Name <email@example.com>
 */
class Alti_ProtectUploads_Activator {

	/**
	 * Create necessary database tables and initialize plugin.
	 *
	 * @since    0.5.2
	 */
	public function run() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		// Table for storing passwords.
		$table_passwords = $wpdb->prefix . 'protect_uploads_passwords';
		$sql_passwords = "CREATE TABLE IF NOT EXISTS $table_passwords (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) NOT NULL,
			password_hash varchar(255) NOT NULL,
			password_label varchar(100) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by bigint(20) NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id)
		) $charset_collate;";

		// Table for access logs.
		$table_logs = $wpdb->prefix . 'protect_uploads_access_logs';
		$sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) NOT NULL,
			password_id bigint(20) NOT NULL,
			access_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address varchar(45) NOT NULL,
			user_agent varchar(255) NOT NULL,
			access_type varchar(20) NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY password_id (password_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_passwords );
		dbDelta( $sql_logs );

		// Add default options.
		$default_settings = array(
			'enable_watermark' => false,
			'watermark_text' => get_bloginfo( 'name' ),
			'watermark_position' => 'bottom-right',
			'watermark_opacity' => 50,
			'enable_right_click_protection' => false,
			'enable_password_protection' => false,
		);

		if ( false === get_option( 'protect_uploads_settings' ) ) {
			add_option( 'protect_uploads_settings', $default_settings );
		}
	}
}
