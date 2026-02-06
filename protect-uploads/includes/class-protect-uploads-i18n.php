<?php
class Alti_ProtectUploads_i18n {

	/**
	 * The domain specified for this plugin.
	 * @var      string    $domain    The domain identifier for this plugin.
	 */
	private $domain;

	/**
	 * Load the plugin text domain for translation.
	 *
	 * Note: Since WordPress 4.6, translations are automatically loaded for plugins
	 * hosted on WordPress.org. This method is kept for backwards compatibility
	 * but no longer calls load_plugin_textdomain().
	 *
	 * @see https://make.wordpress.org/core/2016/07/06/i18n-improvements-in-4-6/
	 */
	public function load_plugin_textdomain() {
		// Translations are now automatically loaded by WordPress 4.6+
		// for plugins hosted on WordPress.org.
	}

	/**
	 * Set the domain equal to that of the specified domain.
	 * @param    string    $domain    The domain that represents the locale of this plugin.
	 */
	public function set_domain( $domain ) {
		$this->domain = $domain;
	}

}
