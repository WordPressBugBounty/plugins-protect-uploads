<?php
/**
 * Removes everything the plugin stored, when it is deleted.
 *
 * Kept apart from uninstall.php so the list of what goes can be checked
 * without uninstalling.
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 * @since      0.8.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Uninstall routine.
 *
 * Untouched copies of watermarked images (uploads/protect-uploads-originals/)
 * and the attachment meta pointing to them are kept: they are the site
 * owner's only copy of the original photos.
 *
 * @since 0.8.0
 */
class Alti_ProtectUploads_Uninstaller {

	/**
	 * What uninstalling removes on the current site.
	 *
	 * @since  0.8.0
	 * @return array {
	 *     @type string[] $options            Option names.
	 *     @type string[] $transients         Transient names.
	 *     @type string[] $transient_prefixes Transient name prefixes (per-user transients).
	 *     @type string[] $user_meta          User meta keys (network-wide).
	 *     @type string[] $tables             Database tables.
	 * }
	 */
	public static function plan() {
		global $wpdb;

		return array(
			'options'            => array(
				'protect_uploads_settings',
				// Legacy option from before 0.3, also deleted on deactivation.
				'protect-uploads-protection',
				Alti_ProtectUploads_Password_Rules::OPTION_SYNCED,
				Alti_ProtectUploads_Upsell::NOTICE_DISMISSED_OPTION,
			),
			'transients'         => array(
				Alti_ProtectUploads_Password_Rules::TRANSIENT_FAILED,
				Alti_ProtectUploads_Admin::ROOT_STATUS_TRANSIENT,
			),
			'transient_prefixes' => array(
				Alti_ProtectUploads_Admin::MESSAGES_TRANSIENT,
				'protect_uploads_rl_',
			),
			'user_meta'          => array(
				Alti_ProtectUploads_Upsell::BANNER_DISMISSED_META,
			),
			'tables'             => array(
				$wpdb->prefix . 'protect_uploads_passwords',
				$wpdb->prefix . 'protect_uploads_access_logs',
			),
		);
	}

	/**
	 * Remove the plugin's data from every site.
	 *
	 * @since 0.8.0
	 */
	public static function run() {
		if ( is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::run_for_site();
				restore_current_blog();
			}
		} else {
			self::run_for_site();
		}

		// User meta is shared by every site of a network.
		$plan = self::plan();
		foreach ( $plan['user_meta'] as $meta_key ) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}
	}

	/**
	 * Remove the current site's files, options and tables.
	 *
	 * @since 0.8.0
	 */
	private static function run_for_site() {
		global $wpdb;

		$plan = self::plan();

		// The index.php files and .htaccess blocks the plugin wrote. Only its
		// own index.php files go: other plugins keep theirs in uploads too.
		$admin = new Alti_ProtectUploads_Admin( 'protect-uploads', '' );
		$admin->remove_index();
		$admin->remove_htaccess();
		Alti_ProtectUploads_Password_Rules::remove();

		foreach ( $plan['options'] as $option ) {
			delete_option( $option );
		}

		foreach ( $plan['transients'] as $transient ) {
			delete_transient( $transient );
		}

		foreach ( $plan['transient_prefixes'] as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of per-user transients.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $prefix ) . '%',
					$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
				)
			);
		}

		foreach ( $plan['tables'] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names built from $wpdb->prefix.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
