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
 * @author     Your Name <email@example.com>
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
		}
	}

	/**
	 * Handle image upload to add watermark
	 *
	 * @since    0.5.2
	 * @param    array $upload    Upload file information.
	 * @return   array
	 */
	public function handle_image_upload( $upload ) {
		// Only process if it's an image and watermarking is enabled
		if ( ! $this->is_image_file( $upload['file'] ) || empty( $this->settings['enable_watermark'] ) ) {
			return $upload;
		}

		// Try to add watermark
		$watermarked = $this->add_watermark( $upload['file'] );
		
		// If watermarking failed, log error but don't block upload
		if ( ! $watermarked ) {
			// error_log( 'Protect Uploads: Failed to add watermark to ' . $upload['file'] ); // Removed error log
		}

		// Always return the original upload array to allow the upload to complete
		return $upload;
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
			// error_log( 'Protect Uploads: GD library not available' ); // Removed error log
			return false;
		}

		$image_info = getimagesize( $image_path );
		if ( false === $image_info ) {
			// error_log( 'Protect Uploads: Could not get image size for ' . $image_path ); // Removed error log
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
				// error_log( 'Protect Uploads: Unsupported image type for ' . $image_path ); // Removed error log
				return false;
		}

		if ( ! $image ) {
			// error_log( 'Protect Uploads: Failed to create image resource for ' . $image_path ); // Removed error log
			return false;
		}

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
				// error_log( 'Protect Uploads: Font file not readable: ' . $font_path ); // Removed error log
				$use_ttf = false;
			}
		}

		// Calculate text size and position
		if ( $use_ttf ) {
			$bbox = @imagettfbbox( $font_size, 0, $font_path, $watermark_text );
			if ( $bbox ) {
				$text_width = $bbox[2] - $bbox[0];
				$text_height = $bbox[1] - $bbox[7];
			} else {
				// error_log( 'Protect Uploads: Failed to calculate TTF text size, falling back to system font' ); // Removed error log
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
			@imagettftext( $image, $font_size, 0, $x + 2, $y + 2, $shadow_color, $font_path, $watermark_text );
			@imagettftext( $image, $font_size, 0, $x, $y, $text_color, $font_path, $watermark_text );
		} else {
			// Fallback to basic text if TTF is not available
			imagestring( $image, 5, $x + 2, $y + 2, $watermark_text, $shadow_color );
			imagestring( $image, 5, $x, $y, $watermark_text, $text_color );
		}

		// Save image with proper quality settings
		$result = false;
		switch ( $image_info[2] ) {
			case IMAGETYPE_JPEG:
				$result = imagejpeg( $image, $image_path, 90 );
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

		imagedestroy( $image );

		if ( ! $result ) {
			// error_log( 'Protect Uploads: Failed to save watermarked image: ' . $image_path ); // Removed error log
		}

		return $result;
	}
} 