<?php
/**
 * Job Killer Feeds Store
 *
 * @package Job_Killer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle feed storage and retrieval
 */
class Job_Killer_Feeds_Store {
    
    /**
     * Feed post type
     */
    const POST_TYPE = 'jk_feed';
    
    /**
     * Initialize
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'register_post_type'));
    }
    
    /**
     * Register feed post type
     */
    public static function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Job Feeds', 'job-killer'),
                'singular_name' => __('Job Feed', 'job-killer')
            ),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'capability_type' => 'post',
            'supports' => array('title'),
            'rewrite' => false
        ));
    }
    
    /**
     * Insert new feed
     */
    public static function insert($data) {
        $post_data = array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => sanitize_text_field($data['name']),
            'meta_input' => array(
                '_jk_feed_url' => esc_url_raw($data['url'] ?? ''),
                '_jk_feed_provider' => sanitize_text_field($data['provider'] ?? 'generic_rss'),
                '_jk_feed_active' => !empty($data['active']) ? 1 : 0,
                '_jk_feed_auth' => wp_json_encode($data['auth'] ?? array()),
                '_jk_feed_parameters' => wp_json_encode($data['parameters'] ?? array()),
                '_jk_feed_created_at' => current_time('mysql'),
                '_jk_feed_updated_at' => current_time('mysql')
            )
        );
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        return $post_id;
    }
    
    /**
     * Update existing feed
     */
    public static function update($id, $data) {
        $post_data = array(
            'ID' => $id,
            'post_title' => sanitize_text_field($data['name']),
            'meta_input' => array(
                '_jk_feed_url' => esc_url_raw($data['url'] ?? ''),
                '_jk_feed_provider' => sanitize_text_field($data['provider'] ?? 'generic_rss'),
                '_jk_feed_active' => !empty($data['active']) ? 1 : 0,
                '_jk_feed_auth' => wp_json_encode($data['auth'] ?? array()),
                '_jk_feed_parameters' => wp_json_encode($data['parameters'] ?? array()),
                '_jk_feed_updated_at' => current_time('mysql')
            )
        );
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $id;
    }
    
    /**
     * Get feed by ID
     */
    public static function get($id) {
        $post = get_post($id);
        
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return null;
        }
        
        return array(
            'id' => $post->ID,
            'name' => $post->post_title,
            'url' => get_post_meta($post->ID, '_jk_feed_url', true),
            'provider' => get_post_meta($post->ID, '_jk_feed_provider', true),
            'active' => get_post_meta($post->ID, '_jk_feed_active', true),
            'auth' => json_decode(get_post_meta($post->ID, '_jk_feed_auth', true), true) ?: array(),
            'parameters' => json_decode(get_post_meta($post->ID, '_jk_feed_parameters', true), true) ?: array(),
            'created_at' => get_post_meta($post->ID, '_jk_feed_created_at', true),
            'updated_at' => get_post_meta($post->ID, '_jk_feed_updated_at', true),
            'last_import' => get_post_meta($post->ID, '_jk_feed_last_import', true)
        );
    }
    
    /**
     * Get all feeds
     */
    public static function get_all($active_only = false) {
        $args = array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        if ($active_only) {
            $args['meta_query'] = array(
                array(
                    'key' => '_jk_feed_active',
                    'value' => '1',
                    'compare' => '='
                )
            );
        }
        
        $posts = get_posts($args);
        $feeds = array();
        
        foreach ($posts as $post) {
            $feeds[$post->ID] = self::get($post->ID);
        }
        
        return $feeds;
    }
    
    /**
     * Delete feed
     */
    public static function delete($id) {
        $result = wp_delete_post($id, true);
        
        if (!$result) {
            return new WP_Error('delete_failed', __('Failed to delete feed', 'job-killer'));
        }
        
        return true;
    }
    
    /**
     * Update last import time
     */
    public static function update_last_import($id, $imported_count = 0) {
        update_post_meta($id, '_jk_feed_last_import', current_time('mysql'));
        update_post_meta($id, '_jk_feed_last_import_count', intval($imported_count));
    }
    
    /**
     * Toggle feed active status
     */
    public static function toggle_active($id) {
        $current_status = get_post_meta($id, '_jk_feed_active', true);
        $new_status = $current_status ? 0 : 1;
        
        update_post_meta($id, '_jk_feed_active', $new_status);
        update_post_meta($id, '_jk_feed_updated_at', current_time('mysql'));
        
        return $new_status;
    }
}

// Initialize feeds store
Job_Killer_Feeds_Store::init();