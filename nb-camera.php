<?php
/**
 * Plugin Name: NB Camera (NetBound)
 * Description: Front-end camera (photo/video) capture with Media Library uploads and optional profile picture update. Shortcode: [nb_camera].
 * Version: 6.5.0
 * Author: Orange Jeff + Copilot
 * License: GPL-2.0-or-later
 */

// Version history
// Version 6.5.0 (2025-12-05) - Embedded shared menu system (no separate plugin needed)
// Version 6.4.1 (2025-11-13 19:45) - CRITICAL FIX: Removed all btnStart/btnStop references from JS that were breaking camera (leftover from removed start/stop buttons). Moved Scan/Flip/Mirror to separate row for cleaner layout.
// Version 6.4.0 (2025-11-13 19:30) - Added allow_public_access option to bypass role checks (for public pages), added allow_device_select option to show/hide camera dropdown, fixed photoCountdown dataset reading. Admin settings now support public access and device selection visibility.
// Version 6.3.4 (2025-11-13) - CRITICAL FIX: Corrected dataset reading (was looking at wrong element), added photo countdown timer with admin option, digital stopwatch-style recording timer. Fixed download/preview/profile-pic button visibility after capture.
// Version 6.3.3 (2025-11-13) - Orange button theme, popup positioned 100px lower with z-index 999999, close X button styled, added flash effect for photos, notification messages for captures, camera always on.
// Version 6.3.2 (2025-11-13) - Removed start/stop camera buttons, auto-start camera on init, added red recording indicator, fixed download/preview/profile-pic button visibility after capture, changed default width to 450px, added WordPress button theme classes.
// Version 6.3.1 (2025-11-13) - Classic Editor toolbar + QuickTags buttons to insert [nb_camera popup="1"].
// Version 6.3 (2025-11-13) - Distribution prep: i18n loading, uninstall option, improved device detection UX (Retry/Flip), and packaging notes.
// Version 0.3.0 (2025-11-13) - Added capability-based access option and admin diagnostics (REST/capability) with inline tests.
// Version 0.2.0 (2025-11-13) - Added auto performance tuning, upload concurrency limit, and refined frontend options.
// Version 0.1.0 (2025-11-13) - Initial plugin created from scratch: admin settings, shortcode, webcam detection, recording, uploads, profile pic option.

if (!defined('ABSPATH')) {
	// Allow the file to be linted outside WP without execution
	exit;
}

if (!defined('NB_CAMERA_VERSION')) {
	define('NB_CAMERA_VERSION', '6.5.0');
}

