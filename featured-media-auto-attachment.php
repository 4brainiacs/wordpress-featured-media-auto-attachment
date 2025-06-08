<?php
/**
 * Plugin Name: Featured Media Auto-Attachment
 * Description: Atomically sets the post_parent for featured media on REST API post creation.
 * Version: 4.1.0
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Author: onwardSEO
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Prevent duplicate initialization (versioned for clarity if multiple versions exist)
if (defined('FMA_INITIALIZED_V4_1_0')) {
    return;
}
define('FMA_INITIALIZED_V4_1_0', true);

/**
 * Production-hardened engine for atomically setting media post_parent.
 */
final class FMA_Production_Engine {

    private static ?self $instance = null;

    /**
     * Transaction state for atomic parent update operations.
     * Keyed by post_id, stores original parent data for the media item.
     */
    private array $transaction_state = [];

    /**
     * Bootstrap with environment verification.
     */
    public static function init(): void {
        if (!self::environmentReady()) {
            // Error is logged by environmentReady()
            return;
        }

        if (self::$instance === null) {
            self::$instance = new self();
        }
    }

    /**
     * Lightweight constructor.
     */
    private function __construct() {
        $this->registerHooks();
    }

    /**
     * Optimized environment check with static caching.
     */
    private static function environmentReady(): bool {
        static $verified = null;

        if ($verified !== null) {
            return $verified;
        }

        $required_functions = [
            'get_post_thumbnail_id', 'wp_update_post', 'wp_attachment_is_image',
            'current_user_can', 'get_post_field', 'clean_post_cache',
            'clean_attachment_cache', 'get_post_types', 'add_action',
            'get_post', 'error_log', 'wp_json_encode', 'memory_get_usage'
        ];

        foreach ($required_functions as $fn) {
            if (!function_exists($fn)) {
                error_log("[FMA v4.1.0] Critical: Missing WordPress core function {$fn}. Plugin disabled.");
                return $verified = false;
            }
        }

        global $wpdb;
        if (!$wpdb || !is_object($wpdb)) {
            error_log("[FMA v4.1.0] Critical: \$wpdb is not available. Plugin disabled.");
            return $verified = false;
        }
        
        return $verified = true;
    }

    /**
     * Register hooks with lazy CPT loading.
     */
    private function registerHooks(): void {
        // Hooks for core post types. Priority 10 is default.
        // This should run after WP core has processed 'featured_media' from the REST request.
        add_action('rest_after_insert_post', [$this, 'processParentAttachment'], 10, 3);
        add_action('rest_after_insert_page', [$this, 'processParentAttachment'], 10, 3);

        // Defer CPT hook registration to 'rest_api_init' to ensure CPTs are registered.
        add_action('rest_api_init', [$this, 'registerCptHooks']);
    }

    /**
     * Register CPT hooks once on the first relevant REST API request.
     */
    public function registerCptHooks(): void {
        static $cpt_hooks_registered = false;

        if ($cpt_hooks_registered) {
            return;
        }
        $cpt_hooks_registered = true;

        $custom_post_types = get_post_types(
            ['public' => true, '_builtin' => false, 'show_in_rest' => true],
            'names'
        );

        foreach ($custom_post_types as $type) {
            add_action("rest_after_insert_{$type}", [$this, 'processParentAttachment'], 10, 3);
        }
    }

    /**
     * Core processing logic for attaching media parent.
     * Assumes the featured image (_thumbnail_id) has already been set by WordPress core.
     */
    public function processParentAttachment(WP_Post $post, WP_REST_Request $request, bool $creating): void {
        if (!$creating) {
            return; // Only act on new post creation
        }

        if (!current_user_can('edit_post', $post->ID)) {
            $this->log('Permission denied: Cannot edit the newly created post.', ['post_id' => $post->ID]);
            return;
        }

        $media_id = $this->extractMediaId($request);
        if ($media_id === 0) {
            // No 'featured_media' in request, or it's invalid. Plugin's job is tied to this param.
            // $this->log('No valid media ID in request for parent attachment.', ['post_id' => $post->ID]); // Optional: log if needed for debugging specific requests
            return;
        }

        // Verify WP core successfully set this media_id as the featured image
        $current_thumbnail_id = (int) get_post_thumbnail_id($post->ID);
        if ($current_thumbnail_id !== $media_id) {
            $this->log(
                'Featured image mismatch or not set by core as expected.',
                [
                    'post_id' => $post->ID, 'expected_media_id' => $media_id,
                    'actual_thumbnail_id' => $current_thumbnail_id,
                    'note' => 'Plugin will not attach parent if featured_media was not successfully processed by WP core.'
                ]
            );
            return;
        }

        // Crucial: Can the current user edit the media item to change its parent?
        if (!current_user_can('edit_post', $media_id)) {
            $this->log(
                'Permission denied: Cannot edit media item to set parent.',
                ['post_id' => $post->ID, 'media_id' => $media_id, 'user_id' => get_current_user_id()]
            );
            return;
        }
        
        if (!$this->validateAttachment($media_id)) {
            // Logged within validateAttachment
            return;
        }

        $this->executeAtomicParentUpdate($post->ID, $media_id);
    }

