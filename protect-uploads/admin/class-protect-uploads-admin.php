<?php

class Alti_ProtectUploads_Admin
{

	private $plugin_name;
	private $version;
	private $messages = array();
	private $settings = array();

	/**
	 * Pro upgrade-hints (upsell) helper.
	 *
	 * @since 0.7.0
	 * @var   Alti_ProtectUploads_Upsell|null
	 */
	private $upsell = null;

	/**
	 * Transient caching the uploads-root loopback check.
	 *
	 * @since 0.8.0
	 * @var   string
	 */
	const ROOT_STATUS_TRANSIENT = 'protect_uploads_root_status';

	/**
	 * Per-user transient prefix holding messages across the post-save redirect.
	 *
	 * @since 0.8.0
	 * @var   string
	 */
	const MESSAGES_TRANSIENT = 'protect_uploads_messages_';

	/**
	 * Uploads-root response code checked during this request.
	 *
	 * @since 0.8.0
	 * @var   int|null
	 */
	private $root_response_code = null;

	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version = $version;

		// Define default settings
		$default_settings = array(
			'protection_method'             => 'index',
			'enable_watermark'              => false,
			'watermark_text'                => get_bloginfo('name'),
			'watermark_position'            => 'bottom-right',
			'watermark_opacity'             => 50,
			'watermark_font_size'           => 'medium', // Added default for font size
			'enable_right_click_protection' => false,
			'enable_password_protection'    => false
		);

		// Get stored settings
		$stored_settings = get_option('protect_uploads_settings');

		// Merge stored settings with defaults
		$this->settings = wp_parse_args( $stored_settings, $default_settings );
		
