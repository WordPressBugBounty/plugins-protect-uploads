<?php
/**
 * Handles image watermarking functionality
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 */

/**
 * The image watermarking functionality of the plugin.
 *
 * @package    Protect_Uploads
 * @subpackage Protect_Uploads/includes
 */
class Alti_ProtectUploads_Image {

	/**
	 * The plugin settings
	 *
	 * @since    0.5.2
	 * @access   private
	 * @var      array    $settings    The plugin settings.
	 */
	private $settings;

	/**
	 * Folder, inside uploads, holding the untouched copy of each image the
	 * watermark was drawn on.
	 *
	 * @since 0.8.0
	 * @var   string
	 */
	const ORIGINALS_DIR = 'protect-uploads-originals';

	/**
	 * Attachment meta key holding the path of the untouched copy, relative to
	 * the uploads directory.
	 *
	 * @since 0.8.0
	 * @var   string
	 */
	const ORIGINAL_META = '_protect_uploads_original';

	/**
	 * Untouched copies made during this request, keyed by uploaded file path,
	 * until the attachment they belong to exists.
	 *
	 * @since 0.8.0
	 * @var   string[]
	 */
	private $pending_originals = array();

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
		if ( ! empty( $this->settings['enable_watermark'] ) ) {
			add_filter( 'wp_handle_upload', array( $this, 'handle_image_upload' ) );
			add_action( 'add_attachment', array( $this, 'record_original' ) );
		}

