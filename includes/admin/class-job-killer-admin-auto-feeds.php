<?php
/**
 * Job Killer Admin Auto Feeds
 *
 * @package Job_Killer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle automatic feeds administration
 */
class Job_Killer_Admin_Auto_Feeds {
    
    /**
     * Providers manager
     */
    private $providers_manager;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->providers_manager = new Job_Killer_Providers_Manager();
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('wp_ajax_job_killer_delete_auto_feed', array($this, 'ajax_delete_auto_feed'));
        add_action('wp_ajax_job_killer_toggle_auto_feed', array($this, 'ajax_toggle_auto_feed'));
        add_action('wp_ajax_job_killer_import_auto_feed', array($this, 'ajax_import_auto_feed'));
    }
    
    /**
     * Render auto feeds page
     */
    public function render_page() {
        $auto_feeds = Job_Killer_Feeds_Store::get_all();
        $providers = Job_Killer_Providers_Registry::get_all_providers();
        
        // Filter to only show providers with instances
        $available_providers = array();
        foreach ($providers as $provider_id => $provider_config) {
            $instance = Job_Killer_Providers_Registry::get_provider_instance($provider_id);
            if ($instance && method_exists($instance, 'get_provider_info')) {
                $available_providers[$provider_id] = $instance->get_provider_info();
            }
        }
        
        $providers = $available_providers;
        
        include JOB_KILLER_PLUGIN_DIR . 'includes/templates/admin/auto-feeds.php';
    }
    
    /**
     * Delete auto feed (AJAX)
     */
    public function ajax_delete_auto_feed() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $feed_id = intval($_POST['feed_id'] ?? 0);
        
        if ($feed_id <= 0) {
            wp_send_json_error(__('Feed ID is required', 'job-killer'));
        }
        
        $feed = Job_Killer_Feeds_Store::get($feed_id);
        
        if (!$feed) {
            wp_send_json_error(__('Feed not found', 'job-killer'));
        }
        
        $result = Job_Killer_Feeds_Store::delete($feed_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        if (class_exists('Job_Killer_Helper')) {
            $helper = new Job_Killer_Helper();
            $helper->log('info', 'admin', 
                sprintf('Auto feed "%s" deleted', $feed['name']),
                array('feed_id' => $feed_id)
            );
        }
        
        wp_send_json_success(array(
            'message' => __('Feed deleted successfully!', 'job-killer')
        ));
    }
    
    /**
     * Toggle auto feed status (AJAX)
     */
    public function ajax_toggle_auto_feed() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $feed_id = intval($_POST['feed_id'] ?? 0);
        
        if ($feed_id <= 0) {
            wp_send_json_error(__('Feed ID is required', 'job-killer'));
        }
        
        $feed = Job_Killer_Feeds_Store::get($feed_id);
        
        if (!$feed) {
            wp_send_json_error(__('Feed not found', 'job-killer'));
        }
        
        $new_status = Job_Killer_Feeds_Store::toggle_active($feed_id);
        
        $status = $new_status ? __('activated', 'job-killer') : __('deactivated', 'job-killer');
        
        if (class_exists('Job_Killer_Helper')) {
            $helper = new Job_Killer_Helper();
            $helper->log('info', 'admin', 
                sprintf('Auto feed "%s" %s', $feed['name'], $status),
                array('feed_id' => $feed_id, 'active' => $new_status)
            );
        }
        
        wp_send_json_success(array(
            'message' => sprintf(__('Feed %s successfully!', 'job-killer'), $status),
            'active' => $new_status
        ));
    }
    
    /**
     * Import from auto feed (AJAX)
     */
    public function ajax_import_auto_feed() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $feed_id = intval($_POST['feed_id'] ?? 0);
        
        if ($feed_id <= 0) {
            wp_send_json_error(__('Feed ID is required', 'job-killer'));
        }
        
        $feed = Job_Killer_Feeds_Store::get($feed_id);
        
        if (!$feed) {
            wp_send_json_error(__('Feed not found', 'job-killer'));
        }
        
        $provider = Job_Killer_Providers_Registry::get_provider_instance($feed['provider']);
        
        if (!$provider) {
            wp_send_json_error(__('Provider not found', 'job-killer'));
        }
        
        try {
            $imported = $provider->import_jobs($feed);
            
            // Update last import time
            Job_Killer_Feeds_Store::update_last_import($feed_id, $imported);
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully imported %d jobs!', 'job-killer'), $imported),
                'imported' => $imported
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}