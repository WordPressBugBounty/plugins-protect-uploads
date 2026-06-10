<?php
/**
 * Pro upgrade hints (upsell) for the free Protect Uploads plugin.
 *
 * Renders unobtrusive, wp.org-guideline-safe upgrade hints pointing at
 * Protect Uploads Pro. Every surface registered here is confined to the
 * plugin's own admin screens, with the only exceptions being a single
 * plugins-row action link and one one-time admin notice. When the Pro plugin
 * is present (active or its license class is loaded), this class registers
 * nothing at all.
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
 * Pro upgrade hints (upsell).
 *
 * @since 0.7.0
 */
class Alti_ProtectUploads_Upsell {

	/**
	 * The plugin slug / text domain.
	 *
	 * @since 0.7.0
	 * @var   string
	 */
	private $plugin_name;

	/**
	 * The plugin version (used for asset cache-busting).
	 *
	 * @since 0.7.0
	 * @var   string
	 */
	private $version;

	/**
	 * User-meta key recording that the settings-page banner was dismissed.
	 *
	 * @since 0.7.0
	 * @var   string
	 */
	const BANNER_DISMISSED_META = 'protect_uploads_upsell_banner_dismissed';

	/**
	 * Site option flag recording that the one-time notice was dismissed.
	 *
	 * @since 0.7.0
	 * @var   string
	 */
	const NOTICE_DISMISSED_OPTION = 'protect_uploads_upsell_notice_dismissed';