		// Kept on when watermarking is turned off, so older copies still go.
		add_action( 'delete_attachment', array( $this, 'delete_original' ) );
	}

	/**
	 * Handle image upload to add watermark
	 *
	 * The watermark is drawn on the uploaded file itself, so an untouched copy
	 * is kept first in a folder the web server refuses to serve.
	 *
	 * @since    0.5.2
	 * @since    0.8.0 Keeps an untouched copy and skips animated GIFs.
	 * @param    array $upload    Upload file information.
	 * @return   array
	 */
	public function handle_image_upload( $upload ) {
		// Only process if it's an image and watermarking is enabled
		if ( empty( $upload['file'] ) || ! empty( $upload['error'] ) || ! $this->is_image_file( $upload['file'] ) || empty( $this->settings['enable_watermark'] ) ) {
			return $upload;
		}

		// GD keeps only the first frame, so the animation would be lost.
		if ( $this->is_animated_gif( $upload['file'] ) ) {
			return $upload;
		}

		$original = $this->keep_original( $upload['file'] );
		if ( false === $original ) {
			// Never change a file we could not back up.
			return $upload;
		}

		if ( $this->add_watermark( $upload['file'] ) ) {
			$this->pending_originals[ wp_normalize_path( $upload['file'] ) ] = $original;
		} else {
			$this->delete_copy( $original );
		}

		// Always return the original upload array to allow the upload to complete
		return $upload;
	}

	/**
	 * Store the untouched copy's path on the attachment created for the upload.
	 *
	 * @since 0.8.0
	 * @param int $attachment_id Attachment ID.
	 */
	public function record_original( $attachment_id ) {
		$file = get_attached_file( $attachment_id, true );
		$key  = $file ? wp_normalize_path( $file ) : '';

		if ( '' !== $key && isset( $this->pending_originals[ $key ] ) ) {
			update_post_meta( $attachment_id, self::ORIGINAL_META, $this->pending_originals[ $key ] );
			unset( $this->pending_originals[ $key ] );
		}
	}

	/**
	 * Delete the untouched copy along with its attachment.
	 *
	 * @since 0.8.0
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete_original( $attachment_id ) {
		$relative = get_post_meta( $attachment_id, self::ORIGINAL_META, true );
		if ( is_string( $relative ) && '' !== $relative ) {
			$this->delete_copy( $relative );
		}
	}

	/**
	 * Copy an uploaded file into the originals folder.
	 *
	 * The copy gets a random suffix: servers that ignore .htaccess (Nginx)
	 * would otherwise serve it at a guessable URL.
	 *
	 * @since  0.8.0
	 * @param  string $file Absolute path of the uploaded file.
	 * @return string|false Copy path relative to the uploads directory.
	 */
	private function keep_original( $file ) {
		$upload_dir = wp_upload_dir( null, false );
		$basedir    = wp_normalize_path( untrailingslashit( $upload_dir['basedir'] ) );
		$file       = wp_normalize_path( $file );

		if ( 0 !== strpos( $file, $basedir . '/' ) ) {
			return false;
		}

		$subdir = dirname( substr( $file, strlen( $basedir ) + 1 ) );
		$subdir = '.' === $subdir ? '' : $subdir . '/';
		$root   = $basedir . '/' . self::ORIGINALS_DIR;

		if ( ! wp_mkdir_p( $root . '/' . $subdir ) || ! $this->protect_originals_folder( $root ) ) {
			return false;
		}

		$info     = pathinfo( $file );
		$name     = $info['filename'] . '-' . strtolower( wp_generate_password( 12, false ) ) . ( isset( $info['extension'] ) ? '.' . $info['extension'] : '' );
		$relative = self::ORIGINALS_DIR . '/' . $subdir . $name;

		if ( ! @copy( $file, $basedir . '/' . $relative ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled.
			return false;
		}

		// Write an index.php into new year/month folders so they cannot be listed.
		$dir = $root;
		foreach ( array_filter( explode( '/', rtrim( $subdir, '/' ) ) ) as $part ) {
			$dir .= '/' . $part;
			$this->write_if_missing( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		return $relative;
	}

	/**
	 * Make sure the originals folder refuses web requests and cannot be listed.
	 *
	 * @since  0.8.0
	 * @param  string $root Absolute path of the originals folder.
	 * @return bool Whether the .htaccess file is in place.
	 */
	private function protect_originals_folder( $root ) {
		// Both syntaxes, each behind its module check: "Deny from all" alone is
		// a server error on Apache 2.4 without mod_access_compat.
		$htaccess = "# Protect Uploads: untouched copies of watermarked images. Not for public access.\n"
			. "<IfModule mod_authz_core.c>\n"
			. "\tRequire all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\tOrder allow,deny\n"
			. "\tDeny from all\n"
			. "</IfModule>\n";

		$this->write_if_missing( $root . '/index.php', "<?php\n// Silence is golden.\n" );

		return $this->write_if_missing( $root . '/.htaccess', $htaccess );
	}

	/**
	 * Write a file unless it already exists.
	 *
	 * @since  0.8.0
	 * @param  string $path    Absolute path.
	 * @param  string $content File content.
	 * @return bool Whether the file exists afterwards.
	 */
	private function write_if_missing( $path, $content ) {
		if ( file_exists( $path ) ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs inside the upload request; WP_Filesystem may need credentials.
		return false !== @file_put_contents( $path, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled by the caller.
	}

	/**
	 * Delete a copy from the originals folder, and nothing outside it.
	 *
	 * @since 0.8.0
	 * @param string $relative Copy path relative to the uploads directory.
	 */
	private function delete_copy( $relative ) {
		$upload_dir = wp_upload_dir( null, false );
		$root       = realpath( $upload_dir['basedir'] . '/' . self::ORIGINALS_DIR );
		$path       = realpath( $upload_dir['basedir'] . '/' . ltrim( $relative, '/' ) );

		if ( false !== $root && false !== $path && 0 === strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Whether a file is a GIF with more than one frame.
	 *
	 * @since  0.8.0
	 * @param  string $file File path.
	 * @return bool
	 */
	private function is_animated_gif( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$contents = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled by the caller.

		// Go by the content, not the name: a GIF renamed .jpg is still a GIF.
		if ( 0 !== strpos( $contents, 'GIF8' ) ) {
			return false;
		}

		// Each frame of an animation starts with a graphic control extension
		// (21 F9 04, four bytes, block terminator) before its image descriptor.
		return preg_match_all( '#\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $contents ) > 1;
	}

	/**
	 * Turn a JPEG upright according to its EXIF orientation.
	 *
	 * GD drops the EXIF data when it saves, so without this a photo taken in
	 * portrait would be stored, and shown, on its side.
	 *
	 * @since  0.8.0
	 * @param  resource|GdImage $image      Image.
	 * @param  string           $image_path Path to the JPEG.
	 * @return resource|GdImage|false The upright image, or false if the
	 *                                orientation cannot be read or applied.
	 */
	private function apply_exif_orientation( $image, $image_path ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			// Only a problem when the file has EXIF data to lose.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the JPEG header only.
			$head = (string) @file_get_contents( $image_path, false, null, 0, 65536 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled by the caller.
			return false === strpos( $head, "Exif\x00\x00" ) ? $image : false;
		}

		$exif        = @exif_read_data( $image_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt EXIF is common.
		$orientation = ( is_array( $exif ) && ! empty( $exif['Orientation'] ) ) ? (int) $exif['Orientation'] : 1;

		if ( $orientation < 2 || $orientation > 8 ) {
			return $image;
		}

		// Flip first (2, 4, 5, 7 are mirrored), then rotate counter-clockwise.
		if ( in_array( $orientation, array( 2, 4, 5, 7 ), true ) ) {
			if ( ! function_exists( 'imageflip' ) || ! imageflip( $image, IMG_FLIP_HORIZONTAL ) ) {
				return false;
			}
		}

		$angles = array( 3 => 180, 4 => 180, 5 => 90, 6 => 270, 7 => 270, 8 => 90 );
		if ( isset( $angles[ $orientation ] ) ) {
			$rotated = imagerotate( $image, $angles[ $orientation ], 0 );
			if ( ! $rotated ) {
				return false;
			}
			// GD images are freed automatically since PHP 8; imagedestroy() is deprecated in 8.5.
			if ( PHP_VERSION_ID < 80000 ) {
				imagedestroy( $image );
			}
			$image = $rotated;
		}

		return $image;
	}

	/**
	 * Check if file is an image
	 *
	 * @since    0.5.2
	 * @param    string $file    File path.
	 * @return   boolean
	 */
	private function is_image_file( $file ) {
		$image_types = array( 'image/jpeg', 'image/png', 'image/gif' );
		$file_type = wp_check_filetype( $file );
		return in_array( $file_type['type'], $image_types, true );
	}

	/**
	 * Add watermark to image
	 *
	 * @since    0.5.2
	 * @param    string $image_path    Path to image file.
	 * @return   boolean
	 */
	public function add_watermark( $image_path ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}

		$image_info = getimagesize( $image_path );
		if ( false === $image_info ) {
			return false;
		}

		// Create image resource based on file type.
		switch ( $image_info[2] ) {
			case IMAGETYPE_JPEG:
				$image = imagecreatefromjpeg( $image_path );
				break;
			case IMAGETYPE_PNG:
				$image = imagecreatefrompng( $image_path );
				if ( $image ) {
					imagealphablending( $image, true );
					imagesavealpha( $image, true );
				}
				break;
			case IMAGETYPE_GIF:
				$image = imagecreatefromgif( $image_path );
				break;
			default:
				return false;
		}

		if ( ! $image ) {
			return false;
		}

		if ( IMAGETYPE_JPEG === $image_info[2] ) {
			$upright = $this->apply_exif_orientation( $image, $image_path );
			if ( false === $upright ) {
				// Drawing on a sideways image and dropping its orientation
				// would store it on its side: leave the file alone.
				// GD images are freed automatically since PHP 8; imagedestroy() is deprecated in 8.5.
				if ( PHP_VERSION_ID < 80000 ) {
					imagedestroy( $image );
				}
				return false;
			}
			$image = $upright;
		}

		// Rotation can swap width and height.
		$image_info[0] = imagesx( $image );
		$image_info[1] = imagesy( $image );

		// Set up watermark text.
		$watermark_text = ! empty( $this->settings['watermark_text'] ) ? $this->settings['watermark_text'] : get_bloginfo( 'name' );
		
		// Calculate font size based on setting
		$font_size_percentage = 5; // Default to medium (5%)
		if ( ! empty( $this->settings['watermark_font_size'] ) ) {
			switch ( $this->settings['watermark_font_size'] ) {
				case 'small':
					$font_size_percentage = 3;
					break;
				case 'large':
					$font_size_percentage = 7;
					break;
				case 'medium':
				default:
					$font_size_percentage = 5;
					break;
			}
		}
		$font_size = (int) min( $image_info[0], $image_info[1] ) * ($font_size_percentage / 100);
		
		// Try to load custom font, fallback to system font if not available
		$font_path = plugin_dir_path( dirname( __FILE__ ) ) . 'assets/fonts/OpenSans-Regular.ttf';
		$use_ttf = file_exists( $font_path );

		if ( $use_ttf ) {
			// Verify font is readable
			if ( ! is_readable( $font_path ) ) {
				$use_ttf = false;
			}
		}

		// Calculate text size and position
		if ( $use_ttf ) {
			$bbox = @imagettfbbox( $font_size, 0, $font_path, $watermark_text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled by the caller.
			if ( $bbox ) {
				$text_width = $bbox[2] - $bbox[0];
				$text_height = $bbox[1] - $bbox[7];
			} else {
				$text_width = imagefontwidth( 5 ) * strlen( $watermark_text );
				$text_height = imagefontheight( 5 );
				$use_ttf = false;
			}
		} else {
			$text_width = imagefontwidth( 5 ) * strlen( $watermark_text );
			$text_height = imagefontheight( 5 );
		}

		// Calculate position.
		$padding = 20;
		switch ( $this->settings['watermark_position'] ) {
			case 'top-left':
				$x = $padding;
				$y = $text_height + $padding;
				break;
			case 'top-right':
				$x = $image_info[0] - $text_width - $padding;
				$y = $text_height + $padding;
				break;
			case 'bottom-left':
				$x = $padding;
				$y = $image_info[1] - $padding;
				break;
			case 'center':
				$x = (int) (( $image_info[0] - $text_width ) / 2);
				$y = (int) (( $image_info[1] + $text_height ) / 2);
				break;
			case 'bottom-right':
			default:
				$x = $image_info[0] - $text_width - $padding;
				$y = $image_info[1] - $padding;
				break;
		}

		// Create watermark.
		$opacity = isset( $this->settings['watermark_opacity'] ) ? $this->settings['watermark_opacity'] : 50;
		$opacity = min( 100, max( 0, $opacity ) ); // Ensure opacity is between 0 and 100.
		$alpha = (int) (127 - ( $opacity * 1.27 )); // Convert percentage to alpha value (127 = transparent, 0 = opaque).

		$text_color = imagecolorallocatealpha( $image, 255, 255, 255, $alpha );
		$shadow_color = imagecolorallocatealpha( $image, 0, 0, 0, $alpha );

		// Add text with shadow effect.
		if ( $use_ttf ) {
			@imagettftext( $image, $font_size, 0, $x + 2, $y + 2, $shadow_color, $font_path, $watermark_text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled by the caller.
			@imagettftext( $image, $font_size, 0, $x, $y, $text_color, $font_path, $watermark_text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled by the caller.
		} else {
			// Fallback to basic text if TTF is not available
			imagestring( $image, 5, $x + 2, $y + 2, $watermark_text, $shadow_color );
			imagestring( $image, 5, $x, $y, $watermark_text, $text_color );
		}

		// Save image with proper quality settings
		$result = false;
		switch ( $image_info[2] ) {
			case IMAGETYPE_JPEG:
				/** This filter is documented in wp-includes/class-wp-image-editor.php */
				$result = imagejpeg( $image, $image_path, (int) apply_filters( 'jpeg_quality', 90, 'protect_uploads_watermark' ) );
				break;
			case IMAGETYPE_PNG:
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
				$result = imagepng( $image, $image_path, 9 );
				break;
			case IMAGETYPE_GIF:
				$result = imagegif( $image, $image_path );
				break;
		}

		// GD images are freed automatically since PHP 8; imagedestroy() is deprecated in 8.5.
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $image );
		}

		return $result;
	}
} 