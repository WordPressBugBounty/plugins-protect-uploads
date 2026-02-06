<?php

class Alti_ProtectUploads_Admin
{

	private $plugin_name;
	private $version;
	private $messages = array();
	private $settings = array();

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
		
		// Check if server is running nginx, and if so, force index protection method
		if ($this->is_nginx() && $this->settings['protection_method'] === 'htaccess') {
			$this->settings['protection_method'] = 'index';
			update_option('protect_uploads_settings', $this->settings);
		}
	}

	public function get_plugin_name()
	{
		return $this->plugin_name;
	}

	public function add_submenu_page()
	{
		add_submenu_page('upload.php', $this->plugin_name, 'Protect Uploads <span class="dashicons dashicons-shield-alt" style="font-size:15px;"></span>', 'manage_options', $this->plugin_name . '-settings-page', array($this, 'render_settings_page'));
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
							<p class="description"><?php esc_html_e('Create an index.php file on the root of your uploads directory and subfolders (two levels max).', 'protect-uploads'); ?></p>
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
									$uploads_dir = self::get_uploads_dir();
									$upload_folders = self::get_uploads_subdirectories();
									$baseurl = wp_upload_dir()['baseurl'];
									$basedir = wp_upload_dir()['basedir'];
									
									foreach ($upload_folders as $dir) {
										$is_protected = self::check_directory_is_protected($dir);
										$rel_path = str_replace($basedir, '', $dir);
										$rel_path = empty($rel_path) ? '/' : $rel_path;
										
										$protection_type = '';
										if (file_exists($dir . '/index.php')) {
											$protection_type = __('index.php', 'protect-uploads');
										} elseif (file_exists($dir . '/index.html')) {
											$protection_type = __('index.html', 'protect-uploads');
										} elseif ($dir === $uploads_dir && file_exists($dir . '/.htaccess') && self::get_uploads_root_response_code() === 403) {
											$protection_type = __('.htaccess (403)', 'protect-uploads');
										} elseif (self::get_uploads_root_response_code() === 403) {
											$protection_type = __('Parent directory protection', 'protect-uploads');
										}
										?>
										<tr>
											<td><?php echo esc_html($rel_path); ?></td>
											<td>
												<?php if ($is_protected): ?>
													<span class="dashicons dashicons-yes-alt" style="color: green;"></span> <?php esc_html_e('Protected', 'protect-uploads'); ?>
												<?php else: ?>
													<span class="dashicons dashicons-warning" style="color: red;"></span> <?php esc_html_e('Not Protected', 'protect-uploads'); ?>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html($protection_type); ?></td>
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
							<p class="description"><?php esc_html_e('Prevents visitors from right-clicking on images to save them', 'protect-uploads'); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button(__('Save Changes', 'protect-uploads')); ?>
	</form>
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

	public function create_index()
	{
		$indexContent = "<?php // Silence is golden \n // " . self::get_htaccess_identifier() . " \n // protect-uploads \n // date:" . gmdate('d/m/Y') . "\n // .";
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
					/* translators: 1: number of directories, 2: "directory" or "directories" */
					__('Successfully created index.php in %1$d %2$s.', 'protect-uploads'),
					$successful_count,
					_n('directory', 'directories', $successful_count, 'protect-uploads')
				),
				'updated'
			);
		}
		
		if ($already_exists_count > 0) {
			self::register_message(
				sprintf(
					/* translators: 1: number of directories, 2: "directory" or "directories" */
					__('Skipped %1$d %2$s where index.php already exists.', 'protect-uploads'),
					$already_exists_count,
					_n('directory', 'directories', $already_exists_count, 'protect-uploads')
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
				if (self::get_uploads_root_response_code() === 403) {
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
			if (self::get_uploads_root_response_code() === 403) {
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

	public function remove_index()
	{
		$i = 0;
		foreach (self::get_uploads_subdirectories() as $subDirectory) {
			if (file_exists($subDirectory . '/index.php')) {
				wp_delete_file($subDirectory . '/index.php');
				$i++;
			}
		}
		if ($i == count(self::get_uploads_subdirectories())) {
			self::register_message('The index.php file(s) have(has) been deleted.');
		}
	}

	public function remove_htaccess()
	{
		if (file_exists(self::get_uploads_dir() . '/.htaccess')) {

			$htaccessContent = file_get_contents(self::get_uploads_dir() . '/.htaccess');
			$htaccessContent = preg_replace('/(# BEGIN protect-uploads Plugin)(.*?)(# END protect-uploads Plugin)/is', '', $htaccessContent);
			file_put_contents(self::get_uploads_dir() . '/.htaccess', $htaccessContent, LOCK_EX);

			// if htaccess is empty, we remove it.
			if (strlen(preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "", file_get_contents(self::get_uploads_dir() . '/.htaccess'))) == 0) {
				wp_delete_file(self::get_uploads_dir() . '/.htaccess');
			}


			//
			self::register_message('The htaccess file has been updated.');
		}
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

	public function get_uploads_root_response_code()
	{
		$response = wp_safe_remote_get(
			self::get_uploads_url(),
			array(
				'timeout'      => 5,
				'redirection'  => 2,
				'headers'      => array(),
				'blocking'     => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	public function get_htaccess_content()
	{
		return file_get_contents(self::get_uploads_dir() . '/.htaccess');
	}

	public function check_htaccess_is_self_generated()
	{
		if (self::check_protective_file('.htaccess') && preg_match('/' . self::get_htaccess_identifier() . '/', self::get_htaccess_content())) {
			return true;
		} else {
			return false;
		}
	}

	// heart? <3
	public function check_uploads_is_protected()
	{
		foreach (self::get_protective_files_array() as $file) {
			if ($file === 'index.html') {
				return true;
				break;
			}
			if ($file === 'index.php') {
				return true;
				break;
			}
			if ($file === '.htaccess' && self::get_uploads_root_response_code() === 200) {
					return false;
					break;
			}
		}
		if (self::get_uploads_root_response_code() === 403) {
			return true;
		}
		else {
			return false;
		}
	}

	public function check_protective_file_removable() {
		if( self::check_protective_file('index.html') ) {
			return false;
		}
		elseif( self::check_protective_file('.htaccess') === false && self::get_uploads_root_response_code() === 403 ) {
			return false;
		}
		else {
			return true;
		}
	}

	public function get_uploads_protection_message_array()
	{
		$response = [];
		foreach (self::get_protective_files_array() as $file) {
			if ($file === '.htaccess' && self::get_uploads_root_response_code() === 403) {
				$response[] = '<span class="dashicons dashicons-yes"></span> ' . esc_html__('.htaccess file is present and access to uploads directory returns 403 code.', 'protect-uploads');
			}
			if ($file === 'index.php') {
				$response[] = '<span class="dashicons dashicons-yes"></span> ' . __('index.php file is present.', 'protect-uploads');
			}
			if ($file === 'index.html') {
				$response[] = '<span class="dashicons dashicons-yes"></span> ' . __('index.html file is present.', 'protect-uploads');
			}
		}
		if (self::check_protective_file('.htaccess') === true && self::get_uploads_root_response_code() === 200) {
			$response[] = '<span class="dashicons dashicons-search"></span> ' . __('.htaccess file is present but not protecting uploads directory.', 'protect-uploads');
		}
		if (self::check_protective_file('.htaccess') === false && self::get_uploads_root_response_code() === 403) {
			$response[] = '<span class="dashicons dashicons-yes"></span> ' . __('Access to uploads directory is protected (403) with a global .htaccess or another global declaration.', 'protect-uploads');
		}
		return $response;
	}

	public function check_apache()
	{
		if (!function_exists('apache_get_modules')) {
			self::register_message('The Protect Uploads plugin cannot work without Apache. Yourself or your web host has to activate this module.');
		}
	}


	public function register_message($message, $type = 'updated', $id = 0)
	{
		$this->messages['apache'][] = array(
			'message' => $message,
			'type' => $type,
			'id' => $id
		);
	}

	public function display_messages()
	{
		$output = '';

		// Check for settings-updated query parameter
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only parameter, no data processing
		if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) {
			$output .= '<div id="message" class="updated"><p>' . esc_html__( 'Settings saved successfully.', 'protect-uploads' ) . '</p></div>';
		}
		
		// Display any registered messages
		if ( ! empty( $this->messages ) ) {
			foreach ( $this->messages as $name => $messages ) {
				foreach ( $messages as $message ) {
					// Ensure valid message type (updated, error, warning, info)
					$type = in_array($message['type'], array('updated', 'error', 'warning', 'info')) ? $message['type'] : 'updated';
					$output .= '<div id="message" class="' . esc_attr( $type ) . '"><p>' . esc_html( $message['message'] ) . '</p></div>';
				}
			}
		}
		
		return $output;
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
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'directory-protection';

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

		// If we're on the directory protection tab or the protection method changed,
		// we need to ensure the proper protection is applied
		if ($active_tab === 'directory-protection' || $protection_changed) {
			// Apply the protection method
			$this->save_form($protection);
		}

		// Add success message
		$this->register_message( __( 'Settings saved successfully.', 'protect-uploads' ), 'updated' );
		
		// Redirect to prevent form resubmission, preserving the active tab
		wp_safe_redirect( add_query_arg( array(
			'settings-updated' => 'true',
			'tab' => $active_tab
		), wp_get_referer() ) );
		exit;
	}

	/**
	 * Check if a specific directory is protected
	 * 
	 * @param string $directory Path to directory to check
	 * @return bool True if directory is protected, false otherwise
	 */
	public function check_directory_is_protected($directory) 
	{
		// Check if directory has index.php file
		if (file_exists($directory . '/index.php')) {
			return true;
		}
		
		// Check if directory has index.html file
		if (file_exists($directory . '/index.html')) {
			return true;
		}
		
		// Check if uploads directory has .htaccess and it's returning 403
		if ($directory === self::get_uploads_dir() && 
			file_exists($directory . '/.htaccess') && 
			self::get_uploads_root_response_code() === 403) {
			return true;
		}
		
		// If we're checking a subdirectory, the parent directory's .htaccess may protect it
		if ($directory !== self::get_uploads_dir() && self::get_uploads_root_response_code() === 403) {
			return true;
		}
		
		return false;
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
