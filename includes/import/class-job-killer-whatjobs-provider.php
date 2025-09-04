<?php
/**
 * Job Killer WhatJobs Provider
 *
 * @package Job_Killer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WhatJobs API Provider
 */
class Job_Killer_WhatJobs_Provider {
    
    /**
     * Provider ID
     */
    const PROVIDER_ID = 'whatjobs';
    
    /**
     * API Base URL
     */
    const API_BASE_URL = 'https://api.whatjobs.com/api/v1/jobs.xml';
    
    /**
     * Helper instance
     */
    private $helper;
    
    /**
     * Constructor
     */
    public function __construct() {
        if (class_exists('Job_Killer_Helper')) {
            $this->helper = new Job_Killer_Helper();
        }
    }
    
    /**
     * Get provider information
     */
    public function get_provider_info() {
        return array(
            'id' => self::PROVIDER_ID,
            'name' => 'WhatJobs',
            'description' => __('Import jobs from WhatJobs API with advanced filtering and mapping.', 'job-killer'),
            'requires_auth' => true,
            'auth_fields' => array(
                'publisher_id' => array(
                    'label' => __('Publisher ID', 'job-killer'),
                    'type' => 'text',
                    'required' => true,
                    'description' => __('Your WhatJobs Publisher ID (required for API access)', 'job-killer')
                )
            ),
            'parameters' => array(
                'keyword' => array(
                    'label' => __('Keywords', 'job-killer'),
                    'type' => 'text',
                    'description' => __('Job search keywords (optional)', 'job-killer')
                ),
                'location' => array(
                    'label' => __('Location', 'job-killer'),
                    'type' => 'text',
                    'description' => __('Job location (city, state, or country)', 'job-killer')
                ),
                'limit' => array(
                    'label' => __('Results Limit', 'job-killer'),
                    'type' => 'number',
                    'default' => 50,
                    'min' => 1,
                    'max' => 100,
                    'description' => __('Maximum number of jobs to import per request', 'job-killer')
                ),
                'page' => array(
                    'label' => __('Page', 'job-killer'),
                    'type' => 'number',
                    'default' => 1,
                    'min' => 1,
                    'description' => __('Page number for pagination', 'job-killer')
                ),
                'only_today' => array(
                    'label' => __('Only Today\'s Jobs', 'job-killer'),
                    'type' => 'checkbox',
                    'default' => true,
                    'description' => __('Import only jobs posted today (age_days = 0)', 'job-killer')
                )
            ),
            'field_mapping' => array(
                'title' => 'title',
                'company' => 'company',
                'location' => 'location',
                'description' => 'description',
                'url' => 'url',
                'job_type' => 'job_type',
                'salary' => 'salary',
                'logo' => 'logo',
                'age_days' => 'age_days',
                'date' => 'date'
            )
        );
    }
    
    /**
     * Build API URL with proper parameters
     */
    public static function build_url($publisher_id, $args = array()) {
        if (empty($publisher_id)) {
            throw new Exception(__('Publisher ID is required for WhatJobs API', 'job-killer'));
        }
        
        $params = array(
            'publisher'  => $publisher_id,
            'user_ip'    => self::get_safe_user_ip(),
            'user_agent' => self::get_safe_user_agent(),
            'snippet'    => 'full'
        );
        
        // Add optional parameters
        if (!empty($args['keyword'])) {
            $params['keyword'] = sanitize_text_field($args['keyword']);
        }
        
        if (!empty($args['location'])) {
            $params['location'] = sanitize_text_field($args['location']);
        }
        
        if (!empty($args['limit'])) {
            $params['limit'] = min(100, max(1, intval($args['limit'])));
        }
        
        if (!empty($args['page'])) {
            $params['page'] = max(1, intval($args['page']));
        }
        
        // Only today's jobs by default
        if (!isset($args['age_days'])) {
            $params['age_days'] = 0;
        } else {
            $params['age_days'] = max(0, intval($args['age_days']));
        }
        
        return add_query_arg($params, self::API_BASE_URL);
    }
    
