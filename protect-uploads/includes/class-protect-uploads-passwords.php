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
			add_action( 'wp_ajax_verify_attachment_password', array( $this, 'verify_attachment_password' ) );
			add_action( 'wp_ajax_nopriv_verify_attachment_password', array( $this, 'verify_attachment_password' ) );
			
			// Handle frontend URL modification
			add_filter( 'wp_get_attachment_url', array( $this, 'modify_attachment_url' ), 10, 2 );
		}
	}

	/**
	 * Add password fields to media modal and edit screen
	 *
	 * @since    0.5.2
	 * @param    array   $form_fields    Array of form fields.
	 * @param    WP_Post $post           Attachment post object.
	 * @return   array
	 */
	public function add_password_fields( $form_fields, $post ) {
		$passwords = $this->get_attachment_passwords( $post->ID );
		
		$html = '<div class="protect-uploads-passwords">';
		$html .= wp_nonce_field( 'protect_uploads_save_password_' . $post->ID, 'protect_uploads_password_nonce', false, false );
		$html .= '<div class="existing-passwords">';
		if ( ! empty( $passwords ) ) {
			$html .= '<h4>' . esc_html__( 'Existing Passwords', 'protect-uploads' ) . '</h4>';
			$html .= '<ul>';
			foreach ( $passwords as $password ) {
				$html .= '<li>';
				$html .= esc_html( $password->password_label );
				$html .= ' <a href="#" class="delete-password" data-id="' . esc_attr( $password->id ) . '">';
				$html .= esc_html__( 'Delete', 'protect-uploads' );
				$html .= '</a>';
				$html .= '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</div>';
		
		$html .= '<div class="add-password">';
		$html .= '<input type="text" name="protect_uploads_password_label" placeholder="' . esc_attr__( 'Password Label', 'protect-uploads' ) . '" />';
		$html .= '<input type="password" name="protect_uploads_password" placeholder="' . esc_attr__( 'Password', 'protect-uploads' ) . '" />';
		$html .= '<button type="button" class="button add-password-button">' . esc_html__( 'Add Password', 'protect-uploads' ) . '</button>';
		$html .= '</div>';
		$html .= '</div>';

		$form_fields['protect_uploads_passwords'] = array(
			'label' => __( 'Password Protection', 'protect-uploads' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}

	/**
	 * Save password fields
	 *
	 * @since    0.5.2
	 * @param    array   $post       Attachment post array.
	 * @param    array   $attachment Attachment fields array.
	 * @return   array
	 */
	public function save_password_fields( $post, $attachment ) {
		// Verify nonce first
		$nonce = isset( $_POST['protect_uploads_password_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['protect_uploads_password_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'protect_uploads_save_password_' . $post['ID'] ) ) {
			// Nonce is invalid, maybe log this or return the post array without changes
			return $post; 
		}
		
		// Check if both password and label are set and password is not empty
		if ( isset( $_POST['protect_uploads_password'], $_POST['protect_uploads_password_label'] ) && ! empty( $_POST['protect_uploads_password'] ) ) {
			$this->add_attachment_password(
				$post['ID'],
				sanitize_text_field( wp_unslash( $_POST['protect_uploads_password'] ) ),
				sanitize_text_field( wp_unslash( $_POST['protect_uploads_password_label'] ) )
			);
		}
		return $post;
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

		return $wpdb->insert_id;
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
		if ( $this->has_passwords( $attachment_id ) ) {
			return add_query_arg(
				array(
					'protect_uploads_file' => $attachment_id,
					'_wpnonce'            => wp_create_nonce( 'protect_uploads_' . $attachment_id ),
				),
				home_url( 'index.php' )
			);
		}
		return $url;
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

		$cache_key = 'protect_uploads_has_passwords_' . $attachment_id;
		$cached = wp_cache_get( $cache_key, 'protect_uploads' );

		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table requires direct query
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}protect_uploads_passwords WHERE attachment_id = %d",
				$attachment_id
			)
		);

		$result = $count > 0;
		wp_cache_set( $cache_key, $result, 'protect_uploads', HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Verify attachment password
	 *
	 * @since    0.5.2
	 */
	public function verify_attachment_password() {
		check_ajax_referer( 'protect_uploads_verify_password', 'nonce' );

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		$password = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';

		if ( ! $attachment_id || ! $password ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request', 'protect-uploads' ) ) );
		}

		$verified = $this->verify_password( $attachment_id, $password );
		if ( $verified ) {
			$this->log_access( $attachment_id, $verified, 'view' );
			wp_send_json_success( array(
				'url' => wp_get_attachment_url( $attachment_id ),
			) );
		}

		wp_send_json_error( array( 'message' => __( 'Invalid password', 'protect-uploads' ) ) );
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

		// Check against all passwords
		foreach ( $passwords as $pwd ) {
			if ( wp_check_password( $password, $pwd->password_hash ) ) {
				return $pwd->id;
			}
		}

		// Log failed attempt
		$this->log_failed_attempt( $attachment_id );

		return false;
	}

	/**
	 * Log failed password attempt
	 *
	 * @since    0.5.2
	 * @param    int $attachment_id    Attachment ID.
	 */
	private function log_failed_attempt( $attachment_id ) {
		global $wpdb;

		// Get number of recent failed attempts (no caching - needs real-time count for rate limiting)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, real-time rate limiting
		$attempts = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}protect_uploads_access_logs
			WHERE attachment_id = %d
			AND access_type = 'failed'
			AND access_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
			$attachment_id
		) );

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

		// If too many attempts, maybe implement temporary lockout
		if ( $attempts > 5 ) {
			/* // Removed error log
			error_log( sprintf( 
				'Protect Uploads: Multiple failed password attempts for attachment %d from IP %s',
				$attachment_id,
				$this->get_client_ip()
			) );
			*/
		}
	}

	/**
	 * Get client IP address with proxy support
	 *
	 * @since    0.5.2
	 * @return   string
	 */
	private function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$raw_value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			$candidates = ( 'HTTP_X_FORWARDED_FOR' === $header )
				? explode( ',', $raw_value )
				: array( $raw_value );

			foreach ( $candidates as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		return '0.0.0.0';
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
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->access_time ) ) ); ?></td>
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
				'user_agent'    => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
				'access_type'   => $access_type,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}
} 