// ============================================================================
// NETBOUND SHARED MENU SYSTEM v2.1 (embedded - works standalone or with other NB plugins)
// ============================================================================
if (!defined('NB_SHARED_MENU_VERSION')) {
    define('NB_SHARED_MENU_VERSION', '2.1.0');

    global $nb_registered_plugins;
    if (!isset($nb_registered_plugins)) {
        );
    }

    function nb_register_plugin($slug, $name, $desc = '', $version = '1.0', $icon = 'dashicons-admin-generic', $menu_slug = '') {
        global $nb_registered_plugins;
        $nb_registered_plugins[$slug] = array('name' => $name, 'description' => $desc, 'version' => $version, 'icon' => $icon, 'menu_slug' => $menu_slug ?: $slug);
    }

    function nb_create_parent_menu() {
        global $admin_page_hooks;
        if (isset($admin_page_hooks['nb_netbound_tools'])) return;
        add_menu_page('NetBound Tools', 'NetBound Tools', 'manage_options', 'nb_netbound_tools', 'nb_render_index_page', 'dashicons-shield', 80);
        add_submenu_page('nb_netbound_tools', 'NetBound Tools', 'All Tools', 'manage_options', 'nb_netbound_tools', 'nb_render_index_page');
    }
    add_action('admin_menu', 'nb_create_parent_menu', 5);

    function nb_render_index_page() {
        global $nb_registered_plugins;
        $all_plugins = nb_get_all_plugins();
        $installed = count($nb_registered_plugins);
        $available = count($all_plugins) - $installed;
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-shield" style="font-size:30px;margin-right:10px;"></span> NetBound Tools</h1>
            <p style="font-size:14px;color:#666;">Your WordPress toolkit by <a href="https://netbound.ca" target="_blank">NetBound.ca</a> — <?php echo $installed; ?> installed<?php if($available > 0) echo ", $available more available"; ?></p>
            <?php if (!empty($nb_registered_plugins)): ?>
            <h2 style="margin-top:30px;border-bottom:1px solid #ccc;padding-bottom:10px;">✅ Installed</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:20px;">
                <?php foreach ($nb_registered_plugins as $slug => $p): ?>
                <div class="card" style="margin:0;padding:20px;border-left:4px solid #00a32a;">
                    <h2 style="margin-top:0;display:flex;align-items:center;gap:10px;"><span class="dashicons <?php echo esc_attr($p['icon']); ?>" style="font-size:24px;color:#00a32a;"></span><?php echo esc_html($p['name']); ?></h2>
                    <p style="color:#666;min-height:40px;"><?php echo esc_html($p['description']); ?></p>
                    <p style="margin-bottom:0;"><span style="color:#00a32a;font-size:12px;">✓ v<?php echo esc_html($p['version']); ?></span><?php if(!empty($p['menu_slug'])): ?><a href="<?php echo esc_url(admin_url('admin.php?page='.$p['menu_slug'])); ?>" class="button button-primary" style="float:right;">Open →</a><?php endif; ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php $not_installed = array_diff_key($all_plugins, $nb_registered_plugins); if (!empty($not_installed)): ?>
            <h2 style="margin-top:30px;border-bottom:1px solid #ccc;padding-bottom:10px;">📦 More NetBound Plugins</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:20px;">
                <?php foreach ($not_installed as $slug => $p): ?>
                <div class="card" style="margin:0;padding:20px;border-left:4px solid #ddd;opacity:0.85;">
                    <h2 style="margin-top:0;display:flex;align-items:center;gap:10px;"><span class="dashicons <?php echo esc_attr($p['icon']); ?>" style="font-size:24px;color:#999;"></span><?php echo esc_html($p['name']); ?></h2>
                    <p style="color:#666;min-height:40px;"><?php echo esc_html($p['description']); ?></p>
                    <p style="margin-bottom:0;"><span style="color:#999;font-size:12px;">Not installed</span><a href="<?php echo esc_url($p['url']); ?>" class="button" style="float:right;" target="_blank">Learn More →</a></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="card" style="margin-top:30px;padding:20px;background:#f0f6fc;border-left:4px solid #2271b1;">
                <h2 style="margin-top:0;">🛡️ About NetBound Tools</h2>
                <p>WordPress plugins by <a href="https://netbound.ca" target="_blank">netbound.ca</a>. Each works standalone or together!</p>
                <p style="margin-bottom:0;color:#666;font-size:12px;"><?php echo $installed; ?> of <?php echo count($all_plugins); ?> plugins installed</p>
            </div>
        </div>
        <?php
    }

    function nb_get_parent_slug() { return 'nb_netbound_tools'; }
}

// Register this plugin
nb_register_plugin('nb-camera', 'NB Camera', 'Front-end camera capture with Media Library uploads', NB_CAMERA_VERSION, 'dashicons-camera', 'nb-camera-settings');
// ============================================================================

class NB_Camera_Plugin {
	const OPTION_KEY = 'nb_camera_options';

	public function __construct() {
		register_activation_hook(__FILE__, [$this, 'on_activate']);
		add_action('init', [$this, 'load_textdomain']);
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_init', [$this, 'maybe_redirect_to_settings']);
		add_shortcode('nb_camera', [$this, 'shortcode']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_public_assets']);
		add_action('rest_api_init', [$this, 'register_rest']);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);
		// Classic Editor integration (TinyMCE + QuickTags)
		add_action('admin_init', [$this, 'register_tinymce_button']);
		add_action('admin_print_footer_scripts', [$this, 'print_quicktags_button']);

		// Optional avatar override if enabled in options
		add_filter('get_avatar_url', [$this, 'maybe_avatar_url_override'], 10, 3);
	}