    /**
     * Fetch jobs from API
     */
    public static function fetch($url, $args = array()) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/xml',
                'User-Agent' => self::get_safe_user_agent()
            )
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('http_error', sprintf(__('API returned status code %d', 'job-killer'), $status_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return new WP_Error('empty_response', __('Empty response from WhatJobs API', 'job-killer'));
        }
        
        // Log response for debugging (first 500 chars)
        if (class_exists('Job_Killer_Helper')) {
            $helper = new Job_Killer_Helper();
            $helper->log('info', 'whatjobs', 
                'API Response received',
                array(
                    'url' => $url,
                    'status' => $status_code,
                    'response_preview' => substr($body, 0, 500)
                )
            );
        }
        
        // Parse XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_messages = array();
            foreach ($errors as $error) {
                $error_messages[] = trim($error->message);
            }
            return new WP_Error('xml_error', 'XML parsing failed: ' . implode(', ', $error_messages));
        }
        
        // Parse jobs from WhatJobs XML structure
        $jobs = array();
        
        // WhatJobs structure: <data><job>...</job></data>
        if (isset($xml->job)) {
            foreach ($xml->job as $job_xml) {
                $job = array(
                    'title' => (string) ($job_xml->title ?? ''),
                    'company' => (string) ($job_xml->company ?? ''),
                    'location' => (string) ($job_xml->location ?? ''),
                    'description' => (string) ($job_xml->snippet ?? ''),
                    'url' => (string) ($job_xml->url ?? ''),
                    'job_type' => (string) ($job_xml->job_type ?? ''),
                    'salary' => (string) ($job_xml->salary ?? ''),
                    'logo' => (string) ($job_xml->logo ?? ''),
                    'age_days' => intval($job_xml->age_days ?? 999),
                    'site' => (string) ($job_xml->site ?? ''),
                    'date' => current_time('mysql') // Use current time as fallback
                );
                
                // Filter only today's jobs if requested
                if (!empty($args['only_today']) && $job['age_days'] !== 0) {
                    continue;
                }
                
                // Skip jobs without essential data
                if (empty($job['title']) || empty($job['description'])) {
                    continue;
                }
                
                $jobs[] = $job;
            }
        }
        
        return $jobs;
    }
    
    /**
     * Get safe user IP for API calls
     */
    private static function get_safe_user_ip() {
        // Try to get real IP from various headers
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        // Fallback for cron jobs
        return '127.0.0.1';
    }
    
    /**
     * Get safe user agent for API calls
     */
    private static function get_safe_user_agent() {
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            return $_SERVER['HTTP_USER_AGENT'];
        }
        
        // Fallback for cron jobs
        return 'JobKillerBot/1.0 (WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url') . ')';
    }
    /**
     * Build API URL
     */
    public function build_api_url($config) {
        $publisher_id = '';
        
        // Handle different config structures
        if (isset($config['auth']['publisher_id'])) {
            $publisher_id = $config['auth']['publisher_id'];
        } elseif (isset($config['publisher_id'])) {
            $publisher_id = $config['publisher_id'];
        }
        
        if (empty($publisher_id)) {
            throw new Exception(__('Publisher ID is required for WhatJobs API', 'job-killer'));
        }
        
        $args = $config['parameters'] ?? array();
        return self::build_url($publisher_id, $args);
    }
    
    /**
     * Test API connection
     */
    public function test_connection($config) {
        try {
            $url = $this->build_api_url($config);
            
            if ($this->helper) {
                $this->helper->log('info', 'whatjobs', 
                    'Testing WhatJobs API connection',
                    array('url' => $url)
                );
            }
            
            $args = array('only_today' => true);
            $jobs = self::fetch($url, $args);
            
            if (is_wp_error($jobs)) {
                return array(
                    'success' => false,
                    'message' => $jobs->get_error_message()
                );
            }
            
            return array(
                'success' => true,
                'message' => sprintf(__('Connection successful! Found %d jobs.', 'job-killer'), count($jobs)),
                'jobs_found' => count($jobs),
                'sample_jobs' => array_slice($jobs, 0, 3),
                'api_url' => $url // Include URL for debugging
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Import jobs from API
     */
    public function import_jobs($config) {
        if ($this->helper) {
            $this->helper->log('info', 'whatjobs', 'Starting WhatJobs import');
        }
        
        try {
            $url = $this->build_api_url($config);
            
            $args = array('only_today' => !empty($config['parameters']['only_today']));
            $jobs = self::fetch($url, $args);
            
            if (is_wp_error($jobs)) {
                throw new Exception('API request failed: ' . $jobs->get_error_message());
            }
            
            // Filter and import jobs
            $imported_count = 0;
            foreach ($jobs as $job_data) {
                if ($this->should_import_job($job_data)) {
                    if ($this->import_single_job($job_data, $config)) {
                        $imported_count++;
                    }
                }
            }
            
            if ($this->helper) {
                $this->helper->log('success', 'whatjobs', 
                    sprintf('WhatJobs import completed. Imported %d jobs.', $imported_count),
                    array('imported' => $imported_count, 'total_found' => count($jobs))
                );
            }
            
            return $imported_count;
            
        } catch (Exception $e) {
            if ($this->helper) {
                $this->helper->log('error', 'whatjobs', 
                    'WhatJobs import failed: ' . $e->getMessage(),
                    array('error' => $e->getMessage())
                );
            }
            throw $e;
        }
    }
    
    /**
     * Clean and format job description
     */
    private function clean_description($description) {
        if (empty($description)) {
            return '';
        }
        
        // Remove excessive whitespace
        $description = preg_replace('/\s+/', ' ', $description);
        
        // Convert line breaks to proper HTML
        $description = nl2br($description);
        
        // Clean up HTML but preserve structure
        $allowed_tags = array(
            'p' => array(),
            'br' => array(),
            'strong' => array(),
            'b' => array(),
            'em' => array(),
            'i' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'h3' => array(),
            'h4' => array(),
            'h5' => array(),
            'h6' => array(),
            'div' => array('class' => array()),
            'span' => array('class' => array())
        );
        
        $description = wp_kses($description, $allowed_tags);
        
        // Fix common formatting issues
        $description = preg_replace('/<br\s*\/?>\s*<br\s*\/?>/i', '</p><p>', $description);
        $description = '<p>' . $description . '</p>';
        $description = preg_replace('/<p>\s*<\/p>/', '', $description);
        
        return trim($description);
    }
    
    /**
     * Normalize employment type
     */
    private function normalize_employment_type($job_type) {
        $type = strtolower(trim($job_type));
        
        $type_mapping = array(
            'full time' => 'FULL_TIME',
            'full-time' => 'FULL_TIME',
            'tempo integral' => 'FULL_TIME',
            'part time' => 'PART_TIME',
            'part-time' => 'PART_TIME',
            'meio período' => 'PART_TIME',
            'contract' => 'CONTRACTOR',
            'contractor' => 'CONTRACTOR',
            'freelance' => 'CONTRACTOR',
            'temporary' => 'TEMPORARY',
            'temporário' => 'TEMPORARY',
            'internship' => 'INTERN',
            'estágio' => 'INTERN'
        );
        
        return isset($type_mapping[$type]) ? $type_mapping[$type] : 'FULL_TIME';
    }
    
    /**
     * Detect remote work
     */
    private function detect_remote_work($job_data) {
        $remote_keywords = array(
            'remoto', 'remote', 'home office', 'trabalho remoto', 
            'teletrabalho', 'work from home', 'wfh'
        );
        
        $search_text = strtolower(
            $job_data['title'] . ' ' . 
            $job_data['description'] . ' ' . 
            $job_data['location']
        );
        
        foreach ($remote_keywords as $keyword) {
            if (strpos($search_text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if job should be imported
     */
    private function should_import_job($job_data) {
        // Skip if no title
        if (empty($job_data['title'])) {
            return false;
        }
        
        // Skip if description is empty or too short
        $description = strip_tags($job_data['description']);
        $settings = get_option('job_killer_settings', array());
        $min_length = $settings['description_min_length'] ?? 100;
        
        if (strlen($description) < $min_length) {
            return false;
        }
        
        // Check for duplicates
        if ($this->is_duplicate_job($job_data)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if job is duplicate
     */
    private function is_duplicate_job($job_data) {
        $settings = get_option('job_killer_settings', array());
        
        if (empty($settings['deduplication_enabled'])) {
            return false;
        }
        
        global $wpdb;
        
        $title = sanitize_text_field($job_data['title']);
        $company = sanitize_text_field($job_data['company']);
        $location = sanitize_text_field($job_data['location']);
        
        $existing = $wpdb->get_var($wpdb->prepare("
            SELECT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_company_name'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_job_location'
            WHERE p.post_type = 'job_listing'
            AND p.post_status = 'publish'
            AND p.post_title = %s
            AND (pm1.meta_value = %s OR %s = '')
            AND (pm2.meta_value = %s OR %s = '')
            LIMIT 1
        ", $title, $company, $company, $location, $location));
        
        return !empty($existing);
    }
    
    /**
     * Import single job
     */
    private function import_single_job($job_data, $config) {
        try {
            // Prepare post data
            $post_data = array(
                'post_title' => sanitize_text_field($job_data['title']),
                'post_content' => wp_kses_post($this->clean_description($job_data['description'])),
                'post_status' => 'publish',
                'post_type' => 'job_listing',
                'post_author' => 1,
                'meta_input' => $this->prepare_job_meta($job_data, $config)
            );
            
            // Insert post
            $post_id = wp_insert_post($post_data);
            
            if (is_wp_error($post_id)) {
                throw new Exception('Failed to create job post: ' . $post_id->get_error_message());
            }
            
            // Set taxonomies
            $this->set_job_taxonomies($post_id, $job_data);
            
            // Handle company logo
            if (!empty($job_data['logo'])) {
                $this->handle_company_logo($post_id, $job_data['logo'], $job_data['company']);
            }
            
            // Trigger WP Job Manager hooks if available
            if (function_exists('job_manager_job_submitted')) {
                do_action('job_manager_job_submitted', $post_id);
            }
            
            do_action('job_killer_after_job_import', $post_id, $job_data, self::PROVIDER_ID);
            
            return true;
            
        } catch (Exception $e) {
            if ($this->helper) {
                $this->helper->log('error', 'whatjobs', 
                    'Failed to import job: ' . $e->getMessage(),
                    array('error' => $e->getMessage())
                );
            }
            return false;
        }
    }
    
    /**
     * Prepare job meta data
     */
    private function prepare_job_meta($job_data, $config) {
        $meta = array(
            // Core WP Job Manager fields
            '_job_location' => sanitize_text_field($job_data['location']),
            '_company_name' => sanitize_text_field($job_data['company']),
            '_application' => esc_url_raw($job_data['url']),
            '_job_expires' => $this->calculate_expiry_date($job_data),
            '_filled' => 0,
            '_featured' => 0,
            '_job_salary' => sanitize_text_field($job_data['salary']),
            '_remote_position' => $job_data['remote_work'] ? 1 : 0,
            
            // Job Killer specific
            '_job_killer_provider' => self::PROVIDER_ID,
            '_job_killer_imported' => current_time('mysql'),
            '_job_killer_source_url' => esc_url_raw($job_data['url']),
            '_job_killer_age_days' => intval($job_data['age_days']),
            
            // WhatJobs specific
            '_whatjobs_category' => sanitize_text_field($job_data['category']),
            '_whatjobs_subcategory' => sanitize_text_field($job_data['subcategory']),
            '_whatjobs_country' => sanitize_text_field($job_data['country']),
            '_whatjobs_state' => sanitize_text_field($job_data['state']),
            '_whatjobs_city' => sanitize_text_field($job_data['city']),
            '_whatjobs_postal_code' => sanitize_text_field($job_data['postal_code'])
        );
        
        // Add employment type for structured data
        $meta['_employment_type'] = $job_data['employment_type'];
        
        // Set job status for WP Job Manager
        $meta['_job_status'] = 'active';
        
        return $meta;
    }
    
    /**
     * Set job taxonomies
     */
    private function set_job_taxonomies($post_id, $job_data) {
        // Set job type
        if (!empty($job_data['job_type'])) {
            $job_type = $this->normalize_job_type($job_data['job_type']);
            $this->set_or_create_term($post_id, $job_type, 'job_listing_type');
        }
        
        // Set category based on WhatJobs category
        if (!empty($job_data['site'])) {
            $category = sanitize_text_field($job_data['site']);
            $this->set_or_create_term($post_id, $category, 'job_listing_category');
        }
        
        // Set region based on location
        if (!empty($job_data['location'])) {
            $region = $this->extract_region_from_location($job_data['location']);
            $this->set_or_create_term($post_id, $region, 'job_listing_region');
        }
    }
    
    /**
     * Extract region from location string
     */
    private function extract_region_from_location($location) {
        // Brazilian states mapping
        $states = array(
            'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
            'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
            'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
            'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
            'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins'
        );
        
        $location_upper = strtoupper($location);
        
        // Check for state abbreviations
        foreach ($states as $abbr => $name) {
            if (strpos($location_upper, $abbr) !== false) {
                return $name;
            }
        }
        
        // Check for full state names
        foreach ($states as $name) {
            if (strpos($location_upper, strtoupper($name)) !== false) {
                return $name;
            }
        }
        
        // Extract city (first part before comma or dash)
        $parts = preg_split('/[,\-]/', $location);
        if (!empty($parts[0])) {
            return trim($parts[0]);
        }
        
        return $location;
    }
    
    /**
     * Set or create taxonomy term
     */
    private function set_or_create_term($post_id, $term_name, $taxonomy) {
        if (empty($term_name)) {
            return;
        }
        
        $term = get_term_by('name', $term_name, $taxonomy);
        
        if (!$term) {
            $term_result = wp_insert_term($term_name, $taxonomy);
            if (!is_wp_error($term_result)) {
                $term = get_term($term_result['term_id'], $taxonomy);
            }
        }
        
        if ($term && !is_wp_error($term)) {
            wp_set_post_terms($post_id, array($term->term_id), $taxonomy);
        }
    }
    
    /**
     * Normalize job type for taxonomy
     */
    private function normalize_job_type($job_type) {
        $type = strtolower(trim($job_type));
        
        $type_mapping = array(
            'full time' => 'Tempo Integral',
            'full-time' => 'Tempo Integral',
            'part time' => 'Meio Período',
            'part-time' => 'Meio Período',
            'contract' => 'Contrato',
            'contractor' => 'Contrato',
            'freelance' => 'Freelance',
            'temporary' => 'Temporário',
            'internship' => 'Estágio',
            'intern' => 'Estágio'
        );
        
        return isset($type_mapping[$type]) ? $type_mapping[$type] : ucfirst($job_type);
    }
    
    /**
     * Calculate job expiry date
     */
    private function calculate_expiry_date($job_data) {
        // Default to 30 days from now
        return date('Y-m-d', strtotime('+30 days'));
    }
    
    /**
     * Handle company logo
     */
    private function handle_company_logo($post_id, $logo_url, $company_name) {
        if (empty($logo_url) || !filter_var($logo_url, FILTER_VALIDATE_URL)) {
            return;
        }
        
        // Try to download and attach the logo
        $attachment_id = $this->download_company_logo($logo_url, $post_id, $company_name);
        
        if ($attachment_id) {
            update_post_meta($post_id, '_company_logo', $attachment_id);
        } else {
            // Store URL as fallback
            update_post_meta($post_id, '_company_logo_url', esc_url($logo_url));
        }
    }
    
    /**
     * Download company logo
     */
    private function download_company_logo($url, $post_id, $company_name) {
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Download file
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            return false;
        }
        
        // Get file extension
        $file_info = pathinfo($url);
        $extension = isset($file_info['extension']) ? $file_info['extension'] : 'jpg';
        
        // Prepare file array
        $file_array = array(
            'name' => sanitize_file_name($company_name . '-logo.' . $extension),
            'tmp_name' => $tmp
        );
        
        // Handle sideload
        $attachment_id = media_handle_sideload($file_array, $post_id, 'Logo da empresa ' . $company_name);
        
        // Clean up temp file
        @unlink($tmp);
        
        if (is_wp_error($attachment_id)) {
            return false;
        }
        
        return $attachment_id;
    }
    
    /**
     * Get provider statistics
     */
    public function get_provider_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Total imported jobs
        $stats['total_imported'] = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'job_listing'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_job_killer_provider'
            AND pm.meta_value = %s
        ", self::PROVIDER_ID));
        
        // Jobs imported today
        $stats['today_imported'] = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'job_listing'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_job_killer_provider'
            AND pm.meta_value = %s
            AND DATE(p.post_date) = CURDATE()
        ", self::PROVIDER_ID));
        
        // Active jobs
        $stats['active_jobs'] = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_job_killer_provider'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_filled'
            WHERE p.post_type = 'job_listing'
            AND p.post_status = 'publish'
            AND pm1.meta_value = %s
            AND (pm2.meta_value IS NULL OR pm2.meta_value = '0')
        ", self::PROVIDER_ID));
        
        return $stats;
    }
}