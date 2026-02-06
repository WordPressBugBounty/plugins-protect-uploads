<?php

class Alti_ProtectUploads
{

	/**
	 * The current version of the plugin.
	 *
	 * @since    0.1
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version = '0.6.0';
	protected $plugin_name;
	protected $loader;
	protected $settings;

	public function __construct()
	{
		$this->plugin_name = 'protect-uploads';
		$this->settings = get_option('protect_uploads_settings', array());

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	private function load_dependencies()
	{
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-protect-uploads-loader.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-protect-uploads-i18n.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-protect-uploads-admin.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-protect-uploads-image.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-protect-uploads-passwords.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-protect-uploads-frontend.php';

		$this->loader = new Alti_ProtectUploads_Loader();
	}

	/**
	 * set locale for translation ends.
	 */
	private function set_locale()
	{

		$plugin_i18n = new Alti_ProtectUploads_i18n();
		$plugin_i18n->set_domain($this->get_plugin_name());

		$this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
	}

	/**
	 * action and filter for admin side
	 */
	private function define_admin_hooks()
	{
		$plugin_admin = new Alti_ProtectUploads_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_menu', $plugin_admin, 'add_submenu_page' );
		
		// Only hook save_settings on the plugin's settings page
		$this->loader->add_action( 'load-media_page_' . $this->plugin_name . '-settings-page', $plugin_admin, 'save_settings' );
		
		$this->loader->add_filter( 'plugin_action_links_' . plugin_basename( plugin_dir_path( dirname( __FILE__ ) ) . $this->plugin_name . '.php' ), $plugin_admin, 'add_settings_link' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );

		// Initialize password protection in admin
		if ( ! empty( $this->settings['enable_password_protection'] ) ) {
			$passwords = new Alti_ProtectUploads_Passwords();
			$passwords->init();
			$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_password_scripts' );
		}
	}

	private function define_public_hooks() {
		// Initialize image protection.
		$image_protection = new Alti_ProtectUploads_Image();
		$image_protection->init();

		// Initialize password protection.
		$frontend = new Alti_ProtectUploads_Frontend();
		$frontend->init();

		// Add right-click protection if enabled.
		if ( ! empty( $this->settings['enable_right_click_protection'] ) ) {
			$this->loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_protection_scripts' );
		}
	}

	public function enqueue_protection_scripts() {
		wp_enqueue_script(
			$this->plugin_name . '-protection',
			plugin_dir_url(dirname(__FILE__)) . 'assets/js/protect-uploads.js',
			array('jquery'),
			$this->version,
			true
		);
	}

	/**
	 * Enqueue scripts for password protection functionality
	 */
	public function enqueue_password_scripts() {
		$screen = get_current_screen();
		if ( 'attachment' === $screen->id || 'upload' === $screen->id ) {
			wp_enqueue_script(
				$this->plugin_name . '-passwords',
				plugin_dir_url( dirname( __FILE__ ) ) . 'admin/js/protect-uploads-passwords.js',
				array( 'jquery' ),
				$this->version,
				true
			);

			wp_localize_script(
				$this->plugin_name . '-passwords',
				'protectUploadsPasswords',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'protect_uploads_password_action' ),
					'i18n' => array(
						'confirmDelete' => __( 'Are you sure you want to delete this password?', 'protect-uploads' ),
						'addingPassword' => __( 'Adding password...', 'protect-uploads' ),
						'deletingPassword' => __( 'Deleting password...', 'protect-uploads' ),
						'delete' => __( 'Delete', 'protect-uploads' ),
						'existingPasswords' => __( 'Existing Passwords', 'protect-uploads' ),
						'enterBothFields' => __( 'Please enter both a label and a password.', 'protect-uploads' ),
						'addPassword' => __( 'Add Password', 'protect-uploads' )
					)
				)
			);
		}
	}

	public function run()
	{
		$this->loader->run();
	}

	public function get_plugin_name()
	{
		return $this->plugin_name;
	}

	public function get_loader()
	{
		return $this->loader;
	}

	/**
	 * Returns the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version()
	{
		return $this->version;
	}

	/**
	 * Get default settings
	 *
	 * @since    0.5.2
	 * @return   array    Default settings.
	 */
	private function get_default_settings() {
		return array(
			'enable_watermark'     => false,
			'watermark_text'       => get_bloginfo( 'name' ),
			'watermark_position'   => 'bottom-right',
			'watermark_opacity'    => 50,
			'watermark_font_size'  => 'medium',
			'enable_right_click'   => false,
			'enable_password'      => false,
		);
	}
}
