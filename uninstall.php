<?php
// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$option_key = 'nb_camera_options';
$opts = get_option($option_key);

// Default behavior: keep settings unless explicitly disabled in options
$keep = true;
if (is_array($opts) && array_key_exists('keep_settings_on_uninstall', $opts)) {
	$keep = !empty($opts['keep_settings_on_uninstall']);
}

if (!$keep) {
	if (function_exists('delete_option')) {
		delete_option($option_key);
	}
}
