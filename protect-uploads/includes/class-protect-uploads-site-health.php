<?php
/**
 * Site Health protection checks.
 *
 * Registers WordPress Site Health tests (Tools -> Site Health -> Status) that
 * report on the state of the uploads directory protection provided by this
 * plugin: directory-browsing protection, the detected server configuration,
 * and a scan for sensitive file types that should never live in uploads.
 *
 * @link       https://protectuploads.com
 * @since      0.7.0
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Site Health protection checks.
 *
 * @since 0.7.0
 */
class Alti_ProtectUploads_Site_Health {

	/**
	 * Maximum directory depth the sensitive-file scanner will descend.
	 *
	 * @since 0.7.0
	 * @var   int
	 */
	const SCAN_MAX_DEPTH = 3;

	/**
	 * Maximum number of files the sensitive-file scanner will inspect before
	 * stopping early. Keeps the async test bounded on large media libraries.
	 *
	 * @since 0.7.0
	 * @var   int
	 */
	const SCAN_MAX_FILES = 2000;

	/**
	 * File extensions that should never be served from the uploads directory.
	 *
	 * @since 0.7.0
	 * @var   string[]
	 */
	const RISKY_EXTENSIONS = array( 'php', 'phtml', 'php5', 'sh', 'cgi' );

	/**
	 * The async test slug used for the sensitive-files Site Health test.
	 *
	 * This value is sent to WordPress as the async test's "test" field. For
	 * non-REST async tests, core's site-health.js builds the admin-ajax action
	 * as ( 'health-check-' + test.replace( '_', '-' ) ) -- note the JS string
	 * .replace() only swaps the FIRST underscore for a hyphen. Using a slug that
	 * contains no underscores makes the transform a no-op, so the action JS posts
	 * matches the wp_ajax_ hook we register exactly. The previous value
	 * ('pu_sensitive_files') produced 'health-check-pu-sensitive_files' on the
	 * wire while the hook was 'health-check-pu_sensitive_files', causing an
	 * HTTP 400 (no matching handler).
	 *
	 * @since 0.7.0
	 * @var   string
	 */
	const ASYNC_ACTION = 'pu-sensitive-files';

	/**
	 * Register the Site Health hooks.
	 *
	 * Site Health runs in the admin and (for async tests) via an authenticated
	 * admin-ajax request, so all wiring here is admin-side.
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function init() {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
		add_action( 'wp_ajax_health-check-' . self::ASYNC_ACTION, array( $this, 'ajax_sensitive_files' ) );
	}

	/**
	 * Register this plugin's tests with the Site Health screen.
	 *
	 * @since 0.7.0
	 * @param array $tests The current set of Site Health tests.
	 * @return array The filtered set of tests.
	 */
	public function register_tests( $tests ) {
		$tests['direct']['protect_uploads_directory_browsing'] = array(
			'label' => __( 'Uploads directory browsing protection', 'protect-uploads' ),
			'test'  => array( $this, 'test_directory_browsing' ),
		);

		$tests['direct']['protect_uploads_server_config'] = array(
			'label' => __( 'Server configuration detected', 'protect-uploads' ),
			'test'  => array( $this, 'test_server_config' ),
		);

		$settings = get_option( 'protect_uploads_settings', array() );
		if ( ! empty( $settings['enable_password_protection'] ) ) {
			$tests['direct']['protect_uploads_password_direct_urls'] = array(
				'label' => __( 'Direct URLs of password-protected files', 'protect-uploads' ),
				'test'  => array( $this, 'test_password_direct_urls' ),
			);
		}

		$tests['async']['protect_uploads_sensitive_files'] = array(
			'label'             => __( 'Sensitive file types in uploads', 'protect-uploads' ),
			'test'              => self::ASYNC_ACTION,
			'has_rest'          => false,
			'async_direct_test' => array( $this, 'test_sensitive_files' ),
		);

		return $tests;
	}