	public function load_textdomain() {
		load_plugin_textdomain('nb-camera', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	public function default_options(): array {
		return [
			'roles' => ['subscriber','contributor','author','editor','administrator'],
			'access_mode' => 'roles', // roles|capability
			'required_capability' => 'read',
			'allow_public_access' => false, // bypass role check for public access
			'allow_device_select' => true, // show camera selection dropdown
			'popup' => false,
			'width' => 450,
			'mode' => 'both', // photo|video|both
			'show_download' => true,
			'filename_base' => 'capture',
			'save_to_media' => true,
			'recording_mode' => 'single', // single|segments
			'max_time' => 0, // seconds; 0 = unlimited
			'show_timer' => true,
			'preview' => true,
			'allow_set_profile_pic' => true,
			'photo_countdown' => 0, // 0 = no countdown, 3, 5, 10 seconds
			'use_avatar_filter' => false, // if true, use saved avatar for get_avatar_url
			'chunk_ms' => 5000, // segment size when recording_mode = segments
			'auto_perf_tuning' => true, // detect slow machines and auto-adjust
			'max_upload_concurrency' => 2, // limit parallel segment uploads
			'keep_settings_on_uninstall' => true,
		];
	}

	public function on_activate() {
		$defaults = $this->default_options();
		$current = get_option(self::OPTION_KEY);
		if (!is_array($current)) {
			update_option(self::OPTION_KEY, $defaults);
		} else {
			update_option(self::OPTION_KEY, array_merge($defaults, $current));
		}
		add_option('nb_camera_do_activation_redirect', 1);
	}

	public function admin_menu() {
		add_submenu_page(
			nb_get_parent_slug(),
			__('NB Camera', 'nb-camera'),
			__('NB Camera', 'nb-camera'),
			'manage_options',
			'nb-camera-settings',
			[$this, 'render_settings_page']
		);
	}

	public function maybe_redirect_to_settings() {
		if (!is_admin()) return;
		if (!get_option('nb_camera_do_activation_redirect')) return;
		delete_option('nb_camera_do_activation_redirect');
		if (isset($_GET['activate-multi'])) return;
		wp_safe_redirect(admin_url('admin.php?page=nb-camera-settings'));
		exit;
	}

	public function plugin_action_links($links) {
		$url = admin_url('admin.php?page=nb-camera-settings');
		$settings_link = '<a href="' . esc_url($url) . '">Settings</a>';
		array_unshift($links, $settings_link);
		return $links;
	}

	public function register_settings() {
		register_setting('nb_camera_settings', self::OPTION_KEY, [
			'type' => 'array',
			'sanitize_callback' => [$this, 'sanitize_options'],
			'default' => $this->default_options(),
		]);
	}

	public function sanitize_options($input) {
		$defaults = $this->default_options();
		$opts = is_array($input) ? $input : [];

		$opts['roles'] = array_values(array_intersect(
			isset($opts['roles']) && is_array($opts['roles']) ? $opts['roles'] : [],
			['subscriber','contributor','author','editor','administrator']
		));
		if (empty($opts['roles'])) { $opts['roles'] = $defaults['roles']; }

		$opts['popup'] = !empty($opts['popup']);
		$opts['width'] = max(160, intval($opts['width'] ?? $defaults['width']));
		$opts['mode'] = in_array(($opts['mode'] ?? 'both'), ['photo','video','both'], true) ? $opts['mode'] : 'both';
		$opts['show_download'] = !empty($opts['show_download']);
		$opts['filename_base'] = sanitize_file_name($opts['filename_base'] ?? $defaults['filename_base']);
		$opts['save_to_media'] = !empty($opts['save_to_media']);
		$opts['recording_mode'] = in_array(($opts['recording_mode'] ?? 'single'), ['single','segments'], true) ? $opts['recording_mode'] : 'single';
		$opts['max_time'] = max(0, intval($opts['max_time'] ?? 0));
		$opts['show_timer'] = !empty($opts['show_timer']);
		$opts['preview'] = !empty($opts['preview']);
		$opts['allow_set_profile_pic'] = !empty($opts['allow_set_profile_pic']);
		$opts['use_avatar_filter'] = !empty($opts['use_avatar_filter']);
		$opts['chunk_ms'] = max(1000, intval($opts['chunk_ms'] ?? 5000));
		$opts['auto_perf_tuning'] = !empty($opts['auto_perf_tuning']);
		$opts['max_upload_concurrency'] = max(1, intval($opts['max_upload_concurrency'] ?? 2));
		$opts['access_mode'] = in_array(($opts['access_mode'] ?? 'roles'), ['roles','capability'], true) ? $opts['access_mode'] : 'roles';
		$opts['required_capability'] = sanitize_text_field($opts['required_capability'] ?? 'read');
		$opts['keep_settings_on_uninstall'] = !empty($opts['keep_settings_on_uninstall']);

		return array_merge($this->default_options(), $opts);
	}

	public function render_settings_page() {
		if (!current_user_can('manage_options')) { return; }
		$opts = get_option(self::OPTION_KEY, $this->default_options());
		$all_roles = ['subscriber','contributor','author','editor','administrator'];
		?>
		<div class="wrap">
			<h1>NB Camera Settings</h1>
			<p><!-- Version 0.1.0 - Initial settings page. --></p>
			<p><!-- Version 6.3 - Distribution prep, improved detection UX, uninstall behavior. --></p>
			<p><!-- Version 0.3.0 - Added capability-based access and diagnostics. --></p>
			<p><!-- Version 0.2.0 - Added auto performance tuning and upload concurrency. --></p>
			<form method="post" action="options.php">
				<?php settings_fields('nb_camera_settings'); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Access control</th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[access_mode]" value="roles" <?php checked(($opts['access_mode'] ?? 'roles') === 'roles'); ?>> By roles</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[access_mode]" value="capability" <?php checked(($opts['access_mode'] ?? 'roles') === 'capability'); ?>> By capability</label>
							<p class="description">Capability example: upload_files</p>
							<p><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[required_capability]" value="<?php echo esc_attr($opts['required_capability'] ?? 'read'); ?>" placeholder="upload_files"></p>
						</td>
					</tr>
					<tr>
						<th scope="row">Roles that can use camera</th>
						<td>
							<?php foreach ($all_roles as $role): ?>
							<label style="display:inline-block;margin-right:12px;">
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[roles][]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, $opts['roles'], true)); ?>>
								<?php echo esc_html(ucfirst($role)); ?>
							</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Allow public access</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_public_access]" value="1" <?php checked($opts['allow_public_access']); ?>> Allow non-logged-in users to use camera</label>
						<p class="description">When enabled, bypasses role/capability checks. Use for public-facing pages.</p></td>
					</tr>
					<tr>
						<th scope="row">Device selection</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_device_select]" value="1" <?php checked($opts['allow_device_select']); ?>> Show camera selection dropdown</label>
						<p class="description">Allows users to choose between multiple connected cameras/webcams.</p></td>
					</tr>
					<tr>
						<th scope="row">Render mode</th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[popup]" value="0" <?php checked(!$opts['popup']); ?>> Inline</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[popup]" value="1" <?php checked($opts['popup']); ?>> Popup</label>
							<p class="description">Inline shows the camera UI directly; Popup shows a button that opens a modal.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Camera width (px)</th>
						<td><input type="number" min="160" step="10" name="<?php echo esc_attr(self::OPTION_KEY); ?>[width]" value="<?php echo esc_attr($opts['width']); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Capture mode</th>
						<td>
							<select name="<?php echo esc_attr(self::OPTION_KEY); ?>[mode]">
								<option value="both" <?php selected($opts['mode'], 'both'); ?>>Photo and Video</option>
								<option value="photo" <?php selected($opts['mode'], 'photo'); ?>>Photo only</option>
								<option value="video" <?php selected($opts['mode'], 'video'); ?>>Video only</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Show download button</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_download]" value="1" <?php checked($opts['show_download']); ?>> Yes</label></td>
					</tr>
					<tr>
						<th scope="row">Filename base</th>
						<td><input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[filename_base]" value="<?php echo esc_attr($opts['filename_base']); ?>" placeholder="capture"></td>
					</tr>
					<tr>
						<th scope="row">Save to Media Library</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[save_to_media]" value="1" <?php checked($opts['save_to_media']); ?>> Yes</label></td>
					</tr>
					<tr>
						<th scope="row">Video upload mode</th>
						<td>
							<select name="<?php echo esc_attr(self::OPTION_KEY); ?>[recording_mode]">
								<option value="single" <?php selected($opts['recording_mode'], 'single'); ?>>Upload single file on stop</option>
								<option value="segments" <?php selected($opts['recording_mode'], 'segments'); ?>>Upload in segments while recording</option>
							</select>
							<p class="description">Streaming to the library isn’t supported natively; segments simulate a stream by uploading chunks.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Segment size (ms)</th>
						<td><input type="number" min="1000" step="500" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chunk_ms]" value="<?php echo esc_attr($opts['chunk_ms']); ?>">
							<p class="description">Only used when uploading in segments.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Max upload concurrency</th>
						<td><input type="number" min="1" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_upload_concurrency]" value="<?php echo esc_attr($opts['max_upload_concurrency']); ?>">
							<p class="description">Limits simultaneous segment uploads to reduce stutter on slow machines.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Max time (seconds)</th>
						<td><input type="number" min="0" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_time]" value="<?php echo esc_attr($opts['max_time']); ?>"> <span class="description">0 = unlimited</span></td>
					</tr>
					<tr>
						<th scope="row">Show timer on front end</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[show_timer]" value="1" <?php checked($opts['show_timer']); ?>> Yes</label></td>
					</tr>
					<tr>
						<th scope="row">Photo countdown (seconds)</th>
						<td>
							<select name="<?php echo esc_attr(self::OPTION_KEY); ?>[photo_countdown]">
								<option value="0" <?php selected($opts['photo_countdown'], 0); ?>>No countdown</option>
								<option value="3" <?php selected($opts['photo_countdown'], 3); ?>>3 seconds</option>
								<option value="5" <?php selected($opts['photo_countdown'], 5); ?>>5 seconds</option>
								<option value="10" <?php selected($opts['photo_countdown'], 10); ?>>10 seconds</option>
							</select>
							<p class="description">Countdown before taking photo (selfie timer)</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Show preview</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[preview]" value="1" <?php checked($opts['preview']); ?>> Yes</label></td>
					</tr>
					<tr>
						<th scope="row">Allow "Set as Profile Picture"</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[allow_set_profile_pic]" value="1" <?php checked($opts['allow_set_profile_pic']); ?>> Yes</label></td>
					</tr>
					<tr>
						<th scope="row">Auto performance tuning</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[auto_perf_tuning]" value="1" <?php checked($opts['auto_perf_tuning']); ?>> Yes</label>
							<p class="description">Detects slow machines and auto-adjusts chunk size, mode, and upload concurrency on the fly.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Use avatar filter site-wide</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[use_avatar_filter]" value="1" <?php checked($opts['use_avatar_filter']); ?>> Yes</label>
							<p class="description">If enabled, WordPress avatar URLs will use the last image set via NB Camera for that user.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Keep settings on uninstall</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[keep_settings_on_uninstall]" value="1" <?php checked(!empty($opts['keep_settings_on_uninstall'])); ?>> Yes</label>
							<p class="description">If unchecked, uninstalling the plugin will remove NB Camera settings from the database.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; background: #fff5e6; border-left: 4px solid #ff9900;">
				<h2 style="margin-top: 0; color: #ff9900;">📷 NetBound Camera Plugins - Shortcode Reference</h2>
				<p>There are two camera plugins available, both using the same underlying technology. Use whichever shortcode keyword fits your content best:</p>

				<table class="widefat" style="margin: 15px 0;">
					<thead>
						<tr>
							<th>Plugin</th>
							<th>Shortcode</th>
							<th>Best For</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong>NB Camera</strong> (this plugin)</td>
							<td><code>[nb_camera]</code></td>
							<td>Full-featured: photo + video, settings, profile pics</td>
						</tr>
						<tr>
							<td><strong>NetBound Snapshot</strong></td>
							<td><code>[netbound_snapshot]</code></td>
							<td>Quick snapshots, featured images, simpler UI</td>
						</tr>
					</tbody>
				</table>

				<h3 style="color: #ff9900;">🖼️ Iframe/Theme Compatibility Tips</h3>
				<p>If embedding camera in pages where themes override iframe sizing:</p>
				<ul style="line-height: 1.8;">
					<li>Use <code>popup="1"</code> attribute to open camera in a modal overlay</li>
					<li>Wrap shortcode in a div: <code>&lt;div style="min-width: 450px;"&gt;[nb_camera]&lt;/div&gt;</code></li>
					<li>Set explicit width: <code>[nb_camera width="500"]</code></li>
					<li>For Divi/Elementor: Use a "Code" or "HTML" module, not text block</li>
				</ul>

				<h3 style="color: #ff9900;">📋 Example Shortcodes</h3>
				<pre style="background: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto;">
