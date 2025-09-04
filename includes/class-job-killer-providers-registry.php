<?php
/**
 * Job Killer Providers Registry
 *
 * @package Job_Killer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central registry for all import providers
 */
class Job_Killer_Providers_Registry {
    
    /**
     * Registered providers
     */
    private static $providers = array();
    
    /**
     * Initialize registry
     */
    public static function init() {
        self::register_default_providers();
        do_action('job_killer_register_providers');
    }
    
    /**
     * Register default providers
     */
    private static function register_default_providers() {
        // WhatJobs provider
        self::register_provider('whatjobs', array(
            'name' => 'WhatJobs',
            'class' => 'Job_Killer_WhatJobs_Provider',
            'url_patterns' => array(
                '/api\.whatjobs\.com/i',
                '/whatjobs\.com.*\/api/i'
            ),
            'type' => 'api'
        ));
        
        // Generic RSS provider
        self::register_provider('generic_rss', array(
            'name' => 'Generic RSS',
            'class' => 'Job_Killer_Generic_RSS_Provider',
            'url_patterns' => array('/.*\.rss/i', '/.*\/rss/i', '/.*\/feed/i'),
            'type' => 'rss'
        ));
        
        // Indeed RSS provider
        self::register_provider('indeed', array(
            'name' => 'Indeed RSS',
            'class' => 'Job_Killer_Indeed_Provider',
            'url_patterns' => array('/indeed\.com.*\/rss/i'),
            'type' => 'rss'
        ));
    }
    
    /**
     * Register a provider
     */
    public static function register_provider($id, $config) {
        self::$providers[$id] = $config;
    }
    
    /**
     * Get provider from URL
     */
    public static function get_provider_from_url($url) {
        if (empty($url)) {
            return 'generic_rss';
        }
        
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        $full_url = $host . $path;
        
        // Check each provider's patterns
        foreach (self::$providers as $provider_id => $config) {
            if (empty($config['url_patterns'])) {
                continue;
            }
            
            foreach ($config['url_patterns'] as $pattern) {
                if (preg_match($pattern, $full_url)) {
                    return $provider_id;
                }
            }
        }
        
        // Fallback to generic RSS
        return 'generic_rss';
    }
    
    /**
     * Get provider config
     */
    public static function get_provider($provider_id) {
        return isset(self::$providers[$provider_id]) ? self::$providers[$provider_id] : null;
    }
    
    /**
     * Get all providers
     */
    public static function get_all_providers() {
        return self::$providers;
    }
    
    /**
     * Get provider instance
     */
    public static function get_provider_instance($provider_id) {
        $config = self::get_provider($provider_id);
        
        if (!$config || !class_exists($config['class'])) {
            return null;
        }
        
        return new $config['class']();
    }
}

// Initialize registry
add_action('init', array('Job_Killer_Providers_Registry', 'init'), 5);