	/**
	 * Constructor.
	 *
	 * @since 0.7.0
	 * @param string $plugin_name The plugin slug / text domain.
	 * @param string $version     The plugin version.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register hooks -- but only when Pro is NOT present.
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function init() {
		// If Pro is present in any form, register absolutely nothing.
		if ( self::is_pro_present() ) {
			return;
		}

		// One-time admin notice (upload.php / plugins.php / plugin settings screen).
		add_action( 'admin_notices', array( $this, 'render_one_time_notice' ) );

		// AJAX dismiss handlers.
		add_action( 'wp_ajax_protect_uploads_dismiss_banner', array( $this, 'ajax_dismiss_banner' ) );
		add_action( 'wp_ajax_protect_uploads_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );

		// Inline JS for AJAX dismissals (only on relevant screens).
		add_action( 'admin_footer', array( $this, 'print_dismiss_script' ) );
	}

	/**
	 * Determine whether Protect Uploads Pro is present.
	 *
	 * Pro is considered present if its license class is loaded OR the Pro
	 * plugin is active. When present, the free plugin shows no upsell at all.
	 *
	 * @since 0.7.0
	 * @return bool True when Pro is present, false otherwise.
	 */
	public static function is_pro_present() {
		if ( class_exists( 'Protect_Uploads_Pro_License' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'protect-uploads-pro/protect-uploads-pro.php' );
	}

	/**
	 * Build a UTM-tagged upgrade URL for a given placement.
	 *
	 * @since 0.7.0
	 * @param string $placement The placement identifier (banner, inline-*, plugins-row, notice).
	 * @return string The full URL with UTM query args.
	 */
	private function upgrade_url( $placement ) {
		return add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => $placement,
				'utm_campaign' => 'free-to-pro',
			),
			'https://protectuploads.com/'
		);
	}

	/**
	 * Whether the current user has dismissed the settings-page banner.
	 *
	 * @since 0.7.0
	 * @return bool
	 */
	private function banner_is_dismissed() {
		return (bool) get_user_meta( get_current_user_id(), self::BANNER_DISMISSED_META, true );
	}

	/**
	 * Whether the one-time notice has been dismissed for this site.
	 *
	 * @since 0.7.0
	 * @return bool
	 */
	private function notice_is_dismissed() {
		return (bool) get_option( self::NOTICE_DISMISSED_OPTION, false );
	}

	/**
	 * Render the settings-page banner (or, when dismissed, the quiet recovery link).
	 *
	 * Called directly from the admin settings page renderer. Does nothing when
	 * Pro is present.
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function render_settings_banner() {
		if ( self::is_pro_present() ) {
			return;
		}

		if ( $this->banner_is_dismissed() ) {
			// Collapsed, quiet, recoverable link rendered at the bottom of the page.
			return;
		}

		$bullets = array(
			__( 'Image watermarks with 9 positions', 'protect-uploads' ),
			__( 'Expiring and single-use passwords', 'protect-uploads' ),
			__( 'Role-based access with server enforcement', 'protect-uploads' ),
			__( 'Download analytics and hotlink blocking', 'protect-uploads' ),
		);
		?>
		<div class="protect-uploads-upsell-banner" id="protect-uploads-upsell-banner">
			<button type="button" class="protect-uploads-upsell-dismiss" id="protect-uploads-upsell-banner-dismiss" aria-label="<?php esc_attr_e( 'Dismiss this upgrade notice', 'protect-uploads' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
			<div class="protect-uploads-upsell-banner-icon">
				<span class="dashicons dashicons-shield"></span>
			</div>
			<div class="protect-uploads-upsell-banner-body">
				<h2 class="protect-uploads-upsell-headline"><?php esc_html_e( 'Protect More. Control Everything.', 'protect-uploads' ); ?></h2>
				<p class="protect-uploads-upsell-subline"><?php esc_html_e( 'Upgrade to Pro and get the file protection features serious creators actually need.', 'protect-uploads' ); ?></p>
				<ul class="protect-uploads-upsell-bullets">
					<?php foreach ( $bullets as $bullet ) : ?>
						<li><span class="dashicons dashicons-yes"></span> <?php echo esc_html( $bullet ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="protect-uploads-upsell-cta">
					<a href="<?php echo esc_url( $this->upgrade_url( 'banner' ) ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Upgrade to Pro', 'protect-uploads' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
		$this->print_banner_styles();
	}

	/**
	 * Render the quiet recovery link shown at the bottom of the settings page
	 * once the banner has been dismissed.
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function render_settings_footer_link() {
		if ( self::is_pro_present() ) {
			return;
		}

		if ( ! $this->banner_is_dismissed() ) {
			return;
		}
		?>
		<p class="protect-uploads-upsell-quiet-link" style="margin-top:20px;">
			<a href="<?php echo esc_url( $this->upgrade_url( 'banner' ) ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Upgrade to Protect Uploads Pro &rarr;', 'protect-uploads' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render a contextual one-liner under a settings section.
	 *
	 * Intended to be called from the admin settings renderer for the watermark,
	 * password, and directory-protection areas. Keeps the admin-class edits to a
	 * single method call per section.
	 *
	 * @since 0.7.0
	 * @param string $section One of: watermark, passwords, protection.
	 * @return void
	 */
	public function render_inline_hint( $section ) {
		if ( self::is_pro_present() ) {
			return;
		}

		$map = array(
			'watermark'  => array(
				'placement' => 'inline-watermark',
				'text'      => __( 'Pro adds image watermarks with 9 placement positions, custom opacity, and one-click bulk re-watermarking across your library.', 'protect-uploads' ),
			),
			'passwords'  => array(
				'placement' => 'inline-passwords',
				'text'      => __( 'Pro unlocks expiring, single-use, and limited-use passwords plus brute-force rate limiting for stronger file security.', 'protect-uploads' ),
			),
			'protection' => array(
				'placement' => 'inline-protection',
				'text'      => __( 'Pro enforces access at the server level with role-based rules and rotating-token hotlink protection.', 'protect-uploads' ),
			),
		);

		if ( ! isset( $map[ $section ] ) ) {
			return;
		}

		$hint = $map[ $section ];
		?>
		<p class="description protect-uploads-upsell-inline">
			<span class="dashicons dashicons-lock" aria-hidden="true"></span>
			<?php echo esc_html( $hint['text'] ); ?>
			<a href="<?php echo esc_url( $this->upgrade_url( $hint['placement'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'protect-uploads' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Add the colored "Upgrade to Pro" link to the plugin's action links row.
	 *
	 * @since 0.7.0
	 * @param array $links The existing action links.
	 * @return array The filtered action links.
	 */
	public function add_plugins_row_link( $links ) {
		if ( self::is_pro_present() ) {
			return $links;
		}

		$upgrade_link = '<a href="' . esc_url( $this->upgrade_url( 'plugins-row' ) ) . '" target="_blank" rel="noopener noreferrer" style="color:#f7a933;font-weight:bold;">'
			. esc_html__( 'Upgrade to Pro', 'protect-uploads' )
			. '</a>';

		$links[] = $upgrade_link;

		return $links;
	}

	/**
	 * Render the one-time dismissible notice on relevant screens.
	 *
	 * Shown only on upload.php, plugins.php, and the plugin's settings page, and
	 * only once per site (controlled by an option flag set on dismiss).
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function render_one_time_notice() {
		if ( self::is_pro_present() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->notice_is_dismissed() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array(
			'upload',                                       // upload.php
			'plugins',                                      // plugins.php
			'media_page_' . $this->plugin_name . '-settings-page', // plugin settings page
		);

		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible protect-uploads-upsell-notice" id="protect-uploads-upsell-notice" data-nonce="<?php echo esc_attr( wp_create_nonce( 'protect_uploads_dismiss_notice' ) ); ?>">
			<p>
				<span class="dashicons dashicons-shield" style="color:#f7a933;vertical-align:middle;"></span>
				<?php esc_html_e( 'Protect Uploads Pro adds image watermarking, expiring links, analytics, and server-level access control for photographers and creators.', 'protect-uploads' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $this->upgrade_url( 'notice' ) ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Learn More', 'protect-uploads' ); ?>
				</a>
				<a href="#" class="protect-uploads-upsell-notice-dismiss" style="margin-left:8px;">
					<?php esc_html_e( 'Dismiss', 'protect-uploads' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX handler: dismiss the settings-page banner (per user).
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function ajax_dismiss_banner() {
		check_ajax_referer( 'protect_uploads_dismiss_banner', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'protect-uploads' ) ), 403 );
		}

		update_user_meta( get_current_user_id(), self::BANNER_DISMISSED_META, 1 );

		wp_send_json_success();
	}

	/**
	 * AJAX handler: dismiss the one-time notice (per site).
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function ajax_dismiss_notice() {
		check_ajax_referer( 'protect_uploads_dismiss_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'protect-uploads' ) ), 403 );
		}

		update_option( self::NOTICE_DISMISSED_OPTION, 1 );

		wp_send_json_success();
	}

	/**
	 * Print the inline styles for the settings-page banner.
	 *
	 * Kept small and scoped; uses native-WP styling with a brand accent.
	 *
	 * @since 0.7.0
	 * @return void
	 */
	private function print_banner_styles() {
		?>
		<style>
			.protect-uploads-upsell-banner {
				position: relative;
				display: flex;
				gap: 16px;
				align-items: flex-start;
				background: #fff;
				border: 1px solid #dcdcde;
				border-left: 4px solid #f7a933;
				border-radius: 4px;
				padding: 16px 20px;
				margin: 16px 0 24px;
				box-shadow: 0 1px 1px rgba(0,0,0,0.04);
			}
			.protect-uploads-upsell-banner-icon .dashicons {
				color: #f7a933;
				font-size: 40px;
				width: 40px;
				height: 40px;
			}
			.protect-uploads-upsell-banner-body { flex: 1; }
			.protect-uploads-upsell-headline {
				margin: 0 0 4px;
				font-size: 18px;
				line-height: 1.3;
			}
			.protect-uploads-upsell-subline {
				margin: 0 0 10px;
				color: #50575e;
				font-size: 13px;
			}
			.protect-uploads-upsell-bullets {
				margin: 0 0 12px;
				display: flex;
				flex-wrap: wrap;
				gap: 4px 24px;
				list-style: none;
			}
			.protect-uploads-upsell-bullets li {
				margin: 0;
				flex: 0 0 calc(50% - 24px);
				min-width: 220px;
				font-size: 13px;
				color: #1d2327;
			}
			.protect-uploads-upsell-bullets .dashicons {
				color: #f7a933;
				font-size: 18px;
				width: 18px;
				height: 18px;
				vertical-align: text-bottom;
			}
			.protect-uploads-upsell-cta { margin: 0; }
			.protect-uploads-upsell-dismiss {
				position: absolute;
				top: 8px;
				right: 8px;
				background: none;
				border: none;
				cursor: pointer;
				color: #787c82;
				padding: 2px;
			}
			.protect-uploads-upsell-dismiss:hover { color: #d63638; }
			.protect-uploads-upsell-inline .dashicons-lock {
				font-size: 16px;
				width: 16px;
				height: 16px;
				vertical-align: text-bottom;
				color: #f7a933;
			}
		</style>
		<?php
	}

	/**
	 * Print the inline dismiss script on relevant screens.
	 *
	 * Handles AJAX dismissal for both the banner and the one-time notice. The
	 * banner collapse reveals the quiet recovery link on the next page load.
	 *
	 * @since 0.7.0
	 * @return void
	 */
	public function print_dismiss_script() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$relevant = array(
			'upload',
			'plugins',
			'media_page_' . $this->plugin_name . '-settings-page',
		);

		if ( ! in_array( $screen->id, $relevant, true ) ) {
			return;
		}

		$banner_nonce = wp_create_nonce( 'protect_uploads_dismiss_banner' );
		?>
		<script>
		( function() {
			var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			function post( action, nonce, cb ) {
				var data = new URLSearchParams();
				data.append( 'action', action );
				data.append( 'nonce', nonce );
				fetch( ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: data.toString()
				} ).then( function() { if ( cb ) { cb(); } } );
			}

			// Settings-page banner dismiss.
			var bannerBtn = document.getElementById( 'protect-uploads-upsell-banner-dismiss' );
			if ( bannerBtn ) {
				bannerBtn.addEventListener( 'click', function() {
					var banner = document.getElementById( 'protect-uploads-upsell-banner' );
					if ( banner ) { banner.style.display = 'none'; }
					post( 'protect_uploads_dismiss_banner', <?php echo wp_json_encode( $banner_nonce ); ?> );
				} );
			}

			// One-time notice dismiss (core "X" button and the inline "Dismiss" link).
			var notice = document.getElementById( 'protect-uploads-upsell-notice' );
			if ( notice ) {
				var noticeNonce = notice.getAttribute( 'data-nonce' );
				var sendNoticeDismiss = function() {
					post( 'protect_uploads_dismiss_notice', noticeNonce );
				};
				// Core renders the dismiss "X" button after DOM load; delegate.
				notice.addEventListener( 'click', function( e ) {
					if ( e.target.closest( '.notice-dismiss' ) ) {
						sendNoticeDismiss();
					}
					var dismissLink = e.target.closest( '.protect-uploads-upsell-notice-dismiss' );
					if ( dismissLink ) {
						e.preventDefault();
						notice.style.display = 'none';
						sendNoticeDismiss();
					}
				} );
			}
		} )();
		</script>
		<?php
	}
}
