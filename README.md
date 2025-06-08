# Featured Media Auto-Attachment (MU-Plugin) by onwardSEO

**Atomically attaches featured media (sets post_parent) on REST API post creation for WordPress.**

## Description

This Must-Use (MU) plugin for WordPress, developed by **onwardSEO**, automatically sets the `post_parent` attribute of a featured media item to the ID of the post it's assigned to. This action is performed atomically during post creation via the WordPress REST API, specifically when the `featured_media` parameter is used. It includes rollback support to ensure data integrity if the parent update fails.

This is particularly useful for workflows where images are uploaded and then immediately assigned as featured media to new posts created programmatically, ensuring the media item is correctly "attached" to its post in the WordPress Media Library.

## Features

*   Targets post creation via the REST API (`post`, `page`, and CPTs with `show_in_rest`).
*   Focuses solely on setting the `post_parent` of the media item specified in `featured_media`.
*   Verifies user permissions (`edit_post` for the new post, `edit_post` for the media item).
*   Validates the media item (is an image, exists, not trashed).
*   Atomic operation: If updating the media's `post_parent` fails, it attempts to roll back (though in this focused version, the primary operation *is* the parent update).
*   Includes `WP_DEBUG` conditional logging for troubleshooting.
*   Lightweight and designed for MU-plugin usage.

## Installation

1.  Ensure you have an `mu-plugins` directory in your `wp-content` folder. If not, create it: `wp-content/mu-plugins/`.
2.  Place the plugin file (e.g., `featured-media-auto-attachment.php`) directly into the `wp-content/mu-plugins/` directory.
3.  That's it! MU-plugins are automatically activated.

## Requirements

*   WordPress 6.0 or higher
*   PHP 8.1 or higher

## How it Works

The plugin hooks into `rest_after_insert_{$post_type}`. When a new post is created via the REST API and a `featured_media` ID is provided:
1. It verifies the request is for a new post creation.
2. It checks if the current user has permissions to edit the new post and the specified media item.
3. It validates that the `featured_media` ID is a valid, usable image attachment.
4. It confirms that WordPress core has successfully set this media item as the post's thumbnail.
5. It attempts to update the `post_parent` of the media item to the new post's ID.
6. If the update fails, it logs the error.
7. It clears relevant caches upon successful update or rollback.

## Logging

If `WP_DEBUG` is enabled in your `wp-config.php`, the plugin will output diagnostic messages to your `debug.log` file, prefixed with `[FMA vX.Y.Z]`.

## Author

*   **onwardSEO**

---

*This plugin was developed by onwardSEO to address specific enterprise needs for atomic media attachment during REST API operations.*
