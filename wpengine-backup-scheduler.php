<?php
/**
 * Plugin Name: WP Engine Backup Scheduler
 * Plugin URI: https://github.com/josefresco/wpengine-hourly-backup
 * Description: Automated backup scheduling for WP Engine hosted sites using the WP Engine API
 * Version: 1.1.6
 * Author: josefresco
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wpengine-backup-scheduler
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!defined('WPENGINE_BACKUP_PLUGIN_URL')) {
    define('WPENGINE_BACKUP_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('WPENGINE_BACKUP_PLUGIN_PATH')) {
    define('WPENGINE_BACKUP_PLUGIN_PATH', plugin_dir_path(__FILE__));
}
if (!defined('WPENGINE_BACKUP_VERSION')) {
    define('WPENGINE_BACKUP_VERSION', '1.1.5');
}

/**
 * Main plugin class
 */
if (!class_exists('WPEngineBackupScheduler')) {
class WPEngineBackupScheduler {
    
    private $api_base_url = 'https://api.wpengineapi.com/v1';
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wpengine_create_backup', array($this, 'ajax_create_backup'));
        add_action('wp_ajax_wpengine_save_schedule', array($this, 'ajax_save_schedule'));
        add_action('wp_ajax_wpengine_save_api_settings', array($this, 'ajax_save_api_settings'));
        add_action('wp_ajax_wpengine_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_wpengine_fetch_backups', array($this, 'ajax_fetch_backups'));
        add_action('wp_ajax_wpengine_fetch_installs', array($this, 'ajax_fetch_installs'));
        add_action('wp_ajax_wpengine_auto_detect_install', array($this, 'ajax_auto_detect_install'));
        add_action('wp_ajax_wpengine_debug_settings', array($this, 'ajax_debug_settings'));
        add_action('wp_ajax_wpengine_cancel_schedule', array($this, 'ajax_cancel_schedule'));
        add_action('wp_ajax_wpengine_debug_cron', array($this, 'ajax_debug_cron'));
        add_action('wp_ajax_wpengine_test_backup', array($this, 'ajax_test_backup'));
        add_action('wp_ajax_wpengine_trigger_cron', array($this, 'ajax_trigger_cron'));
        add_action('wp_ajax_wpengine_test_schedule', array($this, 'ajax_test_schedule'));
        
        // Hook for scheduled backups
        add_action('wpengine_backup_cron_hook', array($this, 'execute_scheduled_backup'));
        
        // Setup custom cron schedules early
        $this->setup_cron_schedules();
        
        // Plugin activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        load_plugin_textdomain('wpengine-backup-scheduler', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database table for backup logs
        $this->create_backup_logs_table();
        
        // Set default options
        if (!get_option('wpengine_backup_settings')) {
            add_option('wpengine_backup_settings', array(
                'api_username' => '',
                'api_password' => '',
                'install_id' => '',
                'install_name' => '',
                'backup_frequency' => '24',
                'email_notifications' => '',
                'enabled' => false
            ));
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('wpengine_backup_cron_hook');
    }
    
    /**
     * Create backup logs table
     */
    private function create_backup_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpengine_backup_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            backup_type varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            backup_id varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Setup custom cron schedules
     */
    public function setup_cron_schedules() {
        // Use static variable to prevent duplicate filter additions
        static $schedules_registered = false;
        
        if (!$schedules_registered) {
            add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
            $schedules_registered = true;
        }
    }
    
    /**
     * Add custom cron schedules to WordPress
     */
    public function add_custom_cron_schedules($schedules) {
        // Add hourly intervals from 1-23 hours
        for ($i = 1; $i <= 23; $i++) {
            $schedules["every_{$i}_hours"] = array(
                'interval' => $i * HOUR_IN_SECONDS,
                'display' => sprintf(__('Every %d Hours', 'wpengine-backup-scheduler'), $i)
            );
        }
        return $schedules;
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            __('WP Engine Backups', 'wpengine-backup-scheduler'),
            __('WP Engine Backups', 'wpengine-backup-scheduler'),
            'manage_options',
            'wpengine-backup-scheduler',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_wpengine-backup-scheduler') {
            return;
        }
        
        wp_enqueue_script(
            'wpengine-backup-admin',
            WPENGINE_BACKUP_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            WPENGINE_BACKUP_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wpengine-backup-admin',
            WPENGINE_BACKUP_PLUGIN_URL . 'assets/admin.css',
            array(),
            WPENGINE_BACKUP_VERSION
        );
        
        wp_localize_script('wpengine-backup-admin', 'wpengineBackup', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpengine_backup_nonce'),
            'strings' => array(
                'creating_backup' => __('Creating backup...', 'wpengine-backup-scheduler'),
                'backup_created' => __('Backup created successfully!', 'wpengine-backup-scheduler'),
                'backup_failed' => __('Backup creation failed.', 'wpengine-backup-scheduler'),
                'testing_api' => __('Testing API connection...', 'wpengine-backup-scheduler'),
                'api_success' => __('API connection successful!', 'wpengine-backup-scheduler'),
                'api_failed' => __('API connection failed.', 'wpengine-backup-scheduler')
            )
        ));
    }
    
    /**
     * Get current WP Engine install ID and name from environment
     */
    private function get_current_install_info() {
        $install_info = array(
            'id' => null,
            'name' => null,
            'environment' => null,
            'wpe_detected' => false
        );
        
        // Method 1: Extract from ABSPATH (most reliable for WP Engine)
        if (defined('ABSPATH')) {
            $abspath = ABSPATH;
            if (preg_match('/\/nas\/content\/(live|staging|development)\/([^\/]+)\//', $abspath, $matches)) {
                $install_info['name'] = $matches[2];
                $install_info['environment'] = $matches[1] === 'live' ? 'production' : $matches[1];
                $install_info['wpe_detected'] = true;
            }
        }
        
        // Method 2: Check server name patterns
        if (empty($install_info['name']) && isset($_SERVER['SERVER_NAME'])) {
            $server_name = $_SERVER['SERVER_NAME'];
            
            if (strpos($server_name, '.wpengine.com') !== false) {
                $install_name = str_replace('.wpengine.com', '', $server_name);
                $install_info['name'] = $install_name;
                $install_info['environment'] = 'production';
                $install_info['wpe_detected'] = true;
            }
            elseif (strpos($server_name, '.staging.wpengine.com') !== false) {
                $install_name = str_replace('.staging.wpengine.com', '', $server_name);
                $install_info['name'] = $install_name;
                $install_info['environment'] = 'staging';
                $install_info['wpe_detected'] = true;
            }
            elseif (strpos($server_name, '.dev.wpengine.com') !== false) {
                $install_name = str_replace('.dev.wpengine.com', '', $server_name);
                $install_info['name'] = $install_name;
                $install_info['environment'] = 'development';
                $install_info['wpe_detected'] = true;
            }
        }
        
        // Method 3: Check for WP Engine specific constants
        if (defined('WPE_APIKEY')) {
            $install_info['wpe_detected'] = true;
        }
        
        // Method 4: Check document root path
        if (empty($install_info['name']) && isset($_SERVER['DOCUMENT_ROOT'])) {
            $doc_root = $_SERVER['DOCUMENT_ROOT'];
            if (preg_match('/\/nas\/content\/(live|staging|development)\/([^\/]+)/', $doc_root, $matches)) {
                $install_info['name'] = $matches[2];
                $install_info['environment'] = $matches[1] === 'live' ? 'production' : $matches[1];
                $install_info['wpe_detected'] = true;
            }
        }
        
        return $install_info;
    }
    
    /**
     * Get list of installs from WP Engine API (with pagination support)
     */
    private function get_installs() {
        $settings = get_option('wpengine_backup_settings');
        
        if (empty($settings['api_username']) || empty($settings['api_password'])) {
            return array('success' => false, 'message' => __('API credentials not configured.', 'wpengine-backup-scheduler'));
        }
        
        $all_installs = array();
        $limit = 100; // Maximum allowed by WP Engine API
        $offset = 0;
        $has_more = true;
        
        // Loop through all pages to get all installs
        while ($has_more) {
            $url = $this->api_base_url . '/installs?limit=' . $limit . '&offset=' . $offset;
            
            error_log('WP Engine Backup - Fetching installs: ' . $url);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($settings['api_username'] . ':' . $settings['api_password']),
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
                ),
                'timeout' => 30,
                'sslverify' => true
            ));
            
            if (is_wp_error($response)) {
                error_log('WP Engine Backup - Error fetching installs: ' . $response->get_error_message());
                return array(
                    'success' => false, 
                    'message' => __('Failed to fetch installs: ', 'wpengine-backup-scheduler') . $response->get_error_message()
                );
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                error_log('WP Engine Backup - API error: ' . $response_code . ' - ' . $response_body);
                return array(
                    'success' => false,
                    'message' => sprintf(__('Failed to fetch installs. HTTP %d: %s', 'wpengine-backup-scheduler'), $response_code, $response_body)
                );
            }
            
            $installs_data = json_decode($response_body, true);
            
            if (!is_array($installs_data)) {
                error_log('WP Engine Backup - Invalid response format: ' . $response_body);
                return array(
                    'success' => false,
                    'message' => __('Invalid response format from WP Engine API', 'wpengine-backup-scheduler')
                );
            }
            
            // Handle different response structures
            $current_installs = array();
            $total_count = 0;
            
            if (isset($installs_data['results'])) {
                // Paginated response format
                $current_installs = $installs_data['results'];
                $total_count = $installs_data['count'] ?? 0;
                
                error_log('WP Engine Backup - Page results: ' . count($current_installs) . ' installs, total: ' . $total_count);
            } else {
                // Direct array format (fallback)
                $current_installs = $installs_data;
                $total_count = count($current_installs);
                
                error_log('WP Engine Backup - Direct results: ' . count($current_installs) . ' installs');
            }
            
            // Add current page results to our collection
            $all_installs = array_merge($all_installs, $current_installs);
            
            // Check if we need to fetch more pages
            $current_count = count($current_installs);
            $offset += $limit;
            
            // Stop if we got fewer results than the limit (last page) or if we have all installs
            if ($current_count < $limit || count($all_installs) >= $total_count) {
                $has_more = false;
            }
            
            error_log('WP Engine Backup - Progress: ' . count($all_installs) . ' of ' . $total_count . ' installs fetched');
            
            // Safety check to prevent infinite loops
            if ($offset > 1000) { // Reasonable safety limit
                error_log('WP Engine Backup - Safety limit reached, stopping pagination');
                $has_more = false;
            }
        }
        
        error_log('WP Engine Backup - Final result: ' . count($all_installs) . ' total installs fetched');
        
        return array(
            'success' => true,
            'installs' => $all_installs
        );
    }
    
    /**
     * Get install ID by name using API
     */
    private function get_install_id_by_name($install_name) {
        $installs_result = $this->get_installs();
        
        if (!$installs_result['success']) {
            return null;
        }
        
        foreach ($installs_result['installs'] as $install) {
            if (isset($install['name']) && $install['name'] === $install_name) {
                return $install['id'];
            }
        }
        
        return null;
    }
    
    /**
     * Auto-detect and configure current install
     */
    private function auto_configure_install() {
        $install_info = $this->get_current_install_info();
        
        if (empty($install_info['name'])) {
            return array(
                'success' => false,
                'message' => __('Could not detect WP Engine install name. This plugin must be run on a WP Engine hosted site.', 'wpengine-backup-scheduler')
            );
        }
        
        // Try to get the install ID via API
        $install_id = $this->get_install_id_by_name($install_info['name']);
        
        if ($install_id) {
            $settings = get_option('wpengine_backup_settings', array());
            $settings['install_id'] = $install_id;
            $settings['install_name'] = $install_info['name'];
            $settings['environment'] = $install_info['environment'];
            update_option('wpengine_backup_settings', $settings);
            
            return array(
                'success' => true,
                'message' => sprintf(__('Auto-detected install: %s (ID: %s)', 'wpengine-backup-scheduler'), $install_info['name'], $install_id),
                'install_id' => $install_id,
                'install_name' => $install_info['name'],
                'environment' => $install_info['environment']
            );
        } else {
            return array(
                'success' => false,
                'message' => sprintf(__('Detected install name "%s" but could not find corresponding install ID via API. Please check your API credentials.', 'wpengine-backup-scheduler'), $install_info['name'])
            );
        }
    }
    
    /**
     * Admin page HTML with step-by-step onboarding
     */
    public function admin_page() {
        $settings = get_option('wpengine_backup_settings', array());
        $backup_logs = $this->get_recent_backup_logs();
        
        // Determine setup progress
        $has_api_credentials = !empty($settings['api_username']) && !empty($settings['api_password']);
        $has_install_config = !empty($settings['install_id']) && !empty($settings['install_name']);
        $has_email_config = !empty($settings['email_notifications']);
        $is_enabled = $settings['enabled'] ?? false;
        
        ?>
        <div class="wrap">
            <h1><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?> 
                <span style="font-size: 14px; color: #666; font-weight: normal;">v<?php echo WPENGINE_BACKUP_VERSION; ?></span>
            </h1>
            
            <!-- Progress Indicator -->
            <div class="wpengine-setup-progress">
                <div class="progress-step <?php echo $has_api_credentials ? 'completed' : 'active'; ?>" data-step="1">
                    <span class="step-number">1</span>
                    <span class="step-title"><?php _e('API Credentials', 'wpengine-backup-scheduler'); ?></span>
                </div>
                <div class="progress-step <?php echo !$has_api_credentials ? 'disabled' : ($has_install_config ? 'completed' : 'active'); ?>" data-step="2">
                    <span class="step-number">2</span>
                    <span class="step-title"><?php _e('Install Setup', 'wpengine-backup-scheduler'); ?></span>
                </div>
                <div class="progress-step <?php echo (!$has_api_credentials || !$has_install_config) ? 'disabled' : ($has_email_config ? 'completed' : 'active'); ?>" data-step="3">
                    <span class="step-number">3</span>
                    <span class="step-title"><?php _e('Email & Schedule', 'wpengine-backup-scheduler'); ?></span>
                </div>
                <div class="progress-step <?php echo (!$has_api_credentials || !$has_install_config || !$has_email_config) ? 'disabled' : ($is_enabled ? 'completed' : 'active'); ?>" data-step="4">
                    <span class="step-number">4</span>
                    <span class="step-title"><?php _e('Complete', 'wpengine-backup-scheduler'); ?></span>
                </div>
            </div>
            
            <div class="wpengine-backup-container">
                <div class="wpengine-backup-main">
                    
                    <!-- Step 1: API Credentials -->
                    <div class="postbox setup-step step-1 <?php echo $has_api_credentials ? 'completed' : 'active'; ?>">
                        <h2 class="hndle">
                            <span class="step-indicator">1</span>
                            <?php _e('WP Engine API Credentials', 'wpengine-backup-scheduler'); ?>
                            <?php if ($has_api_credentials) : ?>
                                <span class="status-badge completed">✓ <?php _e('Complete', 'wpengine-backup-scheduler'); ?></span>
                            <?php endif; ?>
                        </h2>
                        <div class="inside">
                            <?php if (!$has_api_credentials) : ?>
                                <div class="setup-intro">
                                    <p><?php _e('First, you need to create API credentials in your WP Engine User Portal.', 'wpengine-backup-scheduler'); ?></p>
                                    <p><strong><a href="https://my.wpengine.com/profile/api_access" target="_blank" class="button button-secondary">
                                        <?php _e('Open WP Engine API Settings →', 'wpengine-backup-scheduler'); ?>
                                    </a></strong></p>
                                    <p class="description">
                                        <?php _e('Click the link above to create your API username and password in the WP Engine User Portal. You\'ll need these to connect your site to the WP Engine backup system.', 'wpengine-backup-scheduler'); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            
                            <form id="wpengine-api-settings">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="api_username"><?php _e('API Username', 'wpengine-backup-scheduler'); ?> <span class="required">*</span></label>
                                        </th>
                                        <td>
                                            <input type="text" name="api_username" id="api_username" value="<?php echo esc_attr($settings['api_username'] ?? ''); ?>" class="regular-text" required />
                                            <p class="description"><?php _e('The API username from your WP Engine User Portal', 'wpengine-backup-scheduler'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">
                                            <label for="api_password"><?php _e('API Password', 'wpengine-backup-scheduler'); ?> <span class="required">*</span></label>
                                        </th>
                                        <td>
                                            <input type="password" name="api_password" id="api_password" value="<?php echo esc_attr($settings['api_password'] ?? ''); ?>" class="regular-text" required />
                                            <p class="description"><?php _e('The API password from your WP Engine User Portal', 'wpengine-backup-scheduler'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                                
                                <div class="step-actions">
                                    <button type="submit" class="button-primary" id="save-api-btn">
                                        <?php _e('Save & Test Credentials', 'wpengine-backup-scheduler'); ?>
                                    </button>
                                    <span id="api-test-result"></span>
                                </div>
                                
                                <?php if ($has_api_credentials) : ?>
                                    <div class="step-completed-actions">
                                        <button type="button" id="test-api-connection" class="button">
                                            <?php _e('Test Connection', 'wpengine-backup-scheduler'); ?>
                                        </button>
                                        <span class="success-indicator" style="color: #46b450; margin-left: 10px;">
                                            <?php _e('✅ Credentials Saved', 'wpengine-backup-scheduler'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <!-- Step 2: Install Configuration -->
                    <div class="postbox setup-step step-2 <?php echo !$has_api_credentials ? 'disabled' : ($has_install_config ? 'completed' : 'active'); ?>">
                        <h2 class="hndle">
                            <span class="step-indicator">2</span>
                            <?php _e('Install Configuration', 'wpengine-backup-scheduler'); ?>
                            <?php if ($has_install_config) : ?>
                                <span class="status-badge completed">✓ <?php _e('Complete', 'wpengine-backup-scheduler'); ?></span>
                            <?php endif; ?>
                        </h2>
                        <div class="inside">
                            <?php if (!$has_api_credentials) : ?>
                                <div class="step-disabled-notice">
                                    <p><?php _e('Complete Step 1 first to configure your WP Engine install.', 'wpengine-backup-scheduler'); ?></p>
                                </div>
                            <?php else : ?>
                                <?php
                                $current_install = $this->get_current_install_info();
                                if (!empty($current_install['name'])) :
                                ?>
                                    <div class="current-install-detected">
                                        <div class="notice notice-info inline">
                                            <p><strong><?php _e('Auto-Detected Install:', 'wpengine-backup-scheduler'); ?></strong> 
                                            <?php echo esc_html($current_install['name']); ?>
                                            <?php if (!empty($current_install['environment'])) : ?>
                                                <span class="env-badge"><?php echo esc_html($current_install['environment']); ?></span>
                                            <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <form id="wpengine-install-settings">
                                    <div class="install-auto-detect">
                                        <button type="button" id="auto-detect-install-btn" class="button button-primary">
                                            <?php _e('Auto-Detect & Configure Current Install', 'wpengine-backup-scheduler'); ?>
                                        </button>
                                        <span id="auto-detect-status"></span>
                                        <p class="description">
                                            <?php _e('Automatically detect and configure the current WP Engine install (recommended).', 'wpengine-backup-scheduler'); ?>
                                        </p>
                                    </div>
                                    
                                    <div class="manual-config-toggle">
                                        <button type="button" class="button-link" onclick="toggleManualConfig()">
                                            <?php _e('Or configure manually ↓', 'wpengine-backup-scheduler'); ?>
                                        </button>
                                    </div>
                                    
                                    <div id="manual-install-config" class="manual-config" style="display: none;">
                                        <table class="form-table">
                                            <tr>
                                                <th scope="row"><?php _e('Install ID', 'wpengine-backup-scheduler'); ?></th>
                                                <td>
                                                    <input type="text" name="install_id" value="<?php echo esc_attr($settings['install_id'] ?? ''); ?>" class="regular-text" placeholder="e.g., 12345" />
                                                    <p class="description"><?php _e('Your WP Engine install ID (numeric)', 'wpengine-backup-scheduler'); ?></p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><?php _e('Install Name', 'wpengine-backup-scheduler'); ?></th>
                                                <td>
                                                    <input type="text" name="install_name" value="<?php echo esc_attr($settings['install_name'] ?? ''); ?>" class="regular-text" placeholder="e.g., mysite" />
                                                    <p class="description"><?php _e('Your WP Engine install name for reference', 'wpengine-backup-scheduler'); ?></p>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <button type="button" id="fetch-installs-btn" class="button">
                                            <?php _e('Load All My Installs', 'wpengine-backup-scheduler'); ?>
                                        </button>
                                        <span id="fetch-installs-status"></span>
                                        <div id="installs-list"></div>
                                    </div>
                                    
                                    <?php if ($has_install_config) : ?>
                                        <div class="current-config">
                                            <table class="form-table">
                                                <tr>
                                                    <th scope="row"><?php _e('Current Install', 'wpengine-backup-scheduler'); ?></th>
                                                    <td>
                                                        <strong><?php echo esc_html($settings['install_name'] ?? 'Unknown'); ?></strong> 
                                                        (ID: <?php echo esc_html($settings['install_id'] ?? 'Unknown'); ?>)
                                                        <span class="success-indicator" style="color: #46b450; margin-left: 10px;">
                                                            <?php _e('✅ Install Configured', 'wpengine-backup-scheduler'); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Step 3: Email & Schedule Configuration -->
                    <div class="postbox setup-step step-3 <?php echo (!$has_api_credentials || !$has_install_config) ? 'disabled' : ($has_email_config ? 'completed' : 'active'); ?>">
                        <h2 class="hndle">
                            <span class="step-indicator">3</span>
                            <?php _e('Email & Schedule Configuration', 'wpengine-backup-scheduler'); ?>
                            <?php if ($has_email_config) : ?>
                                <span class="status-badge completed">✓ <?php _e('Complete', 'wpengine-backup-scheduler'); ?></span>
                            <?php endif; ?>
                        </h2>
                        <div class="inside">
                            <?php if (!$has_api_credentials || !$has_install_config) : ?>
                                <div class="step-disabled-notice">
                                    <p><?php _e('Complete the previous steps first to configure email notifications and scheduling.', 'wpengine-backup-scheduler'); ?></p>
                                </div>
                            <?php else : ?>
                                <form id="wpengine-email-schedule-settings">
                                    <div class="form-section">
                                        <h3><?php _e('Email Notifications', 'wpengine-backup-scheduler'); ?> <span class="required">*</span></h3>
                                        <p class="section-description"><?php _e('Email notifications are required by the WP Engine API for backup completion.', 'wpengine-backup-scheduler'); ?></p>
                                        
                                        <table class="form-table">
                                            <tr>
                                                <th scope="row">
                                                    <label for="email_notifications"><?php _e('Notification Email', 'wpengine-backup-scheduler'); ?> <span class="required">*</span></label>
                                                </th>
                                                <td>
                                                    <input type="email" name="email_notifications" id="email_notifications" 
                                                           value="<?php echo esc_attr($settings['email_notifications'] ?? ''); ?>" 
                                                           class="regular-text" required />
                                                    <p class="description"><?php _e('You\'ll receive notifications when backups complete or fail.', 'wpengine-backup-scheduler'); ?></p>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    
                                    <div class="form-section">
                                        <h3><?php _e('Backup Schedule', 'wpengine-backup-scheduler'); ?></h3>
                                        <p class="section-description"><?php _e('Configure how often you want automatic backups to run.', 'wpengine-backup-scheduler'); ?></p>
                                        
                                        <table class="form-table">
                                            <tr>
                                                <th scope="row"><?php _e('Backup Frequency', 'wpengine-backup-scheduler'); ?></th>
                                                <td>
                                                    <select name="backup_frequency" id="backup_frequency">
                                                        <option value="1" <?php selected($settings['backup_frequency'] ?? 24, 1); ?>><?php _e('Every Hour', 'wpengine-backup-scheduler'); ?></option>
                                                        <?php for ($i = 2; $i <= 23; $i++) : ?>
                                                            <option value="<?php echo $i; ?>" <?php selected($settings['backup_frequency'] ?? 24, $i); ?>>
                                                                <?php printf(__('Every %d Hours', 'wpengine-backup-scheduler'), $i); ?>
                                                            </option>
                                                        <?php endfor; ?>
                                                        <option value="24" <?php selected($settings['backup_frequency'] ?? 24, 24); ?>><?php _e('Daily (24 Hours)', 'wpengine-backup-scheduler'); ?></option>
                                                    </select>
                                                    <p class="description"><?php _e('More frequent backups provide better protection but use more resources.', 'wpengine-backup-scheduler'); ?></p>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    
                                    <div class="step-actions">
                                        <button type="submit" class="button-primary">
                                            <?php _e('Save Configuration', 'wpengine-backup-scheduler'); ?>
                                        </button>
                                        <span id="email-schedule-status"></span>
                                    </div>
                                    
                                    <?php if ($has_email_config) : ?>
                                        <div class="step-completed-actions">
                                            <span class="success-indicator" style="color: #46b450;">
                                                <?php _e('✅ Email & Schedule Configured', 'wpengine-backup-scheduler'); ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Step 4: Complete & Enable -->
                    <div class="postbox setup-step step-4 <?php echo (!$has_api_credentials || !$has_install_config || !$has_email_config) ? 'disabled' : ($is_enabled ? 'completed' : 'active'); ?>">
                        <h2 class="hndle">
                            <span class="step-indicator">4</span>
                            <?php _e('Complete Setup', 'wpengine-backup-scheduler'); ?>
                            <?php if ($is_enabled) : ?>
                                <span class="status-badge completed">✓ <?php _e('Active', 'wpengine-backup-scheduler'); ?></span>
                            <?php endif; ?>
                        </h2>
                        <div class="inside">
                            <?php if (!$has_api_credentials || !$has_install_config || !$has_email_config) : ?>
                                <div class="step-disabled-notice">
                                    <p><?php _e('Complete all previous steps to enable automatic backups.', 'wpengine-backup-scheduler'); ?></p>
                                </div>
                            <?php else : ?>
                                <?php if (!$is_enabled) : ?>
                                    <div class="setup-final">
                                        <div class="final-step-info">
                                            <h3><?php _e('🎉 Ready to Enable Automatic Backups!', 'wpengine-backup-scheduler'); ?></h3>
                                            <p><?php _e('Your configuration is complete. Click the button below to start automatic backups.', 'wpengine-backup-scheduler'); ?></p>
                                            
                                            <div class="config-summary">
                                                <h4><?php _e('Configuration Summary:', 'wpengine-backup-scheduler'); ?></h4>
                                                <ul>
                                                    <li><strong><?php _e('WP Engine Install:', 'wpengine-backup-scheduler'); ?></strong> <?php echo esc_html($settings['install_name'] ?? 'Unknown'); ?> (<?php echo esc_html($settings['install_id'] ?? 'Unknown'); ?>)</li>
                                                    <li><strong><?php _e('Email Notifications:', 'wpengine-backup-scheduler'); ?></strong> <?php echo esc_html($settings['email_notifications'] ?? 'None'); ?></li>
                                                    <li><strong><?php _e('Backup Frequency:', 'wpengine-backup-scheduler'); ?></strong> <?php echo ($settings['backup_frequency'] ?? 24) == 1 ? __('Every Hour', 'wpengine-backup-scheduler') : sprintf(__('Every %d Hours', 'wpengine-backup-scheduler'), $settings['backup_frequency'] ?? 24); ?></li>
                                                </ul>
                                            </div>
                                        </div>
                                        
                                        <div class="final-actions">
                                            <button type="button" id="enable-backups-btn" class="button-primary button-large">
                                                <?php _e('🚀 Enable Automatic Backups', 'wpengine-backup-scheduler'); ?>
                                            </button>
                                            <span id="enable-backups-status"></span>
                                        </div>
                                        
                                        <div class="test-actions">
                                            <p><?php _e('Want to test first?', 'wpengine-backup-scheduler'); ?></p>
                                            <button type="button" id="test-backup-final-btn" class="button">
                                                <?php _e('Create Test Backup', 'wpengine-backup-scheduler'); ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php else : ?>
                                    <div class="setup-completed">
                                        <div class="success-message">
                                            <h3>✅ <?php _e('Automatic Backups Enabled!', 'wpengine-backup-scheduler'); ?></h3>
                                            <p><?php _e('Your WP Engine backup scheduler is now active and running.', 'wpengine-backup-scheduler'); ?></p>
                                            
                                            <?php
                                            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
                                            if ($next_backup) :
                                            ?>
                                                <div class="next-backup-info">
                                                    <p><strong><?php _e('Next Backup:', 'wpengine-backup-scheduler'); ?></strong> 
                                                    <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_backup); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="management-actions">
                                            <button type="button" id="create-manual-backup-btn" class="button button-primary">
                                                <?php _e('Create Manual Backup', 'wpengine-backup-scheduler'); ?>
                                            </button>
                                            <button type="button" id="disable-backups-btn" class="button-secondary">
                                                <?php _e('Disable Backups', 'wpengine-backup-scheduler'); ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Manual Backup Section -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Manual Backup', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <p><?php _e('Create an on-demand backup of your website.', 'wpengine-backup-scheduler'); ?></p>
                            <form id="wpengine-manual-backup">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><?php _e('Backup Description', 'wpengine-backup-scheduler'); ?></th>
                                        <td>
                                            <input type="text" name="backup_description" placeholder="<?php _e('e.g., Before plugin update', 'wpengine-backup-scheduler'); ?>" class="regular-text" />
                                        </td>
                                    </tr>
                                </table>
                                <p>
                                    <button type="submit" class="button-primary" id="create-backup-btn">
                                        <?php _e('Create Backup Now', 'wpengine-backup-scheduler'); ?>
                                    </button>
                                    <span id="backup-status"></span>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="wpengine-backup-sidebar">
                    <!-- Recent Backups -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Recent Backup Activity', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <?php if (!empty($backup_logs)) : ?>
                                <ul class="backup-log-list">
                                    <?php foreach ($backup_logs as $log) : ?>
                                        <li class="backup-log-item status-<?php echo esc_attr($log->status); ?>">
                                            <div class="backup-log-type"><?php echo esc_html(ucfirst($log->backup_type)); ?></div>
                                            <div class="backup-log-status"><?php echo esc_html(ucfirst($log->status)); ?></div>
                                            <div class="backup-log-date"><?php echo esc_html($log->created_at); ?></div>
                                            <?php if ($log->message) : ?>
                                                <div class="backup-log-message"><?php echo esc_html($log->message); ?></div>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p><?php _e('No backup activity yet.', 'wpengine-backup-scheduler'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Next Scheduled Backup -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Next Scheduled Backup', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <?php
                            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
                            if ($next_backup && ($settings['enabled'] ?? false)) {
                                echo '<p>' . sprintf(__('Next backup: %s', 'wpengine-backup-scheduler'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_backup)) . '</p>';
                                echo '<p><button type="button" id="cancel-schedule-sidebar-btn" class="button button-secondary button-small">' . __('Cancel Schedule', 'wpengine-backup-scheduler') . '</button></p>';
                            } else {
                                echo '<p>' . __('No backup scheduled', 'wpengine-backup-scheduler') . '</p>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <!-- Debug Panel -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Debug & Testing', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <p><?php _e('Use these tools to diagnose why hourly backups might not be running:', 'wpengine-backup-scheduler'); ?></p>
                            
                            <button type="button" id="debug-cron-btn" class="button"><?php _e('Run Cron Diagnostics', 'wpengine-backup-scheduler'); ?></button>
                            <button type="button" id="test-backup-btn" class="button"><?php _e('Test Backup Now', 'wpengine-backup-scheduler'); ?></button>
                            <button type="button" id="trigger-cron-btn" class="button"><?php _e('Trigger Cron Function', 'wpengine-backup-scheduler'); ?></button>
                            
                            <div id="debug-panel" style="display: none; margin-top: 15px; padding: 15px; background: #f0f0f0; border: 1px solid #ddd; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto;"></div>
                        </div>
                    </div>
                    
                    <!-- Help -->
                    <div class="postbox">
                        <h2 class="hndle"><?php _e('Help & Documentation', 'wpengine-backup-scheduler'); ?></h2>
                        <div class="inside">
                            <ul>
                                <li><a href="https://wpengine.com/support/enabling-wp-engine-api/" target="_blank"><?php _e('Enable WP Engine API', 'wpengine-backup-scheduler'); ?></a></li>
                                <li><a href="https://wpengineapi.com/reference" target="_blank"><?php _e('API Documentation', 'wpengine-backup-scheduler'); ?></a></li>
                                <li><a href="https://wpengine.com/support/restore/" target="_blank"><?php _e('Backup & Restore Guide', 'wpengine-backup-scheduler'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .wpengine-backup-container {
            display: flex;
            gap: 20px;
        }
        .wpengine-backup-main {
            flex: 2;
        }
        .wpengine-backup-sidebar {
            flex: 1;
        }
        .wpengine-backup-main .postbox,
        .wpengine-backup-sidebar .postbox {
            margin-bottom: 20px;
            padding: 1em;
        }
        .backup-log-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .backup-log-item {
            padding: 10px;
            margin-bottom: 5px;
            border-left: 4px solid #ddd;
            background: #f9f9f9;
        }
        .backup-log-item.status-success {
            border-left-color: #46b450;
        }
        .backup-log-item.status-error {
            border-left-color: #dc3232;
        }
        .backup-log-item.status-running {
            border-left-color: #ffb900;
        }
        .backup-log-type {
            font-weight: bold;
        }
        .backup-log-status {
            font-size: 12px;
            text-transform: uppercase;
        }
        .backup-log-date {
            font-size: 11px;
            color: #666;
        }
        .backup-log-message {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        #backup-status, #api-test-result {
            margin-left: 10px;
            font-weight: bold;
        }
        .status-success {
            color: #46b450;
        }
        .status-error {
            color: #dc3232;
        }
        .status-running {
            color: #ffb900;
        }
        
        /* Step-by-step onboarding styles */
        .wpengine-setup-progress {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 20px 0;
            padding: 20px;
            background: #f0f6fc;
            border: 1px solid #c3d9ff;
            border-radius: 6px;
        }
        
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            padding: 0 15px;
            min-width: 120px;
        }
        
        .progress-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            right: -20px;
            width: 40px;
            height: 2px;
            background: #ddd;
            z-index: 1;
        }
        
        .progress-step.completed::after {
            background: #46b450;
        }
        
        .progress-step.active::after {
            background: linear-gradient(to right, #0073aa 50%, #ddd 50%);
        }
        
        .step-number {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #ddd;
            color: #666;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 8px;
            position: relative;
            z-index: 2;
        }
        
        .progress-step.active .step-number {
            background: #0073aa;
            color: white;
        }
        
        .progress-step.completed .step-number {
            background: #46b450;
            color: white;
        }
        
        .progress-step.completed .step-number::after {
            content: '✓';
            position: absolute;
            font-size: 16px;
        }
        
        .progress-step.disabled .step-number {
            background: #f0f0f0;
            color: #ccc;
        }
        
        .step-title {
            font-size: 12px;
            text-align: center;
            color: #666;
            font-weight: 500;
        }
        
        .progress-step.active .step-title {
            color: #0073aa;
            font-weight: 600;
        }
        
        .progress-step.completed .step-title {
            color: #46b450;
            font-weight: 600;
        }
        
        .progress-step.disabled .step-title {
            color: #ccc;
        }
        
        /* Setup step styles */
        .setup-step {
            transition: opacity 0.3s ease;
        }
        
        .setup-step.disabled {
            opacity: 0.6;
        }
        
        .setup-step .hndle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
        }
        
        .step-indicator {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #ddd;
            color: #666;
            font-weight: bold;
            font-size: 12px;
        }
        
        .setup-step.active .step-indicator {
            background: #0073aa;
            color: white;
        }
        
        .setup-step.completed .step-indicator {
            background: #46b450;
            color: white;
        }
        
        .setup-step.completed .step-indicator::after {
            content: '✓';
            position: absolute;
            font-size: 14px;
        }
        
        .status-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: auto;
        }
        
        .status-badge.completed {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        /* Setup form styles */
        .setup-intro {
            background: #e7f3ff;
            padding: 15px;
            border-left: 4px solid #0073aa;
            margin-bottom: 20px;
        }
        
        .setup-intro p {
            margin: 0 0 10px 0;
        }
        
        .setup-intro .button {
            text-decoration: none;
        }
        
        .form-section {
            margin-bottom: 25px;
        }
        
        .form-section h3 {
            margin-top: 0;
            margin-bottom: 5px;
            color: #23282d;
        }
        
        .section-description {
            margin-bottom: 15px;
            color: #666;
            font-style: italic;
        }
        
        .step-actions {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .step-completed-actions {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #eee;
            display: flex;
            gap: 10px;
        }
        
        .step-disabled-notice {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-left: 4px solid #6c757d;
            padding: 15px;
            margin: 15px 0;
        }
        
        .step-disabled-notice p {
            margin: 0;
            color: #6c757d;
        }
        
        /* Forms are now always editable - collapsed state removed */
        
        .current-config {
            background: #f0f6fc;
            padding: 15px;
            border: 1px solid #c3d9ff;
            border-radius: 4px;
            margin-top: 15px;
        }
        
        /* Environment detection styles */
        .current-install-detected {
            margin-bottom: 15px;
        }
        
        .env-badge {
            background: #0073aa;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            text-transform: uppercase;
            margin-left: 8px;
        }
        
        /* Final step styles */
        .setup-final,
        .setup-completed {
            text-align: center;
            padding: 20px;
        }
        
        .final-step-info h3,
        .success-message h3 {
            color: #0073aa;
            margin-bottom: 15px;
        }
        
        .config-summary {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
        }
        
        .config-summary h4 {
            margin-top: 0;
            margin-bottom: 10px;
        }
        
        .config-summary ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .config-summary li {
            margin-bottom: 5px;
        }
        
        .final-actions,
        .management-actions {
            margin: 20px 0;
        }
        
        .test-actions {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .test-actions p {
            margin-bottom: 10px;
            font-size: 14px;
            color: #666;
        }
        
        .next-backup-info {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            padding: 10px;
            margin-top: 15px;
        }
        
        .next-backup-info p {
            margin: 0;
            color: #155724;
        }
        
        /* Manual config toggle */
        .manual-config-toggle {
            text-align: center;
            margin: 15px 0;
        }
        
        .manual-config {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        /* Required field indicator */
        .required {
            color: #dc3232;
        }
        
        /* Responsive adjustments */
        @media (max-width: 782px) {
            .wpengine-backup-container {
                flex-direction: column;
            }
            
            .wpengine-setup-progress {
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .progress-step {
                min-width: 80px;
            }
            
            .progress-step:not(:last-child)::after {
                display: none;
            }
        }
        </style>
        <?php
    }
    
    /**
     * AJAX handler for canceling scheduled backups
     */
    public function ajax_cancel_schedule() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $result = $this->cancel_scheduled_backups();
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Cancel all scheduled backups
     */
    private function cancel_scheduled_backups() {
        // Clear all scheduled backup events
        $cleared = wp_clear_scheduled_hook('wpengine_backup_cron_hook');
        
        // Update settings to disable scheduling
        $settings = get_option('wpengine_backup_settings', array());
        $settings['enabled'] = false;
        update_option('wpengine_backup_settings', $settings);
        
        // Log the cancellation
        $this->log_backup_activity('scheduled', 'cancelled', __('Scheduled backups cancelled by user', 'wpengine-backup-scheduler'));
        
        return array(
            'success' => true,
            'message' => __('All scheduled backups have been cancelled and automatic backups are now disabled.', 'wpengine-backup-scheduler')
        );
    }
    
    /**
     * AJAX handler for debugging settings (temporary)
     */
    public function ajax_debug_settings() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $settings = get_option('wpengine_backup_settings');
        
        // Mask password for security
        if (!empty($settings['api_password'])) {
            $settings['api_password'] = str_repeat('*', strlen($settings['api_password']));
        }
        
        wp_send_json_success($settings);
    }
    
    /**
     * AJAX handler for comprehensive cron debugging
     */
    public function ajax_debug_cron() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        // Generate debug report
        $settings = get_option('wpengine_backup_settings', array());
        $is_wpengine = $this->is_wpengine_hosting();
        $is_wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
        
        $debug_info = array(
            'environment' => array(
                'wp_engine_detected' => $is_wpengine,
                'wp_cron_disabled' => $is_wp_cron_disabled,
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION
            ),
            'configuration' => array(
                'api_username_set' => !empty($settings['api_username']),
                'api_password_set' => !empty($settings['api_password']),
                'install_id' => $settings['install_id'] ?? null,
                'email_notifications' => $settings['email_notifications'] ?? null,
                'backups_enabled' => $settings['enabled'] ?? false,
                'frequency' => ($settings['backup_frequency'] ?? 'not set') . ' hours'
            ),
            'cron_status' => array(
                'next_backup_timestamp' => $next_backup,
                'next_backup_formatted' => $next_backup ? date('Y-m-d H:i:s', $next_backup) : null,
                'time_until_next' => $next_backup ? ($next_backup - time()) : null,
                'backup_running' => get_transient('wpengine_backup_running') ? true : false
            ),
            'recent_activity' => $this->get_recent_backup_logs(5)
        );
        
        // Test API if credentials available
        if (!empty($settings['api_username']) && !empty($settings['api_password'])) {
            $api_test = $this->test_api_connection($settings['api_username'], $settings['api_password']);
            $debug_info['api_test'] = $api_test;
        } else {
            $debug_info['api_test'] = array('success' => false, 'message' => 'No credentials configured');
        }
        
        // Add comprehensive cron debugging
        $schedules = wp_get_schedules();
        $debug_info['available_schedules'] = array_keys($schedules);
        
        // Check if custom schedules exist
        $debug_info['custom_schedules_registered'] = array();
        for ($i = 1; $i <= 23; $i++) {
            $schedule_name = "every_{$i}_hours";
            $debug_info['custom_schedules_registered'][$schedule_name] = array_key_exists($schedule_name, $schedules);
        }
        
        // Check current WordPress cron jobs
        $cron = _get_cron_array();
        $debug_info['all_cron_jobs'] = array();
        $debug_info['wpengine_cron_jobs'] = array();
        
        if ($cron) {
            foreach ($cron as $timestamp => $cronhooks) {
                foreach ($cronhooks as $hook => $details) {
                    $debug_info['all_cron_jobs'][] = array(
                        'timestamp' => $timestamp,
                        'hook' => $hook,
                        'time' => date('Y-m-d H:i:s', $timestamp),
                        'details' => $details
                    );
                    
                    if ($hook === 'wpengine_backup_cron_hook') {
                        $debug_info['wpengine_cron_jobs'][] = array(
                            'timestamp' => $timestamp,
                            'time' => date('Y-m-d H:i:s', $timestamp),
                            'details' => $details
                        );
                    }
                }
            }
        }
        
        // Test schedule creation manually
        $debug_info['manual_schedule_test'] = array();
        if ($settings['enabled']) {
            $interval = $settings['backup_frequency'] == 24 ? 'daily' : "every_{$settings['backup_frequency']}_hours";
            $debug_info['manual_schedule_test']['requested_interval'] = $interval;
            $debug_info['manual_schedule_test']['interval_exists'] = array_key_exists($interval, $schedules);
            $debug_info['manual_schedule_test']['interval_details'] = $schedules[$interval] ?? null;
            
            // Try to manually schedule (but don't actually do it to avoid duplicates)
            $debug_info['manual_schedule_test']['would_schedule'] = array(
                'function' => 'wp_schedule_event',
                'time' => time(),
                'interval' => $interval,
                'hook' => 'wpengine_backup_cron_hook'
            );
        }
        
        wp_send_json_success($debug_info);
    }
    
    /**
     * AJAX handler for testing backup functionality
     */
    public function ajax_test_backup() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $description = 'Admin panel test backup - ' . current_time('Y-m-d H:i:s');
        $result = $this->create_backup('manual', $description);
        
        if ($result['success']) {
            wp_send_json_success($result['message'] . (isset($result['backup_id']) ? ' (ID: ' . $result['backup_id'] . ')' : ''));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for manually triggering cron function
     */
    public function ajax_trigger_cron() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        // Check if backup is already running
        $running_transient = get_transient('wpengine_backup_running');
        if ($running_transient) {
            wp_send_json_error(__('A backup is already running. Please wait for it to complete.', 'wpengine-backup-scheduler'));
            return;
        }
        
        $result = $this->execute_scheduled_backup();
        
        if ($result !== false) {
            wp_send_json_success(__('Scheduled backup function executed successfully. Check backup logs for details.', 'wpengine-backup-scheduler'));
        } else {
            wp_send_json_error(__('Scheduled backup function returned an error. Check logs for details.', 'wpengine-backup-scheduler'));
        }
    }
    
    /**
     * AJAX handler for testing cron scheduling
     */
    public function ajax_test_schedule() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $settings = get_option('wpengine_backup_settings', array());
        $test_results = array();
        
        // Clear any existing schedule first
        wp_clear_scheduled_hook('wpengine_backup_cron_hook');
        $test_results['cleared_existing'] = true;
        
        if (!$settings['enabled']) {
            wp_send_json_error('Scheduling is disabled in settings.');
            return;
        }
        
        // Ensure custom schedules are registered before testing
        $this->setup_cron_schedules();
        
        // Check if custom schedules are available
        $schedules = wp_get_schedules();
        $interval = $settings['backup_frequency'] == 24 ? 'daily' : "every_{$settings['backup_frequency']}_hours";
        
        $test_results['requested_interval'] = $interval;
        $test_results['interval_available'] = array_key_exists($interval, $schedules);
        $test_results['all_schedules'] = array_keys($schedules);
        
        if (!$test_results['interval_available']) {
            wp_send_json_error(array(
                'message' => "Interval '$interval' not available",
                'debug' => $test_results
            ));
            return;
        }
        
        // Try to schedule the event
        $schedule_result = wp_schedule_event(time(), $interval, 'wpengine_backup_cron_hook');
        $test_results['schedule_result'] = $schedule_result;
        $test_results['schedule_error'] = $schedule_result === false;
        
        // Check if it was actually scheduled
        $next_scheduled = wp_next_scheduled('wpengine_backup_cron_hook');
        $test_results['next_scheduled'] = $next_scheduled;
        $test_results['successfully_scheduled'] = $next_scheduled !== false;
        
        if ($next_scheduled) {
            $test_results['next_run_time'] = date('Y-m-d H:i:s', $next_scheduled);
            $test_results['seconds_until_run'] = $next_scheduled - time();
        }
        
        // Get all current cron jobs to verify
        $cron = _get_cron_array();
        $test_results['wpengine_jobs_found'] = 0;
        if ($cron) {
            foreach ($cron as $timestamp => $cronhooks) {
                foreach ($cronhooks as $hook => $details) {
                    if ($hook === 'wpengine_backup_cron_hook') {
                        $test_results['wpengine_jobs_found']++;
                    }
                }
            }
        }
        
        if ($test_results['successfully_scheduled']) {
            wp_send_json_success(array(
                'message' => 'Cron schedule test successful!',
                'debug' => $test_results
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Failed to schedule cron event',
                'debug' => $test_results
            ));
        }
    }
    
    /**
     * AJAX handler for auto-detecting install
     */
    public function ajax_auto_detect_install() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $result = $this->auto_configure_install();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for fetching WP Engine installs
     */
    public function ajax_fetch_installs() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $result = $this->get_installs();
        
        if ($result['success']) {
            wp_send_json_success($result['installs']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Get list of backups from WP Engine API
     */
    private function get_api_backups($install_id = null) {
        $settings = get_option('wpengine_backup_settings');
        
        if (empty($settings['api_username']) || empty($settings['api_password'])) {
            return array('success' => false, 'message' => __('API credentials not configured.', 'wpengine-backup-scheduler'));
        }
        
        $install = $install_id ?: $settings['install_id'];
        if (empty($install)) {
            return array('success' => false, 'message' => __('Install ID not configured.', 'wpengine-backup-scheduler'));
        }
        
        $url = $this->api_base_url . '/installs/' . urlencode($install) . '/backups';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($settings['api_username'] . ':' . $settings['api_password']),
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            ),
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false, 
                'message' => __('Failed to fetch backups: ', 'wpengine-backup-scheduler') . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            $backups_data = json_decode($response_body, true);
            return array(
                'success' => true,
                'backups' => $backups_data['results'] ?? $backups_data ?? array()
            );
        } else {
            return array(
                'success' => false,
                'message' => sprintf(__('Failed to fetch backups. HTTP %d: %s', 'wpengine-backup-scheduler'), $response_code, $response_body)
            );
        }
    }
    
    /**
     * AJAX handler for fetching WP Engine backups
     */
    public function ajax_fetch_backups() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $result = $this->get_api_backups();
        
        if ($result['success']) {
            wp_send_json_success($result['backups']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for creating backup
     */
    public function ajax_create_backup() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $description = sanitize_text_field($_POST['description'] ?? '');
        
        $result = $this->create_backup('manual', $description);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for saving API settings only
     */
    public function ajax_save_api_settings() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        // Get existing settings first
        $settings = get_option('wpengine_backup_settings', array());
        
        // Update API and configuration settings
        $settings['api_username'] = sanitize_text_field($_POST['api_username'] ?? '');
        $settings['api_password'] = sanitize_text_field($_POST['api_password'] ?? '');
        $settings['install_id'] = sanitize_text_field($_POST['install_id'] ?? '');
        $settings['install_name'] = sanitize_text_field($_POST['install_name'] ?? '');
        $settings['email_notifications'] = sanitize_email($_POST['email_notifications'] ?? '');
        
        // Update backup frequency if provided
        if (isset($_POST['backup_frequency'])) {
            $settings['backup_frequency'] = intval($_POST['backup_frequency']);
        } else if (!isset($settings['backup_frequency'])) {
            $settings['backup_frequency'] = 24;
        }
        
        // Keep existing enabled setting if not explicitly set
        if (!isset($settings['enabled'])) {
            $settings['enabled'] = false;
        }
        
        update_option('wpengine_backup_settings', $settings);
        
        wp_send_json_success(__('API settings saved successfully!', 'wpengine-backup-scheduler'));
    }
    
    /**
     * AJAX handler for saving schedule
     */
    public function ajax_save_schedule() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $settings = array(
            'api_username' => sanitize_text_field($_POST['api_username'] ?? ''),
            'api_password' => sanitize_text_field($_POST['api_password'] ?? ''),
            'install_id' => sanitize_text_field($_POST['install_id'] ?? ''),
            'install_name' => sanitize_text_field($_POST['install_name'] ?? ''),
            'backup_frequency' => intval($_POST['backup_frequency'] ?? 24),
            'email_notifications' => sanitize_email($_POST['email_notifications'] ?? ''),
            'enabled' => isset($_POST['enabled'])
        );
        
        update_option('wpengine_backup_settings', $settings);
        
        // Update cron schedule
        wp_clear_scheduled_hook('wpengine_backup_cron_hook');
        
        if ($settings['enabled']) {
            // Ensure custom schedules are registered before scheduling
            $this->setup_cron_schedules();
            
            $interval = $settings['backup_frequency'] == 24 ? 'daily' : "every_{$settings['backup_frequency']}_hours";
            wp_schedule_event(time(), $interval, 'wpengine_backup_cron_hook');
        }
        
        wp_send_json_success(__('Settings saved successfully!', 'wpengine-backup-scheduler'));
    }
    
    /**
     * AJAX handler for testing API connection
     */
    public function ajax_test_api() {
        check_ajax_referer('wpengine_backup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wpengine-backup-scheduler'));
        }
        
        $username = sanitize_text_field($_POST['api_username'] ?? '');
        $password = sanitize_text_field($_POST['api_password'] ?? '');
        
        $result = $this->test_api_connection($username, $password);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Test API connection
     */
    public function test_api_connection($username, $password) {
        if (empty($username) || empty($password)) {
            return array(
                'success' => false,
                'message' => __('Please enter both username and password.', 'wpengine-backup-scheduler')
            );
        }
        
        $url = $this->api_base_url . '/accounts';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => __('Connection failed: ', 'wpengine-backup-scheduler') . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            return array(
                'success' => true,
                'message' => __('API connection successful!', 'wpengine-backup-scheduler')
            );
        } else {
            return array(
                'success' => false,
                'message' => sprintf(__('API connection failed with status code: %d', 'wpengine-backup-scheduler'), $response_code)
            );
        }
    }
    
    /**
     * Create backup via API
     */
    private function create_backup($type = 'manual', $description = '') {
        $settings = get_option('wpengine_backup_settings');
        
        // Debug: Log the settings to see what we have
        error_log('WP Engine Backup - Settings check: ' . print_r($settings, true));
        
        // Check for API credentials
        $api_username = $settings['api_username'] ?? '';
        $api_password = $settings['api_password'] ?? '';
        $install_id = $settings['install_id'] ?? '';
        
        error_log('WP Engine Backup - Credentials check: username=' . (!empty($api_username) ? 'SET' : 'EMPTY') . ', password=' . (!empty($api_password) ? 'SET' : 'EMPTY') . ', install_id=' . (!empty($install_id) ? $install_id : 'EMPTY'));
        
        if (empty($api_username) || empty($api_password)) {
            return array(
                'success' => false,
                'message' => __('API username or password not configured. Please save your API credentials first.', 'wpengine-backup-scheduler')
            );
        }
        
        if (empty($install_id)) {
            return array(
                'success' => false,
                'message' => __('Install ID not configured. Please use "Auto-Detect & Configure Current Install" first.', 'wpengine-backup-scheduler')
            );
        }
        
        // Check for notification email - it's required by WP Engine API
        $notification_email = $settings['email_notifications'] ?? '';
        if (empty($notification_email)) {
            return array(
                'success' => false,
                'message' => __('Notification email is required for backups. Please add an email address in the backup schedule settings and save.', 'wpengine-backup-scheduler')
            );
        }
        
        // Log backup start
        $log_id = $this->log_backup_activity($type, 'running', __('Backup initiated', 'wpengine-backup-scheduler'));
        
        $url = $this->api_base_url . '/installs/' . urlencode($install_id) . '/backups';
        
        // Prepare backup data with required notification_emails field
        $backup_data = array(
            'notification_emails' => array($notification_email)
        );
        
        // Add description if provided
        if (!empty($description)) {
            $backup_data['description'] = $description;
        }
        
        error_log('WP Engine Backup - API Request: URL=' . $url . ', Data=' . json_encode($backup_data));
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_username . ':' . $api_password),
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            ),
            'body' => json_encode($backup_data),
            'timeout' => 120,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('WP Engine Backup - WP Error: ' . $error_message);
            $this->update_backup_log($log_id, 'error', $error_message);
            return array(
                'success' => false,
                'message' => __('Backup failed: ', 'wpengine-backup-scheduler') . $error_message
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log the response for debugging
        error_log('WP Engine Backup API Response: ' . $response_code . ' - ' . $response_body);
        
        if ($response_code === 201 || $response_code === 200 || $response_code === 202) {
            $backup_response = json_decode($response_body, true);
            $backup_id = null;
            $backup_status = 'unknown';
            
            // Handle different response structures
            if (is_array($backup_response)) {
                $backup_id = $backup_response['id'] ?? $backup_response['backup_id'] ?? 'created';
                $backup_status = $backup_response['status'] ?? 'created';
            }
            
            // Determine success message based on status code
            $message = '';
            if ($response_code === 202) {
                // Accepted - backup is being processed
                $message = __('Backup request accepted! Your backup is being created and you will receive an email notification when it completes.', 'wpengine-backup-scheduler');
            } else {
                // 200/201 - backup completed immediately (less common)
                $message = __('Backup created successfully! You will receive an email notification when it completes.', 'wpengine-backup-scheduler');
            }
            
            $this->update_backup_log($log_id, 'success', sprintf(__('Backup %s (ID: %s)', 'wpengine-backup-scheduler'), $backup_status, $backup_id), $backup_id);
            
            return array(
                'success' => true,
                'message' => $message,
                'backup_id' => $backup_id,
                'status' => $backup_status
            );
        } else {
            // Parse error response for more details
            $error_message = '';
            $error_data = json_decode($response_body, true);
            
            if (is_array($error_data)) {
                // Log the full error response for debugging
                error_log('WP Engine Backup - Full Error Response: ' . print_r($error_data, true));
                
                if (isset($error_data['message'])) {
                    $error_message = $error_data['message'];
                } elseif (isset($error_data['error'])) {
                    $error_message = $error_data['error'];
                } elseif (isset($error_data['detail'])) {
                    $error_message = $error_data['detail'];
                } elseif (isset($error_data['errors']) && is_array($error_data['errors'])) {
                    $error_details = array();
                    foreach ($error_data['errors'] as $error) {
                        if (isset($error['message'])) {
                            $error_details[] = $error['message'];
                        }
                    }
                    $error_message = implode(', ', $error_details);
                } else {
                    $error_message = 'Unknown API error: ' . $response_body;
                }
            } else {
                $error_message = 'HTTP ' . $response_code . ': ' . $response_body;
            }
            
            // Handle specific error codes with more context
            switch ($response_code) {
                case 400:
                    $error_message = __('Bad request - The API request format is invalid. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 401:
                    $error_message = __('Authentication failed - Please check your API credentials. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 403:
                    $error_message = __('Access denied - You may not have permission to create backups for this install. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 404:
                    $error_message = __('Install not found - Please check your install ID. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 405:
                    $error_message = __('Method not allowed - The backup endpoint may not be available. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 429:
                    $error_message = __('Too many requests - Please wait before creating another backup. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                case 500:
                    $error_message = __('Server error - Please try again later or contact WP Engine support. ', 'wpengine-backup-scheduler') . $error_message;
                    break;
                default:
                    $error_message = sprintf(__('HTTP %d error: ', 'wpengine-backup-scheduler'), $response_code) . $error_message;
                    break;
            }
            
            $this->update_backup_log($log_id, 'error', $error_message);
            
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
    }
    
    /**
     * Execute scheduled backup with WP Engine optimizations
     */
    public function execute_scheduled_backup() {
        // Prevent overlapping executions using transients
        $transient_key = 'wpengine_backup_running';
        if (get_transient($transient_key)) {
            error_log('WP Engine Backup - Skipping execution: backup already in progress');
            return false;
        }
        
        // Set transient to prevent overlap (expires in 5 minutes as safety net)
        set_transient($transient_key, time(), 5 * MINUTE_IN_SECONDS);
        
        // Add timeout protection for WP Engine Alternate Cron (must complete in under 60 seconds)
        $start_time = time();
        $max_execution_time = 50; // seconds - leave buffer for WP Engine's 60-second limit
        
        try {
            // Check if we're running on WP Engine
            $wpe_detected = $this->is_wpengine_hosting();
            
            if ($wpe_detected) {
                error_log('WP Engine Backup - Executing scheduled backup on WP Engine hosting');
            }
            
            // Execute the backup with timeout monitoring
            $result = $this->create_backup_with_timeout('scheduled', __('Automated scheduled backup', 'wpengine-backup-scheduler'), $max_execution_time, $start_time);
            
            // Clear the running transient on success
            delete_transient($transient_key);
            
            return $result;
            
        } catch (Exception $e) {
            error_log('WP Engine Backup - Scheduled backup failed with exception: ' . $e->getMessage());
            delete_transient($transient_key);
            
            // Log the failure
            $this->log_backup_activity('scheduled', 'error', 'Backup failed with exception: ' . $e->getMessage());
            
            return false;
        }
    }
    
    /**
     * Check if running on WP Engine hosting
     */
    public function is_wpengine_hosting() {
        // Multiple detection methods for WP Engine
        $indicators = [
            defined('WPE_APIKEY'),
            isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'WP Engine') !== false,
            defined('ABSPATH') && strpos(ABSPATH, '/nas/content/') !== false,
            isset($_SERVER['DOCUMENT_ROOT']) && strpos($_SERVER['DOCUMENT_ROOT'], '/nas/content/') !== false,
            isset($_SERVER['SERVER_NAME']) && (
                strpos($_SERVER['SERVER_NAME'], '.wpengine.com') !== false ||
                strpos($_SERVER['SERVER_NAME'], '.staging.wpengine.com') !== false ||
                strpos($_SERVER['SERVER_NAME'], '.dev.wpengine.com') !== false
            )
        ];
        
        return in_array(true, $indicators, true);
    }
    
    /**
     * Create backup with timeout protection for WP Engine Alternate Cron
     */
    private function create_backup_with_timeout($type = 'manual', $description = '', $max_execution_time = 50, $start_time = null) {
        $start_time = $start_time ?: time();
        
        // Quick pre-flight checks to avoid timeouts
        $elapsed = time() - $start_time;
        if ($elapsed > ($max_execution_time - 10)) {
            return array(
                'success' => false,
                'message' => __('Backup skipped: insufficient time remaining for safe execution', 'wpengine-backup-scheduler')
            );
        }
        
        // Use the existing create_backup method but with timeout awareness
        return $this->create_backup($type, $description);
    }
    
    /**
     * Log backup activity
     */
    private function log_backup_activity($type, $status, $message, $backup_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpengine_backup_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'backup_type' => $type,
                'status' => $status,
                'message' => $message,
                'backup_id' => $backup_id,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Update backup log
     */
    private function update_backup_log($log_id, $status, $message, $backup_id = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpengine_backup_logs';
        
        $update_data = array(
            'status' => $status,
            'message' => $message,
            'completed_at' => current_time('mysql')
        );
        
        if ($backup_id) {
            $update_data['backup_id'] = $backup_id;
        }
        
        $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $log_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Get recent backup logs
     */
    private function get_recent_backup_logs($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wpengine_backup_logs';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }
}
} // End class_exists check

// Initialize the plugin
if (class_exists('WPEngineBackupScheduler')) {
    new WPEngineBackupScheduler();
}

// Admin JavaScript (inline for simplicity - in production, this should be in a separate file)
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen->id !== 'tools_page_wpengine-backup-scheduler') {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Save Email Settings
        $('#wpengine-email-settings').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Saving...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_api_settings',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val(),
                install_id: $('input[name="install_id"]').val(),
                install_name: $('input[name="install_name"]').val(),
                email_notifications: $('input[name="email_notifications"]').val()
            }, function(response) {
                if (response.success) {
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                } else {
                    $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Save API Settings
        $('#wpengine-api-settings').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Saving...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_api_settings',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val(),
                install_id: $('input[name="install_id"]').val(),
                install_name: $('input[name="install_name"]').val()
            }, function(response) {
                if (response.success) {
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                } else {
                    $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Debug Settings
        $('#debug-settings').on('click', function() {
            var $btn = $(this);
            var $output = $('#debug-output');
            
            $btn.prop('disabled', true).text('<?php _e('Loading...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_debug_settings',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success) {
                    $output.html('<strong>Current Settings:</strong><br>' + JSON.stringify(response.data, null, 2)).show();
                } else {
                    $output.html('<strong>Error:</strong> ' + response.data).show();
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Debug Settings', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Test API Connection
        $('#test-api-connection').on('click', function() {
            var $btn = $(this);
            var $result = $('#api-test-result');
            
            $btn.prop('disabled', true).text(wpengineBackup.strings.testing_api);
            $result.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_test_api',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val()
            }, function(response) {
                if (response.success) {
                    $result.addClass('status-success').text(response.data);
                } else {
                    $result.addClass('status-error').text(response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Test API Connection', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Auto-detect current install
        $('#auto-detect-install-btn').on('click', function() {
            var $btn = $(this);
            var $status = $('#auto-detect-status');
            
            $btn.prop('disabled', true).text('<?php _e('Detecting...', 'wpengine-backup-scheduler'); ?>');
            $status.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_auto_detect_install',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success) {
                    $('input[name="install_id"]').val(response.data.install_id);
                    $('input[name="install_name"]').val(response.data.install_name);
                    
                    $status.addClass('status-success').text(response.data.message);
                    
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>')
                        .insertAfter('.wrap h1').delay(5000).fadeOut();
                } else {
                    $status.addClass('status-error').text(response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Auto-Detect & Configure Current Install', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Fetch WP Engine Installs
        $('#fetch-installs-btn').on('click', function() {
            var $btn = $(this);
            var $status = $('#fetch-installs-status');
            var $list = $('#installs-list');
            
            $btn.prop('disabled', true).text('<?php _e('Loading all installs...', 'wpengine-backup-scheduler'); ?>');
            $status.removeClass('status-success status-error').text('<?php _e('This may take a moment for accounts with many installs...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_fetch_installs',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success && response.data) {
                    var installs = response.data;
                    var html = '';
                    
                    if (installs.length > 0) {
                        html = '<div style="border: 1px solid #ddd; padding: 10px; background: #f9f9f9; max-height: 300px; overflow-y: auto;">';
                        html += '<p><strong><?php _e('Found', 'wpengine-backup-scheduler'); ?> ' + installs.length + ' <?php _e('installs. Select your install:', 'wpengine-backup-scheduler'); ?></strong></p>';
                        
                        // Add search box for many installs
                        if (installs.length > 10) {
                            html += '<input type="text" id="install-search" placeholder="<?php _e('Search installs...', 'wpengine-backup-scheduler'); ?>" style="width: 100%; margin-bottom: 10px; padding: 5px;">';
                        }
                        
                        html += '<div id="install-options">';
                        installs.forEach(function(install) {
                            html += '<label style="display: block; margin: 5px 0; cursor: pointer;" class="install-option" data-name="' + (install.name || '').toLowerCase() + '">';
                            html += '<input type="radio" name="selected_install" value="' + install.id + '" data-name="' + (install.name || '') + '" style="margin-right: 8px;">';
                            html += '<span style="font-weight: bold;">' + (install.name || 'Unnamed') + '</span>';
                            html += ' <span style="color: #666;">(ID: ' + install.id + ')</span>';
                            if (install.environment) {
                                html += ' <span style="color: #0073aa;">(' + install.environment + ')</span>';
                            }
                            html += '</label>';
                        });
                        html += '</div>';
                        
                        html += '<button type="button" id="select-install-btn" class="button button-primary" style="margin-top: 10px;"><?php _e('Use Selected Install', 'wpengine-backup-scheduler'); ?></button>';
                        html += '</div>';
                    } else {
                        html = '<p class="error"><?php _e('No installs found.', 'wpengine-backup-scheduler'); ?></p>';
                    }
                    
                    $list.html(html);
                    $status.addClass('status-success').text('<?php _e('All installs loaded successfully', 'wpengine-backup-scheduler'); ?>');
                    
                    // Add search functionality
                    $('#install-search').on('input', function() {
                        var searchTerm = $(this).val().toLowerCase();
                        $('.install-option').each(function() {
                            var installName = $(this).data('name');
                            if (installName.indexOf(searchTerm) !== -1) {
                                $(this).show();
                            } else {
                                $(this).hide();
                            }
                        });
                    });
                    
                } else {
                    $list.html('<p class="error"><?php _e('Failed to load installs.', 'wpengine-backup-scheduler'); ?></p>');
                    $status.addClass('status-error').text(response.data || '<?php _e('Unknown error occurred', 'wpengine-backup-scheduler'); ?>');
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Load All My Installs', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Handle install selection
        $(document).on('click', '#select-install-btn', function() {
            var selectedInstall = $('input[name="selected_install"]:checked');
            if (selectedInstall.length) {
                var installId = selectedInstall.val();
                var installName = selectedInstall.data('name');
                
                $('input[name="install_id"]').val(installId);
                $('input[name="install_name"]').val(installName);
                
                $('#installs-list').html('<p class="success"><?php _e('Install selected successfully!', 'wpengine-backup-scheduler'); ?></p>');
            } else {
                alert('<?php _e('Please select an install first.', 'wpengine-backup-scheduler'); ?>');
            }
        });
        
        // Cancel Scheduled Backups
        $('#cancel-schedule-btn, #cancel-schedule-sidebar-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            
            // Confirm before canceling
            if (!confirm('<?php _e('Are you sure you want to cancel all scheduled backups? This will disable automatic backups.', 'wpengine-backup-scheduler'); ?>')) {
                return;
            }
            
            $btn.prop('disabled', true).text('<?php _e('Canceling...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_cancel_schedule',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success) {
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                    
                    // Update the UI
                    $('input[name="enabled"]').prop('checked', false);
                    $('#cancel-schedule-btn').hide();
                    
                    // Reload page to update the sidebar
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Save Schedule Settings
        $('#wpengine-backup-schedule').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            
            $btn.prop('disabled', true);
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_schedule',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val(),
                install_id: $('input[name="install_id"]').val(),
                install_name: $('input[name="install_name"]').val(),
                backup_frequency: $('select[name="backup_frequency"]').val(),
                email_notifications: $('input[name="email_notifications"]').val(),
                enabled: $('input[name="enabled"]').is(':checked') ? 1 : 0
            }, function(response) {
                if (response.success) {
                    // Show success message
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(3000).fadeOut();
                    
                    // Reload page to update next backup time
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('<div class="notice notice-error is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1');
                }
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });
        
        // Create Manual Backup
        $('#wpengine-manual-backup').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $('#create-backup-btn');
            var $status = $('#backup-status');
            var description = $('input[name="backup_description"]').val();
            
            $btn.prop('disabled', true).text(wpengineBackup.strings.creating_backup);
            $status.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_create_backup',
                nonce: wpengineBackup.nonce,
                description: description
            }, function(response) {
                if (response.success) {
                    $status.addClass('status-success').text(wpengineBackup.strings.backup_created);
                    $('input[name="backup_description"]').val('');
                    
                    // Reload page to update backup logs
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $status.addClass('status-error').text(wpengineBackup.strings.backup_failed + ' ' + response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text('<?php _e('Create Backup Now', 'wpengine-backup-scheduler'); ?>');
            });
        });
        
        // Auto-dismiss notices
        $(document).on('click', '.notice-dismissible .notice-dismiss', function() {
            $(this).parent().fadeOut();
        });
        
        // Debug Panel Functions
        $('#debug-cron-btn').on('click', function() {
            var $btn = $(this);
            var $panel = $('#debug-panel');
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Running Diagnostics...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_debug_cron',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success) {
                    var data = response.data;
                    var html = '<strong>🔍 CRON DEBUG REPORT</strong><br><br>';
                    
                    // Environment
                    html += '<strong>Environment:</strong><br>';
                    html += '  WP Engine: ' + (data.environment.wp_engine_detected ? '✅ Detected' : '❌ Not detected') + '<br>';
                    html += '  WP_CRON disabled: ' + (data.environment.wp_cron_disabled ? '✅ Yes (good)' : '❌ No (problem)') + '<br>';
                    html += '  WordPress: ' + data.environment.wordpress_version + '<br>';
                    html += '  PHP: ' + data.environment.php_version + '<br><br>';
                    
                    // Configuration
                    html += '<strong>Configuration:</strong><br>';
                    html += '  API Username: ' + (data.configuration.api_username_set ? '✅ Set' : '❌ Missing') + '<br>';
                    html += '  API Password: ' + (data.configuration.api_password_set ? '✅ Set' : '❌ Missing') + '<br>';
                    html += '  Install ID: ' + (data.configuration.install_id ? '✅ ' + data.configuration.install_id : '❌ Missing') + '<br>';
                    html += '  Email: ' + (data.configuration.email_notifications ? '✅ ' + data.configuration.email_notifications : '❌ Missing') + '<br>';
                    html += '  Enabled: ' + (data.configuration.backups_enabled ? '✅ Yes' : '❌ No') + '<br>';
                    html += '  Frequency: ' + data.configuration.frequency + '<br><br>';
                    
                    // Cron Status
                    html += '<strong>Cron Status:</strong><br>';
                    if (data.cron_status.next_backup_formatted) {
                        html += '  Next backup: ✅ ' + data.cron_status.next_backup_formatted + '<br>';
                        var timeUntil = data.cron_status.time_until_next;
                        if (timeUntil > 0) {
                            var hours = Math.floor(timeUntil / 3600);
                            var minutes = Math.floor((timeUntil % 3600) / 60);
                            html += '  Time until: ' + hours + 'h ' + minutes + 'm<br>';
                        } else {
                            html += '  Status: ⚠️ OVERDUE by ' + Math.abs(Math.floor(timeUntil / 60)) + ' minutes<br>';
                        }
                    } else {
                        html += '  Next backup: ❌ NO CRON EVENT SCHEDULED<br>';
                    }
                    html += '  Backup running: ' + (data.cron_status.backup_running ? '⏳ Yes' : '❌ No') + '<br><br>';
                    
                    // API Test
                    html += '<strong>API Test:</strong><br>';
                    html += '  Result: ' + (data.api_test.success ? '✅ ' + data.api_test.message : '❌ ' + data.api_test.message) + '<br><br>';
                    
                    // Recent Activity
                    html += '<strong>Recent Activity:</strong><br>';
                    if (data.recent_activity && data.recent_activity.length > 0) {
                        data.recent_activity.forEach(function(log) {
                            var icon = log.status === 'success' ? '✅' : (log.status === 'error' ? '❌' : '⏳');
                            html += '  ' + icon + ' ' + log.created_at + ' - ' + log.backup_type + ' (' + log.status + ')<br>';
                        });
                    } else {
                        html += '  ❌ No activity found<br>';
                    }
                    
                    $panel.html(html).show();
                } else {
                    $panel.html('<strong>Error:</strong> ' + response.data).show();
                }
            }).fail(function() {
                $panel.html('<strong>Error:</strong> Failed to run diagnostics').show();
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        $('#test-backup-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Creating Test Backup...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_test_backup',
                nonce: wpengineBackup.nonce
            }, function(response) {
                var message = response.success ? 
                    '✅ Test backup successful: ' + response.data : 
                    '❌ Test backup failed: ' + response.data;
                    
                $('#debug-panel').html('<strong>' + message + '</strong>').show();
                
                // Reload page after a delay to show new backup in logs
                if (response.success) {
                    setTimeout(function() { location.reload(); }, 2000);
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        $('#trigger-cron-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            
            if (!confirm('<?php _e('This will manually trigger the scheduled backup function. Continue?', 'wpengine-backup-scheduler'); ?>')) {
                return;
            }
            
            $btn.prop('disabled', true).text('<?php _e('Triggering...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_trigger_cron',
                nonce: wpengineBackup.nonce
            }, function(response) {
                var message = response.success ? 
                    '✅ ' + response.data : 
                    '❌ ' + response.data;
                    
                $('#debug-panel').html('<strong>' + message + '</strong>').show();
                
                // Reload page after a delay to show results
                setTimeout(function() { location.reload(); }, 3000);
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Step-by-step onboarding functions
        
        // Save API settings for step 1
        $('#wpengine-api-settings').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $('#save-api-btn');
            var $result = $('#api-test-result');
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Saving & Testing...', 'wpengine-backup-scheduler'); ?>');
            $result.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_api_settings',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val()
            }, function(response) {
                if (response.success) {
                    $result.addClass('status-success').text('<?php _e('✅ API credentials saved and tested successfully!', 'wpengine-backup-scheduler'); ?>');
                    
                    // Show success notification without page reload
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(4000).fadeOut();
                } else {
                    $result.addClass('status-error').text(response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Save email and schedule settings for step 3
        $('#wpengine-email-schedule-settings').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $status = $('#email-schedule-status');
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Saving...', 'wpengine-backup-scheduler'); ?>');
            $status.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_api_settings',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val(),
                install_id: $('input[name="install_id"]').val(),
                install_name: $('input[name="install_name"]').val(),
                email_notifications: $('input[name="email_notifications"]').val(),
                backup_frequency: $('select[name="backup_frequency"]').val()
            }, function(response) {
                if (response.success) {
                    $status.addClass('status-success').text('<?php _e('✅ Configuration saved successfully!', 'wpengine-backup-scheduler'); ?>');
                    
                    // Show success notification without page reload
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(4000).fadeOut();
                } else {
                    $status.addClass('status-error').text(response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Enable automatic backups (final step)
        $('#enable-backups-btn').on('click', function() {
            var $btn = $(this);
            var $status = $('#enable-backups-status');
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Enabling...', 'wpengine-backup-scheduler'); ?>');
            $status.removeClass('status-success status-error').text('');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_save_schedule',
                nonce: wpengineBackup.nonce,
                api_username: $('input[name="api_username"]').val(),
                api_password: $('input[name="api_password"]').val(),
                install_id: $('input[name="install_id"]').val(),
                install_name: $('input[name="install_name"]').val(),
                backup_frequency: $('select[name="backup_frequency"]').val(),
                email_notifications: $('input[name="email_notifications"]').val(),
                enabled: 1
            }, function(response) {
                if (response.success) {
                    $status.addClass('status-success').text('<?php _e('🎉 Automatic backups enabled successfully!', 'wpengine-backup-scheduler'); ?>');
                    
                    // Reload page to show completed state
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $status.addClass('status-error').text(response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Disable automatic backups
        $('#disable-backups-btn').on('click', function() {
            if (!confirm('<?php _e('Are you sure you want to disable automatic backups?', 'wpengine-backup-scheduler'); ?>')) {
                return;
            }
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Disabling...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_cancel_schedule',
                nonce: wpengineBackup.nonce
            }, function(response) {
                if (response.success) {
                    // Show success message and reload
                    $('<div class="notice notice-success is-dismissible"><p>' + response.data + '</p></div>')
                        .insertAfter('.wrap h1').delay(2000).fadeOut();
                    
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    alert(response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Test backup from final step
        $('#test-backup-final-btn').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Creating Test Backup...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_create_backup',
                nonce: wpengineBackup.nonce,
                description: 'Test backup before enabling automatic backups'
            }, function(response) {
                if (response.success) {
                    alert('<?php _e('Test backup created successfully! Check your email for confirmation.', 'wpengine-backup-scheduler'); ?>');
                } else {
                    alert('<?php _e('Test backup failed: ', 'wpengine-backup-scheduler'); ?>' + response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Create manual backup from final step
        $('#create-manual-backup-btn').on('click', function() {
            var description = prompt('<?php _e('Enter backup description (optional):', 'wpengine-backup-scheduler'); ?>');
            if (description === null) return; // User cancelled
            
            var $btn = $(this);
            var originalText = $btn.text();
            
            $btn.prop('disabled', true).text('<?php _e('Creating Backup...', 'wpengine-backup-scheduler'); ?>');
            
            $.post(wpengineBackup.ajaxUrl, {
                action: 'wpengine_create_backup',
                nonce: wpengineBackup.nonce,
                description: description || ''
            }, function(response) {
                if (response.success) {
                    alert('<?php _e('Manual backup created successfully!', 'wpengine-backup-scheduler'); ?>');
                    
                    // Reload to show in recent activity
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    alert('<?php _e('Backup failed: ', 'wpengine-backup-scheduler'); ?>' + response.data);
                }
            }).always(function() {
                $btn.prop('disabled', false).text(originalText);
            });
        });
        
        // Toggle manual configuration visibility
        window.toggleManualConfig = function() {
            var $manualConfig = $('#manual-install-config');
            var $toggle = $('.manual-config-toggle button');
            
            if ($manualConfig.is(':visible')) {
                $manualConfig.slideUp();
                $toggle.text('<?php _e('Or configure manually ↓', 'wpengine-backup-scheduler'); ?>');
            } else {
                $manualConfig.slideDown();
                $toggle.text('<?php _e('Hide manual configuration ↑', 'wpengine-backup-scheduler'); ?>');
            }
        };
        
        // Note: toggleStepEdit function removed - forms are now always editable
        
    });
    </script>
    <?php
});

/**
 * Additional helper functions and hooks
 */

// Add settings link to plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('tools.php?page=wpengine-backup-scheduler') . '">' . __('Settings', 'wpengine-backup-scheduler') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Add admin notice if API credentials are not set
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen->id !== 'tools_page_wpengine-backup-scheduler') {
        return;
    }
    
    $settings = get_option('wpengine_backup_settings', array());
    $plugin_instance = new WPEngineBackupScheduler();
    
    // Check for WP Engine Alternate Cron setup requirements
    $is_wpengine = $plugin_instance->is_wpengine_hosting();
    $is_wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    
    if ($is_wpengine && !$is_wp_cron_disabled) {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler - IMPORTANT SETUP REQUIRED', 'wpengine-backup-scheduler'); ?>:</strong><br>
                <?php _e('For reliable hourly backups on WP Engine hosting, please complete these steps:', 'wpengine-backup-scheduler'); ?>
            </p>
            <ol style="margin: 10px 0 10px 25px;">
                <li><?php _e('Add this line to your wp-config.php file:', 'wpengine-backup-scheduler'); ?> <code>define( 'DISABLE_WP_CRON', true );</code></li>
                <li><?php _e('Enable "Alternate Cron" in your WP Engine User Portal under Utilities', 'wpengine-backup-scheduler'); ?></li>
            </ol>
            <p><a href="https://wpengine.com/support/wp-cron-wordpress-scheduling/" target="_blank"><?php _e('View WP Engine Cron Setup Guide', 'wpengine-backup-scheduler'); ?></a></p>
        </div>
        <?php
    } elseif ($is_wpengine && $is_wp_cron_disabled) {
        ?>
        <div class="notice notice-success">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?>:</strong>
                <?php _e('✓ WP Engine hosting detected with proper cron configuration. Your hourly backups should run reliably!', 'wpengine-backup-scheduler'); ?>
            </p>
        </div>
        <?php
    }
    
    if (empty($settings['api_username']) || empty($settings['api_password'])) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?>:</strong>
                <?php _e('Please configure your API credentials below, then use "Auto-Detect & Configure Current Install" to automatically set up the plugin.', 'wpengine-backup-scheduler'); ?>
            </p>
        </div>
        <?php
    } elseif (empty($settings['install_id'])) {
        ?>
        <div class="notice notice-info">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?>:</strong>
                <?php _e('Click "Auto-Detect & Configure Current Install" to automatically configure this plugin for the current WP Engine install.', 'wpengine-backup-scheduler'); ?>
            </p>
        </div>
        <?php
    } elseif (empty($settings['email_notifications'])) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('WP Engine Backup Scheduler', 'wpengine-backup-scheduler'); ?>:</strong>
                <?php _e('Please add an email address in the backup schedule settings. Email notifications are required by the WP Engine API for backup creation.', 'wpengine-backup-scheduler'); ?>
            </p>
        </div>
        <?php
    }
});

// Clean up old backup logs (keep only last 100 entries)
add_action('wpengine_backup_cron_hook', function() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'wpengine_backup_logs';
    
    // Delete old logs, keeping only the 100 most recent
    $wpdb->query("
        DELETE FROM $table_name 
        WHERE id NOT IN (
            SELECT id FROM (
                SELECT id FROM $table_name 
                ORDER BY created_at DESC 
                LIMIT 100
            ) AS recent_logs
        )
    ");
});

// Add dashboard widget for backup status
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'wpengine_backup_status',
        __('WP Engine Backup Status', 'wpengine-backup-scheduler'),
        function() {
            $settings = get_option('wpengine_backup_settings', array());
            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpengine_backup_logs';
            $last_backup = $wpdb->get_row("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 1");
            
            echo '<div class="wpengine-backup-dashboard-widget">';
            
            if ($settings['enabled'] ?? false) {
                echo '<p><span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> ';
                echo __('Automatic backups enabled', 'wpengine-backup-scheduler') . '</p>';
                
                if ($next_backup) {
                    echo '<p><strong>' . __('Next backup:', 'wpengine-backup-scheduler') . '</strong> ';
                    echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_backup) . '</p>';
                }
            } else {
                echo '<p><span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span> ';
                echo __('Automatic backups disabled', 'wpengine-backup-scheduler') . '</p>';
            }
            
            if ($last_backup) {
                echo '<p><strong>' . __('Last backup:', 'wpengine-backup-scheduler') . '</strong> ';
                echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_backup->created_at));
                echo ' (' . ucfirst($last_backup->status) . ')</p>';
            }
            
            echo '<p><a href="' . admin_url('tools.php?page=wpengine-backup-scheduler') . '" class="button">';
            echo __('Manage Backups', 'wpengine-backup-scheduler') . '</a></p>';
            echo '</div>';
        }
    );
});

// Add WP-CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    /**
     * WP-CLI commands for WP Engine Backup Scheduler
     */
    class WPEngine_Backup_CLI_Command {
        
        /**
         * Create a manual backup
         *
         * ## OPTIONS
         *
         * [--description=<description>]
         * : Description for the backup
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup create --description="Pre-update backup"
         */
        public function create($args, $assoc_args) {
            $plugin = new WPEngineBackupScheduler();
            $description = $assoc_args['description'] ?? 'WP-CLI backup';
            
            WP_CLI::log('Creating backup...');
            
            $result = $plugin->create_backup('manual', $description);
            
            if ($result['success']) {
                WP_CLI::success($result['message']);
            } else {
                WP_CLI::error($result['message']);
            }
        }
        
        /**
         * Show backup status and recent activity
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup status
         */
        public function status($args, $assoc_args) {
            $settings = get_option('wpengine_backup_settings', array());
            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
            $plugin = new WPEngineBackupScheduler();
            
            WP_CLI::log('WP Engine Backup Scheduler Status:');
            WP_CLI::log('');
            
            // WP Engine hosting check
            $is_wpengine = $plugin->is_wpengine_hosting();
            $is_wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            
            if ($is_wpengine) {
                WP_CLI::log('✓ WP Engine hosting: DETECTED');
                WP_CLI::log('  WP_CRON disabled: ' . ($is_wp_cron_disabled ? '✓ YES (recommended)' : '✗ NO (should enable Alternate Cron)'));
                
                if (!$is_wp_cron_disabled) {
                    WP_CLI::log('  ⚠️  For reliable hourly backups, add DISABLE_WP_CRON to wp-config.php and enable Alternate Cron');
                }
            } else {
                WP_CLI::log('? WP Engine hosting: NOT DETECTED');
            }
            
            WP_CLI::log('');
            
            if ($settings['enabled'] ?? false) {
                WP_CLI::log('✓ Automatic backups: ENABLED');
                WP_CLI::log('  Frequency: Every ' . ($settings['backup_frequency'] ?? 24) . ' hours');
                
                if ($next_backup) {
                    WP_CLI::log('  Next backup: ' . date_i18n('Y-m-d H:i:s', $next_backup));
                }
            } else {
                WP_CLI::log('✗ Automatic backups: DISABLED');
            }
            
            WP_CLI::log('');
            
            // Show recent backup logs
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpengine_backup_logs';
            $recent_logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 5");
            
            if ($recent_logs) {
                WP_CLI::log('Recent backup activity:');
                foreach ($recent_logs as $log) {
                    $status_icon = $log->status === 'success' ? '✓' : ($log->status === 'error' ? '✗' : '⋯');
                    WP_CLI::log(sprintf(
                        '  %s %s %s (%s) - %s',
                        $status_icon,
                        $log->created_at,
                        ucfirst($log->backup_type),
                        ucfirst($log->status),
                        $log->message
                    ));
                }
            } else {
                WP_CLI::log('No backup activity recorded.');
            }
        }
        
        /**
         * Enable or disable automatic backups
         *
         * ## OPTIONS
         *
         * <action>
         * : Action to perform (enable|disable)
         *
         * [--frequency=<hours>]
         * : Backup frequency in hours (1-24)
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup toggle enable --frequency=12
         *     wp wpengine-backup toggle disable
         */
        public function toggle($args, $assoc_args) {
            $action = $args[0] ?? '';
            
            if (!in_array($action, ['enable', 'disable'])) {
                WP_CLI::error('Invalid action. Use "enable" or "disable".');
            }
            
            $settings = get_option('wpengine_backup_settings', array());
            $settings['enabled'] = ($action === 'enable');
            
            if ($action === 'enable' && isset($assoc_args['frequency'])) {
                $frequency = intval($assoc_args['frequency']);
                if ($frequency >= 1 && $frequency <= 24) {
                    $settings['backup_frequency'] = $frequency;
                } else {
                    WP_CLI::error('Frequency must be between 1 and 24 hours.');
                }
            }
            
            update_option('wpengine_backup_settings', $settings);
            
            // Update cron schedule
            wp_clear_scheduled_hook('wpengine_backup_cron_hook');
            
            if ($settings['enabled']) {
                // Ensure custom schedules are registered before scheduling
                $this->setup_cron_schedules();
                
                $interval = $settings['backup_frequency'] == 24 ? 'daily' : "every_{$settings['backup_frequency']}_hours";
                wp_schedule_event(time(), $interval, 'wpengine_backup_cron_hook');
            }
            
            WP_CLI::success(sprintf('Automatic backups %s.', $action === 'enable' ? 'enabled' : 'disabled'));
        }
        
        /**
         * Auto-detect current WP Engine install
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup detect
         */
        public function detect($args, $assoc_args) {
            $plugin = new WPEngineBackupScheduler();
            
            WP_CLI::log('Auto-detecting current WP Engine install...');
            
            $result = $plugin->auto_configure_install();
            
            if ($result['success']) {
                WP_CLI::success($result['message']);
                WP_CLI::log('Install ID: ' . $result['install_id']);
                WP_CLI::log('Install Name: ' . $result['install_name']);
                WP_CLI::log('Environment: ' . $result['environment']);
            } else {
                WP_CLI::error($result['message']);
            }
        }
        
        /**
         * Debug cron and backup configuration issues
         *
         * ## OPTIONS
         *
         * [--verbose]
         * : Show detailed debugging information
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup debug
         *     wp wpengine-backup debug --verbose
         */
        public function debug($args, $assoc_args) {
            $verbose = isset($assoc_args['verbose']);
            $plugin = new WPEngineBackupScheduler();
            
            WP_CLI::log('=== WP Engine Backup Scheduler Debug Report ===');
            WP_CLI::log('');
            
            // 1. Environment Check
            WP_CLI::log('🔍 ENVIRONMENT CHECK:');
            $is_wpengine = $plugin->is_wpengine_hosting();
            $is_wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            
            WP_CLI::log('  WP Engine hosting: ' . ($is_wpengine ? '✅ DETECTED' : '❌ NOT DETECTED'));
            WP_CLI::log('  DISABLE_WP_CRON: ' . ($is_wp_cron_disabled ? '✅ TRUE (good)' : '❌ FALSE (problem)'));
            WP_CLI::log('  WordPress version: ' . get_bloginfo('version'));
            WP_CLI::log('  PHP version: ' . PHP_VERSION);
            WP_CLI::log('');
            
            // 2. Plugin Configuration
            WP_CLI::log('⚙️  PLUGIN CONFIGURATION:');
            $settings = get_option('wpengine_backup_settings', array());
            
            WP_CLI::log('  API Username: ' . (!empty($settings['api_username']) ? '✅ SET' : '❌ MISSING'));
            WP_CLI::log('  API Password: ' . (!empty($settings['api_password']) ? '✅ SET' : '❌ MISSING'));
            WP_CLI::log('  Install ID: ' . (!empty($settings['install_id']) ? '✅ ' . $settings['install_id'] : '❌ MISSING'));
            WP_CLI::log('  Email Notifications: ' . (!empty($settings['email_notifications']) ? '✅ ' . $settings['email_notifications'] : '❌ MISSING'));
            WP_CLI::log('  Backups Enabled: ' . (($settings['enabled'] ?? false) ? '✅ YES' : '❌ NO'));
            WP_CLI::log('  Frequency: ' . ($settings['backup_frequency'] ?? 'not set') . ' hours');
            WP_CLI::log('');
            
            // 3. Cron Status
            WP_CLI::log('⏰ CRON STATUS:');
            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
            
            if ($next_backup) {
                $time_until = $next_backup - time();
                WP_CLI::log('  Next backup scheduled: ✅ ' . date('Y-m-d H:i:s', $next_backup));
                WP_CLI::log('  Time until next backup: ' . ($time_until > 0 ? gmdate('H:i:s', $time_until) : 'OVERDUE by ' . gmdate('H:i:s', abs($time_until))));
            } else {
                WP_CLI::log('  Next backup scheduled: ❌ NO CRON EVENT FOUND');
            }
            
            // Get all scheduled events for our hook
            $cron_array = _get_cron_array();
            $our_events = array();
            foreach ($cron_array as $timestamp => $cron) {
                if (isset($cron['wpengine_backup_cron_hook'])) {
                    $our_events[] = array('time' => $timestamp, 'data' => $cron['wpengine_backup_cron_hook']);
                }
            }
            
            WP_CLI::log('  Scheduled events found: ' . count($our_events));
            if ($verbose && !empty($our_events)) {
                foreach ($our_events as $event) {
                    WP_CLI::log('    - ' . date('Y-m-d H:i:s', $event['time']));
                }
            }
            WP_CLI::log('');
            
            // 4. Recent Backup Activity
            WP_CLI::log('📋 RECENT BACKUP ACTIVITY:');
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpengine_backup_logs';
            $recent_logs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d",
                $verbose ? 10 : 5
            ));
            
            if ($recent_logs) {
                foreach ($recent_logs as $log) {
                    $icon = $log->status === 'success' ? '✅' : ($log->status === 'error' ? '❌' : '⏳');
                    WP_CLI::log('  ' . $icon . ' ' . $log->created_at . ' - ' . ucfirst($log->backup_type) . ' (' . $log->status . ')');
                    if ($verbose && $log->message) {
                        WP_CLI::log('      Message: ' . $log->message);
                    }
                }
            } else {
                WP_CLI::log('  ❌ No backup activity found');
            }
            WP_CLI::log('');
            
            // 5. Test API Connection
            WP_CLI::log('🔌 API CONNECTION TEST:');
            if (!empty($settings['api_username']) && !empty($settings['api_password'])) {
                $test_result = $plugin->test_api_connection($settings['api_username'], $settings['api_password']);
                WP_CLI::log('  API Test: ' . ($test_result['success'] ? '✅ ' . $test_result['message'] : '❌ ' . $test_result['message']));
            } else {
                WP_CLI::log('  API Test: ❌ Cannot test - credentials missing');
            }
            WP_CLI::log('');
            
            // 6. Check for running backup
            WP_CLI::log('🚀 CURRENT BACKUP STATUS:');
            $running_transient = get_transient('wpengine_backup_running');
            if ($running_transient) {
                $running_since = time() - $running_transient;
                WP_CLI::log('  Backup currently running: ⏳ Started ' . gmdate('H:i:s', $running_since) . ' ago');
            } else {
                WP_CLI::log('  Backup currently running: ❌ No');
            }
            WP_CLI::log('');
            
            // 7. Recommendations
            WP_CLI::log('💡 RECOMMENDATIONS:');
            $issues = array();
            
            if (!$is_wpengine) {
                $issues[] = 'Not running on WP Engine hosting - this plugin is designed for WP Engine';
            }
            
            if (!$is_wp_cron_disabled) {
                $issues[] = 'Add "define( \'DISABLE_WP_CRON\', true );" to wp-config.php';
                $issues[] = 'Enable Alternate Cron in WP Engine User Portal → Utilities';
            }
            
            if (empty($settings['api_username']) || empty($settings['api_password'])) {
                $issues[] = 'Configure API credentials in plugin settings';
            }
            
            if (empty($settings['install_id'])) {
                $issues[] = 'Use "Auto-Detect & Configure Current Install" in plugin settings';
            }
            
            if (empty($settings['email_notifications'])) {
                $issues[] = 'Add email notification address (required by WP Engine API)';
            }
            
            if (!($settings['enabled'] ?? false)) {
                $issues[] = 'Enable automatic backups in plugin settings';
            }
            
            if (!$next_backup && ($settings['enabled'] ?? false)) {
                $issues[] = 'No cron event scheduled - try saving settings again';
            }
            
            if (empty($issues)) {
                WP_CLI::success('✅ No configuration issues found!');
                if (!empty($recent_logs)) {
                    $latest = $recent_logs[0];
                    if ($latest->status === 'error') {
                        WP_CLI::log('  However, the latest backup failed. Check the error message above.');
                    }
                } else {
                    WP_CLI::log('  Try running: wp wpengine-backup test');
                }
            } else {
                foreach ($issues as $issue) {
                    WP_CLI::log('  ⚠️  ' . $issue);
                }
            }
            
            WP_CLI::log('');
            WP_CLI::log('=== End Debug Report ===');
        }
        
        /**
         * Test the backup system by manually triggering a backup
         *
         * ## OPTIONS
         *
         * [--description=<description>]
         * : Description for the test backup
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup test
         *     wp wpengine-backup test --description="Debug test backup"
         */
        public function test($args, $assoc_args) {
            $plugin = new WPEngineBackupScheduler();
            $description = $assoc_args['description'] ?? 'WP-CLI test backup';
            
            WP_CLI::log('🧪 Testing backup system...');
            WP_CLI::log('');
            
            // Check configuration first
            $settings = get_option('wpengine_backup_settings', array());
            $issues = array();
            
            if (empty($settings['api_username']) || empty($settings['api_password'])) {
                $issues[] = 'API credentials not configured';
            }
            
            if (empty($settings['install_id'])) {
                $issues[] = 'Install ID not configured';
            }
            
            if (empty($settings['email_notifications'])) {
                $issues[] = 'Email notifications not configured';
            }
            
            if (!empty($issues)) {
                WP_CLI::error('Cannot run test - configuration issues found:' . "\n  - " . implode("\n  - ", $issues));
                return;
            }
            
            WP_CLI::log('Configuration check: ✅ Passed');
            WP_CLI::log('Creating test backup...');
            
            $result = $plugin->create_backup('manual', $description);
            
            if ($result['success']) {
                WP_CLI::success('✅ Test backup created successfully!');
                WP_CLI::log('Message: ' . $result['message']);
                if (isset($result['backup_id'])) {
                    WP_CLI::log('Backup ID: ' . $result['backup_id']);
                }
            } else {
                WP_CLI::error('❌ Test backup failed: ' . $result['message']);
            }
        }
        
        /**
         * Manually trigger the scheduled backup function for testing
         *
         * ## EXAMPLES
         *
         *     wp wpengine-backup trigger-cron
         */
        public function trigger_cron($args, $assoc_args) {
            $plugin = new WPEngineBackupScheduler();
            
            WP_CLI::log('🔧 Manually triggering scheduled backup function...');
            WP_CLI::log('');
            
            // Check if backup is already running
            $running_transient = get_transient('wpengine_backup_running');
            if ($running_transient) {
                WP_CLI::warning('A backup appears to be already running (started ' . gmdate('H:i:s', time() - $running_transient) . ' ago)');
                WP_CLI::confirm('Continue anyway?');
            }
            
            WP_CLI::log('Executing scheduled backup function...');
            
            $result = $plugin->execute_scheduled_backup();
            
            if ($result !== false) {
                WP_CLI::success('✅ Scheduled backup function executed');
                WP_CLI::log('Check the backup logs for results');
            } else {
                WP_CLI::error('❌ Scheduled backup function returned false');
            }
            
            // Show recent logs
            WP_CLI::log('');
            WP_CLI::log('Recent backup activity:');
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpengine_backup_logs';
            $recent_logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 3");
            
            foreach ($recent_logs as $log) {
                $icon = $log->status === 'success' ? '✅' : ($log->status === 'error' ? '❌' : '⏳');
                WP_CLI::log('  ' . $icon . ' ' . $log->created_at . ' - ' . ucfirst($log->backup_type) . ' (' . $log->status . ')');
                if ($log->message) {
                    WP_CLI::log('      ' . $log->message);
                }
            }
        }
    }
    
    WP_CLI::add_command('wpengine-backup', 'WPEngine_Backup_CLI_Command');
}

/**
 * REST API endpoints for external integrations
 */
add_action('rest_api_init', function() {
    register_rest_route('wpengine-backup/v1', '/status', array(
        'methods' => 'GET',
        'callback' => function() {
            $settings = get_option('wpengine_backup_settings', array());
            $next_backup = wp_next_scheduled('wpengine_backup_cron_hook');
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'wpengine_backup_logs';
            $last_backup = $wpdb->get_row("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 1");
            
            return array(
                'enabled' => $settings['enabled'] ?? false,
                'frequency_hours' => $settings['backup_frequency'] ?? 24,
                'install_id' => $settings['install_id'] ?? null,
                'install_name' => $settings['install_name'] ?? null,
                'next_backup' => $next_backup ? date_i18n('c', $next_backup) : null,
                'last_backup' => $last_backup ? array(
                    'date' => $last_backup->created_at,
                    'type' => $last_backup->backup_type,
                    'status' => $last_backup->status,
                    'message' => $last_backup->message
                ) : null
            );
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
    
    register_rest_route('wpengine-backup/v1', '/create', array(
        'methods' => 'POST',
        'callback' => function($request) {
            $plugin = new WPEngineBackupScheduler();
            $description = $request->get_param('description') ?? 'REST API backup';
            
            $result = $plugin->create_backup('manual', $description);
            
            if ($result['success']) {
                return new WP_REST_Response($result, 200);
            } else {
                return new WP_Error('backup_failed', $result['message'], array('status' => 500));
            }
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => array(
            'description' => array(
                'required' => false,
                'type' => 'string',
                'description' => 'Backup description'
            )
        )
    ));
});

?>