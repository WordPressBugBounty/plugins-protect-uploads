<?php
/**
 * Handles password protection functionality
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 */

/**
 * The password protection functionality of the plugin.
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 * @author     Your Name <email@example.com>
 */
class Alti_ProtectUploads_Passwords {

	/**
	 * The plugin settings
	 *
	 * @since    0.5.2
	 * @access   private
	 * @var      array    $settings    The plugin settings.
	 */
	private $settings;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    0.5.2
	 */
	public function __construct() {
		$this->settings = get_option( 'protect_uploads_settings', array() );
	}

	/**
	 * Initialize hooks
	 *
	 * @since    0.5.2
	 */
	public function init() {
		if ( ! empty( $this->settings['enable_password_protection'] ) ) {
			// Add meta box to media edit screen
			add_action( 'add_meta_boxes', array( $this, 'add_password_meta_box' ) );
			
			// Handle AJAX actions
			add_action( 'wp_ajax_protect_uploads_add_password', array( $this, 'ajax_add_password' ) );
			add_action( 'wp_ajax_protect_uploads_delete_password', array( $this, 'ajax_delete_password' ) );
			
			// Handle frontend URL modification
			add_filter( 'wp_get_attachment_url', array( $this, 'modify_attachment_url' ), 10, 2 );

			// New image sizes or a deleted file change which paths need rules.
			add_filter( 'wp_update_attachment_metadata', array( $this, 'sync_rules_for_changed_attachment' ), 10, 2 );
			add_action( 'delete_attachment', array( $this, 'sync_rules_for_changed_attachment' ), 20 );
		}
	}

	/**
	 * Re-sync the direct-URL rules when a protected attachment's files change.
	 *
	 * Used as a filter (wp_update_attachment_metadata) and as an action
	 * (delete_attachment), so it accepts either signature.
	 *
	 * @since  0.8.0
	 * @param  mixed $data_or_id    Attachment metadata, or the attachment ID.
	 * @param  int   $attachment_id Attachment ID when used as a filter.
	 * @return mixed The metadata, unchanged.
	 */
	public function sync_rules_for_changed_attachment( $data_or_id, $attachment_id = 0 ) {
		$attachment_id = $attachment_id ? $attachment_id : absint( $data_or_id );

		if ( $attachment_id && $this->has_passwords( $attachment_id ) ) {
			add_action( 'shutdown', array( 'Alti_ProtectUploads_Password_Rules', 'sync' ) );
		}

		return $data_or_id;
	}

	/**
	 * Add a password to an attachment
	 *
	 * @since    0.5.2
	 * @param    int    $attachment_id    Attachment ID.
	 * @param    string $password         Password.
	 * @param    string $label            Password label.
	 * @return   bool|int
	 */
	public function add_attachment_password( $attachment_id, $password, $label ) {
		global $wpdb;

		// Validate attachment exists
		if ( ! get_post( $attachment_id ) ) {
			return false;
		}

		// Validate input
		$attachment_id = absint( $attachment_id );
		$label = sanitize_text_field( $label );
		
		if ( empty( $password ) || empty( $label ) ) {
			return false;
		}

		// Use WordPress password hashing
		$hash = wp_hash_password( $password );

		// Prepare data with wpdb->prepare
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
		$result = $wpdb->insert(
			$wpdb->prefix . 'protect_uploads_passwords',
			array(
				'attachment_id'  => $attachment_id,
				'password_hash'  => $hash,
				'password_label' => $label,
				'created_by'     => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		// Invalidate caches for this attachment
		wp_cache_delete( 'protect_uploads_passwords_' . $attachment_id, 'protect_uploads' );
		wp_cache_delete( 'protect_uploads_has_passwords_' . $attachment_id, 'protect_uploads' );
		wp_cache_delete( 'protect_uploads_password_hashes_' . $attachment_id, 'protect_uploads' );

		$insert_id = $wpdb->insert_id;

		// Route the file's direct URL to the password prompt.
		Alti_ProtectUploads_Password_Rules::sync();

		return $insert_id;
	}

	/**
	 * Get passwords for an attachment
	 *
	 * @since    0.5.2
	 * @param    int $attachment_id    Attachment ID.
	 * @return   array
	 */
	public function get_attachment_passwords( $attachment_id ) {
		global $wpdb;

		$cache_key = 'protect_uploads_passwords_' . $attachment_id;
		$cached = wp_cache_get( $cache_key, 'protect_uploads' );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, password_label, created_at FROM {$wpdb->prefix}protect_uploads_passwords WHERE attachment_id = %d",
				$attachment_id
			)
		);

		wp_cache_set( $cache_key, $results, 'protect_uploads', HOUR_IN_SECONDS );

		return $results;
	}