    /**
     * Extracts and validates media ID from the REST request.
     */
    private function extractMediaId(WP_REST_Request $request): int {
        $media_raw = $request->get_param('featured_media');
        if (!is_numeric($media_raw)) {
            return 0;
        }
        $media_id = (int) $media_raw;
        return $media_id > 0 ? $media_id : 0;
    }

    /**
     * Validates if the media ID corresponds to a valid, usable image attachment.
     */
    private function validateAttachment(int $media_id): bool {
        if (!wp_attachment_is_image($media_id)) {
            $this->log('Validation failed: Media is not an image.', ['media_id' => $media_id]);
            return false;
        }

        $attachment_post = get_post($media_id);
        if (!$attachment_post || $attachment_post->post_type !== 'attachment') {
            $this->log('Validation failed: Media is not a valid attachment post type.', ['media_id' => $media_id]);
            return false;
        }

        if ($attachment_post->post_status === 'trash') {
            $this->log('Validation failed: Media is in trash.', ['media_id' => $media_id]);
            return false;
        }
        return true;
    }

    /**
     * Atomically updates the media item's post_parent.
     * Rolls back only the parent update on failure.
     */
    private function executeAtomicParentUpdate(int $post_id, int $media_id): void {
        $original_parent_id = (int) get_post_field('post_parent', $media_id);

        if ($original_parent_id === $post_id) {
            $this->log('Media already attached to this post.', ['post_id' => $post_id, 'media_id' => $media_id]);
            return;
        }

        $this->transaction_state[$post_id] = [
            'media_id'           => $media_id,
            'original_parent_id' => $original_parent_id,
        ];

        try {
            $update_result = wp_update_post(
                ['ID' => $media_id, 'post_parent' => $post_id],
                true // Return WP_Error on failure
            );

            if (is_wp_error($update_result)) {
                throw new RuntimeException('Failed to update media post_parent: ' . $update_result->get_error_message());
            }

            $this->log('Media parent successfully updated.', ['post_id' => $post_id, 'media_id' => $media_id, 'new_parent' => $post_id]);
            $this->invalidateCaches($post_id, $media_id);
            unset($this->transaction_state[$post_id]); // Commit

        } catch (Throwable $e) {
            $this->log(
                'Parent update failed, attempting rollback.',
                ['post_id' => $post_id, 'media_id' => $media_id, 'error' => $e->getMessage()]
            );
            $this->rollbackParentUpdate($post_id); // Handles unsetting transaction_state
        }
    }

    /**
     * Clears relevant WordPress caches for the post and media item.
     */
    private function invalidateCaches(int $post_id, int $media_id): void {
        clean_post_cache($post_id);
        clean_attachment_cache($media_id); // Alias for clean_post_cache($media_id)

        do_action('fma_caches_cleared', $post_id, $media_id);
        $this->log('Caches invalidated.', ['post_id' => $post_id, 'media_id' => $media_id]);
    }

    /**
     * Rolls back the post_parent of the media item to its original state.
     */
    private function rollbackParentUpdate(int $post_id): void {
        if (!isset($this->transaction_state[$post_id])) {
            return; // No active transaction for this post
        }

        $state = $this->transaction_state[$post_id];
        $media_id = $state['media_id'];
        $original_parent_id = $state['original_parent_id'];

        try {
            $this->log(
                'Rolling back media parent.',
                ['post_id' => $post_id, 'media_id' => $media_id, 'restoring_parent_to' => $original_parent_id]
            );

            $rollback_result = wp_update_post(
                ['ID' => $media_id, 'post_parent' => $original_parent_id],
                true // Return WP_Error on failure
            );

            if (is_wp_error($rollback_result)) {
                $this->log(
                    'CRITICAL: Rollback of media parent FAILED.',
                    [
                        'post_id' => $post_id, 'media_id' => $media_id,
                        'intended_original_parent' => $original_parent_id,
                        'error' => $rollback_result->get_error_message(),
                    ]
                );
            } else {
                $this->log('Media parent successfully rolled back.', ['post_id' => $post_id, 'media_id' => $media_id]);
                $this->invalidateCaches($post_id, $media_id); // Clear caches after successful rollback
            }
        } catch (Throwable $e) {
            $this->log(
                'CRITICAL: Exception during media parent rollback.',
                ['post_id' => $post_id, 'media_id' => $media_id, 'error' => $e->getMessage()]
            );
        } finally {
            unset($this->transaction_state[$post_id]); // Always clear transaction state
        }
    }

    /**
     * Conditional debug logging. Logs if WP_DEBUG is true.
     */
    private function log(string $message, array $context = []): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_entry = sprintf('[FMA v4.1.0] %s', $message);
        if (!empty($context)) {
            $log_entry .= ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        // $log_entry .= sprintf(' | Memory: %.2f MB', memory_get_usage(true) / (1024 * 1024)); // Optional
        error_log($log_entry);
    }
}

// Initialize the engine
try {
    FMA_Production_Engine::init();
} catch (Throwable $e) {
    error_log('[FMA v4.1.0] Critical initialization failure: ' . $e->getMessage());
}
