<?php
/**
 * Handles frontend file access and password verification
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 */

/**
 * The frontend functionality of the plugin.
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 * @author     Your Name <email@example.com>
 */
class Alti_ProtectUploads_Frontend {

	/**
	 * The plugin settings
	 *
	 * @since    0.5.2
	 * @access   private
	 * @var      array    $settings    The plugin settings.
	 */
	private $settings;

	/**
	 * The passwords handler instance
	 *
	 * @since    0.5.2
	 * @access   private
	 * @var      Alti_ProtectUploads_Passwords    $passwords    The passwords handler instance.
	 */
	private $passwords;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    0.5.2
	 */
	public function __construct() {
		$this->settings = get_option( 'protect_uploads_settings', array() );
		$this->passwords = new Alti_ProtectUploads_Passwords();
	}

	/**
	 * Initialize hooks
	 *
	 * @since    0.5.2
	 */
	public function init() {
		if ( ! empty( $this->settings['enable_password_protection'] ) ) {
			add_action( 'parse_request', array( $this, 'handle_protected_file_request' ) );
		}
	}

	/**
	 * Handle protected file request
	 *
	 * @since    0.5.2
	 */
	public function handle_protected_file_request() {
		if ( ! isset( $_GET['protect_uploads_file'] ) ) {
			return;
		}

		$attachment_id = absint( $_GET['protect_uploads_file'] );
		if ( ! $attachment_id ) {
			wp_die( esc_html__( 'Invalid file request.', 'protect-uploads' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'protect_uploads_' . $attachment_id ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'protect-uploads' ) );
		}

		$error_message = '';

		// Handle password submission.
		if ( isset( $_POST['password'] ) && isset( $_POST['protect_uploads_nonce'] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protect_uploads_nonce'] ) ), 'protect_uploads_verify_password' ) ) {
				$password = sanitize_text_field( wp_unslash( $_POST['password'] ) );
				$verified = $this->passwords->verify_password( $attachment_id, $password );

				if ( $verified ) {
					$this->passwords->log_access( $attachment_id, $verified, 'download' );
					$this->serve_file( $attachment_id );
					exit;
				} else {
					$error_message = __( 'Invalid password. Please try again.', 'protect-uploads' );
				}
			}
		}

		// Show password prompt.
		$this->show_password_prompt( $attachment_id, $error_message );
		exit;
	}

	/**
	 * Show password prompt
	 *
	 * @since    0.5.2
	 * @param    int    $attachment_id    Attachment ID.
	 * @param    string $error_message    Error message to display.
	 */
	private function show_password_prompt( $attachment_id, $error_message = '' ) {
		include plugin_dir_path( dirname( __FILE__ ) ) . 'templates/password-prompt.php';
	}

	/**
	 * Serve the protected file
	 *
	 * @since    0.5.2
	 * @param    int $attachment_id    Attachment ID.
	 */
	private function serve_file( $attachment_id ) {
		// Verify attachment exists and is valid
		if ( ! $attachment = get_post( $attachment_id ) ) {
			wp_die( esc_html__( 'Invalid file request.', 'protect-uploads' ), 404 );
		}

		if ( 'attachment' !== $attachment->post_type ) {
			wp_die( esc_html__( 'Invalid file type.', 'protect-uploads' ), 404 );
		}

		// Get file path
		$file = get_attached_file( $attachment_id );
		
		// Include WP_Filesystem
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() ) {
			wp_die( esc_html__( 'Could not initialize filesystem.', 'protect-uploads' ), 500 );
		}

		// Basic security checks using WP_Filesystem
		if ( ! $file || ! $wp_filesystem->exists( $file ) || ! $wp_filesystem->is_readable( $file ) ) {
			wp_die( esc_html__( 'File not found or not readable.', 'protect-uploads' ), 404 );
		}

		// Validate file is within uploads directory
		$upload_dir = wp_upload_dir();
		// Note: realpath might fail with stream wrappers if WP_Filesystem uses FTP/SSH
		// A direct string comparison might be more reliable if WP_Filesystem context is unknown
		$file_path = str_replace('\\', '/', $file);
		$uploads_path = str_replace('\\', '/', $upload_dir['basedir']);
		
		if ( 0 !== strpos( $file_path, $uploads_path ) ) {
			wp_die( esc_html__( 'Invalid file location.', 'protect-uploads' ), 403 );
		}

		// Get MIME type
		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! $mime_type ) {
			$mime_type = 'application/octet-stream';
		}

		// Get file size using WP_Filesystem
		$file_size = $wp_filesystem->size( $file );
		if ( false === $file_size ) {
			wp_die( esc_html__( 'Could not determine file size.', 'protect-uploads' ), 500 );
		}

		// Clear any previous output
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Prevent caching
		nocache_headers();

		// Set headers
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( basename( $file ) ) . '"' );
		header( 'Content-Length: ' . $file_size );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );

		// Read and output file content using WP_Filesystem
		$file_content = $wp_filesystem->get_contents( $file );

		if ( false === $file_content ) {
			wp_die( esc_html__( 'Error reading file content.', 'protect-uploads' ), 500 );
		}

		echo $file_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
} 