=== NB Camera (NetBound) ===
Contributors: orangejeff
Tags: camera, media, video, photo, webcam, recorder, avatar, profile picture
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 5.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The most robust and versatile camera recorder for WordPress. Capture photos/videos from any device directly to the Media Library or Profile.

== Description ==
NB Camera is the most robust and versatile camera recorder for the front-end or back-end, giving you the opportunity to upload to the website from any computer, anywhere you can log on. Video or still photos can be downloaded immediately, added to the WordPress Media Library, or used as the user's Profile photo.

Built for flexibility, it supports inline rendering, popup modes, device selection, and robust permission handling.

**NetBound Tools - Free Open Source Tools.**
Created by OrangeJeff with the help of Gemini AI.

**Features:**
- **Universal Capture:** Works on desktop and mobile webcams.
- **Media Integration:** Uploads directly to the WordPress Media Library.
- **Profile Pictures:** Users can set their webcam snapshot as their avatar instantly.
- ** robust & Resilient:** Smart falbacks and retry logic for browser permissions.
- **Performance Tuned:** Auto-adjusts settings for slower devices.
- **Photo-Only Version:** Also available without video option as a separate plugin (NB Snapshot).

Highlights:
- Webcam detection with clear comments and robust fallbacks
- Photo capture and video recording (single file at stop, or "segments" to simulate streaming)
- Download link toggle
- Filename base with auto-increment per browser
- Upload to Media Library via REST (respects user capabilities)
- Optional "Set as Profile Picture" (stores attachment ID in user meta)
- Optional site-wide avatar filter to use NB Camera image for get_avatar() calls
- Auto performance tuning on slow machines (optional)
- Upload queue with concurrency limit to reduce stutter when uploading segments
- Retry and Flip controls to recover from browser permission prompts and to switch front/back on mobile

Privacy/Permissions:
- Camera access is always gated by the browser permission prompt.
- Uploads require a logged-in user with upload_files capability.

== Installation ==
1. Upload the `nb-camera` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure settings under NetBound Tools > NB Camera.
4. Use the shortcode `[nb_camera]` in posts/pages.

== Frequently Asked Questions ==

= Why do camera labels show as "Camera 1" until after permission? =
Browsers hide device labels until getUserMedia is granted. After starting the camera once, labels typically appear. The plugin retries detection, listens for `devicechange`, and re-detects after tab focus to handle permission UI pauses.

= Does it stream live to the webpage? =
No, NB Camera records video clips (or segments) and uploads them to your server. It is not a live streaming server.
For continuous live streaming (broadcast to others), please check out our companion plugin **NB VDO.Ninja**.

= Does it stream into the Media Library? =
WordPress does not support true live streaming into the Media Library. "Segments" mode uploads chunks during recording to approximate streaming/long-form recording, but it is not for live playback.

= Can it update the user's avatar everywhere? =
Enable "Use avatar filter site-wide" in settings to make get_avatar() return the last NB Camera image for that user.

== Screenshots ==
1. Frontend Interface: The clean camera UI with device selection and controls.
2. Admin Settings: Configuration for upload modes, timers, and permissions.
3. Profile Picture Integration: How users can set their webcam capture as their avatar.

== Changelog ==
= 6.3 =
- Distribution prep: i18n, uninstall behavior option, improved device detection (Retry/Flip, devicechange, visibility/backoff). Readme added.

= 0.3.0 =
- Capability-based access option and admin diagnostics.

= 0.2.0 =
- Auto performance tuning and upload concurrency limit.

= 0.1.0 =
- Initial release: settings, shortcode, webcam detection, recording, uploads, profile picture option.

== Upgrade Notice ==
= 6.3 =
Adds robust detection for permission pauses, Retry/Flip UI, i18n loader, and uninstall option. Recommended update.
