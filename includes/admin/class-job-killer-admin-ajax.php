<?php
/**
 * Job Killer Admin AJAX Class
 *
 * @package Job_Killer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle admin AJAX requests
 */
class Job_Killer_Admin_Ajax {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Feed management
        add_action('wp_ajax_job_killer_test_feed', array($this, 'test_feed'));
        add_action('wp_ajax_job_killer_save_feed', array($this, 'save_feed'));
        add_action('wp_ajax_job_killer_delete_feed', array($this, 'delete_feed'));
        add_action('wp_ajax_job_killer_toggle_feed', array($this, 'toggle_feed'));
        add_action('wp_ajax_job_killer_import_feed', array($this, 'import_feed'));
        
        // Scheduling
        add_action('wp_ajax_job_killer_run_import', array($this, 'run_import'));
        add_action('wp_ajax_job_killer_update_schedule', array($this, 'update_schedule'));
        
        // Logs
        add_action('wp_ajax_job_killer_clear_logs', array($this, 'clear_logs'));
        add_action('wp_ajax_job_killer_export_logs', array($this, 'export_logs'));
        
        // Settings
        add_action('wp_ajax_job_killer_reset_settings', array($this, 'reset_settings'));
        add_action('wp_ajax_job_killer_export_settings', array($this, 'export_settings'));
        add_action('wp_ajax_job_killer_import_settings', array($this, 'import_settings'));
        
        // System
        add_action('wp_ajax_job_killer_system_info', array($this, 'system_info'));
        add_action('wp_ajax_job_killer_get_chart_data', array($this, 'get_chart_data'));
        
        // Auto feeds
        add_action('wp_ajax_job_killer_test_provider', array($this, 'test_provider'));
        add_action('wp_ajax_job_killer_save_auto_feed', array($this, 'save_auto_feed'));
        add_action('wp_ajax_job_killer_get_provider_params', array($this, 'get_provider_params'));
        
