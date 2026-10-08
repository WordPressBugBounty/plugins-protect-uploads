<?php
/**
 * Server rules that send direct requests for password-protected files to
 * the password prompt.
 *
 * Without these rules a password only changes the link WordPress prints:
 * the file itself stays downloadable at its /wp-content/uploads/ URL by
 * anyone who has or guesses that URL, including search engines.
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 * @since      0.8.0
 */
class Alti_ProtectUploads_Password_Rules {

	/**
	 * Marker name used for the BEGIN/END comments around the rules.
	 *
	 * @since 0.8.0
	 * @var   string
	 */
	const MARKER = 'Protect Uploads Passwords';

	/**
	 * Option holding the rules format last written.
	 *
	 * @since 0.8.0
	 * @var   string
	 */
	const OPTION_SYNCED = 'protect_uploads_password_rules_synced';

	/**
	 * Transient set when the uploads .htaccess file could not be written.
	 *
	 * @since 0.8.0
	 * @var   string
	 */
	const TRANSIENT_FAILED = 'protect_uploads_password_rules_failed';

	/**
	 * Marker of Protect Uploads Pro's catch-all rule. Ours goes after it.
	 *
	 * @since 0.8.0
	 * @var   string
	 */
	const PRO_MARKER = 'Protect Uploads Pro';

	/**
	 * Rules format. Bump when the rule text changes, so sites rewrite them.
	 *
	 * @since 0.8.0
	 * @var   int
	 */
	const RULES_VERSION = 3;

