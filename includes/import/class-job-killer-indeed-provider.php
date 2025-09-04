<?php
/**
 * Job Killer Indeed RSS Provider
 *
 * @package Job_Killer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Indeed RSS Provider
 */
class Job_Killer_Indeed_Provider {
    
    /**
     * Provider ID
     */
    const PROVIDER_ID = 'indeed';
    
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
            'name' => 'Indeed RSS',
            'description' => __('Import jobs from Indeed RSS feeds with specialized parsing.', 'job-killer'),
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
                'company' => 'source',
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
                    'message' => __('Indeed RSS URL is required', 'job-killer')
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
                    'message' => sprintf(__('Indeed RSS returned status code %d', 'job-killer'), $status_code)
                );
            }
            
            $body = wp_remote_retrieve_body($response);
            $jobs = $this->parse_indeed_rss($body, $config);
            
            if (is_wp_error($jobs)) {
                return array(
                    'success' => false,
                    'message' => $jobs->get_error_message()
                );
            }
            
            return array(
                'success' => true,
                'message' => sprintf(__('Indeed RSS test successful! Found %d jobs.', 'job-killer'), count($jobs)),
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
        // Use the generic RSS provider logic but with Indeed-specific parsing
        $generic_provider = new Job_Killer_Generic_RSS_Provider();
        return $generic_provider->import_jobs($config);
    }
    
    /**
     * Parse Indeed RSS feed
     */
    private function parse_indeed_rss($xml_content, $config) {
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
        $items = $xml->channel->item ?? array();
        
        foreach ($items as $item) {
            $job = array(
                'title' => (string) ($item->title ?? ''),
                'description' => (string) ($item->description ?? ''),
                'url' => (string) ($item->link ?? ''),
                'date' => (string) ($item->pubDate ?? ''),
                'company' => $this->extract_indeed_company($item),
                'location' => $this->extract_indeed_location($item),
                'salary' => $this->extract_indeed_salary($item)
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
     * Extract company from Indeed RSS
     */
    private function extract_indeed_company($item) {
        // Indeed uses 'source' field for company
        if (isset($item->source)) {
            return (string) $item->source;
        }
        
        // Try to extract from title (format: "Job Title - Company")
        $title = (string) ($item->title ?? '');
        if (preg_match('/^(.+?)\s*-\s*(.+?)(?:\s*em\s|$)/i', $title, $matches)) {
            return trim($matches[2]);
        }
        
        return '';
    }
    
    /**
     * Extract location from Indeed RSS
     */
    private function extract_indeed_location($item) {
        // Indeed often includes location in the title
        $title = (string) ($item->title ?? '');
        
        if (preg_match('/em\s+([^-]+)$/i', $title, $matches)) {
            return trim($matches[1]);
        }
        
        // Try description
        $description = (string) ($item->description ?? '');
        if (preg_match('/Location:\s*([^\n]+)/i', $description, $matches)) {
            return trim($matches[1]);
        }
        
        return '';
    }
    
    /**
     * Extract salary from Indeed RSS
     */
    private function extract_indeed_salary($item) {
        $description = (string) ($item->description ?? '');
        
        if (preg_match('/Salary:\s*([^\n]+)/i', $description, $matches)) {
            return trim($matches[1]);
        }
        
        // Look for salary patterns
        if (preg_match('/R\$\s*[\d.,]+/i', $description, $matches)) {
            return trim($matches[0]);
        }
        
        return '';
    }
}