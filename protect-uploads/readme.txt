=== Protect Uploads ===
Contributors: alticreation
Tags: password protection, watermark, security, private files, directory listing
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.8.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Protect your uploads directory. Prevent browsing, password-protect files, add watermarks, and discourage right-click copying.

== Description ==

The uploads directory is where the files of the WordPress media library are stored. Unfortunately, this directory is not protected by default: anyone who wants to see your whole library may be able to list it by going to http://yourwebsite/wp-content/uploads. This plugin hides the contents by adding an index.php file to your uploads directory and its subfolders, or by adding an .htaccess file that returns a 403 error (Forbidden Access).

* Depending on your server, the .htaccess option may be unavailable (Nginx does not read .htaccess files).

For more information, visit [protectuploads.com](https://protectuploads.com).

**Features:**

* **Directory Protection**: Stop visitors from browsing your uploads directory (index.php or .htaccess 403). New year and month folders are protected as files are uploaded to them.
* **Password Protection**: Secure individual media files with passwords. Multiple passwords can be set for each file with custom labels. On Apache and LiteSpeed, the file's direct URL also asks for the password, and repeated wrong guesses are rate limited.
* **Image Watermarking**: Add text watermarks to your uploaded images with customizable position, opacity, and font size. An untouched copy of each original is kept.
* **Right-Click Protection**: Discourages casual copying: disables the right-click menu and dragging on images. Determined visitors can still save images.
* **Access Logging**: See when password-protected files were downloaded, with which password and from which IP address.
* **Site Health Checks**: Built-in protection status tests with actionable advice.

Need more protection? [Protect Uploads Pro](https://protectuploads.com) adds Secure Send (emailed files released by a one-time code), a Client Portal, file requests, expiring and single-use passwords, role-based access, hotlink protection, logo and bulk watermarks, and download analytics.

Available languages:

* English
* Français
* Español
* Italian (thanks to Marko97)

== Installation ==

1. Upload `protect-uploads` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure protection options in Media → Protect Uploads

Note: watermarking needs the PHP GD extension. The .htaccess method and the direct-URL password rules need a server that reads .htaccess files (Apache or LiteSpeed) and a writable uploads directory.

== Frequently Asked Questions ==

= How do I add a password to a media file? =

1. Enable password protection in Media → Protect Uploads, on the Image Protection tab
2. Open any media file's edit screen (Media → Library, select the file, then "Edit more details")
3. Find the "Password Protection" box in the sidebar
4. Add one or more passwords with descriptive labels

= How does watermarking work? =

When enabled, watermarking automatically adds text to images when they are uploaded. You can customize:
- The watermark text (defaults to your site name)
- Position (top-left, top-right, bottom-left, bottom-right, center)
- Opacity (0-100%)
- Font size (small, medium, large)

The watermark is applied to new uploads only. Animated GIFs are left unchanged.

= Does watermarking change my original images? =

Yes: the watermark is drawn on the uploaded file itself, so every size WordPress generates from it carries the mark. Before drawing it, the plugin keeps an untouched copy of the original in wp-content/uploads/protect-uploads-originals/, in the same year/month folders. That folder is blocked from web access on Apache and LiteSpeed; on Nginx each copy has a random name so its URL cannot be guessed. Deleting a media file deletes its copy. Deleting the plugin keeps the copies, since they may be your only originals.

= Can I password protect only certain file types? =

You choose files one by one: any media file, including PDFs, images, videos and documents, can have its own passwords.

= Does a password protect the file's direct URL? =

On Apache and LiteSpeed, yes: the plugin adds rules to the uploads .htaccess file that send the file's direct URL to the password prompt. Nginx does not read .htaccess files, so there the password protects the links WordPress shows but the file itself stays reachable at its uploads URL. The password box on the media screen tells you when this applies, and Tools → Site Health warns you when protected files are reachable at their direct URL.

= Does this work on Nginx? =

Partly. The index.php method, watermarking, right-click protection and the password prompt work on any server. Nginx does not read .htaccess files, so the .htaccess method is not available and a password-protected file can still be downloaded at its direct uploads URL by anyone who knows it. To block directory listing on Nginx, also make sure "autoindex" is off for your uploads directory.

= What does Pro add? =

[Protect Uploads Pro](https://protectuploads.com) is a paid add-on that works alongside this plugin. It adds logo (image) watermarks, more watermark positions and bulk watermarking of existing images, expiring, single-use and limited-use passwords, expiring download links, role-based access to files, hotlink protection, download analytics, and a Protected Download block.

= What is removed when I delete the plugin? =

Its settings, its password and access-log tables, the index.php files it created and the rules it added to .htaccess. Untouched copies of watermarked images are kept.

== Screenshots ==

1. The Directory Protection tab, with the protection status of each uploads folder.
2. The Password Protection box on a media file's edit screen.
3. The Image Protection tab: password protection, watermark and right-click options.

== Upgrade Notice ==

= 0.8.0 =
Security release: password-protected files are now protected at their direct URL on Apache and LiteSpeed, and password guessing is rate limited. Update as soon as possible.

= 0.7.0 =
Adds Site Health checks for uploads protection and updates compatibility for WordPress 7.0.

= 0.6.0 =
Major update with new security features: watermarking, right-click protection, and password protection for individual media files.

== Changelog ==

= 0.8.0 =

**Security**

* Fixed: a password-protected file stayed downloadable at its direct uploads URL. On Apache and LiteSpeed, direct requests for the file, its image sizes and its WebP/AVIF copies now go to the password prompt
* Fixed: failed passwords were logged but never limited; 5 failed attempts per visitor per file now lock that visitor out for 15 minutes
* Fixed: simultaneous password guesses cannot exceed the attempt limit; successful passwords refund only their own attempt
* Fixed: visitor IPs were read from forwarding headers anyone can set; REMOTE_ADDR is used, with a 'protect_uploads_client_ip' filter for sites behind a trusted proxy
* Fixed: IPv4-mapped IPv6 addresses use each visitor's IPv4 limit instead of sharing a single network limit
* Fixed: SVG and HTML files are downloaded instead of opened, so they cannot run scripts on your site
* Fixed: the protected-file path check could be fooled by "../" segments or a sibling folder name
* Removed: an unused public endpoint that allowed unlimited password guessing

**Fixes**

* Fixed: links to password-protected files stopped working after a day, or when served from a page cache ("Invalid security token")
* Fixed: after entering the password, visitors got the full-size file instead of the image size they asked for
* Fixed: password-protected files were read fully into memory; they are now streamed
* Fixed: watermarking overwrote the original upload; an untouched copy is now kept in uploads/protect-uploads-originals/, blocked from web access
* Fixed: watermarking flattened animated GIFs and stored portrait photos sideways
* Fixed: thumbnails of password-protected images were broken in the Media Library
* Fixed: the settings page could take minutes to load; it makes one cached check of the uploads URL and shows "Could not verify" when that check fails
* Fixed: new year and month upload folders were not protected by the index.php method
* Fixed: messages from saving the settings were lost after the page reloaded
* Fixed: removing the index.php protection deleted other plugins' index.php files
* Fixed: Site Health reported the uploads directory as protected when only password rules were present
* Fixed: Site Health marked the empty index.php files that block folder listings (this plugin's and Protect Uploads Pro's) as dangerous and told you to remove them
* Fixed: deleting the plugin left its settings, tables and .htaccess rules behind (copies of watermarked originals are kept)
* Fixed: on Nginx the settings were rewritten on every request
* Fixed: with Protect Uploads Pro active, the password rules are written after Pro's rules

**Changes**

* Added: Site Health check for password-protected files whose direct URL is not protected, and a notice when the rules cannot be written
* Added: protect_uploads_allow_file_download filter before a password-unlocked file is served
* Changed: the password box warns when the server cannot protect the direct URL (for example, Nginx)
* Changed: right-click protection is described honestly; Pro upgrade texts are accurate, cover Secure Send, the Client Portal and file requests, and link to pricing
* Changed: Site Health no longer mentions Protect Uploads Pro on sites where Pro is active
* Removed: unused script admin-passwords.js and unused code

= 0.7.1 =
* Compatibility verified with WordPress 7.1
* Fixed the bundled watermark font: text watermarks now render at the intended size using Open Sans instead of falling back to a small built-in bitmap font

= 0.7.0 =
* Added Site Health checks: uploads directory browsing protection status, detected server configuration (Apache/Nginx), and a bounded scan for sensitive file types in the uploads directory
* Added unobtrusive upgrade hints for Protect Uploads Pro (hidden entirely when Pro is active)
* Fixed the settings page title showing the plugin slug instead of the plugin name
* Compatibility verified with WordPress 7.0 and PHP 8.3
* Raised minimum requirements to WordPress 5.0 and PHP 7.4

= 0.6.0 =
* Added image watermarking with customizable text, position, opacity, and font size
* Added right-click protection to prevent image downloads
* Added password protection for individual media files
* Added access logging for password-protected files
* Added multiple password support with custom labels
* Added security enhancements throughout the plugin
* Improved file serving with better security checks
* Added font size control for watermarks
* Enhanced error handling and logging

= 0.5.2 =
* Removed unused css

= 0.4 =
* Fix potential security issues.
* Remove recursive loop that creates indexes.

= 0.3 =
* Simplify UI admin.
* check presence of index.html.
* Remove option value managing current protection status.
* Reorganizing code and making it more modular and simple.
* Remove useless pieces.

= 0.2 =
* Add security check to form in admin page.
* Add sidebar for admin page
* Add Italian translation (thanks to Marko97).
* Try to fix the wrong message saying that Protection is disabled even though it is actually working.

= 0.1 =
* Initial release