	/**
	 * Whether this server reads .htaccess rewrite rules (Apache, LiteSpeed).
	 *
	 * @since  0.8.0
	 * @return bool
	 */
	public static function server_supports_rules() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		return false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' );
	}

	/**
	 * Rewrite the rules to match the current passwords and settings.
	 *
	 * @since  0.8.0
	 * @return bool Whether the uploads .htaccess file is up to date.
	 */
	public static function sync() {
		$written = self::write_block( self::build_block() );

		if ( $written ) {
			// Autoloaded: maybe_sync_after_update() reads it on every request.
			update_option( self::OPTION_SYNCED, self::RULES_VERSION, true );
			delete_transient( self::TRANSIENT_FAILED );
		} else {
			// Retry in an hour rather than on every request, and let admins
			// know the direct URLs are not protected meanwhile.
			delete_option( self::OPTION_SYNCED );
			set_transient( self::TRANSIENT_FAILED, time(), HOUR_IN_SECONDS );
		}

		return $written;
	}

	/**
	 * The rule block for the current passwords and settings, or '' if no
	 * file needs one.
	 *
	 * @since  0.8.0
	 * @return string
	 */
	private static function build_block() {
		$settings = get_option( 'protect_uploads_settings', array() );
		if ( empty( $settings['enable_password_protection'] ) ) {
			return '';
		}

		$lines = array();
		// The gate path is absolute, so no RewriteBase is needed (an install
		// path with a space would make RewriteBase invalid). "%" and spaces
		// are escaped: mod_rewrite reads %N as a back-reference.
		$gate = self::escape_substitution( trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) . 'index.php' );

		// On a subdirectory multisite subsite an internal rewrite keeps the
		// /wp-content/uploads/sites/N/ request URI, so WordPress would load
		// the main site; send the visitor to the subsite's prompt instead.
		$flags = ( is_multisite() && ! is_subdomain_install() && ! is_main_site() ) ? 'NC,R=302,L' : 'NC,L';

		foreach ( self::protected_attachment_ids() as $attachment_id ) {
			foreach ( self::get_attachment_files( $attachment_id ) as $relative ) {
				// A control character in one line would make Apache reject the
				// whole file and fail every request in uploads with a 500.
				// WordPress strips them from uploaded names; imports may not.
				if ( preg_match( '/[\x00-\x1f\x7f]/', $relative ) ) {
					continue;
				}

				// The requested path rides along so the visitor gets the size
				// they asked for. [NC] because Apache matches paths
				// case-sensitively and photo.JPG would otherwise slip past.
				$path    = str_replace( array( '%2F', '%' ), array( '/', '\\%' ), rawurlencode( $relative ) );
				$lines[] = 'RewriteRule ^' . self::escape( $relative ) . '$ ' . $gate . '?protect_uploads_file=' . $attachment_id . '&protect_uploads_path=' . $path . ' [' . $flags . ']';
			}
		}

		if ( ! $lines ) {
			return '';
		}

		// RewriteOptions Inherit: a RewriteEngine in uploads/.htaccess would
		// otherwise switch off the site root's rewrite rules for everything
		// under uploads, including security plugins' "no PHP in uploads"
		// rules. Inherited rules run after ours; ours end with [L].
		return '# BEGIN ' . self::MARKER . "\n"
			. "<IfModule mod_rewrite.c>\n"
			. "RewriteEngine On\n"
			. "RewriteOptions Inherit\n"
			. implode( "\n", $lines ) . "\n"
			. "</IfModule>\n"
			. '# END ' . self::MARKER;
	}

	/**
	 * Whether the direct URLs of password-protected files are protected.
	 *
	 * @since  0.8.0
	 * @return string 'none' when no file needs rules, 'unsupported' when the
	 *                server does not read .htaccess, 'missing' when the rules
	 *                in the file are absent or out of date, 'ok' otherwise.
	 */
	public static function status() {
		$expected = self::build_block();
		if ( '' === $expected ) {
			return 'none';
		}

		if ( ! self::server_supports_rules() ) {
			return 'unsupported';
		}

		$path = self::htaccess_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$existing = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

		$existing = str_replace( "\r\n", "\n", $existing );
		$position = strpos( $existing, $expected );
		if ( false === $position ) {
			return 'missing';
		}

		// A catch-all uploads rule above ours that is not Pro 1.2's marked
		// block (for example Pro 1.1.3's, which does not check free-plugin
		// passwords) would send every file elsewhere before our rules run.
		$before = substr( $existing, 0, $position );
		if ( false !== strpos( $before, 'pup_direct_file' ) && false === strpos( $before, '# BEGIN ' . self::PRO_MARKER ) ) {
			return 'missing';
		}

		return 'ok';
	}

	/**
	 * Whether the last attempt to write the rules failed within the hour.
	 *
	 * @since  0.8.0
	 * @return bool
	 */
	public static function write_failed() {
		return false !== get_transient( self::TRANSIENT_FAILED );
	}

	/**
	 * Remove the rules, keeping everything else in the file.
	 *
	 * @since  0.8.0
	 * @return bool
	 */
	public static function remove() {
		delete_option( self::OPTION_SYNCED );
		return self::write_block( '' );
	}

	/**
	 * Re-sync once after an update, so existing passwords get their rules.
	 *
	 * @since 0.8.0
	 */
	public static function maybe_sync_after_update() {
		if ( self::RULES_VERSION !== (int) get_option( self::OPTION_SYNCED ) && ! self::write_failed() ) {
			self::sync();
		}
	}

	/**
	 * IDs of attachments that have at least one password.
	 *
	 * @since  0.8.0
	 * @return int[]
	 */
	private static function protected_attachment_ids() {
		global $wpdb;

		$table = $wpdb->prefix . 'protect_uploads_passwords';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, needs current data.
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, needs current data.
		return array_map( 'absint', $wpdb->get_col( "SELECT DISTINCT attachment_id FROM {$table}" ) );
	}

	/**
	 * Every file of an attachment, relative to the uploads directory: the
	 * file itself, the unscaled original, each generated size, the modern
	 * format copies listed in the metadata (WebP/AVIF "sources", as written
	 * by Performance Lab), and the copies WordPress keeps after an image is
	 * edited. The first entry is the attached file.
	 *
	 * @since  0.8.0
	 * @param  int $attachment_id Attachment ID.
	 * @return string[]
	 */
	public static function get_attachment_files( $attachment_id ) {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $file ) || '' === $file || preg_match( '#^(https?:)?//|^/#i', $file ) ) {
			return array();
		}

		$dir   = dirname( $file );
		$dir   = '.' === $dir ? '' : trailingslashit( $dir );
		$files = array( $file );

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . $meta['original_image'];
		}
		$entries = array( is_array( $meta ) ? $meta : array() );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$entries = array_merge( $entries, array_filter( $meta['sizes'], 'is_array' ) );
		}
		foreach ( $entries as $index => $entry ) {
			// The top-level entry's "file" is the attached file, already listed.
			if ( $index > 0 && ! empty( $entry['file'] ) && is_string( $entry['file'] ) ) {
				$files[] = $dir . wp_basename( $entry['file'] );
			}
			if ( ! empty( $entry['sources'] ) && is_array( $entry['sources'] ) ) {
				foreach ( $entry['sources'] as $source ) {
					if ( ! empty( $source['file'] ) && is_string( $source['file'] ) ) {
						$files[] = $dir . wp_basename( $source['file'] );
					}
				}
			}
		}

		$backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backups ) ) {
			foreach ( $backups as $backup ) {
				if ( ! empty( $backup['file'] ) && is_string( $backup['file'] ) ) {
					$files[] = $dir . wp_basename( $backup['file'] );
				}
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Escape a path for use as a mod_rewrite pattern.
	 *
	 * @since  0.8.0
	 * @param  string $path Relative file path.
	 * @return string
	 */
	private static function escape( $path ) {
		return str_replace( ' ', '\\ ', preg_quote( $path, '#' ) );
	}

	/**
	 * Escape a URL path for use as a mod_rewrite substitution.
	 *
	 * @since  0.8.0
	 * @param  string $path URL path.
	 * @return string
	 */
	private static function escape_substitution( $path ) {
		return str_replace( array( '%', ' ' ), array( '\\%', '\\ ' ), $path );
	}

	/**
	 * Path to the uploads .htaccess file.
	 *
	 * @since  0.8.0
	 * @return string
	 */
	private static function htaccess_path() {
		$upload_dir = wp_upload_dir( null, false );
		return $upload_dir['basedir'] . '/.htaccess';
	}

	/**
	 * Write the rule block into the uploads .htaccess file, or remove it when
	 * $block is empty. Everything else in the file is kept.
	 *
	 * @since  0.8.0
	 * @param  string $block Rule block, or '' to remove.
	 * @return bool
	 */
	private static function write_block( $block ) {
		$path = self::htaccess_path();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$existing = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		$content  = self::place_block( $existing, $block );

		if ( '' === $block && trim( $existing ) === $content ) {
			return true;
		}

		if ( '' === trim( $content ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- Runs during deactivation, where WP_Filesystem may need credentials; failure is reported by the return value.
			return ! file_exists( $path ) || @unlink( $path );
		}

		// Write a temporary file and rename it over the original: Apache reads
		// .htaccess on every request, and a half-written file (an unclosed
		// IfModule) would fail requests with a 500 while it is being written.
		$temp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Runs during activation and deactivation, where WP_Filesystem may need credentials; failure is reported by the return value.
		if ( false === @file_put_contents( $temp, $content . "\n" ) ) {
			return false;
		}
		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort: keep the file's permissions.
			@chmod( $temp, fileperms( $path ) & 0777 );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic replace; failure is reported by the return value.
		if ( @rename( $temp, $path ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- Clean up the temporary file.
		@unlink( $temp );
		return false;
	}

	/**
	 * The .htaccess content with the rule block replaced, without its
	 * trailing newline.
	 *
	 * The block goes at the top, or straight after Protect Uploads Pro's
	 * block when there is one: Pro routes every file through WordPress and
	 * sends password-protected ones to our prompt itself, and Pro rewrites
	 * its block to the top whenever it saves.
	 *
	 * @since  0.8.0
	 * @param  string $existing Current file content.
	 * @param  string $block    Rule block, or '' to remove.
	 * @return string
	 */
	private static function place_block( $existing, $block ) {
		$rest = trim( (string) preg_replace( '/^# BEGIN ' . preg_quote( self::MARKER, '/' ) . '\r?\n.*?^# END ' . preg_quote( self::MARKER, '/' ) . '[ \t]*(\r?\n|$)/ms', '', $existing ) );

		if ( '' === $block ) {
			return $rest;
		}

		if ( preg_match( '/^# END ' . preg_quote( self::PRO_MARKER, '/' ) . '[ \t]*\r?$/m', $rest, $match, PREG_OFFSET_CAPTURE ) ) {
			$cut    = $match[0][1] + strlen( $match[0][0] );
			$before = rtrim( substr( $rest, 0, $cut ) );
			$after  = trim( substr( $rest, $cut ) );

			return $before . "\n\n" . $block . ( '' !== $after ? "\n\n" . $after : '' );
		}

		return $block . ( '' !== $rest ? "\n\n" . $rest : '' );
	}
}