		// .htaccess does nothing on Nginx, so treat the method as index.php.
		// Only in memory: this runs on every request, and the next settings
		// save stores it.
		if ($this->is_nginx() && $this->settings['protection_method'] === 'htaccess') {
			$this->settings['protection_method'] = 'index';
		}
	}

	public function get_plugin_name()
	{
		return $this->plugin_name;
	}

	/**
	 * Inject the Pro upgrade-hints (upsell) helper.
	 *
	 * @since 0.7.0
	 * @param Alti_ProtectUploads_Upsell $upsell The upsell helper instance.
	 * @return void
	 */
	public function set_upsell( $upsell )
	{
		$this->upsell = $upsell;
	}

	/**
	 * Render an upsell surface if the helper is available.
	 *
	 * Thin wrapper so the settings renderer stays minimal and safe when the
	 * helper is absent (e.g. when Pro is present and no helper was injected).
	 *
	 * @since 0.7.0
	 * @param string $method The upsell method to call.
	 * @param mixed  $arg    Optional argument passed to the method.
	 * @return void
	 */
	private function render_upsell( $method, $arg = null )
	{
		if ( $this->upsell && method_exists( $this->upsell, $method ) ) {
			if ( null === $arg ) {
				$this->upsell->$method();
			} else {
				$this->upsell->$method( $arg );
			}
		}
	}

	public function add_submenu_page()
	{
		add_submenu_page('upload.php', __( 'Protect Uploads', 'protect-uploads' ), esc_html__( 'Protect Uploads', 'protect-uploads' ) . ' <span class="dashicons dashicons-shield-alt" style="font-size:15px;"></span>', 'manage_options', $this->plugin_name . '-settings-page', array($this, 'render_settings_page'));
	}

	public function render_settings_page()
	{
		// Get active tab - default to directory-protection
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter is only used for display, not for data processing
		$active_tab = isset($_GET['tab']) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'directory-protection';
		?>
<div class="wrap <?php echo esc_attr( $this->plugin_name ); ?>">
	<?php echo wp_kses_post( $this->display_messages() ); ?>
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

	<?php $this->render_upsell( 'render_settings_banner' ); ?>

	<h2 class="nav-tab-wrapper">
		<a href="?page=<?php echo esc_attr($this->plugin_name); ?>-settings-page&tab=directory-protection" class="nav-tab <?php echo $active_tab === 'directory-protection' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e('Directory Protection', 'protect-uploads'); ?>
		</a>
		<a href="?page=<?php echo esc_attr($this->plugin_name); ?>-settings-page&tab=image-protection" class="nav-tab <?php echo $active_tab === 'image-protection' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e('Image Protection', 'protect-uploads'); ?>
		</a>
	</h2>
	
	<form method="post" action="">
		<?php wp_nonce_field('submit_form', 'protect-uploads_nonce'); ?>
		
		<!-- Directory Protection Tab -->
		<div id="directory-protection" class="tab-content <?php echo $active_tab === 'directory-protection' ? 'active' : 'hidden'; ?>">
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e('Protection Method', 'protect-uploads'); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e('Protection Method', 'protect-uploads'); ?></legend>
							<label>
								<input type="radio" name="protection" value="index" <?php checked($this->settings['protection_method'], 'index'); ?>>
								<?php esc_html_e('Use index.php file', 'protect-uploads'); ?>
							</label>
							<p class="description"><?php esc_html_e('Create an index.php file on the root of your uploads directory and subfolders (two levels max). New year and month folders get one when the first file is uploaded to them.', 'protect-uploads'); ?></p>
							<br>
							<?php $is_nginx = $this->is_nginx(); ?>
							<label <?php echo $is_nginx ? 'class="disabled"' : ''; ?>>
								<input type="radio" name="protection" value="htaccess" <?php checked($this->settings['protection_method'], 'htaccess'); ?> <?php disabled($is_nginx); ?>>
								<?php esc_html_e('Use .htaccess file', 'protect-uploads'); ?>
							</label>
							<p class="description">
								<?php if ($is_nginx): ?>
									<span class="nginx-notice" style="color: #d63638;"><?php esc_html_e('Disabled: .htaccess files do not work with Nginx servers.', 'protect-uploads'); ?></span>
								<?php else: ?>
									<?php esc_html_e('Create .htaccess file at root level of uploads directory and returns 403 code (Forbidden Access).', 'protect-uploads'); ?>
								<?php endif; ?>
							</p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Directory Status', 'protect-uploads'); ?></th>
					<td>
						<div class="directory-status-table-wrapper">
							<table class="widefat directory-status-table">
								<thead>
									<tr>
										<th><?php esc_html_e('Directory', 'protect-uploads'); ?></th>
										<th><?php esc_html_e('Status', 'protect-uploads'); ?></th>
										<th><?php esc_html_e('Protection Method', 'protect-uploads'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$upload_folders = self::get_uploads_subdirectories();
									$basedir = wp_upload_dir()['basedir'];
									$unverified = false;

									foreach ($upload_folders as $dir) {
										$status = self::get_directory_status($dir);
										$rel_path = str_replace($basedir, '', $dir);
										$rel_path = empty($rel_path) ? '/' : $rel_path;
										$unverified = $unverified || 'unverified' === $status['status'];
										?>
										<tr>
											<td><?php echo esc_html($rel_path); ?></td>
											<td>
												<?php if ('protected' === $status['status']): ?>
													<span class="dashicons dashicons-yes-alt" style="color: green;"></span> <?php esc_html_e('Protected', 'protect-uploads'); ?>
												<?php elseif ('unverified' === $status['status']): ?>
													<span class="dashicons dashicons-editor-help" style="color: #996800;"></span> <?php esc_html_e('Could not verify', 'protect-uploads'); ?>
												<?php else: ?>
													<span class="dashicons dashicons-warning" style="color: red;"></span> <?php esc_html_e('Not Protected', 'protect-uploads'); ?>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html($status['method']); ?></td>
										</tr>
										<?php
									}
									?>
								</tbody>
							</table>
						</div>
						<p class="description">
							<?php esc_html_e('This table shows protection status for your uploads directory and subdirectories.', 'protect-uploads'); ?>
						</p>
						<?php if ($unverified): ?>
							<p class="description">
								<?php esc_html_e('Could not verify: this site could not request its own uploads URL, so it is unknown whether the server blocks directory listing in folders without an index file. The check runs again within an hour, or when you save these settings.', 'protect-uploads'); ?>
							</p>
						<?php endif; ?>
						<?php $this->render_upsell( 'render_inline_hint', 'protection' ); ?>
					</td>
				</tr>
			</table>
		</div>
		
		<!-- Image Protection Tab -->
		<div id="image-protection" class="tab-content <?php echo $active_tab === 'image-protection' ? 'active' : 'hidden'; ?>">
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e('Password Protection', 'protect-uploads'); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e('Password Protection', 'protect-uploads'); ?></legend>
							<label>
								<input type="checkbox" name="enable_password_protection" value="1" <?php checked($this->settings['enable_password_protection']); ?>>
								<?php esc_html_e('Enable password protection for media files', 'protect-uploads'); ?>
							</label>
							<p class="description"><?php esc_html_e('Allow setting passwords for individual media files', 'protect-uploads'); ?></p>
							<?php $this->render_upsell( 'render_inline_hint', 'passwords' ); ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Watermark', 'protect-uploads'); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e('Watermark', 'protect-uploads'); ?></legend>
							<label>
								<input type="checkbox" name="enable_watermark" value="1" <?php checked($this->settings['enable_watermark']); ?>>
								<?php esc_html_e('Enable watermark on uploaded images', 'protect-uploads'); ?>
							</label>
							<p class="description"><?php esc_html_e('Automatically add watermark to new image uploads', 'protect-uploads'); ?></p>
							<p class="description"><?php esc_html_e('The watermark is drawn on the uploaded file itself, so every image size WordPress makes from it carries the mark. An untouched copy of each original is kept in uploads/protect-uploads-originals/, which is blocked from web access on Apache and LiteSpeed. Animated GIFs are left unchanged.', 'protect-uploads'); ?></p>
							<?php $this->render_upsell( 'render_inline_hint', 'watermark' ); ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Watermark Text', 'protect-uploads'); ?></th>
					<td>
						<input type="text" name="watermark_text" value="<?php echo esc_attr($this->settings['watermark_text']); ?>" class="regular-text">
						<p class="description"><?php esc_html_e('Text to use as watermark', 'protect-uploads'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Watermark Position', 'protect-uploads'); ?></th>
					<td>
						<select name="watermark_position">
							<option value="top-left" <?php selected($this->settings['watermark_position'], 'top-left'); ?>><?php esc_html_e('Top Left', 'protect-uploads'); ?></option>
							<option value="top-right" <?php selected($this->settings['watermark_position'], 'top-right'); ?>><?php esc_html_e('Top Right', 'protect-uploads'); ?></option>
							<option value="bottom-left" <?php selected($this->settings['watermark_position'], 'bottom-left'); ?>><?php esc_html_e('Bottom Left', 'protect-uploads'); ?></option>
							<option value="bottom-right" <?php selected($this->settings['watermark_position'], 'bottom-right'); ?>><?php esc_html_e('Bottom Right', 'protect-uploads'); ?></option>
							<option value="center" <?php selected($this->settings['watermark_position'], 'center'); ?>><?php esc_html_e('Center', 'protect-uploads'); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Watermark Opacity', 'protect-uploads'); ?></th>
					<td>
						<input type="range" name="watermark_opacity" value="<?php echo esc_attr($this->settings['watermark_opacity']); ?>" min="0" max="100" step="10">
						<span class="opacity-value"><?php echo esc_html($this->settings['watermark_opacity']); ?>%</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Watermark Font Size', 'protect-uploads'); ?></th>
					<td>
						<select name="watermark_font_size">
							<option value="small" <?php selected($this->settings['watermark_font_size'], 'small'); ?>><?php esc_html_e('Small (3% of image)', 'protect-uploads'); ?></option>
							<option value="medium" <?php selected($this->settings['watermark_font_size'], 'medium'); ?>><?php esc_html_e('Medium (5% of image)', 'protect-uploads'); ?></option>
							<option value="large" <?php selected($this->settings['watermark_font_size'], 'large'); ?>><?php esc_html_e('Large (7% of image)', 'protect-uploads'); ?></option>
						</select>
						<p class="description"><?php esc_html_e('Size of the watermark text relative to the image dimensions', 'protect-uploads'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Right-Click Protection', 'protect-uploads'); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e('Right-Click Protection', 'protect-uploads'); ?></legend>
							<label>
								<input type="checkbox" name="enable_right_click_protection" value="1" <?php checked($this->settings['enable_right_click_protection']); ?>>
								<?php esc_html_e('Disable right-click on images', 'protect-uploads'); ?>
							</label>
							<p class="description"><?php esc_html_e('Discourages casual copying: disables the right-click menu and dragging on images, and the browser shortcuts for saving the page and opening the inspector. Determined visitors can still save images.', 'protect-uploads'); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button(__('Save Changes', 'protect-uploads')); ?>
	</form>

	<?php $this->render_upsell( 'render_settings_footer_link' ); ?>
</div>
		<?php
	}

	public function enqueue_styles() {
		$screen = get_current_screen();
		if ( 'attachment' === $screen->id || 'upload' === $screen->id || strpos($screen->id, $this->plugin_name) !== false ) {
			wp_enqueue_style(
				$this->plugin_name,
				plugin_dir_url( __FILE__ ) . 'css/protect-uploads-admin.css',
				array(),
				$this->version,
				'all'
			);
			
			// Add inline styles for directory status table, disabled options, and tabs
			$custom_css = "
				.directory-status-table-wrapper {
					max-height: 300px;
					overflow-y: auto;
					margin-bottom: 10px;
				}
				.directory-status-table th {
					padding: 8px;
				}
				.directory-status-table td {
					padding: 8px;
				}
				label.disabled {
					opacity: 0.6;
					cursor: not-allowed;
				}
				.nginx-notice {
					font-weight: bold;
				}
				
				/* Tab styles */
				.tab-content {
					margin-top: 20px;
				}
				.tab-content.hidden {
					display: none;
				}
				.nav-tab-wrapper {
					margin-bottom: 0;
				}
				
				/* Message styles */
				.info {
					border-left-color: #72aee6;
					background-color: #f0f6fc;
				}
			";
			wp_add_inline_style( $this->plugin_name, $custom_css );
		}
	}

	public function add_settings_link($links)
	{
		$settings_link = '<a href="upload.php?page=' . $this->plugin_name . '-settings-page">' . esc_html__('Settings', 'protect-uploads') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}

	public function get_uploads_dir()
	{
		$uploads_dir = wp_upload_dir();
		return $uploads_dir['basedir'];
	}

	public function get_uploads_url()
	{
		$uploads_dir = wp_upload_dir();
		return $uploads_dir['baseurl'];
	}

	public function get_uploads_subdirectories()
	{
		$uploads_dir = self::get_uploads_dir();
		$dirs = array($uploads_dir); // Start with the main uploads directory

		// Get first level directories
		$first_level = glob($uploads_dir . '/*', GLOB_ONLYDIR);
		if (!empty($first_level)) {
			$dirs = array_merge($dirs, $first_level);

			// Get second level directories
			foreach ($first_level as $dir) {
				$second_level = glob($dir . '/*', GLOB_ONLYDIR);
				if (!empty($second_level)) {
					$dirs = array_merge($dirs, $second_level);
				}
			}
		}

		return $dirs;
	}

	public function save_form($protection)
	{
		if ($protection == 'index') {
			$this->create_index();
		}
		if ($protection == 'htaccess') {
			$this->create_htaccess();
		}
		if ($protection == 'remove') {
			$this->remove_index();
			$this->remove_htaccess();
		}
	}

	// used to check if the current htaccess has been generated by the plugin
	public function get_htaccess_identifier()
	{
		return "[plugin_name=" . $this->plugin_name . "]";
	}

	/**
	 * Content of the index.php files the plugin writes.
	 *
	 * The identifier lets remove_index() tell them from other plugins' files.
	 *
	 * @since  0.8.0
	 * @return string
	 */
	private function get_index_content()
	{
		return "<?php // Silence is golden \n // " . self::get_htaccess_identifier() . " \n // protect-uploads \n // date:" . gmdate('d/m/Y') . "\n // .";
	}

	public function create_index()
	{
		$indexContent = $this->get_index_content();
		$successful_count = 0;
		$failed_count = 0;
		$already_exists_count = 0;
		$directories = self::get_uploads_subdirectories();
		$total_count = count($directories);
		$basedir = wp_upload_dir()['basedir'];
		
		foreach ($directories as $directory) {
			// Only create if it doesn't exist already
			if (!file_exists($directory . '/index.php')) {
				if (file_put_contents($directory . '/index.php', $indexContent)) {
					$successful_count++;
				} else {
					$failed_count++;
					$rel_path = str_replace($basedir, '', $directory);
					$rel_path = empty($rel_path) ? '/' : $rel_path;
					self::register_message(
						sprintf(
							/* translators: %s: directory path */
							__('Failed to create index.php in %s - Check directory permissions', 'protect-uploads'),
							$rel_path
						),
						'error'
					);
				}
			} else {
				$already_exists_count++; // Count as already exists
			}
		}
		
		if ($successful_count > 0) {
			self::register_message(
				sprintf(
					/* translators: %d: number of directories */
					_n('Successfully created index.php in %d directory.', 'Successfully created index.php in %d directories.', $successful_count, 'protect-uploads'),
					$successful_count
				),
				'updated'
			);
		}
		
		if ($already_exists_count > 0) {
			self::register_message(
				sprintf(
					/* translators: %d: number of directories */
					_n('Skipped %d directory where index.php already exists.', 'Skipped %d directories where index.php already exists.', $already_exists_count, 'protect-uploads'),
					$already_exists_count
				),
				'updated'
			);
		}
		
		if ($failed_count === 0 && ($successful_count > 0 || $already_exists_count > 0)) {
			self::register_message(
				__('All directories have been protected successfully with index.php files.', 'protect-uploads'),
				'updated'
			);
		} elseif ($failed_count > 0) {
			self::register_message(
				sprintf(
					/* translators: 1: number of failed directories, 2: total number of directories */
					__('Warning: Failed to protect %1$d out of %2$d directories. Check permissions.', 'protect-uploads'),
					$failed_count,
					$total_count
				),
				'error'
			);
		}
	}

	/**
	 * Write an index.php into the folder of a new upload and its parent.
	 *
	 * create_index() only covers the folders that exist when the settings are
	 * saved, so each new month (and year) folder would otherwise be listable.
	 * Runs on the 'wp_handle_upload' filter; checks for the file first, so
	 * it costs two file_exists() calls per upload.
	 *
	 * @since  0.8.0
	 * @param  array $upload Upload data: file, url, type.
	 * @return array Unchanged upload data.
	 */
	public function protect_upload_folder( $upload )
	{
		if ( 'index' !== $this->settings['protection_method'] || empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
			return $upload;
		}

		$basedir = wp_normalize_path( untrailingslashit( self::get_uploads_dir() ) );

		// Only once the site owner has applied the index.php method.
		if ( ! file_exists( $basedir . '/index.php' ) ) {
			return $upload;
		}

		// The same two levels create_index() covers: month and year.
		$dir = wp_normalize_path( dirname( $upload['file'] ) );
		for ( $level = 0; $level < 2 && 0 === strpos( $dir, $basedir . '/' ); $level++ ) {
			if ( ! file_exists( $dir . '/index.php' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same as create_index().
				@file_put_contents( $dir . '/index.php', $this->get_index_content() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failure is handled by the caller.
			}
			$dir = dirname( $dir );
		}

		return $upload;
	}

	public function create_htaccess()
	{
		// Check if server is Nginx - abort if it is
		if ($this->is_nginx()) {
			self::register_message(
				__('Cannot create .htaccess file: Your server is running Nginx, which does not support .htaccess files. Please use the index.php protection method instead.', 'protect-uploads'),
				'error'
			);
			return;
		}
		
		// Content for htaccess file
		$date = gmdate('Y-m-d H:i.s');
		$phpv = phpversion();
		$uploads_dir = self::get_uploads_dir();

		$htaccessContent = "\n# BEGIN " . $this->get_plugin_name() . " Plugin\n";
		$htaccessContent .= "\tOptions -Indexes\n";
		$htaccessContent .= "# [date={$date}] [php={$phpv}] " . self::get_htaccess_identifier() . " [version={$this->version}]\n";
		$htaccessContent .= "# END " . $this->get_plugin_name() . " Plugin\n";

		$htaccess_path = $uploads_dir . '/.htaccess';
		$htaccess_exists = file_exists($htaccess_path);
		
		if (!$htaccess_exists) {
			// Create new .htaccess file
			if (file_put_contents($htaccess_path, $htaccessContent)) {
				self::register_message(
					__('Successfully created .htaccess file in uploads directory.', 'protect-uploads'),
					'updated'
				);
				
				// Check if the .htaccess is actually working by testing the response code
				if (self::get_uploads_root_response_code(true) === 403) {
					self::register_message(
						__('Directory listing is now blocked (403 Forbidden) as expected.', 'protect-uploads'),
						'updated'
					);
				} else {
					self::register_message(
						__('Warning: .htaccess file was created but directory listing may not be blocked. Your server might need additional configuration.', 'protect-uploads'),
						'warning'
					);
				}
			} else {
				self::register_message(
					__('Failed to create .htaccess file. Please check uploads directory permissions.', 'protect-uploads'),
					'error'
				);
			}
		} else {
			// Update existing .htaccess file
			if (self::check_htaccess_is_self_generated()) {
				// This is our .htaccess, update it
				self::register_message(
					__('Existing .htaccess file was previously created by this plugin and has been verified.', 'protect-uploads'),
					'updated'
				);
			} else {
				// This is a different .htaccess, append our content
				if (file_put_contents($htaccess_path, $htaccessContent, FILE_APPEND | LOCK_EX)) {
					self::register_message(
						__('Updated existing .htaccess file with directory protection rules.', 'protect-uploads'),
						'updated'
					);
				} else {
					self::register_message(
						__('Failed to update existing .htaccess file. Please check file permissions.', 'protect-uploads'),
						'error'
					);
					return;
				}
			}
			
			// Final check to verify protection is working
			if (self::get_uploads_root_response_code(true) === 403) {
				self::register_message(
					__('Directory listing is now blocked (403 Forbidden) as expected.', 'protect-uploads'),
					'updated'
				);
			} else {
				self::register_message(
					__('Warning: .htaccess file exists but directory listing may not be blocked. Your server might require additional configuration.', 'protect-uploads'),
					'warning'
				);
			}
		}
		
		// Remind users that .htaccess only protects the uploads root directory
		self::register_message(
			__('Note: .htaccess protection only applies to the uploads root directory. For complete protection of subdirectories, consider using the index.php method instead.', 'protect-uploads'),
			'info'
		);
	}

	/**
	 * Delete the index.php files this plugin wrote, and only those: other
	 * plugins put their own index.php files in uploads folders.
	 *
	 * @since 0.1
	 * @since 0.8.0 Leaves index.php files it did not create.
	 */
	public function remove_index()
	{
		$deleted = 0;
		foreach (self::get_uploads_subdirectories() as $subDirectory) {
			$file = $subDirectory . '/index.php';
			if (is_file($file) && is_readable($file)) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, first bytes only.
				$head = (string) file_get_contents($file, false, null, 0, 512);
				if (false !== strpos($head, self::get_htaccess_identifier())) {
					wp_delete_file($file);
					$deleted++;
				}
			}
		}
		if ($deleted > 0) {
			self::register_message(
				sprintf(
					/* translators: %d: number of directories */
					_n('Removed the index.php file from %d directory.', 'Removed the index.php files from %d directories.', $deleted, 'protect-uploads'),
					$deleted
				)
			);
		}
	}

	public function remove_htaccess()
	{
		$htaccess_path = self::get_uploads_dir() . '/.htaccess';
		if (!file_exists($htaccess_path)) {
			return;
		}

		$htaccessContent = file_get_contents($htaccess_path);
		$stripped = preg_replace('/(# BEGIN protect-uploads Plugin)(.*?)(# END protect-uploads Plugin)/is', '', $htaccessContent);
		if ($stripped === $htaccessContent) {
			return;
		}
		file_put_contents($htaccess_path, $stripped, LOCK_EX);

		// if htaccess is empty, we remove it.
		if (strlen(preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "", file_get_contents($htaccess_path))) == 0) {
			wp_delete_file($htaccess_path);
		}

		self::register_message(__('The .htaccess file has been updated.', 'protect-uploads'));
	}

	public function get_protective_files_array()
	{
		$uploads_files = ['index.php', 'index.html', '.htaccess'];
		$response = [];
		foreach ($uploads_files as $file) {
			if (file_exists(self::get_uploads_dir() . '/' . $file)) {
				$response[] = $file;
			}
		}
		return $response;
	}

	public function check_protective_file($file)
	{
		if (in_array($file, self::get_protective_files_array())) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * HTTP status of the uploads root URL, 0 when the request failed.
	 *
	 * A blocking loopback request, so it runs at most once per request and
	 * the result is cached for an hour.
	 *
	 * @since  0.1
	 * @since  0.8.0 Cached.
	 * @param  bool $fresh Ignore the cached result (after changing the rules).
	 * @return int
	 */
	public function get_uploads_root_response_code( $fresh = false )
	{
		if ( ! $fresh ) {
			if ( null !== $this->root_response_code ) {
				return $this->root_response_code;
			}
			$cached = get_transient( self::ROOT_STATUS_TRANSIENT );
			if ( false !== $cached ) {
				$this->root_response_code = (int) $cached;
				return $this->root_response_code;
			}
		}

		$response = wp_safe_remote_get(
			trailingslashit( self::get_uploads_url() ),
			array(
				'timeout'      => 3,
				'redirection'  => 2,
				'headers'      => array(),
				'blocking'     => true,
			)
		);

		$this->root_response_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		set_transient( self::ROOT_STATUS_TRANSIENT, $this->root_response_code, HOUR_IN_SECONDS );

		return $this->root_response_code;
	}

	public function get_htaccess_content()
	{
		return file_get_contents(self::get_uploads_dir() . '/.htaccess');
	}

	public function check_htaccess_is_self_generated()
	{
		if (self::check_protective_file('.htaccess') && preg_match('/' . preg_quote(self::get_htaccess_identifier(), '/') . '/', self::get_htaccess_content())) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Add a message to show on the settings page.
	 *
	 * @param string $message Message text.
	 * @param string $type    One of updated, error, warning, info.
	 * @param int    $id      Unused, kept for compatibility.
	 */
	public function register_message($message, $type = 'updated', $id = 0)
	{
		$this->messages[] = array(
			'message' => $message,
			'type' => $type,
		);
	}

	/**
	 * Keep the messages for the current user until the next page load, so
	 * they survive the redirect after saving.
	 *
	 * @since 0.8.0
	 */
	private function persist_messages()
	{
		if ( ! empty( $this->messages ) ) {
			set_transient( self::MESSAGES_TRANSIENT . get_current_user_id(), $this->messages, 5 * MINUTE_IN_SECONDS );
		}
	}

	public function display_messages()
	{
		$output = '';

		$key = self::MESSAGES_TRANSIENT . get_current_user_id();
		$stored = get_transient( $key );
		if ( is_array( $stored ) ) {
			delete_transient( $key );
			$this->messages = array_merge( $stored, $this->messages );
		}

		$classes = array(
			'updated' => 'notice-success',
			'error'   => 'notice-error',
			'warning' => 'notice-warning',
			'info'    => 'notice-info',
		);

		foreach ( $this->messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['message'] ) ) {
				continue;
			}
			$type = isset( $message['type'], $classes[ $message['type'] ] ) ? $message['type'] : 'updated';
			$output .= '<div class="notice ' . esc_attr( $classes[ $type ] ) . '"><p>' . esc_html( $message['message'] ) . '</p></div>';
		}
		$this->messages = array();

		return $output;
	}

	/**
	 * Warn admins when the password rules could not be written, on the
	 * plugin's settings page and the media screens.
	 *
	 * @since 0.8.0
	 */
	public function render_rules_notice()
	{
		if ( empty( $this->settings['enable_password_protection'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The settings page's screen ID depends on its parent menu, which Pro
		// changes, so match it by its slug.
		$screen = get_current_screen();
		if ( ! $screen || ! ( in_array( $screen->id, array( 'upload', 'attachment' ), true ) || false !== strpos( $screen->id, $this->plugin_name . '-settings-page' ) ) ) {
			return;
		}

		if ( ! Alti_ProtectUploads_Password_Rules::write_failed() && 'missing' !== Alti_ProtectUploads_Password_Rules::status() ) {
			return;
		}
		?>
		<div class="notice notice-error protect-uploads-rules-notice">
			<p>
				<?php
				printf(
					/* translators: %s: path of the uploads .htaccess file */
					esc_html__( 'Protect Uploads could not write its rules to %s, so password-protected files can still be downloaded at their direct URL by anyone who has the link. Make the file writable by WordPress, then save the Protect Uploads settings.', 'protect-uploads' ),
					'<code>' . esc_html( wp_normalize_path( self::get_uploads_dir() ) . '/.htaccess' ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	public function save_settings() {
		// Only run when the form is submitted
		if ( ! isset( $_POST['submit'] ) ) {
			return;
		}

		// Get, unslash, and sanitize the nonce value first.
		$nonce = isset( $_POST['protect-uploads_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['protect-uploads_nonce'] ) ) : '';

		// Verify nonce IMMEDIATELY after checking form submission
		if ( ! wp_verify_nonce( $nonce, 'submit_form' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'protect-uploads' ) );
		}

		// Check user capabilities (Now after nonce check)
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'protect-uploads' ) );
		}

		// Get the current active tab
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'directory-protection';

		// Load existing settings to preserve values not on this form
		$current_settings = get_option( 'protect_uploads_settings', array() );
		$settings = is_array( $current_settings ) ? $current_settings : array();

		// Sanitize and validate settings (Now after nonce check)
		// Update only the fields from the current form
		$settings['enable_watermark'] = isset( $_POST['enable_watermark'] );
		$settings['watermark_text'] = sanitize_text_field( wp_unslash( $_POST['watermark_text'] ?? '' ) );
		$settings['watermark_position'] = sanitize_key( wp_unslash( $_POST['watermark_position'] ?? 'bottom-right' ) );
		$settings['watermark_opacity'] = absint( $_POST['watermark_opacity'] ?? 50 );
		$settings['watermark_font_size'] = sanitize_key( wp_unslash( $_POST['watermark_font_size'] ?? 'medium' ) ); // Ensure font size is saved
		$settings['enable_right_click_protection'] = isset( $_POST['enable_right_click_protection'] );
		$settings['enable_password_protection'] = isset( $_POST['enable_password_protection'] );
		// 'protection_method' is handled separately below before final save

		// Validate watermark position
		$valid_positions = array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' );
		if ( ! in_array( $settings['watermark_position'], $valid_positions, true ) ) {
			$settings['watermark_position'] = 'bottom-right';
		}

		// Validate font size
		$valid_sizes = array( 'small', 'medium', 'large' );
		if ( ! in_array( $settings['watermark_font_size'], $valid_sizes, true ) ) {
			$settings['watermark_font_size'] = 'medium';
		}

		// Ensure opacity is between 0 and 100
		$settings['watermark_opacity'] = min( 100, max( 0, $settings['watermark_opacity'] ) );

		// Handle protection method
		$protection = 'index'; // Default value if not set
		$previous_protection = $this->settings['protection_method']; // Store previous setting
		$protection_changed = false;
		
		if ( isset( $_POST['protection'] ) ) {
			$sanitized_protection = sanitize_key( wp_unslash( $_POST['protection'] ) );
			if ( in_array( $sanitized_protection, array( 'index', 'htaccess' ), true ) ) { // Only allow index or htaccess to be saved
				$protection = $sanitized_protection;
				
				// If protection method changed, we need to remove the old protection files
				if ($previous_protection !== $protection) {
					$protection_changed = true;
					if ($previous_protection === 'index') {
						$this->remove_index();
					} elseif ($previous_protection === 'htaccess') {
						$this->remove_htaccess();
					}
				}
			}
		}
		$settings['protection_method'] = $protection; // Save the chosen protection method to the settings array

		// Update settings in the database
		update_option( 'protect_uploads_settings', $settings );
		$this->settings = $settings; // Update the local property as well

		// Turning password protection on or off adds or removes the rules
		// that send protected files' direct URLs to the password prompt.
		Alti_ProtectUploads_Password_Rules::sync();

		// The protection files may have changed: check the uploads root again
		// on the next page load.
		delete_transient( self::ROOT_STATUS_TRANSIENT );

		// If we're on the directory protection tab or the protection method changed,
		// we need to ensure the proper protection is applied
		if ($active_tab === 'directory-protection' || $protection_changed) {
			// Apply the protection method
			$this->save_form($protection);
		}

		// Add success message
		$this->register_message( __( 'Settings saved successfully.', 'protect-uploads' ), 'updated' );
		$this->persist_messages();

		// Redirect to prevent form resubmission, preserving the active tab
		wp_safe_redirect( add_query_arg( array(
			'settings-updated' => 'true',
			'tab' => $active_tab
		), wp_get_referer() ) );
		exit;
	}

	/**
	 * Protection status of a directory, from the files in it and the one
	 * cached check of the uploads root URL.
	 *
	 * @since  0.8.0
	 * @param  string $directory Absolute path.
	 * @return array {
	 *     @type string $status 'protected', 'unprotected' or 'unverified'.
	 *     @type string $method How it is protected, or ''.
	 * }
	 */
	public function get_directory_status($directory)
	{
		if (file_exists($directory . '/index.php')) {
			return array('status' => 'protected', 'method' => __('index.php', 'protect-uploads'));
		}

		if (file_exists($directory . '/index.html')) {
			return array('status' => 'protected', 'method' => __('index.html', 'protect-uploads'));
		}

		// "Options -Indexes" in the uploads root applies to every subfolder.
		$code = self::get_uploads_root_response_code();
		if (403 === $code) {
			$is_root = $directory === self::get_uploads_dir();
			return array(
				'status' => 'protected',
				'method' => $is_root ? __('.htaccess (403)', 'protect-uploads') : __('Parent directory protection', 'protect-uploads'),
			);
		}

		if (0 === $code) {
			return array('status' => 'unverified', 'method' => '');
		}

		return array('status' => 'unprotected', 'method' => '');
	}

	/**
	 * Check if the server is running Nginx
	 * 
	 * @return bool True if server is running Nginx, false otherwise
	 */
	public function is_nginx()
	{
		if ( ! empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			$server_software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
			return ( stripos( $server_software, 'nginx' ) !== false );
		}

		return false;
	}
}
