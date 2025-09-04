<?php
/**
 * Job Killer Generic RSS Provider
 *
 * @package Job_Killer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generic RSS Provider for standard RSS feeds
 */
class Job_Killer_Generic_RSS_Provider {
    
    /**
     * Provider ID
     */
    const PROVIDER_ID = 'generic_rss';
    
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
            'name' => 'Generic RSS',
            'description' => __('Import jobs from standard RSS feeds with customizable field mapping.', 'job-killer'),
            'requires_auth' => false,
            'auth_fields' => array(),
            'parameters' => array(
                'limit' => array(
                    'label' => __('Results Limit', 'job-killer'),
                    'type' => 'number',
                    'default' => 50,
                    'min' => 1,
                    'max' => 200,
                    'description' => __('Maximum number of jobs to import', 'job-killer')
                )
            ),
            'field_mapping' => array(
                'title' => 'title',
                'company' => 'company',
                'location' => 'location',
                'description' => 'description',
                'url' => 'link',
                'date' => 'pubDate',
                'salary' => 'salary'
            )
        );
    }
    
    /**
     * Test connection
     */
    public function test_connection($config) {
        try {
            $url = $config['url'] ?? '';
            
            if (empty($url)) {
                return array(
                    'success' => false,
                    'message' => __('RSS URL is required', 'job-killer')
                );
            }
            
            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'headers' => array('Accept' => 'application/rss+xml, application/xml, text/xml')
            ));
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => $response->get_error_message()
                );
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                return array(
                    'success' => false,
                    'message' => sprintf(__('RSS feed returned status code %d', 'job-killer'), $status_code)
                );
            }
            
            $body = wp_remote_retrieve_body($response);
            $jobs = $this->parse_rss($body, $config);
            
            if (is_wp_error($jobs)) {
                return array(
                    'success' => false,
                    'message' => $jobs->get_error_message()
                );
            }
            
            return array(
                'success' => true,
                'message' => sprintf(__('RSS feed test successful! Found %d jobs.', 'job-killer'), count($jobs)),
                'jobs_found' => count($jobs),
                'sample_jobs' => array_slice($jobs, 0, 3)
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Import jobs
     */
    public function import_jobs($config) {
        if ($this->helper) {
            $this->helper->log('info', 'rss', 'Starting RSS import');
        }
        
        try {
            $url = $config['url'] ?? '';
            
            if (empty($url)) {
                throw new Exception(__('RSS URL is required', 'job-killer'));
            }
            
            $response = wp_remote_get($url, array(
                'timeout' => 60,
                'headers' => array('Accept' => 'application/rss+xml, application/xml, text/xml')
            ));
            
            if (is_wp_error($response)) {
                throw new Exception('RSS request failed: ' . $response->get_error_message());
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                throw new Exception('RSS feed returned status code: ' . $status_code);
            }
            
            $body = wp_remote_retrieve_body($response);
            $jobs = $this->parse_rss($body, $config);
            
            if (is_wp_error($jobs)) {
                throw new Exception('RSS parsing failed: ' . $jobs->get_error_message());
            }
            
            // Import jobs
            $imported_count = 0;
            foreach ($jobs as $job_data) {
                if ($this->should_import_job($job_data)) {
                    if ($this->import_single_job($job_data, $config)) {
                        $imported_count++;
                    }
                }
            }
            
            if ($this->helper) {
                $this->helper->log('success', 'rss', 
                    sprintf('RSS import completed. Imported %d jobs.', $imported_count),
                    array('imported' => $imported_count, 'total_found' => count($jobs))
                );
            }
            
            return $imported_count;
            
        } catch (Exception $e) {
            if ($this->helper) {
                $this->helper->log('error', 'rss', 
                    'RSS import failed: ' . $e->getMessage(),
                    array('error' => $e->getMessage())
                );
            }
            throw $e;
        }
    }
    
    /**
     * Parse RSS feed
     */
    private function parse_rss($xml_content, $config) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            $error_messages = array();
            foreach ($errors as $error) {
                $error_messages[] = trim($error->message);
            }
            return new WP_Error('xml_error', 'XML parsing failed: ' . implode(', ', $error_messages));
        }
        
        $jobs = array();
        $items = $xml->channel->item ?? $xml->item ?? array();
        
        foreach ($items as $item) {
            $job = array(
                'title' => (string) ($item->title ?? ''),
                'description' => (string) ($item->description ?? ''),
                'url' => (string) ($item->link ?? ''),
                'date' => (string) ($item->pubDate ?? ''),
                'company' => $this->extract_company($item),
                'location' => $this->extract_location($item),
                'salary' => $this->extract_salary($item)
            );
            
            // Skip jobs without essential data
            if (empty($job['title']) || empty($job['description'])) {
                continue;
            }
            
            $jobs[] = $job;
        }
        
        return $jobs;
    }
    
    /**
     * Extract company from RSS item
     */
    private function extract_company($item) {
        // Try various common fields
        $fields = array('company', 'source', 'author');
        
        foreach ($fields as $field) {
            if (isset($item->$field)) {
                return (string) $item->$field;
            }
        }
        
        // Try to extract from description
        $description = (string) ($item->description ?? '');
        if (preg_match('/(?:company|empresa):\s*([^\n\r]+)/i', $description, $matches)) {
            return trim($matches[1]);
        }
        
        return '';
    }
    
    /**
     * Extract location from RSS item
     */
    private function extract_location($item) {
        // Try various common fields
        $fields = array('location', 'city', 'address');
        
        foreach ($fields as $field) {
            if (isset($item->$field)) {
                return (string) $item->$field;
            }
        }
        
        // Try to extract from description
        $description = (string) ($item->description ?? '');
        if (preg_match('/(?:location|local|cidade):\s*([^\n\r]+)/i', $description, $matches)) {
            return trim($matches[1]);
        }
        
        return '';
    }
    
    /**
     * Extract salary from RSS item
     */
    private function extract_salary($item) {
        // Try various common fields
        $fields = array('salary', 'compensation', 'pay');
        
        foreach ($fields as $field) {
            if (isset($item->$field)) {
                return (string) $item->$field;
            }
        }
        
        // Try to extract from description
        $description = (string) ($item->description ?? '');
        if (preg_match('/(?:salary|salário|remuneração):\s*([^\n\r]+)/i', $description, $matches)) {
            return trim($matches[1]);
        }
        
        // Look for currency patterns
        if (preg_match('/R\$\s*[\d.,]+/i', $description, $matches)) {
            return trim($matches[0]);
        }
        
        return '';
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
                'post_content' => wp_kses_post($job_data['description']),
                'post_status' => 'publish',
                'post_type' => 'job_listing',
                'post_author' => 1,
                'meta_input' => array(
                    '_job_location' => sanitize_text_field($job_data['location']),
                    '_company_name' => sanitize_text_field($job_data['company']),
                    '_application' => esc_url_raw($job_data['url']),
                    '_job_expires' => date('Y-m-d', strtotime('+30 days')),
                    '_filled' => 0,
                    '_featured' => 0,
                    '_job_salary' => sanitize_text_field($job_data['salary']),
                    '_remote_position' => $this->detect_remote_work($job_data) ? 1 : 0,
                    '_job_killer_provider' => self::PROVIDER_ID,
                    '_job_killer_imported' => current_time('mysql'),
                    '_job_killer_source_url' => esc_url_raw($job_data['url'])
                )
            );
            
            // Insert post
            $post_id = wp_insert_post($post_data);
            
            if (is_wp_error($post_id)) {
                throw new Exception('Failed to create job post: ' . $post_id->get_error_message());
            }
            
            // Set taxonomies
            $this->set_job_taxonomies($post_id, $job_data, $config);
            
            do_action('job_killer_after_job_import', $post_id, $job_data, self::PROVIDER_ID);
            
            return true;
            
        } catch (Exception $e) {
            if ($this->helper) {
                $this->helper->log('error', 'rss', 
                    'Failed to import job: ' . $e->getMessage(),
                    array('error' => $e->getMessage())
                );
            }
            return false;
        }
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
     * Set job taxonomies
     */
    private function set_job_taxonomies($post_id, $job_data, $config) {
        // Set default category if configured
        if (!empty($config['parameters']['default_category'])) {
            $this->set_or_create_term($post_id, $config['parameters']['default_category'], 'job_listing_category');
        }
        
        // Set default region if configured
        if (!empty($config['parameters']['default_region'])) {
            $this->set_or_create_term($post_id, $config['parameters']['default_region'], 'job_listing_region');
        }
        
        // Try to extract job type from description
        $job_type = $this->extract_job_type($job_data);
        if (!empty($job_type)) {
            $this->set_or_create_term($post_id, $job_type, 'job_listing_type');
        }
    }
    
    /**
     * Extract job type from job data
     */
    private function extract_job_type($job_data) {
        $search_text = strtolower($job_data['title'] . ' ' . $job_data['description']);
        
        $type_patterns = array(
            'tempo integral' => array('tempo integral', 'full time', 'full-time'),
            'meio período' => array('meio período', 'part time', 'part-time'),
            'freelance' => array('freelance', 'freelancer'),
            'contrato' => array('contrato', 'contract'),
            'estágio' => array('estágio', 'estagiário', 'internship', 'intern')
        );
        
        foreach ($type_patterns as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($search_text, $keyword) !== false) {
                    return $type;
                }
            }
        }
        
        return 'Tempo Integral'; // Default
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
}