        // Debug actions
        add_action('wp_ajax_job_killer_debug_action', array($this, 'debug_action'));
        add_action('wp_ajax_job_killer_test_connection', array($this, 'test_connection'));
        add_action('wp_ajax_job_killer_get_live_logs', array($this, 'get_live_logs'));
    }
    
    /**
     * Debug action handler
     */
    public function debug_action() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $action = sanitize_text_field($_POST['debug_action'] ?? '');
        
        switch ($action) {
            case 'clear_cache':
                Job_Killer_Cache::clear_all();
                wp_send_json_success(__('Cache cleared successfully', 'job-killer'));
                break;
                
            case 'reset_cron':
                if (class_exists('Job_Killer_Cron')) {
                    $cron = new Job_Killer_Cron();
                    $cron->reschedule_all();
                }
                wp_send_json_success(__('Cron jobs reset successfully', 'job-killer'));
                break;
                
            case 'test_import':
                $this->run_import();
                wp_send_json_success(__('Test import completed', 'job-killer'));
                break;
                
            case 'export_debug':
                $debug_info = $this->get_debug_info();
                wp_send_json_success($debug_info);
                break;
                
            default:
                wp_send_json_error(__('Unknown debug action', 'job-killer'));
        }
    }
    
    /**
     * Test connection handler
     */
    public function test_connection() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $test_type = sanitize_text_field($_POST['test_type'] ?? '');
        
        switch ($test_type) {
            case 'curl':
                $result = $this->test_curl();
                break;
                
            case 'xml':
                $result = $this->test_xml_parsing();
                break;
                
            case 'database':
                $result = $this->test_database();
                break;
                
            case 'cron':
                $result = $this->test_cron();
                break;
                
            default:
                wp_send_json_error(__('Unknown test type', 'job-killer'));
                return;
        }
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Get live logs
     */
    public function get_live_logs() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $last_id = intval($_POST['last_id'] ?? 0);
        
        if (class_exists('Job_Killer_Helper')) {
            $helper = new Job_Killer_Helper();
            $logs = $helper->get_logs(array(
                'limit' => 50,
                'offset' => 0,
                'order' => 'DESC'
            ));
            
            // Filter logs newer than last_id
            $new_logs = array_filter($logs, function($log) use ($last_id) {
                return intval($log->id) > $last_id;
            });
            
            wp_send_json_success(array('logs' => array_values($new_logs)));
        } else {
            wp_send_json_success(array('logs' => array()));
        }
    }
    
    /**
     * Test cURL functionality
     */
    private function test_curl() {
        if (!function_exists('curl_version')) {
            return array(
                'success' => false,
                'message' => __('cURL extension not available', 'job-killer')
            );
        }
        
        $curl_info = curl_version();
        
        return array(
            'success' => true,
            'data' => array(
                'version' => $curl_info['version'],
                'ssl_version' => $curl_info['ssl_version'],
                'protocols' => $curl_info['protocols']
            )
        );
    }
    
    /**
     * Test XML parsing
     */
    private function test_xml_parsing() {
        $test_xml = '<?xml version="1.0"?><test><item>Test content</item></test>';
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($test_xml);
        
        if ($xml === false) {
            return array(
                'success' => false,
                'message' => __('XML parsing failed', 'job-killer')
            );
        }
        
        return array(
            'success' => true,
            'data' => array(
                'libxml_version' => LIBXML_DOTTED_VERSION,
                'test_result' => (string) $xml->item
            )
        );
    }
    
    /**
     * Test database connection
     */
    private function test_database() {
        global $wpdb;
        
        $result = $wpdb->get_var("SELECT 1");
        
        if ($result !== '1') {
            return array(
                'success' => false,
                'message' => __('Database connection failed', 'job-killer')
            );
        }
        
        return array(
            'success' => true,
            'data' => array(
                'mysql_version' => $wpdb->db_version(),
                'charset' => $wpdb->charset,
                'collate' => $wpdb->collate
            )
        );
    }
    
    /**
     * Test cron functionality
     */
    private function test_cron() {
        $next_import = wp_next_scheduled('job_killer_import_jobs');
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        
        return array(
            'success' => true,
            'data' => array(
                'wp_cron_disabled' => $cron_disabled,
                'next_import' => $next_import ? date('Y-m-d H:i:s', $next_import) : 'Not scheduled',
                'current_time' => current_time('mysql'),
                'timezone' => wp_timezone_string()
            )
        );
    }
    
    /**
     * Get debug information
     */
    private function get_debug_info() {
        if (class_exists('Job_Killer_Helper')) {
            $helper = new Job_Killer_Helper();
            $system_info = $helper->get_system_info();
        } else {
            $system_info = array();
        }
        
        $debug_info = array(
            'plugin_version' => JOB_KILLER_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'system_info' => $system_info,
            'active_feeds' => count(Job_Killer_Feeds_Store::get_all(true)),
            'total_feeds' => count(Job_Killer_Feeds_Store::get_all()),
            'providers' => array_keys(Job_Killer_Providers_Registry::get_all_providers())
        );
        
        return $debug_info;
    }
    
    /**
     * Test RSS feed
     */
    public function test_feed() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $url = sanitize_url($_POST['url'] ?? '');
        
        if (empty($url)) {
            wp_send_json_error(__('Feed URL is required', 'job-killer'));
        }
        
        try {
            // Detect provider
            $provider_id = Job_Killer_Providers_Registry::get_provider_from_url($url);
            $provider_config = Job_Killer_Providers_Registry::get_provider($provider_id);
            
            if (!$provider_config) {
                wp_send_json_error(__('Unsupported feed provider', 'job-killer'));
            }
            
            // Test based on provider type
            if ($provider_id === 'whatjobs') {
                // Extract publisher ID from URL or use test ID
                $test_config = array(
                    'auth' => array('publisher_id' => 'test'),
                    'parameters' => array('limit' => 5, 'only_today' => true)
                );
                
                $provider_instance = Job_Killer_Providers_Registry::get_provider_instance($provider_id);
                if (!$provider_instance) {
                    wp_send_json_error(__('Provider instance not available', 'job-killer'));
                }
                
                $result = $provider_instance->test_connection($test_config);
                
                if ($result['success']) {
                    wp_send_json_success(array(
                        'message' => $result['message'],
                        'jobs_found' => $result['jobs_found'],
                        'sample_jobs' => $result['sample_jobs'],
                        'provider' => $provider_id,
                        'provider_name' => $provider_config['name'],
                        'api_url' => $result['api_url']
                    ));
                } else {
                    wp_send_json_error($result['message']);
                }
            } else {
                // RSS feed testing
                $helper = new Job_Killer_Helper();
                $validation = $helper->validate_feed_url($url);
                
                if (is_wp_error($validation)) {
                    wp_send_json_error($validation->get_error_message());
                }
                
                $importer = new Job_Killer_Importer();
                $rss_providers = new Job_Killer_Rss_Providers();
                
                $provider_config_rss = $rss_providers->get_provider_config($provider_id);
                
                $feed_config = array(
                    'url' => $url,
                    'field_mapping' => $provider_config_rss['field_mapping'],
                    'name' => 'Test Feed'
                );
                
                $result = $importer->test_feed_import($feed_config);
                
                if ($result['success']) {
                    wp_send_json_success(array(
                        'message' => sprintf(__('Feed test successful! Found %d jobs.', 'job-killer'), $result['jobs_found']),
                        'jobs_found' => $result['jobs_found'],
                        'sample_jobs' => $result['sample_jobs'],
                        'provider' => $provider_id,
                        'provider_name' => $provider_config['name']
                    ));
                } else {
                    wp_send_json_error($result['error']);
                }
            }
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Save RSS feed
     */
    public function save_feed() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $feed_data = $_POST['feed'] ?? array();
        
        if (empty($feed_data['name']) || empty($feed_data['url'])) {
            wp_send_json_error(__('Feed name and URL are required', 'job-killer'));
        }
        
        try {
            // Detect provider
            $provider_id = Job_Killer_Providers_Registry::get_provider_from_url($feed_data['url']);
            $feed_data['provider'] = $provider_id;
            
            // Validate URL
            if (class_exists('Job_Killer_Helper')) {
                $helper = new Job_Killer_Helper();
                $validation = $helper->validate_feed_url($feed_data['url']);
                if (is_wp_error($validation)) {
                    wp_send_json_error($validation->get_error_message());
                }
            }
            
            // Save using new store
            if (isset($feed_data['id']) && !empty($feed_data['id'])) {
                // Update existing
                $feed_id = Job_Killer_Feeds_Store::update($feed_data['id'], $feed_data);
            } else {
                // Create new
                $feed_id = Job_Killer_Feeds_Store::insert($feed_data);
            }
            
            if (is_wp_error($feed_id)) {
                wp_send_json_error($feed_id->get_error_message());
            }
            
            if (class_exists('Job_Killer_Helper')) {
                $helper = new Job_Killer_Helper();
                $helper->log('info', 'admin', 
                    sprintf('Feed "%s" saved successfully', $feed_data['name']),
                    array('feed_id' => $feed_id)
                );
            }
            
            wp_send_json_success(array(
                'message' => __('Feed saved successfully!', 'job-killer'),
                'feed_id' => $feed_id
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Delete RSS feed
     */
    public function delete_feed() {
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
                sprintf('Feed "%s" deleted', $feed['name']),
                array('feed_id' => $feed_id)
            );
        }
        
        wp_send_json_success(array(
            'message' => __('Feed deleted successfully!', 'job-killer')
        ));
    }
    
    /**
     * Toggle feed active status
     */
    public function toggle_feed() {
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
                sprintf('Feed "%s" %s', $feed['name'], $status),
                array('feed_id' => $feed_id, 'active' => $new_status)
            );
        }
        
        wp_send_json_success(array(
            'message' => sprintf(__('Feed %s successfully!', 'job-killer'), $status),
            'active' => $new_status
        ));
    }
    
    /**
     * Import from specific feed
     */
    public function import_feed() {
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
        
        try {
            $imported = $this->import_from_feed_new($feed);
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully imported %d jobs!', 'job-killer'), $imported),
                'imported' => $imported
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Import from feed using new system
     */
    private function import_from_feed_new($feed) {
        $provider_id = $feed['provider'];
        $provider_instance = Job_Killer_Providers_Registry::get_provider_instance($provider_id);
        
        if (!$provider_instance) {
            throw new Exception(__('Provider not available', 'job-killer'));
        }
        
        if ($provider_id === 'whatjobs') {
            $imported = $provider_instance->import_jobs($feed);
        } else {
            // Use legacy RSS importer
            $importer = new Job_Killer_Importer();
            $imported = $importer->import_from_feed($feed['id'], $feed);
        }
        
        // Update last import time
        Job_Killer_Feeds_Store::update_last_import($feed['id'], $imported);
        
        return $imported;
    }
    
    /**
     * Run manual import
     */
    public function run_import() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        try {
            $total_imported = 0;
            
            // Import from all active feeds
            $active_feeds = Job_Killer_Feeds_Store::get_all(true);
            
            foreach ($active_feeds as $feed) {
                try {
                    $imported = $this->import_from_feed_new($feed);
                    $total_imported += $imported;
                } catch (Exception $e) {
                    if (class_exists('Job_Killer_Helper')) {
                        $helper = new Job_Killer_Helper();
                        $helper->log('error', 'import', 
                            sprintf('Failed to import from feed %s: %s', $feed['name'], $e->getMessage())
                        );
                    }
                }
            }
            
            wp_send_json_success(array(
                'message' => sprintf(__('Import completed! Imported %d jobs total.', 'job-killer'), $total_imported)
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Test provider connection (for auto feeds)
     */
    public function test_provider() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $provider_id = sanitize_text_field($_POST['provider_id'] ?? '');
        $config = $_POST['config'] ?? array();
        
        $provider_instance = Job_Killer_Providers_Registry::get_provider_instance($provider_id);
        
        if (!$provider_instance) {
            wp_send_json_error(__('Invalid provider', 'job-killer'));
        }
        
        try {
            $result = $provider_instance->test_connection($config);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Save auto feed (updated to use new store)
     */
    public function save_auto_feed() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $feed_data = $_POST['feed'] ?? array();
        
        if (empty($feed_data['name']) || empty($feed_data['provider_id'])) {
            wp_send_json_error(__('Feed name and provider are required', 'job-killer'));
        }
        
        $provider_instance = Job_Killer_Providers_Registry::get_provider_instance($feed_data['provider_id']);
        
        if (!$provider_instance) {
            wp_send_json_error(__('Invalid provider', 'job-killer'));
        }
        
        // Build feed data for new store
        $store_data = array(
            'name' => sanitize_text_field($feed_data['name']),
            'provider' => sanitize_text_field($feed_data['provider_id']),
            'active' => !empty($feed_data['active']),
            'auth' => $this->sanitize_auth_data($feed_data['auth'] ?? array()),
            'parameters' => $this->sanitize_parameters($feed_data['parameters'] ?? array())
        );
        
        // For WhatJobs, build URL
        if ($feed_data['provider_id'] === 'whatjobs') {
            $publisher_id = $store_data['auth']['publisher_id'] ?? '';
            if (!empty($publisher_id)) {
                $store_data['url'] = Job_Killer_WhatJobs_Provider::build_url($publisher_id, $store_data['parameters']);
            }
        }
        
        // Test configuration before saving
        try {
            $test_result = $provider_instance->test_connection($store_data);
            
            if (!$test_result['success']) {
                wp_send_json_error(__('Configuration test failed: ', 'job-killer') . $test_result['message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(__('Configuration test failed: ', 'job-killer') . $e->getMessage());
        }
        
        // Save feed
        if (isset($feed_data['id']) && !empty($feed_data['id'])) {
            $feed_id = Job_Killer_Feeds_Store::update($feed_data['id'], $store_data);
        } else {
            $feed_id = Job_Killer_Feeds_Store::insert($store_data);
        }
        
        if (is_wp_error($feed_id)) {
            wp_send_json_error($feed_id->get_error_message());
        }
        
        if (class_exists('Job_Killer_Helper')) {
            $helper = new Job_Killer_Helper();
            $helper->log('info', 'admin', 
                sprintf('Auto feed "%s" saved successfully', $store_data['name']),
                array('feed_id' => $feed_id, 'provider' => $store_data['provider'])
            );
        }
        
        wp_send_json_success(array(
            'message' => __('Feed saved successfully!', 'job-killer'),
            'feed_id' => $feed_id
        ));
    }
    
    /**
     * Update cron schedule
     */
    public function update_schedule() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $interval = sanitize_text_field($_POST['interval'] ?? '');
        
        $allowed_intervals = array(
            'every_30_minutes', 'hourly', 'every_2_hours', 
            'every_6_hours', 'twicedaily', 'daily'
        );
        
        if (!in_array($interval, $allowed_intervals)) {
            wp_send_json_error(__('Invalid interval', 'job-killer'));
        }
        
        // Clear existing schedule
        wp_clear_scheduled_hook('job_killer_import_jobs');
        
        // Schedule with new interval
        wp_schedule_event(time(), $interval, 'job_killer_import_jobs');
        
        // Update settings
        $settings = get_option('job_killer_settings', array());
        $settings['cron_interval'] = $interval;
        update_option('job_killer_settings', $settings);
        
        $helper = new Job_Killer_Helper();
        $helper->log('info', 'admin', 
            sprintf('Cron schedule updated to %s', $interval),
            array('interval' => $interval)
        );
        
        wp_send_json_success(array(
            'message' => __('Schedule updated successfully!', 'job-killer'),
            'next_run' => wp_next_scheduled('job_killer_import_jobs')
        ));
    }
    
    /**
     * Clear logs
     */
    public function clear_logs() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $type = sanitize_text_field($_POST['type'] ?? '');
        
        global $wpdb;
        $table = $wpdb->prefix . 'job_killer_logs';
        
        if (!empty($type)) {
            $deleted = $wpdb->delete($table, array('type' => $type));
        } else {
            $deleted = $wpdb->query("TRUNCATE TABLE $table");
        }
        
        wp_send_json_success(array(
            'message' => sprintf(__('Cleared %d log entries', 'job-killer'), $deleted)
        ));
    }
    
    /**
     * Export logs
     */
    public function export_logs() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $filters = array(
            'type' => sanitize_text_field($_POST['type'] ?? ''),
            'source' => sanitize_text_field($_POST['source'] ?? ''),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to' => sanitize_text_field($_POST['date_to'] ?? '')
        );
        
        $helper = new Job_Killer_Helper();
        $export = $helper->export_logs_csv($filters);
        
        if ($export) {
            wp_send_json_success(array(
                'message' => __('Logs exported successfully!', 'job-killer'),
                'download_url' => $export['url'],
                'filename' => $export['filename']
            ));
        } else {
            wp_send_json_error(__('No logs to export', 'job-killer'));
        }
    }
    
    /**
     * Reset settings
     */
    public function reset_settings() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $settings = new Job_Killer_Admin_Settings();
        $settings->reset_to_defaults();
        
        wp_send_json_success(array(
            'message' => __('Settings reset to defaults successfully!', 'job-killer')
        ));
    }
    
    /**
     * Export settings
     */
    public function export_settings() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $settings = new Job_Killer_Admin_Settings();
        $export_data = $settings->export_settings();
        
        wp_send_json_success(array(
            'data' => $export_data,
            'filename' => 'job-killer-settings-' . date('Y-m-d-H-i-s') . '.json'
        ));
    }
    
    /**
     * Import settings
     */
    public function import_settings() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $json_data = wp_unslash($_POST['data'] ?? '');
        
        if (empty($json_data)) {
            wp_send_json_error(__('No data provided', 'job-killer'));
        }
        
        $settings = new Job_Killer_Admin_Settings();
        $result = $settings->import_settings($json_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(array(
            'message' => __('Settings imported successfully!', 'job-killer')
        ));
    }
    
    /**
     * Get system info
     */
    public function system_info() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $helper = new Job_Killer_Helper();
        $system_info = $helper->get_system_info();
        
        wp_send_json_success($system_info);
    }
    
    /**
     * Get chart data
     */
    public function get_chart_data() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $days = intval($_POST['days'] ?? 30);
        $days = max(7, min(365, $days)); // Limit between 7 and 365 days
        
        $helper = new Job_Killer_Helper();
        $chart_data = $helper->get_chart_data($days);
        
        wp_send_json_success($chart_data);
    }
    
    /**
     * Test provider connection
     */
    public function test_provider() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $provider_id = sanitize_text_field($_POST['provider_id'] ?? '');
        $config = $_POST['config'] ?? array();
        
        $provider = Job_Killer_Providers_Registry::get_provider_instance($provider_id);
        
        if (!$provider) {
            wp_send_json_error(__('Invalid provider', 'job-killer'));
        }
        
        try {
            $result = $provider->test_connection($config);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Save auto feed
     */
    public function save_auto_feed() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'job-killer'));
        }
        
        $feed_data = $_POST['feed'] ?? array();
        
        if (empty($feed_data['name']) || empty($feed_data['provider_id'])) {
            wp_send_json_error(__('Feed name and provider are required', 'job-killer'));
        }
        
        $provider = Job_Killer_Providers_Registry::get_provider_instance($feed_data['provider_id']);
        
        if (!$provider) {
            wp_send_json_error(__('Invalid provider', 'job-killer'));
        }
        
        // Prepare feed data for new store
        $store_data = array(
            'name' => sanitize_text_field($feed_data['name']),
            'provider' => sanitize_text_field($feed_data['provider_id']),
            'active' => !empty($feed_data['active']),
            'auth' => $this->sanitize_auth_data($feed_data['auth'] ?? array()),
            'parameters' => $this->sanitize_parameters($feed_data['parameters'] ?? array())
        );
        
        // For WhatJobs, build the URL
        if ($feed_data['provider_id'] === 'whatjobs') {
            $publisher_id = $store_data['auth']['publisher_id'] ?? '';
            if (!empty($publisher_id)) {
                $store_data['url'] = Job_Killer_WhatJobs_Provider::build_url($publisher_id, $store_data['parameters']);
            }
        }
        
        // Test configuration before saving
        try {
            $test_result = $provider->test_connection($store_data);
            
            if (!$test_result['success']) {
                wp_send_json_error(__('Configuration test failed: ', 'job-killer') . $test_result['message']);
            }
            
            if (class_exists('Job_Killer_Helper')) {
                $helper = new Job_Killer_Helper();
                $helper->log('info', 'admin', 
                    sprintf('Auto feed test successful: %s', $store_data['name']),
                    array(
                        'provider' => $store_data['provider'],
                        'api_url' => $test_result['api_url'] ?? 'N/A'
                    )
                );
            }
            
        } catch (Exception $e) {
            wp_send_json_error(__('Configuration test failed: ', 'job-killer') . $e->getMessage());
        }
        
        // Save using new store
        if (isset($feed_data['id']) && !empty($feed_data['id'])) {
            $feed_id = Job_Killer_Feeds_Store::update($feed_data['id'], $store_data);
        } else {
            $feed_id = Job_Killer_Feeds_Store::insert($store_data);
        }
        
        if (is_wp_error($feed_id)) {
            wp_send_json_error($feed_id->get_error_message());
        }
        
        if (class_exists('Job_Killer_Helper')) {
            $helper = new Job_Killer_Helper();
            $helper->log('info', 'admin', 
                sprintf('Auto feed "%s" saved successfully', $store_data['name']),
                array('feed_id' => $feed_id, 'provider' => $store_data['provider'])
            );
        }
        
        wp_send_json_success(array(
            'message' => __('Feed saved successfully!', 'job-killer'),
            'feed_id' => $feed_id
        ));
    }
    
    /**
     * Get provider parameters
     */
    public function get_provider_params() {
        check_ajax_referer('job_killer_admin_nonce', 'nonce');
        
        $provider_id = sanitize_text_field($_POST['provider_id'] ?? '');
        
        $provider_info = Job_Killer_Providers_Registry::get_provider($provider_id);
        
        if (!$provider_info) {
            // Try to get from provider instance
            $provider_instance = Job_Killer_Providers_Registry::get_provider_instance($provider_id);
            if ($provider_instance && method_exists($provider_instance, 'get_provider_info')) {
                $provider_info = $provider_instance->get_provider_info();
            }
        }
        
        if (!$provider_info) {
            wp_send_json_error(__('Invalid provider', 'job-killer'));
        }
        
        wp_send_json_success($provider_info);
    }
    
    /**
     * Sanitize auth data
     */
    private function sanitize_auth_data($auth_data) {
        $sanitized = array();
        
        foreach ($auth_data as $key => $value) {
            $sanitized[sanitize_key($key)] = sanitize_text_field($value);
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize parameters
     */
    private function sanitize_parameters($parameters) {
        $sanitized = array();
        
        foreach ($parameters as $key => $value) {
            $key = sanitize_key($key);
            
            if (is_numeric($value)) {
                $sanitized[$key] = intval($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        
        return $sanitized;
    }
}