[nb_camera]                           // Full camera with all options
[nb_camera popup="1"]                 // Opens as modal popup
[nb_camera mode="photo"]              // Photo only
[nb_camera mode="video"]              // Video only
[nb_camera width="600"]               // Custom width
[netbound_snapshot]                   // Simple snapshot camera
[netbound_snapshot post_id="123"]     // For specific post featured image
				</pre>
			</div>

			<h2>Webcam detection notes</h2>
			<p>This plugin uses MediaDevices APIs. If device labels don't appear, the browser may require an initial getUserMedia permission grant before enumerateDevices reveals names. See in-file comments for troubleshooting.</p>

			<h2>Diagnostics</h2>
			<p>Run quick checks to verify REST auth and capability.</p>
			<p><button class="button" id="nbc-run-checks" type="button">Run Checks</button></p>
			<pre id="nbc-checks-output" style="max-width:900px; overflow:auto; background:#f6f7f7; padding:8px; border:1px solid #ccd0d4; border-radius:4px;"></pre>
			<script>
			(function(){
				const btn = document.getElementById('nbc-run-checks');
				const out = document.getElementById('nbc-checks-output');
				if (!btn || !out) return;
				const restRoot = <?php echo json_encode( rest_url() ); ?>;
				const nonce = <?php echo json_encode( wp_create_nonce('wp_rest') ); ?>;
				btn.addEventListener('click', async () => {
					out.textContent = 'Running...';
					const results = [];
					const push = (label, ok, info='') => { results.push((ok?'\u2714':'\u2716') + ' ' + label + (info?(': '+info):'')); };
					try {
						const r = await fetch(restRoot + 'wp/v2/users/me', { headers: { 'X-WP-Nonce': nonce }});
						push('REST /wp/v2/users/me', r.ok, 'status '+r.status);
					} catch(e){ push('REST /wp/v2/users/me', false, e.message); }
					try {
						const r2 = await fetch(restRoot + 'nb-camera/v1/set-avatar', { method:'POST', headers: { 'X-WP-Nonce': nonce, 'Content-Type':'application/json' }, body: JSON.stringify({ attachment_id: 0 }) });
						push('REST nb-camera set-avatar', (r2.status===400 || r2.status===200), 'status '+r2.status);
					} catch(e){ push('REST nb-camera set-avatar', false, e.message); }
					try {
						push('Capability upload_files', <?php echo current_user_can('upload_files') ? 'true' : 'false'; ?>);
					} catch(e){ push('Capability upload_files', false, e.message); }
					out.textContent = results.join('\n');
				});
			})();
			</script>
		</div>
		<?php
	}

	public function enqueue_public_assets() {
		// Only enqueue when shortcode present or forced; use a lightweight approach by deferring until shortcode renders
	}

	private function enqueue_front_end_now() {
		$opts = get_option(self::OPTION_KEY, $this->default_options());
		$handle = 'nb-camera-js';
		$css = 'nb-camera-css';
		$base_url = plugin_dir_url(__FILE__);
		wp_enqueue_style($css, $base_url . 'assets/css/nb-camera.css', [], NB_CAMERA_VERSION);
		wp_enqueue_script($handle, $base_url . 'assets/js/nb-camera.js', [], NB_CAMERA_VERSION, true);
		$localized = [
			'options' => $opts,
			'rest' => [
				'root' => esc_url_raw(rest_url()),
				'nonce' => wp_create_nonce('wp_rest'),
				'media' => '/wp/v2/media',
				'avatar' => '/nb-camera/v1/set-avatar',
			],
			'user' => [
				'logged_in' => is_user_logged_in(),
				'can_upload' => current_user_can('upload_files'),
			],
		];
		wp_localize_script($handle, 'NBCAMERA', $localized);
	}

	private function current_user_has_allowed_role(array $allowed): bool {
		if (!is_user_logged_in()) return false;
		$user = wp_get_current_user();
		foreach ((array)$user->roles as $role) {
			if (in_array($role, $allowed, true)) return true;
		}
		return false;
	}

	// TinyMCE toolbar button (Classic Editor)
	public function register_tinymce_button() {
		if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) return;
		if (get_user_option('rich_editing') !== 'true') return;
		add_filter('mce_external_plugins', function($plugins){
			$plugins['nb_camera'] = plugin_dir_url(__FILE__) . 'assets/js/nb-camera-tinymce.js';
			return $plugins;
		});
		add_filter('mce_buttons', function($buttons){
			$buttons[] = 'nb_camera_button';
			return $buttons;
		});
	}

	// QuickTags button (Text editor)
	public function print_quicktags_button() {
		if (!wp_script_is('quicktags')) return;
		if (!current_user_can('edit_posts') && !current_user_can('edit_pages')) return;
		?>
		<script>
		if (window.QTags) {
			QTags.addButton('nb_camera_qt', 'Camera', '[nb_camera popup="1"]', '', 'c', 'Insert NB Camera shortcode', 201);
		}
		</script>
		<?php
	}

	public function shortcode($atts = [], $content = null) {
		$opts = get_option(self::OPTION_KEY, $this->default_options());
		// Shortcode attributes override
		$atts = shortcode_atts([
			'popup' => $opts['popup'] ? '1' : '0',
			'width' => (string)$opts['width'],
			'mode' => $opts['mode'],
			'show_download' => $opts['show_download'] ? '1' : '0',
			'filename_base' => $opts['filename_base'],
			'save_to_media' => $opts['save_to_media'] ? '1' : '0',
			'recording_mode' => $opts['recording_mode'],
			'max_time' => (string)$opts['max_time'],
			'show_timer' => $opts['show_timer'] ? '1' : '0',
			'preview' => $opts['preview'] ? '1' : '0',
			'allow_set_profile_pic' => $opts['allow_set_profile_pic'] ? '1' : '0',
			'photo_countdown' => (string)$opts['photo_countdown'],
			'allow_device_select' => $opts['allow_device_select'] ? '1' : '0',
		], $atts, 'nb_camera');


		// Access check: roles or capability (unless public access enabled)
		if (!$opts['allow_public_access']) {
			if (($opts['access_mode'] ?? 'roles') === 'capability') {
				$cap = $opts['required_capability'] ?? 'read';
				if (!current_user_can($cap)) {
					return '<div class="nbc-permission-msg">You do not have permission to use the camera.</div>';
				}
			} else {
				if (!$this->current_user_has_allowed_role((array)$opts['roles'])) {
					return '<div class="nbc-permission-msg">You do not have permission to use the camera.</div>';
				}
			}
		}

		// Ensure assets enqueued now
		$this->enqueue_front_end_now();

		$container_id = 'nbc_' . wp_generate_uuid4();
		$popup = $atts['popup'] === '1';
		$width = max(160, intval($atts['width']));
		$mode = esc_attr($atts['mode']);

		ob_start();
		?>
		<div class="nbc-root" id="<?php echo esc_attr($container_id); ?>"
			data-width="<?php echo esc_attr($width); ?>"
			data-mode="<?php echo esc_attr($mode); ?>"
			data-show-download="<?php echo esc_attr($atts['show_download']); ?>"
			data-filename-base="<?php echo esc_attr($atts['filename_base']); ?>"
			data-save-to-media="<?php echo esc_attr($atts['save_to_media']); ?>"
			data-recording-mode="<?php echo esc_attr($atts['recording_mode']); ?>"
			data-max-time="<?php echo esc_attr($atts['max_time']); ?>"
			data-show-timer="<?php echo esc_attr($atts['show_timer']); ?>"
			data-preview="<?php echo esc_attr($atts['preview']); ?>"
			data-allow-set-profile-pic="<?php echo esc_attr($atts['allow_set_profile_pic']); ?>"
			data-photo-countdown="<?php echo esc_attr($atts['photo_countdown']); ?>"
			data-allow-device-select="<?php echo esc_attr($atts['allow_device_select']); ?>">
			<?php if ($popup): ?>
				<button type="button" class="nbc-open">Open Camera</button>
				<div class="nbc-modal" hidden>
					<div class="nbc-modal-backdrop" data-nbc-close></div>
					<div class="nbc-modal-content" role="dialog" aria-modal="true" aria-label="Camera">
						<button type="button" class="nbc-close" data-nbc-close>&times;</button>
						<?php echo $this->camera_ui_html($width, $mode); ?>
					</div>
				</div>
			<?php else: ?>
				<?php echo $this->camera_ui_html($width, $mode); ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function camera_ui_html(int $width, string $mode): string {
		ob_start();
		?>
		<div class="nbc-ui" style="max-width: <?php echo esc_attr($width); ?>px;">
			<div class="nbc-row">
				<label>Camera:</label>
				<select class="nbc-device-select"></select>
			</div>
			<div class="nbc-row">
				<button type="button" class="nbc-retry button" title="Scan for cameras">Scan</button>
				<button type="button" class="nbc-flip button" title="Switch front/back camera" hidden>Flip</button>
				<button type="button" class="nbc-mirror button" title="Mirror video display">Mirror</button>
			</div>
			<div class="nbc-video-wrap">
				<video class="nbc-video" playsinline autoplay muted></video>
				<canvas class="nbc-canvas" hidden></canvas>
				<span class="nbc-rec-indicator" hidden></span>
				<div class="nbc-countdown" hidden></div>
			</div>
			<div class="nbc-controls">
				<?php if ($mode !== 'video'): ?>
				<button type="button" class="nbc-photo button button-primary" disabled>Take Photo</button>
				<?php endif; ?>
				<?php if ($mode !== 'photo'): ?>
				<button type="button" class="nbc-rec button button-primary" disabled>Start Recording</button>
				<button type="button" class="nbc-rec-stop button button-secondary" disabled>Stop Recording</button>
				<?php endif; ?>
				<span class="nbc-timer" hidden>00:00</span>
			</div>
			<div class="nbc-actions">
				<a class="nbc-download button" download hidden>Download</a>
				<button type="button" class="nbc-upload button" hidden>Save to Media Library</button>
				<button type="button" class="nbc-set-avatar button" hidden>Set as Profile Picture</button>
			</div>
			<div class="nbc-preview" hidden></div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function register_rest() {
		register_rest_route('nb-camera/v1', '/set-avatar', [
			'methods' => 'POST',
			'callback' => function(WP_REST_Request $req) {
				if (!is_user_logged_in()) {
					return new WP_REST_Response(['message' => 'Unauthorized'], 401);
				}
				$att = intval($req->get_param('attachment_id'));
				if ($att <= 0) {
					return new WP_REST_Response(['message' => 'Invalid attachment'], 400);
				}
				update_user_meta(get_current_user_id(), 'nb_camera_avatar_id', $att);
				return rest_ensure_response(['success' => true, 'attachment_id' => $att]);
			},
			'permission_callback' => function() { return is_user_logged_in(); }
		]);
	}

	public function maybe_avatar_url_override($url, $id_or_email, $args) {
		$opts = get_option(self::OPTION_KEY, $this->default_options());
		if (empty($opts['use_avatar_filter'])) return $url;
		$user = false;
		if (is_numeric($id_or_email)) {
			$user = get_user_by('id', $id_or_email);
		} elseif (is_object($id_or_email) && isset($id_or_email->user_id)) {
			$user = get_user_by('id', $id_or_email->user_id);
		} elseif (is_string($id_or_email)) {
			$user = get_user_by('email', $id_or_email);
		}
		if (!$user) return $url;
		$att_id = intval(get_user_meta($user->ID, 'nb_camera_avatar_id', true));
		if ($att_id > 0) {
			$img = wp_get_attachment_image_src($att_id, 'thumbnail');
			if ($img && !empty($img[0])) return $img[0];
		}
		return $url;
	}
}

// Bootstrap
new NB_Camera_Plugin();