	/**
	 * Modify attachment URL to go through our password check
	 *
	 * @since    0.5.2
	 * @param    string $url            Original URL.
	 * @param    int    $attachment_id  Attachment ID.
	 * @return   string
	 */
	public function modify_attachment_url( $url, $attachment_id ) {
		// People who can edit the file see its real URL in wp-admin and in the
		// editor's REST requests: WordPress builds thumbnail and srcset URLs by
		// swapping the file name in this URL, which breaks on the prompt URL.
		// The direct URL still goes through the prompt, which lets them in.
		if ( ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) && current_user_can( 'edit_post', $attachment_id ) ) {
			return $url;
		}

		if ( $this->has_passwords( $attachment_id ) ) {
			// No nonce: it tied the link to one visitor's session and broke it
			// after a day or when served from a page cache. The password is the
			// gate; anyone may load the prompt.
			return self::get_gate_url( $attachment_id );
		}
		return $url;
	}

	/**
	 * URL of the password prompt for an attachment.
	 *
	 * @since  0.8.0
	 * @param  int    $attachment_id Attachment ID.
	 * @param  string $relative_path Optional. File to serve after the password,
	 *                               relative to the uploads directory (a size
	 *                               or format copy). Defaults to the main file.
	 * @return string
	 */
	public static function get_gate_url( $attachment_id, $relative_path = '' ) {
		$args = array( 'protect_uploads_file' => absint( $attachment_id ) );
		if ( '' !== (string) $relative_path ) {
			$args['protect_uploads_path'] = rawurlencode( ltrim( (string) $relative_path, '/' ) );
		}

		return add_query_arg( $args, home_url( 'index.php' ) );
	}

	/**
	 * Check if attachment has passwords
	 *
	 * @since    0.5.2
	 * @param    int $attachment_id    Attachment ID.
	 * @return   bool
	 */
	public function has_passwords( $attachment_id ) {
		global $wpdb;

		// Cached as 1/0: a cached false would read back as a miss and query
		// again on every call for files without a password.
		$cache_key = 'protect_uploads_has_passwords_' . $attachment_id;
		$cached = wp_cache_get( $cache_key, 'protect_uploads' );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}protect_uploads_passwords WHERE attachment_id = %d",
				$attachment_id
			)
		);

		$result = $count > 0;
		wp_cache_set( $cache_key, $result ? 1 : 0, 'protect_uploads', HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Verify a password for an attachment
	 *
	 * @since    0.5.2
	 * @param    int    $attachment_id    Attachment ID.
	 * @param    string $password         Password to verify.
	 * @return   bool|int
	 */
	public function verify_password( $attachment_id, $password ) {
		global $wpdb;

		// Basic validation
		$attachment_id = absint( $attachment_id );
		if ( empty( $password ) || empty( $attachment_id ) ) {
			return false;
		}

		// Get passwords with caching
		$cache_key = 'protect_uploads_password_hashes_' . $attachment_id;
		$passwords = wp_cache_get( $cache_key, 'protect_uploads' );

		if ( false === $passwords ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
			$passwords = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, password_hash FROM {$wpdb->prefix}protect_uploads_passwords WHERE attachment_id = %d",
				$attachment_id
			) );
			wp_cache_set( $cache_key, $passwords, 'protect_uploads', HOUR_IN_SECONDS );
		}

		if ( empty( $passwords ) ) {
			return false;
		}

		// Reserve a slot atomically before doing any password hashing. Checking
		// and then incrementing a transient lets parallel requests share a slot.
		$window = $this->change_attempt( $attachment_id, 1 );
		if ( false === $window ) {
			return false;
		}

		// Check against all passwords
		foreach ( $passwords as $pwd ) {
			if ( wp_check_password( $password, $pwd->password_hash ) ) {
				$this->change_attempt( $attachment_id, -1, $window );
				return $pwd->id;
			}
		}

		// Log failed attempt
		$this->log_failed_attempt( $attachment_id );

		return false;
	}

	/**
	 * Whether the current visitor has failed too often on this file recently.
	 *
	 * @since  0.8.0
	 * @param  int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_rate_limited( $attachment_id ) {
		/**
		 * Filters how many failed password attempts a visitor gets per file.
		 *
		 * @since 0.8.0
		 * @param int $max_attempts Failed attempts allowed within the window.
		 */
		$max_attempts = (int) apply_filters( 'protect_uploads_max_password_attempts', 5 );

		$state = $this->read_attempts( $attachment_id );
		return false === $state || ( $state['until'] >= time() && $state['count'] >= max( 1, $max_attempts ) );
	}

	/**
	 * Read the counter directly, including when a persistent cache is used.
	 *
	 * The expiry and count share one row so they can be changed together.
	 * The companion timeout row lets WordPress clean up expired counters.
	 *
	 * @since 0.8.0
	 * @param int $attachment_id Attachment ID.
	 * @return array|false Counter state, or false on a database/format error.
	 */
	private function read_attempts( $attachment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic rate limit always uses the database, never a cached count.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $this->rate_limit_key( $attachment_id ) ) );
		if ( $wpdb->last_error || ( null !== $raw && ! preg_match( '/^\d+:\d+$/', $raw ) ) ) {
			return false;
		}
		$parts = null === $raw ? array( 0, 0 ) : explode( ':', $raw );
		return array( 'raw' => $raw, 'until' => (int) $parts[0], 'count' => (int) $parts[1] );
	}

	/**
	 * Remove expired database counters on core's daily cleanup hook.
	 *
	 * Core skips database transients when a persistent object cache is active;
	 * our counters deliberately use the database on all sites for atomicity.
	 * Only this plugin's expired rows are removed, together with their timeout.
	 *
	 * @since 0.8.0
	 */
	public static function cleanup_expired_attempts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Private, non-autoloaded atomic counters are never read through an object cache.
		$wpdb->query( $wpdb->prepare(
			"DELETE a, b FROM {$wpdb->options} a INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12)) WHERE a.option_name LIKE %s AND CAST(b.option_value AS UNSIGNED) < %d",
			$wpdb->esc_like( '_transient_protect_uploads_rl_' ) . '%',
			time()
		) );
	}

	/**
	 * Reserve an attempt, or refund a successful one in the same window.
	 *
	 * Compare-and-swap prevents lost increments without relying on an object
	 * cache's atomic-increment implementation. Contention and DB errors fail
	 * closed. A late success cannot refund an attempt in a newer window.
	 *
	 * @since 0.8.0
	 * @param int $attachment_id Attachment ID.
	 * @param int $change        1 to count an attempt, -1 to give it back.
	 * @param int $window        Original window expiry when refunding.
	 * @return int|false Window expiry on success, false if no slot is available.
	 */
	private function change_attempt( $attachment_id, $change, $window = 0 ) {
		global $wpdb;
		/**
		 * Filters the rate-limit window, in minutes.
		 *
		 * @since 0.8.0
		 * @param int $minutes Window length.
		 */
		$minutes = max( 1, (int) apply_filters( 'protect_uploads_password_lockout_minutes', 15 ) );
		$key     = $this->rate_limit_key( $attachment_id );
		$name    = '_transient_' . $key;
		$max     = max( 1, (int) apply_filters( 'protect_uploads_max_password_attempts', 5 ) );

		for ( $try = 0; $try < 20; $try++ ) {
			$state = $this->read_attempts( $attachment_id );
			if ( false === $state ) {
				return false;
			}
			$now     = time();
			$expired = $state['until'] < $now;
			if ( $change < 0 && ( $expired || $window !== $state['until'] ) ) {
				return false;
			}
			$count = $expired ? 0 : $state['count'];
			$until = $expired ? $now + $minutes * MINUTE_IN_SECONDS : $state['until'];
			if ( $change > 0 && $count >= $max ) {
				return false;
			}
			$value = $until . ':' . max( 0, $count + $change );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on a private, non-autoloaded counter.
			if ( $expired ) {
				// Refresh cleanup's deadline before reviving an expired counter:
				// otherwise cron could delete a newly claimed attempt in between.
				$timeout = '_transient_timeout_' . $key;
				$saved   = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS UNSIGNED), %d)", $timeout, (string) $until, $until ) );
				wp_cache_delete( $timeout, 'options' );
				if ( false === $saved ) {
					return false;
				}
			}
			if ( null === $state['raw'] ) {
				$changed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
			} else {
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $state['raw'] ) );
			}
			if ( false === $changed ) {
				return false;
			}
			if ( 1 !== $changed ) {
				continue;
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_delete( $name, 'options' );
			return $until;
		}

		return false;
	}

	/**
	 * Transient key for the visitor's attempts on a file.
	 *
	 * IPv6 visitors are grouped by their /64 network: one connection usually
	 * holds a whole /64, so per-address counting could be escaped by
	 * changing the last half of the address.
	 *
	 * @since  0.8.0
	 * @param  int $attachment_id Attachment ID.
	 * @return string
	 */
	private function rate_limit_key( $attachment_id ) {
		$ip = $this->get_client_ip();

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				// IPv4-mapped sockets must not group every IPv4 visitor together.
				$ip = str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 )
					? inet_ntop( substr( $packed, 12 ) )
					: inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
			}
		}

		return 'protect_uploads_rl_' . md5( absint( $attachment_id ) . '|' . $ip );
	}

	/**
	 * Log failed password attempt
	 *
	 * @since    0.5.2
	 * @param    int $attachment_id    Attachment ID.
	 */
	private function log_failed_attempt( $attachment_id ) {
		global $wpdb;

		// Log the attempt
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
		$wpdb->insert(
			$wpdb->prefix . 'protect_uploads_access_logs',
			array(
				'attachment_id' => $attachment_id,
				'password_id'   => 0,
				'ip_address'    => $this->get_client_ip(),
				'user_agent'    => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ),
				'access_type'   => 'failed'
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get the client IP address.
	 *
	 * Uses REMOTE_ADDR only. Forwarding headers (CF-Connecting-IP,
	 * X-Forwarded-For, Client-IP) are set by the client unless a trusted proxy
	 * overwrites them, so trusting them lets anyone escape the rate limit.
	 * Sites behind a proxy they control can use the 'protect_uploads_client_ip'
	 * filter.
	 *
	 * @since    0.5.2
	 * @since    0.8.0 Ignores forwarding headers.
	 * @return   string
	 */
	private function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP address used for rate limiting and logs.
		 *
		 * @since 0.8.0
		 * @param string $ip The REMOTE_ADDR value.
		 */
		$ip = (string) apply_filters( 'protect_uploads_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Add password meta box to media edit screen
	 *
	 * @since    0.5.2
	 */
	public function add_password_meta_box() {
		add_meta_box(
			'protect-uploads-passwords',
			__( 'Password Protection', 'protect-uploads' ),
			array( $this, 'render_password_meta_box' ),
			'attachment',
			'side',
			'high'
		);
	}

	/**
	 * Get access logs for an attachment
	 *
	 * @since    0.5.2
	 * @param    int $attachment_id    Attachment ID.
	 * @return   array
	 */
	public function get_attachment_access_logs( $attachment_id ) {
		global $wpdb;

		$cache_key = 'protect_uploads_access_logs_' . $attachment_id;
		$cached = wp_cache_get( $cache_key, 'protect_uploads' );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, p.password_label
				FROM {$wpdb->prefix}protect_uploads_access_logs l
				LEFT JOIN {$wpdb->prefix}protect_uploads_passwords p ON l.password_id = p.id
				WHERE l.attachment_id = %d
				ORDER BY l.access_time DESC
				LIMIT 10",
				$attachment_id
			)
		);

		wp_cache_set( $cache_key, $results, 'protect_uploads', 5 * MINUTE_IN_SECONDS );

		return $results;
	}

	/**
	 * Render password meta box content
	 *
	 * @since    0.5.2
	 * @param    WP_Post $post    Post object.
	 */
	public function render_password_meta_box( $post ) {
		$passwords = $this->get_attachment_passwords( $post->ID );
		$access_logs = $this->get_attachment_access_logs( $post->ID );
		?>
		<div class="protect-uploads-passwords" data-attachment-id="<?php echo esc_attr( $post->ID ); ?>">
			<?php if ( ! Alti_ProtectUploads_Password_Rules::server_supports_rules() ) : ?>
				<p class="description" style="color:#b32d2e;">
					<?php esc_html_e( 'Your server does not read .htaccess rules (for example, Nginx), so this file is still reachable at its direct uploads URL. A password protects the links WordPress shows, not the file itself.', 'protect-uploads' ); ?>
				</p>
			<?php endif; ?>
			<div class="existing-passwords">
				<?php if ( ! empty( $passwords ) ) : ?>
					<h4><?php esc_html_e( 'Existing Passwords', 'protect-uploads' ); ?></h4>
					<ul>
						<?php foreach ( $passwords as $password ) : ?>
							<li>
								<?php echo esc_html( $password->password_label ); ?>
								<a href="#" class="delete-password" data-id="<?php echo esc_attr( $password->id ); ?>">
									<?php esc_html_e( 'Delete', 'protect-uploads' ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			
			<div class="add-password">
				<p>
					<label>
						<?php esc_html_e( 'Password Label:', 'protect-uploads' ); ?><br>
						<input type="text" name="protect_uploads_password_label" class="widefat">
					</label>
				</p>
				<p>
					<label>
						<?php esc_html_e( 'Password:', 'protect-uploads' ); ?><br>
						<input type="password" name="protect_uploads_password" class="widefat">
					</label>
				</p>
				<p>
					<button type="button" class="button add-password-button">
						<?php esc_html_e( 'Add Password', 'protect-uploads' ); ?>
					</button>
				</p>
			</div>

			<?php if ( ! empty( $access_logs ) ) : ?>
				<div class="access-logs">
					<h4><?php esc_html_e( 'Recent Access Logs', 'protect-uploads' ); ?></h4>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'protect-uploads' ); ?></th>
								<th><?php esc_html_e( 'Password', 'protect-uploads' ); ?></th>
								<th><?php esc_html_e( 'IP', 'protect-uploads' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $access_logs as $log ) : ?>
								<tr>
									<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $log->access_time ) ) ); ?></td>
									<td><?php echo esc_html( $log->password_label ); ?></td>
									<td><?php echo esc_html( $log->ip_address ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to add password
	 *
	 * @since    0.5.2
	 */
	public function ajax_add_password() {
		check_ajax_referer( 'protect_uploads_password_action', 'nonce' );

		// Sanitize attachment_id first
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied or invalid attachment ID.', 'protect-uploads' ) ) );
		}

		// $attachment_id is already sanitized
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ); // Added ?? '' for safety
		$password = sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) ); // Added ?? '' for safety

		if ( ! $label || ! $password ) { // Check label and password specifically
			wp_send_json_error( array( 'message' => __( 'Missing required fields (label or password).', 'protect-uploads' ) ) );
		}

		$result = $this->add_attachment_password( $attachment_id, $password, $label );
		if ( $result ) {
			wp_send_json_success( array(
				'message' => __( 'Password added successfully.', 'protect-uploads' ),
				'passwords' => $this->get_attachment_passwords( $attachment_id ),
			) );
		}

		wp_send_json_error( array( 'message' => __( 'Failed to add password.', 'protect-uploads' ) ) );
	}

	/**
	 * Handle AJAX request to delete password
	 *
	 * @since    0.5.2
	 */
	public function ajax_delete_password() {
		check_ajax_referer( 'protect_uploads_password_action', 'nonce' );

		// Sanitize attachment_id first
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied or invalid attachment ID.', 'protect-uploads' ) ) );
		}

		// $attachment_id is already sanitized
		$password_id = isset( $_POST['password_id'] ) ? absint( $_POST['password_id'] ) : 0;

		if ( ! $password_id ) { // Check password_id specifically
			wp_send_json_error( array( 'message' => __( 'Invalid request (missing password ID).', 'protect-uploads' ) ) );
		}

		$result = $this->delete_attachment_password( $attachment_id, $password_id );
		if ( $result ) {
			wp_send_json_success( array(
				'message' => __( 'Password deleted successfully.', 'protect-uploads' ),
				'passwords' => $this->get_attachment_passwords( $attachment_id ),
			) );
		}

		wp_send_json_error( array( 'message' => __( 'Failed to delete password.', 'protect-uploads' ) ) );
	}

	/**
	 * Delete a password
	 *
	 * @since    0.5.2
	 * @param    int $attachment_id    Attachment ID.
	 * @param    int $password_id      Password ID.
	 * @return   bool
	 */
	public function delete_attachment_password( $attachment_id, $password_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
		$result = $wpdb->delete(
			$wpdb->prefix . 'protect_uploads_passwords',
			array(
				'id' => $password_id,
				'attachment_id' => $attachment_id,
			),
			array( '%d', '%d' )
		);

		// Invalidate caches for this attachment
		wp_cache_delete( 'protect_uploads_passwords_' . $attachment_id, 'protect_uploads' );
		wp_cache_delete( 'protect_uploads_has_passwords_' . $attachment_id, 'protect_uploads' );
		wp_cache_delete( 'protect_uploads_password_hashes_' . $attachment_id, 'protect_uploads' );

		Alti_ProtectUploads_Password_Rules::sync();

		return $result;
	}

	/**
	 * Log an access attempt
	 *
	 * @since    0.5.2
	 * @param    int    $attachment_id    Attachment ID.
	 * @param    int    $password_id      Password ID.
	 * @param    string $access_type      Type of access (view/download).
	 * @return   bool|int
	 */
	public function log_access( $attachment_id, $password_id, $access_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
		return $wpdb->insert(
			$wpdb->prefix . 'protect_uploads_access_logs',
			array(
				'attachment_id' => $attachment_id,
				'password_id'   => $password_id,
				'ip_address'    => $this->get_client_ip(),
				'user_agent'    => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ),
				'access_type'   => $access_type,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}
} 
