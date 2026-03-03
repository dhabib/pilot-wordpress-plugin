=== Pilot WMS ===
Contributors: dhabib
Tags: pilot, wms, webhook, content, automation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receives webhook events from Pilot WMS and automatically creates WordPress posts.

== Description ==

Pilot WMS is a WordPress plugin that connects your WordPress site to [Pilot WMS](https://pilotwme.com), a wisdom engine that generates and manages content through AI-powered editorial workflows.

When content is published, updated, or unpublished in Pilot WMS, this plugin receives a signed webhook and automatically:

* Creates a new WordPress post (as draft or pending review)
* Updates existing posts when content changes
* Reverts posts to draft when content is unpublished
* Sideloads featured images into the WordPress media library
* Tags all Pilot-created posts with a configurable marker tag
* Stores provenance metadata (source artifacts, projection ID, tenant ID)

All webhook payloads are verified using HMAC-SHA256 signatures with replay protection.

== Installation ==

1. Upload the `pilot-wordpress-plugin` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings > Pilot WMS
4. Paste your webhook secret from your Pilot WMS channel configuration
5. Choose your preferred default category and post status

In Pilot WMS, create a CMS Webhook channel pointing to:

    https://your-site.com/wp-json/pilot/v1/webhook

Use the "Test Webhook" button in Pilot WMS to verify the connection.

== Frequently Asked Questions ==

= What post type does this create? =

Standard WordPress posts (`post`). Posts are tagged with a configurable marker tag (default: `pilot-wms`) for easy identification.

= Can I review posts before they go live? =

Yes. By default, posts are created as drafts. You can also set them to "Pending Review" in the plugin settings. Either way, no post is published automatically — you retain full editorial control.

= How does the plugin handle duplicate deliveries? =

The plugin is fully idempotent. If the same content is delivered twice, the second delivery updates the existing post rather than creating a duplicate. Posts are matched by their Pilot projection ID stored in post meta.

= What user is assigned as the post author? =

A "Staff" user (login: `pilot-staff`) with the Author role is created on activation. All Pilot-created posts are attributed to this user.

= How are images handled? =

When a webhook payload includes an `image_url`, the plugin downloads the image, adds it to the WordPress media library, and sets it as the post's featured image. On updates, the image is only re-downloaded if the URL has changed.

== Changelog ==

= 1.0.0 =
* Initial release
* Webhook endpoint with HMAC-SHA256 signature verification
* Create, update, and unpublish post handling
* Image sideloading with change detection
* Settings page with webhook secret, default category, post status, and marker tag
* Idempotent delivery handling