	/**
	 * Direct test: is the uploads directory protected from directory listing?
	 *
	 * Reuses the admin class detection logic so the result matches what the
	 * plugin's settings screen reports.
	 *
	 * @since 0.7.0
	 * @return array A Site Health result array.
	 */
	public function test_directory_browsing() {
		$result = array(
			'label'       => __( 'Your uploads directory is protected from browsing', 'protect-uploads' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'protect-uploads' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'A visitor cannot list the contents of your uploads directory.', 'protect-uploads' ) . $this->pro_sentence()
			),
			'actions'     => $this->pro_link(),
			'test'        => 'protect_uploads_directory_browsing',
		);

		if ( ! $this->is_uploads_browsing_protected() ) {
			$result['status']         = 'recommended';
			$result['label']          = __( 'Your uploads directory may be browsable', 'protect-uploads' );
			$result['badge']['color'] = 'orange';
			$result['description']     = sprintf(
				'<p>%s</p>',
				esc_html__( 'No directory-listing protection was detected in your uploads directory. Without it, visitors may be able to list every file you have ever uploaded. Protect Uploads can add an index.php or .htaccess file to block this.', 'protect-uploads' )
			);
			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->get_settings_url() ),
				esc_html__( 'Configure uploads protection', 'protect-uploads' )
			);
		}

		return $result;
	}

	/**
	 * Direct test: are password-protected files refused at their direct URL?
	 *
	 * A password only changes the links WordPress prints; the file's own
	 * uploads URL is covered by the rules in the uploads .htaccess file, which
	 * only Apache and LiteSpeed read.
	 *
	 * @since 0.8.0
	 * @return array A Site Health result array.
	 */
	public function test_password_direct_urls() {
		$status = Alti_ProtectUploads_Password_Rules::status();

		$result = array(
			'label'       => __( 'Password-protected files are protected at their direct URL', 'protect-uploads' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'protect-uploads' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				'none' === $status
					? esc_html__( 'No media file has a password yet.', 'protect-uploads' )
					: esc_html__( 'Requests for the uploads URL of a password-protected file are sent to the password prompt.', 'protect-uploads' )
			),
			'actions'     => '',
			'test'        => 'protect_uploads_password_direct_urls',
		);

		if ( 'unsupported' === $status ) {
			$result['status']         = 'recommended';
			$result['label']          = __( 'Password-protected files can be downloaded at their direct URL', 'protect-uploads' );
			$result['badge']['color'] = 'orange';
			$result['description']    = sprintf(
				'<p>%s</p>',
				esc_html__( 'Your server does not read .htaccess files (for example, Nginx), so the rules that send direct requests to the password prompt do not apply. Anyone who has a protected file\'s uploads URL can download it without the password. Add an equivalent rule to your server configuration, or move the files out of the uploads directory.', 'protect-uploads' )
			);
		} elseif ( 'missing' === $status ) {
			$result['status']         = 'critical';
			$result['label']          = __( 'Password-protected files can be downloaded at their direct URL', 'protect-uploads' );
			$result['badge']['color'] = 'red';
			$result['description']    = sprintf(
				'<p>%s</p>',
				esc_html__( 'The rules that send direct requests for password-protected files to the password prompt are missing from, or out of date in, the uploads .htaccess file. This usually means the file is not writable. Until it is fixed, anyone who has a protected file\'s uploads URL can download it without the password.', 'protect-uploads' )
			);
			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->get_settings_url() ),
				esc_html__( 'Make the uploads .htaccess file writable, then save the Protect Uploads settings', 'protect-uploads' )
			);
		}

		return $result;
	}

	/**
	 * Direct test: report the detected server configuration (Apache/Nginx).
	 *
	 * @since 0.7.0
	 * @return array A Site Health result array.
	 */
	public function test_server_config() {
		$server = $this->detect_server();

		$result = array(
			'label'   => __( 'Server configuration detected', 'protect-uploads' ),
			'status'  => 'good',
			'badge'   => array(
				'label' => __( 'Security', 'protect-uploads' ),
				'color' => 'blue',
			),
			'actions' => $this->pro_link( true ),
			'test'    => 'protect_uploads_server_config',
		);

		if ( 'nginx' === $server ) {
			$result['label']       = __( 'Your server is running Nginx', 'protect-uploads' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'Nginx does not read .htaccess files, so .htaccess-based rules will not apply. Use the index.php protection method on the Protect Uploads settings screen, and make sure "autoindex" is off for your uploads directory in the Nginx configuration.', 'protect-uploads' )
			);
			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->get_settings_url() ),
				esc_html__( 'Open Protect Uploads settings', 'protect-uploads' )
			);
		} elseif ( 'apache' === $server ) {
			$result['label']       = __( 'Your server is running Apache', 'protect-uploads' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'Apache supports .htaccess rules, so both the index.php and .htaccess protection methods are available to you.', 'protect-uploads' )
			);
		} else {
			$result['label']       = __( 'Your server software could not be determined', 'protect-uploads' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'Protect Uploads could not detect your web server software. The index.php protection method works on any server and is the safest choice when the server type is unknown.', 'protect-uploads' )
			);
		}

		return $result;
	}

	/**
	 * Async test (direct runner): scan uploads for sensitive file types.
	 *
	 * @since 0.7.0
	 * @return array A Site Health result array.
	 */
	public function test_sensitive_files() {
		$found = $this->scan_for_sensitive_files();

		if ( empty( $found ) ) {
			return array(
				'label'       => __( 'No sensitive file types were found in your uploads', 'protect-uploads' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Security', 'protect-uploads' ),
					'color' => 'blue',
				),
				'description' => sprintf(
					'<p>%s</p>',
					esc_html__( 'Your uploads directory does not contain executable file types such as .php or .cgi.', 'protect-uploads' ) . $this->pro_sentence()
				),
				'actions'     => $this->pro_link(),
				'test'        => 'protect_uploads_sensitive_files',
			);
		}

		$shown = array_slice( $found, 0, 5 );
		$list  = '';
		foreach ( $shown as $rel_path ) {
			$list .= sprintf( '<li><code>%s</code></li>', esc_html( $rel_path ) );
		}

		$description = sprintf(
			'<p>%s</p><ul>%s</ul>',
			esc_html__( 'Executable file types were found in your uploads directory. These should never be stored in uploads because, if reachable, they can be run on your server. Review and remove the following files:', 'protect-uploads' ),
			$list
		);

		if ( count( $found ) > count( $shown ) ) {
			$description .= sprintf(
				'<p>%s</p>',
				sprintf(
					/* translators: %d: number of additional files not listed. */
					esc_html( _n( 'And %d more file.', 'And %d more files.', count( $found ) - count( $shown ), 'protect-uploads' ) ),
					(int) ( count( $found ) - count( $shown ) )
				)
			);
		}

		return array(
			'label'       => __( 'Sensitive file types were found in your uploads', 'protect-uploads' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => __( 'Security', 'protect-uploads' ),
				'color' => 'red',
			),
			'description' => $description,
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->get_settings_url() ),
				esc_html__( 'Open Protect Uploads settings', 'protect-uploads' )
			),
			'test'        => 'protect_uploads_sensitive_files',
		);
	}

	/**
	 * AJAX handler for the asynchronous sensitive-files test.
	 *
	 * Mirrors WordPress core's own async Site Health endpoints: it verifies the
	 * Site Health nonce and the user's capability, then returns the same result
	 * array as the direct runner.
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function ajax_sensitive_files() {
		check_ajax_referer( 'health-check-site-status' );

		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}

		wp_send_json_success( $this->test_sensitive_files() );
	}

	/**
	 * Determine whether the uploads directory is protected from directory listing.
	 *
	 * Detection mirrors the plugin's existing logic without making remote HTTP
	 * requests: an index.php / index.html in the uploads root, or a plugin
	 * generated .htaccess, counts as protection. We avoid the admin class's
	 * remote 403 probe here because Site Health tests should be fast and must
	 * not depend on outbound HTTP to the site itself.
	 *
	 * @since 0.7.0
	 * @return bool True when protection is detected.
	 */
	private function is_uploads_browsing_protected() {
		$basedir = $this->get_uploads_basedir();

		if ( '' === $basedir || ! is_dir( $basedir ) ) {
			return false;
		}

		if ( file_exists( $basedir . '/index.php' ) || file_exists( $basedir . '/index.html' ) ) {
			return true;
		}

		$htaccess = $basedir . '/.htaccess';
		if ( file_exists( $htaccess ) && is_readable( $htaccess ) ) {
			$contents = file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $contents && $this->htaccess_blocks_browsing( $contents ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether an uploads .htaccess blocks directory browsing.
	 *
	 * Recognises two protection styles:
	 *   1. The classic "Options -Indexes" directive.
	 *   2. Protect Uploads Pro's rewrite-based routing, where every request is
	 *      sent through WordPress (RewriteRule ... index.php?pup_direct_file=...,
	 *      or any RewriteEngine On + RewriteRule that routes to index.php). When
	 *      requests are funnelled through PHP the server never serves a raw
	 *      directory listing, so this counts as protection.
	 *
	 * @since 0.7.0
	 * @param string $contents The .htaccess file contents.
	 * @return bool True when the rules prevent directory browsing.
	 */
	private function htaccess_blocks_browsing( $contents ) {
		// The password rules route single files, not folders: ignore them.
		$contents = (string) preg_replace( '/^# BEGIN ' . preg_quote( Alti_ProtectUploads_Password_Rules::MARKER, '/' ) . '\r?\n.*?^# END ' . preg_quote( Alti_ProtectUploads_Password_Rules::MARKER, '/' ) . '[ \t]*$/ms', '', $contents );

		if ( false !== stripos( $contents, 'Options -Indexes' ) ) {
			return true;
		}

		// Pro's rewrite-based protection routes file requests through WordPress.
		if ( false !== stripos( $contents, 'pup_direct_file' ) ) {
			return true;
		}

		// Generic rewrite rule that routes requests to index.php (the request
		// can never resolve to a static directory listing).
		if ( false !== stripos( $contents, 'RewriteEngine On' )
			&& preg_match( '/RewriteRule\s+.*index\.php/i', $contents ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Scan the uploads directory for sensitive file types.
	 *
	 * Bounded by SCAN_MAX_DEPTH and SCAN_MAX_FILES so the test stays fast even
	 * on large media libraries. Returns paths relative to the uploads basedir.
	 *
	 * @since 0.7.0
	 * @return string[] Relative paths of offending files (may be empty).
	 */
	private function scan_for_sensitive_files() {
		$basedir = $this->get_uploads_basedir();

		if ( '' === $basedir || ! is_dir( $basedir ) ) {
			return array();
		}

		$basedir = rtrim( $basedir, '/\\' );
		$found   = array();
		$seen    = 0;

		// Breadth-first traversal with an explicit queue of [path, depth].
		$queue = array( array( $basedir, 0 ) );

		while ( ! empty( $queue ) ) {
			list( $dir, $depth ) = array_shift( $queue );

			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$path = $dir . '/' . $entry;

				if ( is_dir( $path ) ) {
					if ( $depth < self::SCAN_MAX_DEPTH ) {
						$queue[] = array( $path, $depth + 1 );
					}
					continue;
				}

				++$seen;

				$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
				// Empty "Silence is golden" index.php files are what both this
				// plugin and Protect Uploads Pro write to block folder listings;
				// flagging them would tell owners to delete their protection.
				if ( in_array( $ext, self::RISKY_EXTENSIONS, true ) && ! self::is_inert_php_stub( $path ) ) {
					$rel     = ltrim( substr( $path, strlen( $basedir ) ), '/\\' );
					$found[] = '' === $rel ? $entry : $rel;
				}

				if ( $seen >= self::SCAN_MAX_FILES ) {
					return $found;
				}
			}
		}

		return $found;
	}

	/**
	 * Detect the web server software.
	 *
	 * Mirrors Alti_ProtectUploads_Admin::is_nginx() and extends it to also
	 * recognise Apache, returning one of 'nginx', 'apache' or 'unknown'.
	 *
	 * @since 0.7.0
	 * @return string One of 'nginx', 'apache', or 'unknown'.
	 */
	private function detect_server() {
		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return 'unknown';
		}

		$software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );

		if ( false !== stripos( $software, 'nginx' ) ) {
			return 'nginx';
		}

		if ( false !== stripos( $software, 'apache' ) ) {
			return 'apache';
		}

		return 'unknown';
	}

	/**
	 * Get the uploads base directory.
	 *
	 * @since 0.7.0
	 * @return string Absolute path to the uploads basedir, or '' on failure.
	 */
	private function get_uploads_basedir() {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return $uploads['basedir'];
	}

	/**
	 * URL of the plugin's settings page.
	 *
	 * @since 0.7.0
	 * @return string
	 */
	private function get_settings_url() {
		return admin_url( 'upload.php?page=protect-uploads-settings-page' );
	}

	/**
	 * The Pro sentence appended to passing tests, or '' when Pro is present:
	 * customers who already have Pro are not pitched it.
	 *
	 * @since 0.8.0
	 * @return string Escaped sentence with a leading space, or ''.
	 */
	/**
	 * Whether a file is a small PHP stub containing nothing but comments, such as
	 * the "Silence is golden" index.php files written to block folder listings.
	 * Any executable code, inline HTML or a file over 1 KB is not a stub.
	 *
	 * @since 0.8.0
	 * @param string    $path          Absolute file path.
	 * @param bool|null $use_tokenizer Force the tokenizer on or off (tests); null = auto.
	 * @return bool True only for a comment-only PHP file.
	 */
	public static function is_inert_php_stub( $path, $use_tokenizer = null ) {
		if ( ! is_file( $path ) || filesize( $path ) > 1024 ) {
			return false;
		}
		$code = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, size-capped above.
		if ( false === $code || 0 !== strpos( ltrim( $code ), '<?php' ) ) {
			return false;
		}
		if ( null === $use_tokenizer ) {
			$use_tokenizer = function_exists( 'token_get_all' );
		}
		if ( $use_tokenizer ) {
			$allowed = array( T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_CLOSE_TAG );
			foreach ( token_get_all( $code ) as $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], $allowed, true ) ) {
					return false;
				}
			}
			return true;
		}
		// No tokenizer: strip the open/close tags and comments; anything left is code.
		$rest = preg_replace( array( '/^\s*<\?php/', '/\?>\s*$/', '#/\*.*?\*/#s', '#//[^\n]*#', '/#[^\n]*/' ), '', $code );
		return null !== $rest && '' === trim( $rest );
	}

	private function pro_sentence() {
		if ( Alti_ProtectUploads_Upsell::is_pro_present() ) {
			return '';
		}
		return ' ' . esc_html__( 'Protect Uploads Pro adds Secure Send, a Client Portal, hotlink protection, logo watermarks and role-based access.', 'protect-uploads' );
	}

	/**
	 * The "Learn more about Protect Uploads Pro" action, or '' when Pro is present.
	 *
	 * @since 0.8.0
	 * @param bool $with_sentence Put the Pro sentence before the link, for tests whose description is technical.
	 * @return string HTML for the test's actions, or ''.
	 */
	private function pro_link( $with_sentence = false ) {
		if ( Alti_ProtectUploads_Upsell::is_pro_present() ) {
			return '';
		}
		return sprintf(
			'<p>%s<a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
			$with_sentence ? ltrim( $this->pro_sentence() ) . ' ' : '',
			esc_url( Alti_ProtectUploads_Upsell::upgrade_url( 'site-health' ) ),
			esc_html__( 'Learn more about Protect Uploads Pro', 'protect-uploads' )
		);
	}
}
