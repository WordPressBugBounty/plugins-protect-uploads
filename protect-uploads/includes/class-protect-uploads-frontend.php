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
		if ( ! isset( $_GET['protect_uploads_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public prompt URL; the password is the gate.
			return;
		}

		// The prompt URL carries no nonce (older links still have one; it is
		// ignored). A nonce here only tied links to one visitor's session, so
		// they broke after a day or when served from a page cache. The password
		// is the gate, and the form below keeps its own nonce.
		$attachment_id = absint( $_GET['protect_uploads_file'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public prompt URL; the password is the gate.
		if ( ! $attachment_id || ! $this->passwords->has_passwords( $attachment_id ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Invalid file request.', 'protect-uploads' ), '', array( 'response' => 404 ) );
		}

		// Validated against the attachment's own files in serve_file().
		$requested_path = isset( $_GET['protect_uploads_path'] ) ? sanitize_text_field( wp_unslash( $_GET['protect_uploads_path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public prompt URL; the password is the gate.

		// People who can edit the file see it without the password, so its
		// thumbnails load in the Media Library and the editor.
		if ( current_user_can( 'edit_post', $attachment_id ) ) {
			$this->serve_file( $attachment_id, $requested_path );
			exit;
		}

		$error_message = '';

		// Handle password submission.
		if ( isset( $_POST['password'] ) && isset( $_POST['protect_uploads_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['protect_uploads_nonce'] ) ), 'protect_uploads_verify_password' ) ) {
				$error_message = __( 'This page has expired. Please enter the password again.', 'protect-uploads' );
			} elseif ( $this->passwords->is_rate_limited( $attachment_id ) ) {
				$error_message = __( 'Too many failed attempts. Please wait a few minutes and try again.', 'protect-uploads' );
			} else {
				// Passwords keep going through sanitize_text_field(): existing
				// hashes were made from the sanitized value.
				$password = sanitize_text_field( wp_unslash( $_POST['password'] ) );
				$verified = $this->passwords->verify_password( $attachment_id, $password );

				if ( $verified ) {
					$this->passwords->log_access( $attachment_id, $verified, 'download' );
					$this->serve_file( $attachment_id, $requested_path );
					exit;
				} else {
					$error_message = __( 'Invalid password. Please try again.', 'protect-uploads' );
				}
			}
		}

		// Show password prompt. Never cache it: it holds a form nonce.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
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
	 * The attachment file to serve: the requested one when it belongs to the
	 * attachment (a size, format copy, original or edit backup), otherwise
	 * the main file.
	 *
	 * @since  0.8.0
	 * @param  int    $attachment_id  Attachment ID.
	 * @param  string $requested_path Path relative to the uploads directory.
	 * @return string|false Absolute path, or false if the attachment has no file.
	 */
	private function resolve_file( $attachment_id, $requested_path ) {
		$requested_path = ltrim( wp_normalize_path( (string) $requested_path ), '/' );

		if ( '' !== $requested_path ) {
			$files = Alti_ProtectUploads_Password_Rules::get_attachment_files( $attachment_id );
			// The rules match case-insensitively, so compare the same way and
			// serve the file under its real name.
			foreach ( $files as $relative ) {
				if ( 0 === strcasecmp( $relative, $requested_path ) ) {
					$upload_dir = wp_upload_dir( null, false );
					return $upload_dir['basedir'] . '/' . $relative;
				}
			}
		}

		return get_attached_file( $attachment_id );
	}

	/**
	 * Serve the protected file
	 *
	 * @since    0.5.2
	 * @since    0.8.0 Serves the requested size and streams the file.
	 * @param    int    $attachment_id     Attachment ID.
	 * @param    string $requested_path    Optional. File of the attachment to
	 *                                     serve, relative to the uploads directory.
	 */
	private function serve_file( $attachment_id, $requested_path = '' ) {
		// Verify attachment exists and is valid
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			wp_die( esc_html__( 'Invalid file request.', 'protect-uploads' ), 404 );
		}

		if ( 'attachment' !== $attachment->post_type ) {
			wp_die( esc_html__( 'Invalid file type.', 'protect-uploads' ), 404 );
		}

		$file = $this->resolve_file( $attachment_id, $requested_path );

		if ( ! $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			wp_die( esc_html__( 'File not found or not readable.', 'protect-uploads' ), 404 );
		}

		// Validate file is within uploads directory. Resolve both paths so
		// "../" segments and symlinks cannot point outside it, and compare
		// with a trailing slash so a sibling folder such as uploads-old/ does
		// not match.
		$upload_dir   = wp_upload_dir();
		$file_path    = realpath( $file );
		$uploads_path = realpath( $upload_dir['basedir'] );

		if ( false === $file_path || false === $uploads_path || 0 !== strpos( wp_normalize_path( $file_path ), trailingslashit( wp_normalize_path( $uploads_path ) ) ) ) {
			wp_die( esc_html__( 'Invalid file location.', 'protect-uploads' ), 403 );
		}

		/**
		 * Filters whether a password-unlocked file may be downloaded.
		 *
		 * Protect Uploads Pro uses this to refuse files that also carry Pro
		 * restrictions, so the free password cannot bypass them.
		 *
		 * @since 0.8.0
		 * @param bool $allow         Whether to serve the file. Default true.
		 * @param int  $attachment_id Attachment ID.
		 */
		if ( ! apply_filters( 'protect_uploads_allow_file_download', true, $attachment_id ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'protect-uploads' ), esc_html__( 'Access denied', 'protect-uploads' ), array( 'response' => 403 ) );
		}

		// A size or format copy can have a different type than the attachment
		// (a WebP copy of a JPEG), so go by the served file's extension.
		$file_type = wp_check_filetype( $file_path );
		$mime_type = $file_type['type'] ? $file_type['type'] : get_post_mime_type( $attachment_id );
		if ( ! $mime_type ) {
			$mime_type = 'application/octet-stream';
		}

		$file_size = filesize( $file_path );
		if ( false === $file_size ) {
			wp_die( esc_html__( 'Could not determine file size.', 'protect-uploads' ), 500 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams the file in chunks; WP_Filesystem would load it all into memory.
		$handle = fopen( $file_path, 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'Error reading file content.', 'protect-uploads' ), 500 );
		}

		// Drop every output buffer, so nothing precedes the file and large
		// files are not held in memory.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Prevent caching
		nocache_headers();

		// Types a browser would run as a page on this site's origin are sent as
		// downloads, so an uploaded SVG or HTML file cannot run scripts here.
		$active_types = array( 'image/svg+xml', 'text/html', 'application/xhtml+xml', 'text/xml', 'application/xml' );
		$disposition  = in_array( $mime_type, $active_types, true ) ? 'attachment' : 'inline';

		// Set headers
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . sanitize_file_name( basename( $file_path ) ) . '"' );
		header( 'Content-Length: ' . $file_size );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file data, streamed in chunks.
			echo fread( $handle, 8192 );
			flush();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with fopen() above.
		fclose( $handle );
		exit;
	}
}
